<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Db\Db;
use Aifec\Kernel\App;

/**
 * Servicio «orders»: de un carrito a un pedido sin perder ni duplicar nada (contrato § 4 y reglas del dinero de 03-comercio.md).
 *
 * Cobrado UNA sola vez (regla 5): el pedido nace «pendiente de pago» antes de ir a la pasarela y markPaid() decide quién
 * gana con un UPDATE condicionado (`payment_status <> 'paid'`); solo el que cambia la fila dispara lo demás. Lo mismo vale
 * para devolver las existencias (la transición de estado la gana uno) y para las devoluciones de dinero (se reserva el
 * importe con una sentencia condicionada antes de llamar a la pasarela).
 *
 * Quién manda es el aviso de la pasarela (servidor a servidor), no la vuelta del navegador: la caja solo llama a markPaid()
 * cuando la forma de pago responde «paid».
 *
 * Estados (tabla order_states): pending_payment, on_hold, processing, shipped, completed, cancelled, refunded, failed.
 * De «cancelled» y «refunded» no se sale por setStatus(); «cancelled» y «refunded» tampoco se ponen con setStatus() (para
 * eso están cancel() y refund()). Un pedido pagado no se cancela: se devuelve.
 *
 * Acciones: order.placed (array $pedido), order.paid (array $pedido), order.status (array $pedido, string $de, string $a),
 * order.cancelled (array $pedido), order.refunded (array $pedido, array $devolucion).
 *
 * El pedido que recibe PaymentMethod::start() lleva en «secret» la ficha en claro (la que place() devuelve): las
 * pasarelas la necesitan para volver a la página del pedido de un cliente sin cuenta. En cualquier otro sitio, find() no
 * devuelve la ficha ni su resumen.
 */
final class Orders
{
    /** @var App */
    private $app;

    /** @var OrderLog */
    private $log;

    /** @var OrderStock */
    private $stock;

    /** @var OrderMails */
    private $mails;

    /** @var Downloads */
    private $downloads;

    /** @var OrderPlacer */
    private $placer;

    /** @var array<int,string> fichas en claro de los pedidos creados en esta petición (solo para el enlace de sus correos) */
    private $secrets = [];

    /** @var object|null la petición a la que pertenecen las fichas de arriba: con otra petición se olvidan */
    private $secretsRequest;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->log = new OrderLog($app);
        $this->stock = new OrderStock($app, $this->log);
        $this->mails = new OrderMails($app);
        $this->downloads = new Downloads($app);
        $this->placer = new OrderPlacer($app, $this->log, $this->stock, new Sequence($app));
    }

    public function downloads(): Downloads
    {
        return $this->downloads;
    }

    /**
     * Registra el pedido de una cuota cuyo cobro ya ha confirmado la pasarela.
     * No empieza otro cobro ni necesita carrito. La clave externa identifica una sola cuota.
     *
     * @param array<string,mixed> $snapshot datos aceptados del servicio y del cobro
     *
     * @return array{id:int,number:string}
     */
    public function createRecurringPayment(array $snapshot, string $externalKey): array
    {
        return (new RecurringOrders($this->app, $this))->create($snapshot, $externalKey);
    }

    // ------------------------------------------------------------------ crear

    /**
     * Crea el pedido desde el carrito actual y empieza el cobro.
     *
     * $checkout: email, billing (dirección), shipping (dirección; vacío = la misma), note, accept_terms, marketing,
     * create_account + password. $options: source (web), attribution (array) e ip.
     *
     * Devuelve ['ok','error','errors' => [campo => mensaje],'id','number','secret','action' => PaymentAction|null]. Con
     * un error de validación no se ha tocado nada. Si la forma de pago no arranca el cobro, el pedido queda «failed», las
     * existencias vuelven y el resultado trae el id y la ficha (para reabrir el carrito con Cart::reopen()).
     *
     * @param array<string,mixed> $checkout
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function place(array $checkout, array $options = []): array
    {
        $placed = $this->placer->place($checkout, $options);
        if (empty($placed['ok'])) {
            $placed['action'] = null;

            return $placed;
        }
        $id = (int) $placed['id'];
        $secret = (string) $placed['secret'];
        $this->rememberSecret($id, $secret);
        $order = $this->find($id);
        if ($order === null) {
            throw new \LogicException('The order that was just created cannot be read');
        }
        $this->app->hooks()->fire('order.placed', $order);

        $code = (string) $order['payment_method'];
        $method = $this->app->service('payments')->get($code);
        $action = null;
        $failure = '';
        try {
            if ($method === null) {
                throw new \RuntimeException('The payment method is not available');
            }
            $action = $method->start(['secret' => $secret] + $order);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.start:' . $code);
            $failure = $e->getMessage();
        }
        if ($action === null) {
            $this->paymentFailed($id, $code, $failure !== '' ? $failure : 'The payment could not be started');
            $error = t('The payment could not be started. Try again or choose another payment method.');

            return ['ok' => false, 'error' => $error, 'errors' => [], 'id' => $id, 'number' => (string) $placed['number'], 'secret' => $secret, 'action' => PaymentAction::failed($error)];
        }

        if ($action->type === PaymentAction::PAID) {
            $this->markPaid($id, $code, $action->reference, (int) $order['total'], [], $method !== null && $method->isTest());
        } elseif ($action->type === PaymentAction::INSTRUCTIONS) {
            $row = $this->row($id);
            if ($row !== null) {
                $this->log->add($id, 'payment', null, 'instructions', t('Waiting for the customer to pay'), false, null, ['instructions' => $action->html]);
                $this->changeStatus($row, 'on_hold', '', null, true, ['instructions' => $action->html]);
            }
        } elseif ($action->type === PaymentAction::FAILED) {
            $this->paymentFailed($id, $code, $action->message !== '' ? $action->message : 'The payment was refused');

            return ['ok' => false, 'error' => $action->message !== '' ? $action->message : t('The payment was refused'), 'errors' => [], 'id' => $id, 'number' => (string) $placed['number'], 'secret' => $secret, 'action' => $action];
        }

        return ['ok' => true, 'error' => '', 'errors' => [], 'id' => $id, 'number' => (string) $placed['number'], 'secret' => $secret, 'action' => $action];
    }

    // ------------------------------------------------------------------ lecturas

    /**
     * El pedido completo, con todo descodificado (contrato § 5): billing, shipping, taxes, adjustments, coupons y
     * attribution como arrays; items, history, payments, refunds, state y customer.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->row($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(string $number): ?array
    {
        $row = $this->app->db()->find('orders', ['number' => trim($number)]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * La ficha en claro con la que se ve un pedido sin cuenta. No se guarda en ningún sitio: sale de la clave del sitio y
     * del número del pedido, así cualquier petición posterior (el correo de «enviado», el panel) puede volver a montar el
     * enlace. En la tabla solo queda su resumen. Cambiar la clave del sitio deja sin valor los enlaces antiguos.
     */
    public static function viewToken(App $app, string $number): string
    {
        return substr(hash_hmac('sha256', 'order-view:' . $number, $app->site()->secret()), 0, 48);
    }

    /**
     * La ficha en claro de un pedido que ya existe, o '' si la suya no es la que sale de la clave del sitio (un pedido
     * traído de otra tienda, o creado antes de cambiar la clave).
     */
    public function viewTokenOf(string $number): string
    {
        $stored = (string) $this->app->db()->value('SELECT secret FROM {orders} WHERE number = ?', [trim($number)]);
        $token = self::viewToken($this->app, trim($number));

        return $stored !== '' && hash_equals($stored, hash('sha256', $token)) ? $token : '';
    }

    /**
     * Un pedido por su número y su ficha en claro (la de ver el pedido sin cuenta). La comparación es en tiempo
     * constante sobre el resumen; una ficha vacía o equivocada da null.
     *
     * @return array<string,mixed>|null
     */
    public function findBySecret(string $number, string $secret): ?array
    {
        $row = $this->app->db()->find('orders', ['number' => trim($number)]);
        if ($row === null || $secret === '') {
            return null;
        }
        $stored = isset($row['secret']) && is_string($row['secret']) ? $row['secret'] : '';
        if ($stored === '' || !hash_equals($stored, hash('sha256', $secret))) {
            return null;
        }

        return $this->hydrate($row);
    }

    // ------------------------------------------------------------------ cobro

    /**
     * Apunta un cobro. Devuelve true SOLO la primera vez que el pedido pasa a pagado.
     *
     * El cobro se apunta con INSERT IGNORE (clave única método + referencia + tipo): el mismo aviso con la misma
     * referencia no crea otro. Si el importe no es el del pedido, queda «on_hold» con una nota y devuelve false. Un pedido
     * ya cancelado o fallido que recibe su cobro (el cliente pagó tarde) queda «on_hold» con una nota bien visible; las
     * existencias se intentan volver a descontar y, si no hay, la nota lo dice.
     *
     * @param array<string,mixed> $raw lo que mandó la pasarela
     */
    public function markPaid(int $orderId, string $method, string $reference, int $amount, array $raw = [], bool $test = false): bool
    {
        $db = $this->app->db();
        $row = $this->row($orderId);
        if ($row === null) {
            return false;
        }
        $method = strtolower(trim($method));
        if ($method === '') {
            $method = (string) $row['payment_method'] !== '' ? (string) $row['payment_method'] : 'manual';
        }
        $reference = trim($reference);
        if ($reference === '') {
            $reference = 'order-' . (string) $row['number'];
        }
        $reference = mb_substr($reference, 0, 120, 'UTF-8');
        $now = $this->app->clock()->sql();

        $inserted = $db->insertIgnore('order_payments', [
            'order_id' => $orderId, 'method' => mb_substr($method, 0, 40, 'UTF-8'), 'kind' => 'charge', 'reference' => $reference, 'amount' => $amount,
            'currency' => (string) $row['currency'], 'status' => 'ok', 'test' => $test ? 1 : 0, 'raw' => OrderLog::json($raw), 'created_at' => $now,
        ]);
        if (!$inserted) {
            $existing = $db->row('SELECT order_id FROM {order_payments} WHERE method = ? AND reference = ? AND kind = ?', [mb_substr($method, 0, 40, 'UTF-8'), $reference, 'charge']);
            if ($existing === null || (int) $existing['order_id'] !== $orderId) {
                $this->app->log()->warning('A payment reference belongs to another order', ['order' => $orderId, 'method' => $method]);

                return false;
            }
        }

        $total = (int) $row['total'];
        $status = (string) $row['status'];
        if ($amount !== $total) {
            if ($inserted) {
                $note = t('The amount received ({received}) is not the order total ({total}). Check it before shipping.', [
                    'received' => $this->money($amount, (string) $row['currency']), 'total' => $this->money($total, (string) $row['currency']),
                ]);
                $this->log->add($orderId, 'payment', null, 'amount_mismatch', $note, false, null, ['method' => $method, 'reference' => $reference, 'amount' => $amount]);
                if (in_array($status, ['pending_payment', 'processing'], true)) {
                    $this->changeStatus($row, 'on_hold', '', null, false);
                }
            }

            return false;
        }
        if (in_array($status, ['cancelled', 'failed'], true)) {
            $this->lateCharge($row, $method, $reference, $amount, $test);

            return false;
        }
        if ($status === 'refunded') {
            return false;
        }

        $set = "payment_status = 'paid', paid_at = ?, paid_total = ?, updated_at = ?" . ($test ? ', test = 1' : '');
        $changed = $db->run(
            'UPDATE {orders} SET ' . $set . " WHERE id = ? AND payment_status <> 'paid' AND status NOT IN ('cancelled', 'failed', 'refunded')",
            [$now, $total, $now, $orderId]
        );
        if ($changed !== 1) {
            if ($inserted) {
                $this->log->add($orderId, 'payment', null, 'extra_payment', t('Another payment was received for an order that was already paid ({method}, reference {reference}).', ['method' => $method, 'reference' => $reference]), false, null, ['method' => $method, 'reference' => $reference, 'amount' => $amount]);
            }

            return false;
        }

        // Solo el primero llega aquí.
        $this->afterPaid($orderId, $method, $reference);

        return true;
    }

    /**
     * Lo que pasa una sola vez, cuando el pedido acaba de quedar pagado.
     */
    private function afterPaid(int $orderId, string $method, string $reference): void
    {
        $db = $this->app->db();
        $row = $this->row($orderId);
        if ($row === null) {
            return;
        }
        $this->log->add($orderId, 'payment', null, 'paid', t('Payment received: {method}, reference {reference}', ['method' => $method, 'reference' => $reference]), false, null, ['method' => $method, 'reference' => $reference]);

        $to = (string) $row['fulfillment_status'] === 'not_needed' ? 'completed' : 'processing';
        if (in_array((string) $row['status'], ['pending_payment', 'on_hold'], true)) {
            $this->changeStatus($row, $to, '', null, false);
        }

        $order = $this->find($orderId);
        if ($order === null) {
            return;
        }
        // Un pedido de prueba no cuenta como venta: ni en lo que ha gastado el cliente ni en los más vendidos (14/11/2026: un cliente
        // con 41.989,60 € «gastados» que eran todos pedidos de prueba).
        $counts = empty($order['test']);
        try {
            foreach ($counts ? $order['items'] : [] as $item) {
                if ((string) $item['kind'] === 'product' && (int) $item['product_id'] > 0) {
                    $db->run('UPDATE {products} SET sales_count = sales_count + ? WHERE id = ?', [(int) $item['qty'], (int) $item['product_id']]);
                }
            }
            if ($counts && $order['customer_id'] !== null) {
                $db->run('UPDATE {customers} SET orders_count = orders_count + 1, total_spent = total_spent + ? WHERE id = ?', [(int) $order['total'], (int) $order['customer_id']]);
            }
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.paid.counters');
        }
        $granted = [];
        try {
            $granted = $this->downloads->grant($order);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.paid.downloads');
        }
        $this->mails->send($order, 'order_confirmed', ['downloads' => $granted], $this->secretOf($orderId));
        $this->mails->admin($order);
        $this->app->hooks()->fire('order.paid', $order);
    }

    /**
     * El cobro de un pedido cancelado o fallido: se queda «on_hold» con una nota que no se pueda pasar por alto.
     *
     * @param array<string,mixed> $row
     */
    private function lateCharge(array $row, string $method, string $reference, int $amount, bool $test): void
    {
        $db = $this->app->db();
        $id = (int) $row['id'];
        $now = $this->app->clock()->sql();
        $changed = $db->run(
            "UPDATE {orders} SET payment_status = 'paid', paid_at = ?, paid_total = ?, updated_at = ?" . ($test ? ', test = 1' : '') . " WHERE id = ? AND payment_status <> 'paid' AND status IN ('cancelled', 'failed')",
            [$now, $amount, $now, $id]
        );
        if ($changed !== 1) {
            return;
        }
        $missing = [];
        if (!$this->stock->held($id)) {
            $missing = $this->stock->take($row, 'Late payment');
        }
        if ($missing === []) {
            $note = t('LATE PAYMENT: this order was {state} when the payment arrived. The stock was reserved again; decide whether to process it or refund it.', ['state' => (string) $row['status']]);
        } else {
            $note = t('LATE PAYMENT: this order was {state} when the payment arrived and there is no stock left of: {names}. Refund it or find the goods.', ['state' => (string) $row['status'], 'names' => implode(', ', $missing)]);
        }
        $this->log->add($id, 'payment', null, 'late_payment', $note, false, null, ['method' => $method, 'reference' => $reference, 'amount' => $amount, 'missing' => $missing]);

        $from = (string) $row['status'];
        $moved = $db->run("UPDATE {orders} SET status = 'on_hold', cancelled_at = NULL, updated_at = ? WHERE id = ? AND status = ?", [$now, $id, $from]);
        if ($moved === 1) {
            $this->log->add($id, 'status', $from, 'on_hold', $note, false);
            $fresh = $this->find($id);
            if ($fresh !== null) {
                $this->app->hooks()->fire('order.status', $fresh, $from, 'on_hold');
            }
        }
    }

    /**
     * El cobro ha fallado (la pasarela lo rechazó o no arrancó): el pedido queda «failed», las existencias vuelven y los
     * cupones se liberan, una sola vez aunque se llame dos veces. El carrito sigue ligado al pedido: Cart::reopen() lo
     * encuentra por ese vínculo y lo deja libre para reintentar.
     *
     * @param array<string,mixed> $raw
     */
    public function paymentFailed(int $orderId, string $method, string $reason, array $raw = []): void
    {
        $db = $this->app->db();
        $row = $this->row($orderId);
        if ($row === null) {
            return;
        }
        $from = (string) $row['status'];
        $now = $this->app->clock()->sql();
        $changed = $db->run(
            "UPDATE {orders} SET status = 'failed', payment_status = 'failed', updated_at = ? WHERE id = ? AND status IN ('pending_payment', 'on_hold') AND payment_status IN ('pending', 'authorized')",
            [$now, $orderId]
        );
        if ($changed !== 1) {
            return;
        }
        $method = strtolower(trim($method)) !== '' ? strtolower(trim($method)) : (string) $row['payment_method'];
        $attempt = (int) $db->value("SELECT COUNT(*) FROM {order_payments} WHERE order_id = ? AND status = 'failed'", [$orderId]) + 1;
        $db->insertIgnore('order_payments', [
            'order_id' => $orderId, 'method' => mb_substr($method, 0, 40, 'UTF-8'), 'kind' => 'charge', 'reference' => 'failed-' . $orderId . '-' . $attempt,
            'amount' => 0, 'currency' => (string) $row['currency'], 'status' => 'failed', 'test' => !empty($row['test']) ? 1 : 0, 'raw' => OrderLog::json($raw), 'created_at' => $now,
        ]);
        $this->log->add($orderId, 'payment', null, 'failed', mb_substr($reason, 0, 1000, 'UTF-8'), false, null, ['method' => $method]);
        $this->log->add($orderId, 'status', $from, 'failed', t('The payment failed'), true);
        $this->stock->release($row, 'Payment failed');
        $this->releaseCoupons($orderId);
        $fresh = $this->find($orderId);
        if ($fresh !== null) {
            $this->app->hooks()->fire('order.status', $fresh, $from, 'failed');
        }
    }

    // ------------------------------------------------------------------ estados

    /**
     * Cambia el estado. Solo estados que existen y transiciones permitidas; «cancelled» se pasa a cancel() y «refunded» o
     * «failed» no se ponen a mano. Apunta quién y cuándo, actualiza las fechas y el estado de preparación, manda el correo
     * del estado si $notify y lanza la acción order.status. False si no se ha cambiado nada.
     */
    public function setStatus(int $orderId, string $state, string $note = '', ?int $userId = null, bool $notify = true): bool
    {
        $state = trim($state);
        $row = $this->row($orderId);
        if ($row === null || $this->app->db()->find('order_states', ['code' => $state]) === null) {
            return false;
        }
        $from = (string) $row['status'];
        if ($state === 'cancelled') {
            return $this->cancel($orderId, $note !== '' ? $note : t('Cancelled'), true, $userId);
        }
        if (in_array($state, ['refunded', 'failed', 'pending_payment'], true) || in_array($from, ['cancelled', 'refunded'], true)) {
            return false;
        }
        if ($from === 'failed' && in_array($state, ['processing', 'shipped', 'completed'], true)) {
            return false;
        }
        if ($from === 'pending_payment' && in_array($state, ['processing', 'shipped', 'completed'], true)) {
            return false;
        }
        if ($state === 'shipped' && (string) $row['fulfillment_status'] === 'not_needed') {
            return false;
        }

        return $this->changeStatus($row, $state, $note, $userId, $notify);
    }

    /**
     * El cambio de estado de verdad: una sola sentencia condicionada al estado de partida.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $extra datos para el correo (instructions)
     */
    private function changeStatus(array $row, string $to, string $note, ?int $userId, bool $notify, array $extra = []): bool
    {
        $id = (int) $row['id'];
        $from = (string) $row['status'];
        if ($from === $to) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $fulfillment = (string) $row['fulfillment_status'];
        $set = ['status' => $to, 'updated_at' => $now];
        if ($to === 'shipped') {
            $set['shipped_at'] = $now;
            if ($fulfillment !== 'not_needed') {
                $set['fulfillment_status'] = 'fulfilled';
            }
        } elseif ($to === 'completed') {
            $set['completed_at'] = $now;
            if ($fulfillment !== 'not_needed') {
                $set['fulfillment_status'] = 'fulfilled';
            }
        } elseif ($to === 'processing' && $fulfillment === 'fulfilled') {
            $set['fulfillment_status'] = 'unfulfilled';
        }
        if ($this->app->db()->update('orders', $set, ['id' => $id, 'status' => $from]) !== 1) {
            return false;
        }
        $this->log->add($id, 'status', $from, $to, $note, true, $userId);
        $order = $this->find($id);
        if ($order === null) {
            return true;
        }
        if ($notify) {
            $template = $this->app->db()->value('SELECT mail FROM {order_states} WHERE code = ?', [$to]);
            if (is_string($template) && $template !== '') {
                $this->mails->send($order, $template, $this->mailExtra($order, $template, $extra), $this->secretOf($id));
            }
        }
        $this->app->hooks()->fire('order.status', $order, $from, $to);

        return true;
    }

    /**
     * Lo que lleva cada plantilla además del pedido: las instrucciones de pago en «on_hold» (las del cobro, o las últimas
     * que dejó la forma de pago) y las descargas concedidas en la confirmación.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function mailExtra(array $order, string $template, array $extra): array
    {
        if ($template === 'order_on_hold' && !isset($extra['instructions'])) {
            foreach (array_reverse(isset($order['history']) && is_array($order['history']) ? $order['history'] : []) as $entry) {
                if ((string) $entry['kind'] === 'payment' && isset($entry['data']['instructions'])) {
                    $extra['instructions'] = (string) $entry['data']['instructions'];
                    break;
                }
            }
        }

        return $extra;
    }

    /** Apunta una nota en el historial; $public = la ve el cliente. */
    public function addNote(int $orderId, string $note, bool $public, ?int $userId = null): void
    {
        $note = trim($note);
        if ($note === '' || $this->row($orderId) === null) {
            return;
        }
        $this->log->add($orderId, 'note', null, null, mb_substr($note, 0, 4000, 'UTF-8'), $public, $userId);
    }

    // ------------------------------------------------------------------ cancelar

    /**
     * Cancela un pedido sin pagar: repone las existencias (una vez, si $restock) y libera los cupones. Un pedido pagado
     * no se cancela, se devuelve: false. Manda el correo «order_cancelled» y lanza order.cancelled.
     */
    public function cancel(int $orderId, string $reason, bool $restock = true, ?int $userId = null): bool
    {
        return $this->doCancel($orderId, $reason, $restock, $userId, true);
    }

    private function doCancel(int $orderId, string $reason, bool $restock, ?int $userId, bool $notify): bool
    {
        $db = $this->app->db();
        $row = $this->row($orderId);
        if ($row === null) {
            return false;
        }
        $from = (string) $row['status'];
        $paymentStatus = (string) $row['payment_status'];
        if (!in_array($paymentStatus, ['pending', 'authorized'], true) || in_array($from, ['cancelled', 'refunded', 'failed'], true)) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $changed = $db->update('orders', ['status' => 'cancelled', 'payment_status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now], ['id' => $orderId, 'status' => $from, 'payment_status' => $paymentStatus]);
        if ($changed !== 1) {
            return false;
        }
        $this->log->add($orderId, 'status', $from, 'cancelled', $reason, true, $userId);
        if ($restock) {
            $this->stock->release($row, 'Order cancelled', $userId);
        }
        $this->releaseCoupons($orderId);
        $order = $this->find($orderId);
        if ($order === null) {
            return true;
        }
        if ($notify) {
            $this->mails->send($order, 'order_cancelled', [], $this->secretOf($orderId));
        }
        $this->app->hooks()->fire('order.cancelled', $order);

        return true;
    }

    /**
     * Cancela los pedidos de pasarela que llevan más de orders.pending_minutes (120) sin pagarse. Solo los de las últimas
     * 48 horas y como mucho 50 por pasada. Los de transferencia quedan «on_hold» y no caducan aquí. No manda correo.
     */
    public function expireUnpaid(): int
    {
        $minutes = $this->app->settings()->int('orders.pending_minutes', 120);
        if ($minutes < 1) {
            return 0;
        }
        $clock = $this->app->clock();
        $now = $clock->now();
        $rows = $this->app->db()->select(
            "SELECT id FROM {orders} WHERE status = 'pending_payment' AND payment_status = 'pending' AND placed_at < ? AND placed_at >= ? ORDER BY placed_at ASC, id ASC LIMIT 50",
            [$clock->sql($now - $minutes * 60), $clock->sql($now - 172800)]
        );
        $cancelled = 0;
        foreach ($rows as $row) {
            if ($this->doCancel((int) $row['id'], t('The payment was not completed in time'), true, null, false)) {
                ++$cancelled;
            }
        }

        return $cancelled;
    }

    // ------------------------------------------------------------------ devolver

    /**
     * Devuelve dinero de un pedido pagado y apunta la devolución. Devuelve el id de la devolución.
     *
     * $items = [order_item_id => unidades] (opcional) actualiza refunded_qty. Con $viaGateway se pide a la forma de pago
     * (PaymentMethod::refund): si lanza, no se apunta nada y la excepción sale tal cual. El importe se reserva con una
     * sentencia condicionada ANTES de llamar a la pasarela, así dos devoluciones a la vez no pasan de lo cobrado. Con
     * $restock se reponen las unidades devueltas (o todas las pendientes si se devuelve el pedido entero sin detallarlas).
     *
     * @param array<int,int> $items
     *
     * @throws \InvalidArgumentException pedido sin pagar, importe no válido o más de lo cobrado, líneas que no son del pedido
     * @throws \RuntimeException         la pasarela rechaza la devolución o la forma de pago no devuelve por ese cauce
     */
    public function refund(int $orderId, int $amount, array $items, string $reason, bool $viaGateway, bool $restock, ?int $userId = null): int
    {
        $db = $this->app->db();
        $row = $this->row($orderId);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown order');
        }
        if (!in_array((string) $row['payment_status'], ['paid', 'partially_refunded'], true)) {
            throw new \InvalidArgumentException('The order has not been paid');
        }
        if ($amount < 1) {
            throw new \InvalidArgumentException('The refund amount must be greater than zero');
        }
        if ($amount > (int) $row['paid_total'] - (int) $row['refunded_total']) {
            throw new \InvalidArgumentException('The refund is higher than what was paid');
        }
        $clean = $this->refundItems($orderId, $items);
        $full = $amount === (int) $row['paid_total'] - (int) $row['refunded_total'];
        if ($restock && $clean === [] && $full) {
            foreach ($db->select("SELECT id, qty, refunded_qty FROM {order_items} WHERE order_id = ? AND kind = 'product'", [$orderId]) as $line) {
                if ((int) $line['qty'] > (int) $line['refunded_qty']) {
                    $clean[(int) $line['id']] = (int) $line['qty'] - (int) $line['refunded_qty'];
                }
            }
        }

        // El importe se reserva primero: una sentencia, y solo una de dos peticiones a la vez se lo queda.
        $reserved = $db->run('UPDATE {orders} SET refunded_total = refunded_total + ? WHERE id = ? AND paid_total - refunded_total >= ?', [$amount, $orderId, $amount]);
        if ($reserved !== 1) {
            throw new \InvalidArgumentException('The refund is higher than what was paid');
        }
        $reference = '';
        if ($viaGateway) {
            try {
                $method = $this->app->service('payments')->get((string) $row['payment_method']);
                if ($method === null || !$method->supportsRefund()) {
                    throw new \RuntimeException('This payment method cannot refund through the gateway');
                }
                $order = $this->find($orderId);
                $reference = $method->refund($order !== null ? $order : $row, $amount, $reason);
            } catch (\Throwable $e) {
                $db->run('UPDATE {orders} SET refunded_total = refunded_total - ? WHERE id = ?', [$amount, $orderId]);
                throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            }
        }

        $now = $this->app->clock()->sql();
        $refundId = (int) $db->transaction(function (Db $db) use ($row, $orderId, $amount, $clean, $reason, $restock, $userId, $reference, $now): int {
            $total = (int) $row['total'];
            $tax = $total > 0 ? Money::divRound($amount * (int) $row['total_tax'], $total) : 0;
            $id = $db->insert('order_refunds', [
                'order_id' => $orderId, 'amount' => $amount, 'tax' => $tax, 'items' => OrderLog::json($clean), 'reason' => mb_substr($reason, 0, 255, 'UTF-8'),
                'restock' => $restock ? 1 : 0, 'payment_id' => null, 'user_id' => $userId, 'created_at' => $now,
            ]);
            $paymentId = $db->insert('order_payments', [
                'order_id' => $orderId, 'method' => mb_substr((string) $row['payment_method'] !== '' ? (string) $row['payment_method'] : 'manual', 0, 40, 'UTF-8'),
                'kind' => 'refund', 'reference' => $reference !== '' ? mb_substr($reference, 0, 120, 'UTF-8') : 'refund-' . $id, 'amount' => $amount,
                'currency' => (string) $row['currency'], 'status' => 'ok', 'test' => !empty($row['test']) ? 1 : 0, 'raw' => null, 'created_at' => $now,
            ]);
            $db->update('order_refunds', ['payment_id' => $paymentId], ['id' => $id]);
            foreach ($clean as $itemId => $qty) {
                $db->run('UPDATE {order_items} SET refunded_qty = refunded_qty + ? WHERE id = ? AND order_id = ?', [$qty, $itemId, $orderId]);
            }
            $refunded = (int) $db->value('SELECT refunded_total FROM {orders} WHERE id = ?', [$orderId]);
            $db->update('orders', ['payment_status' => $refunded >= (int) $row['paid_total'] ? 'refunded' : 'partially_refunded', 'updated_at' => $now], ['id' => $orderId]);
            $this->log->add($orderId, 'refund', null, (string) $amount, $reason !== '' ? $reason : t('Refund'), true, $userId, ['refund_id' => $id, 'items' => $clean]);

            return $id;
        });

        $fresh = $this->row($orderId);
        if ($fresh !== null) {
            if ((string) $fresh['payment_status'] === 'refunded') {
                $this->changeStatus($fresh, 'refunded', $reason, $userId, false);
            }
            if ($restock && $clean !== []) {
                $this->stock->restockItems($fresh, $clean, 'Order refunded');
            }
        }
        $order = $this->find($orderId);
        $refund = $db->find('order_refunds', ['id' => $refundId]);
        if ($order !== null && $refund !== null) {
            $refund['items'] = OrderLog::decode($refund['items']);
            foreach (['id', 'order_id', 'amount', 'tax'] as $key) {
                $refund[$key] = (int) $refund[$key];
            }
            $refund['restock'] = !empty($refund['restock']);
            $this->mails->send($order, 'order_refunded', ['refund' => ['amount' => $amount]], $this->secretOf($orderId));
            $this->app->hooks()->fire('order.refunded', $order, $refund);
        }

        return $refundId;
    }

    /**
     * Las unidades a devolver por línea, comprobadas: solo líneas de producto de este pedido y sin pasar de lo que queda.
     *
     * @param array<int|string,mixed> $items
     *
     * @return array<int,int>
     */
    private function refundItems(int $orderId, array $items): array
    {
        $clean = [];
        foreach ($items as $itemId => $qty) {
            $qty = is_numeric($qty) ? (int) $qty : 0;
            if ($qty < 1) {
                continue;
            }
            $line = $this->app->db()->row("SELECT qty, refunded_qty FROM {order_items} WHERE id = ? AND order_id = ? AND kind = 'product'", [(int) $itemId, $orderId]);
            if ($line === null || $qty > (int) $line['qty'] - (int) $line['refunded_qty']) {
                throw new \InvalidArgumentException('Invalid quantity for an order line');
            }
            $clean[(int) $itemId] = $qty;
        }

        return $clean;
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * La fila de orders tal cual.
     *
     * @return array<string,mixed>|null
     */
    private function row(int $id): ?array
    {
        return $id > 0 ? $this->app->db()->find('orders', ['id' => $id]) : null;
    }

    /** Recuerda la ficha en claro de un pedido recién creado, solo mientras dure la petición que lo creó. */
    private function rememberSecret(int $orderId, string $secret): void
    {
        $request = $this->app->request();
        if ($this->secretsRequest !== $request) {
            $this->secrets = [];
            $this->secretsRequest = $request;
        }
        $this->secrets[$orderId] = $secret;
    }

    /** La ficha en claro de un pedido creado en esta misma petición, o cadena vacía. */
    private function secretOf(int $orderId): string
    {
        if ($this->secretsRequest !== $this->app->request()) {
            $this->secrets = [];
            $this->secretsRequest = null;

            return '';
        }

        return isset($this->secrets[$orderId]) ? $this->secrets[$orderId] : '';
    }

    private function releaseCoupons(int $orderId): void
    {
        if ($this->app->has('coupons')) {
            $this->app->service('coupons')->release($orderId);
        }
    }

    private function money(int $cents, string $currency): string
    {
        return $this->app->i18n()->money($cents, $currency);
    }

    /**
     * La fila con todo descodificado y sus partes cargadas. La ficha (ni en claro ni su resumen) no sale nunca.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $db = $this->app->db();
        $id = (int) $row['id'];
        unset($row['secret']);
        foreach (['id', 'items_total', 'discount_total', 'shipping_total', 'fee_total', 'total_net', 'total_tax', 'total', 'paid_total', 'refunded_total'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['customer_id', 'carrier_id', 'cart_id'] as $key) {
            $row[$key] = $row[$key] !== null && (int) $row[$key] > 0 ? (int) $row[$key] : null;
        }
        $row['prices_include_tax'] = !empty($row['prices_include_tax']);
        $row['test'] = !empty($row['test']);
        foreach (['billing', 'shipping', 'taxes', 'adjustments', 'coupons', 'attribution'] as $key) {
            $row[$key] = OrderLog::decode($row[$key]);
        }

        $items = $db->select('SELECT * FROM {order_items} WHERE order_id = ? ORDER BY position, id', [$id]);
        foreach ($items as $i => $item) {
            foreach (['id', 'order_id', 'qty', 'unit_price', 'discount', 'line_total', 'line_net', 'line_tax', 'tax_rate', 'weight', 'refunded_qty', 'refunded_total', 'position'] as $key) {
                $items[$i][$key] = (int) $item[$key];
            }
            foreach (['product_id', 'variant_id'] as $key) {
                $items[$i][$key] = $item[$key] !== null ? (int) $item[$key] : null;
            }
            $items[$i]['is_digital'] = !empty($item['is_digital']);
            $items[$i]['data'] = OrderLog::decode($item['data']);
        }
        $row['items'] = $items;

        $history = $db->select('SELECT * FROM {order_history} WHERE order_id = ? ORDER BY id', [$id]);
        foreach ($history as $i => $entry) {
            $history[$i]['id'] = (int) $entry['id'];
            $history[$i]['order_id'] = (int) $entry['order_id'];
            $history[$i]['user_id'] = $entry['user_id'] !== null ? (int) $entry['user_id'] : null;
            $history[$i]['is_public'] = !empty($entry['is_public']);
            $history[$i]['data'] = OrderLog::decode($entry['data']);
        }
        $row['history'] = $history;

        $payments = $db->select('SELECT * FROM {order_payments} WHERE order_id = ? ORDER BY id', [$id]);
        foreach ($payments as $i => $payment) {
            $payments[$i]['id'] = (int) $payment['id'];
            $payments[$i]['order_id'] = (int) $payment['order_id'];
            $payments[$i]['amount'] = (int) $payment['amount'];
            $payments[$i]['test'] = !empty($payment['test']);
            $payments[$i]['raw'] = OrderLog::decode($payment['raw']);
        }
        $row['payments'] = $payments;

        $refunds = $db->select('SELECT * FROM {order_refunds} WHERE order_id = ? ORDER BY id', [$id]);
        foreach ($refunds as $i => $refund) {
            $refunds[$i]['id'] = (int) $refund['id'];
            $refunds[$i]['order_id'] = (int) $refund['order_id'];
            $refunds[$i]['payment_id'] = $refund['payment_id'] !== null ? (int) $refund['payment_id'] : null;
            $refunds[$i]['user_id'] = $refund['user_id'] !== null ? (int) $refund['user_id'] : null;
            $refunds[$i]['amount'] = (int) $refund['amount'];
            $refunds[$i]['tax'] = (int) $refund['tax'];
            $refunds[$i]['restock'] = !empty($refund['restock']);
            $refunds[$i]['items'] = OrderLog::decode($refund['items']);
        }
        $row['refunds'] = $refunds;

        $row['state'] = $db->find('order_states', ['code' => (string) $row['status']]);
        $row['customer'] = $row['customer_id'] !== null && $this->app->has('customers') ? $this->app->service('customers')->find((int) $row['customer_id']) : null;

        return $row;
    }
}
