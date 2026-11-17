<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Sequence;

/**
 * El servicio «returns»: pedir una devolución o el desistimiento de un pedido y llevarla por sus estados hasta devolver el dinero.
 *
 * Estados: requested → approved → received → refunded, y rejected / cancelled. Cada cambio queda en return_events (quién, cuándo,
 * nota) y avisa al cliente por correo si se pide. Este módulo NO toca pagos ni existencias por su cuenta: devolver el dinero es
 * llamar a Orders::refund() con las líneas, y es el pedido quien repone las existencias y lanza «order.refunded» (con lo que el
 * módulo de facturas emite la rectificativa).
 *
 * Una sola devolución abierta por pedido, garantizada por la columna única open_key de la base de datos. Nada recorre pedidos
 * antiguos: el módulo solo actúa cuando alguien pide una devolución o la tramita.
 *
 * Acciones: return.requested (array $return) y return.status (array $return, string $from, string $to).
 */
final class Returns
{
    /** Estados en los que la devolución sigue abierta. */
    public const OPEN = ['requested', 'approved', 'received'];

    private App $app;

    private ReturnPolicy $policy;

    private ReturnAmounts $amounts;

    private ReturnMails $mails;

    private ReturnPhotos $photos;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->policy = new ReturnPolicy($app);
        $this->amounts = new ReturnAmounts($app);
        $this->mails = new ReturnMails($app);
        $this->photos = new ReturnPhotos($app);
    }

    public function policy(): ReturnPolicy
    {
        return $this->policy;
    }

    public function amounts(): ReturnAmounts
    {
        return $this->amounts;
    }

    public function photos(): ReturnPhotos
    {
        return $this->photos;
    }

    /**
     * Qué se puede devolver de un pedido y hasta cuándo (ver ReturnPolicy::eligibility()).
     *
     * @return array<string,mixed>
     */
    public function eligibility(int $orderId): array
    {
        return $this->policy->eligibility($orderId);
    }

    // ------------------------------------------------------------------ pedir

    /**
     * Pide una devolución (o el desistimiento, con el motivo «withdrawal»). No comprueba quién llama: eso lo hace quien la llama
     * (la ficha del pedido, la sesión del cliente o el correo con el número del pedido).
     *
     * $lines = [id de la línea del pedido => unidades]. $options: photos (ficheros subidos: name, tmp_name, size, type; solo con el
     * motivo «defective»), customer_id, actor (customer, staff o system) y source (web, assistant, api, admin).
     *
     * Devuelve ['ok','error','errors' => [campo => texto],'id','number']. Con un error no se ha guardado nada.
     *
     * @param array<int|string,mixed> $lines
     * @param array<string,mixed>     $options
     *
     * @return array<string,mixed>
     */
    public function request(int $orderId, array $lines, string $reason, string $comment = '', array $options = []): array
    {
        $eligibility = $this->policy->eligibility($orderId);
        if (!$eligibility['ok']) {
            return $this->failed((string) $eligibility['message'], ['order' => (string) $eligibility['message']]);
        }
        $order = $this->app->service('orders')->find($orderId);
        if ($order === null) {
            return $this->failed(t('We could not find that order.'), []);
        }
        $reason = trim($reason);
        $withdrawal = $reason === ReturnPolicy::WITHDRAWAL;
        $errors = [];
        if (!$withdrawal && !isset($this->policy->reasons()[$reason])) {
            $errors['reason'] = t('Choose a reason for the return.');
        }

        $byId = [];
        foreach ($eligibility['lines'] as $line) {
            $byId[(int) $line['id']] = $line;
        }
        $chosen = [];
        foreach ($lines as $itemId => $qty) {
            $qty = is_numeric($qty) ? (int) $qty : 0;
            if ($qty < 1) {
                continue;
            }
            if (!isset($byId[(int) $itemId])) {
                $errors['lines'] = t('One of the products does not belong to this order.');
                continue;
            }
            if ($qty > (int) $byId[(int) $itemId]['available']) {
                $errors['lines'] = t('You cannot return more units than you received.');
                continue;
            }
            $chosen[(int) $itemId] = $qty;
        }
        if ($chosen === [] && !isset($errors['lines'])) {
            $errors['lines'] = t('Choose at least one product to return.');
        }
        if ($withdrawal) {
            if (!$eligibility['withdrawal_open']) {
                $errors['reason'] = t('The withdrawal period for this order has ended.');
            } else {
                foreach (array_keys($chosen) as $itemId) {
                    if (!empty($byId[$itemId]['withdrawal_excluded'])) {
                        $errors['lines'] = t('{name} is excluded from the right of withdrawal.', ['name' => (string) $byId[$itemId]['name']]);
                    }
                }
            }
        } elseif (!$eligibility['return_open'] && !isset($errors['reason'])) {
            $errors['reason'] = !empty($eligibility['unshipped'])
                ? t('This order has not been shipped yet. You can ask for a return once it arrives, or withdraw from the purchase now.')
                : t('The period to return this order has ended.');
        }

        $comment = trim($comment);
        if (mb_strlen($comment, 'UTF-8') > 2000) {
            $errors['comment'] = t('The comment is too long.');
        }
        $photoFiles = [];
        if ($reason === ReturnPolicy::DEFECTIVE && $this->photos->available() && isset($options['photos']) && is_array($options['photos'])) {
            $checked = $this->photos->check($options['photos']);
            if ($checked['error'] !== '') {
                $errors['photos'] = $checked['error'];
            }
            $photoFiles = $checked['files'];
        }
        if ($errors !== []) {
            return $this->failed((string) reset($errors), $errors);
        }

        try {
            $stored = $photoFiles !== [] ? $this->photos->store($photoFiles) : [];
        } catch (\RuntimeException $e) {
            $this->app->log()->exception($e, 'returns.photos');

            return $this->failed(t('The photos could not be saved. Try again.'), ['photos' => t('The photos could not be saved. Try again.')]);
        }

        // ¿Es el pedido entero? Cada línea de producto, con todas sus unidades y sin nada devuelto antes.
        $full = true;
        foreach ($order['items'] as $item) {
            if ((string) $item['kind'] !== 'product') {
                continue;
            }
            if (!isset($chosen[(int) $item['id']]) || $chosen[(int) $item['id']] !== (int) $item['qty'] || (int) $item['refunded_qty'] > 0) {
                $full = false;
            }
        }
        $actor = isset($options['actor']) && in_array($options['actor'], ['customer', 'staff', 'system'], true) ? (string) $options['actor'] : 'customer';
        $source = isset($options['source']) && is_string($options['source']) && $options['source'] !== '' ? substr($options['source'], 0, 12) : 'web';
        $customerId = isset($options['customer_id']) ? (int) $options['customer_id'] : ($order['customer_id'] !== null ? (int) $order['customer_id'] : 0);
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : null;

        try {
            $id = (int) $this->app->db()->transaction(function (Db $db) use ($order, $eligibility, $chosen, $byId, $reason, $withdrawal, $comment, $stored, $full, $customerId, $source, $actor, $userId): int {
                $now = $this->app->clock()->sql();
                $id = $db->insert('returns', [
                    'number' => $this->nextNumber(), 'order_id' => (int) $order['id'], 'customer_id' => $customerId > 0 ? $customerId : null,
                    'email' => (string) $order['email'], 'lang' => (string) $order['lang'], 'currency' => (string) $order['currency'],
                    'kind' => $withdrawal ? 'withdrawal' : 'return', 'status' => 'requested', 'reason' => $reason,
                    'comment' => $comment !== '' ? $comment : null, 'photos' => $stored !== [] ? (string) json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'full_order' => $full ? 1 : 0, 'refunded_amount' => 0, 'open_key' => (int) $order['id'], 'source' => $source,
                    'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($chosen as $itemId => $qty) {
                    $line = $byId[$itemId];
                    $db->insert('return_items', [
                        'return_id' => $id, 'order_item_id' => $itemId, 'product_id' => $line['product_id'], 'variant_id' => $line['variant_id'],
                        'sku' => $line['sku'] !== '' ? $line['sku'] : null, 'name' => mb_substr((string) $line['name'], 0, 191, 'UTF-8'),
                        'variant_label' => $line['variant_label'] !== '' ? mb_substr((string) $line['variant_label'], 0, 191, 'UTF-8') : null,
                        'qty' => $qty, 'restock' => 0, 'refunded' => 0,
                    ]);
                }
                $db->insert('return_events', [
                    'return_id' => $id, 'kind' => 'status', 'from_value' => null, 'to_value' => 'requested', 'note' => null,
                    'actor' => $actor, 'user_id' => $userId, 'notified' => 0, 'created_at' => $now,
                ]);

                return $id;
            });
        } catch (\Throwable $e) {
            $this->photos->delete($stored);
            if ($this->openFor($orderId) !== null) {
                return $this->failed(t('This order already has a return in progress.'), ['order' => t('This order already has a return in progress.')]);
            }
            throw $e;
        }

        $return = $this->find($id);
        if ($return === null) {
            throw new \LogicException('The return that was just created cannot be read');
        }
        $this->app->hooks()->fire('return.requested', $return);
        $mailId = $this->mails->toCustomer($return, $order, 'return_requested', [
            'instructions' => $this->customInstructions((string) $return['number']), 'payer' => $this->customPayer(),
        ]);
        if ($mailId > 0) {
            $this->app->db()->run("UPDATE {return_events} SET notified = 1 WHERE return_id = ? AND to_value = 'requested'", [$id]);
        }
        $this->mails->toShop($return, $order);

        // El desistimiento se acepta solo si el comerciante lo ha dejado así.
        if ($withdrawal && $this->app->settings()->bool('returns.auto_approve_withdrawal', false)) {
            $this->approve($id, '', true, null, 'system');
        }

        return ['ok' => true, 'error' => '', 'errors' => [], 'id' => $id, 'number' => (string) $return['number']];
    }

    // ------------------------------------------------------------------ cambiar de estado

    /**
     * Acepta la devolución (requested → approved). Las instrucciones de envío son las que se pasan o, vacías, las de los ajustes.
     *
     * @return array<string,mixed> la devolución ya cambiada
     *
     * @throws \InvalidArgumentException devolución que no existe o que no está en un estado que lo permita
     */
    public function approve(int $id, string $instructions = '', bool $notify = true, ?int $userId = null, string $actor = 'staff'): array
    {
        $row = $this->row($id);
        $instructions = trim($instructions);
        $text = $instructions !== '' ? mb_substr($instructions, 0, 2000, 'UTF-8') : $this->instructions($row !== null ? (string) $row['number'] : '');

        // Al correo va el texto de la tienda (el escrito ahora o el de los ajustes) y, si no hay ninguno, la plantilla pone el modelo en
        // el idioma del pedido: el modelo no se traduce aquí, con el idioma de quien pulsa el botón.
        $forMail = $instructions !== '' ? $text : $this->customInstructions($row !== null ? (string) $row['number'] : '');

        return $this->transition($id, ['requested'], 'approved', ['instructions' => $text, 'approved_at' => $this->app->clock()->sql()], $instructions, $userId, $actor, $notify, 'return_approved', [
            'instructions' => $forMail, 'payer' => $this->customPayer(),
        ]);
    }

    /**
     * Rechaza la devolución (requested o approved → rejected). El motivo es obligatorio y le llega al cliente.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException sin motivo, o devolución que no existe o en un estado que no lo permita
     */
    public function reject(int $id, string $reason, bool $notify = true, ?int $userId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required to reject a return');
        }
        $reason = mb_substr($reason, 0, 2000, 'UTF-8');

        return $this->transition($id, ['requested', 'approved'], 'rejected', ['reject_reason' => $reason], $reason, $userId, 'staff', $notify, 'return_rejected', ['reject_reason' => $reason]);
    }

    /**
     * Marca la devolución como recibida (approved → received). $restock = [id de return_items => reponer la existencia]: lo que no
     * se marca no se repone.
     *
     * @param array<int|string,mixed> $restock
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException devolución que no existe o que no está aceptada
     */
    public function receive(int $id, array $restock = [], bool $notify = true, ?int $userId = null): array
    {
        $return = $this->transition($id, ['approved'], 'received', ['received_at' => $this->app->clock()->sql()], '', $userId, 'staff', $notify, 'return_received');
        $db = $this->app->db();
        foreach ($return['items'] as $line) {
            $mark = isset($restock[(int) $line['id']]) && !in_array($restock[(int) $line['id']], [false, 0, '0', '', null], true);
            $db->update('return_items', ['restock' => $mark ? 1 : 0], ['id' => (int) $line['id'], 'return_id' => $id]);
        }
        $fresh = $this->find($id);

        return $fresh !== null ? $fresh : $return;
    }

    /**
     * Cancela la devolución (requested o approved → cancelled): la pide el cliente desde su pedido o la tienda desde el panel.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException devolución que no existe o que ya no se puede cancelar
     */
    public function cancel(int $id, string $note = '', bool $notify = false, ?int $userId = null, string $actor = 'staff'): array
    {
        return $this->transition($id, ['requested', 'approved'], 'cancelled', [], mb_substr(trim($note), 0, 2000, 'UTF-8'), $userId, $actor, $notify, 'return_cancelled');
    }

    /**
     * Devuelve el dinero (received → refunded) llamando a Orders::refund() con las líneas de la devolución. El importe no puede
     * pasar del propuesto (ver ReturnAmounts) ni de lo que queda por devolver del pedido. Si hay líneas que se reponen y otras que
     * no, son dos devoluciones del pedido (la reposición de existencias es de todo o nada en cada una).
     *
     * Si algo falla a medias (la pasarela rechaza la segunda), la devolución se queda «received» con lo ya devuelto apuntado, y al
     * repetirla solo se devuelve lo que falta. La excepción de la pasarela sale tal cual.
     *
     * @return array<string,mixed> la devolución ya cerrada
     *
     * @throws \InvalidArgumentException importe no válido o devolución que no está recibida
     * @throws \RuntimeException         la forma de pago rechaza la devolución
     */
    public function refund(int $id, int $amount, bool $viaGateway = false, bool $notify = true, ?int $userId = null): array
    {
        $return = $this->find($id);
        if ($return === null) {
            throw new \InvalidArgumentException('Unknown return');
        }
        if ((string) $return['status'] !== 'received') {
            throw new \InvalidArgumentException('The return is not in a state that allows this');
        }
        $proposal = $this->amounts->propose($return);
        if ($proposal['pending'] < 1) {
            throw new \InvalidArgumentException('There is nothing left to refund in this return');
        }
        if ($amount < 1) {
            throw new \InvalidArgumentException('The refund amount must be greater than zero');
        }
        if ($amount > $proposal['remaining']) {
            throw new \InvalidArgumentException('The refund is higher than what is left to refund in the order');
        }
        if ($amount > $proposal['total']) {
            throw new \InvalidArgumentException('The refund is higher than the proposed amount');
        }
        $calls = $this->amounts->split($return, $proposal, $amount);
        $reason = t('Return {number}', ['number' => (string) $return['number']]);
        $db = $this->app->db();
        foreach ($calls as $call) {
            $this->app->service('orders')->refund((int) $return['order_id'], (int) $call['amount'], $call['items'], $reason, $viaGateway, (bool) $call['restock'], $userId);
            foreach ($call['return_item_ids'] as $itemId) {
                $db->update('return_items', ['refunded' => 1], ['id' => (int) $itemId]);
            }
            $db->run('UPDATE {returns} SET refunded_amount = refunded_amount + ?, updated_at = ? WHERE id = ?', [(int) $call['amount'], $this->app->clock()->sql(), $id]);
            $this->event($id, 'refund', null, (string) $call['amount'], $reason, 'staff', $userId, false);
        }
        $fresh = $this->row($id);

        return $this->transition($id, ['received'], 'refunded', ['refunded_at' => $this->app->clock()->sql()], '', $userId, 'staff', $notify, 'return_refunded', [
            'amount' => $fresh !== null ? (int) $fresh['refunded_amount'] : $amount,
        ]);
    }

    /** Apunta una nota interna en el historial de la devolución. */
    public function addNote(int $id, string $note, ?int $userId = null): void
    {
        $note = trim($note);
        if ($note === '' || $this->row($id) === null) {
            throw new \InvalidArgumentException('A note cannot be empty');
        }
        $this->event($id, 'note', null, null, mb_substr($note, 0, 2000, 'UTF-8'), 'staff', $userId, false);
    }

    // ------------------------------------------------------------------ lecturas

    /**
     * La devolución completa: la fila con sus fotos descodificadas, sus líneas (items) y su historial (events).
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
        $row = $this->app->db()->find('returns', ['number' => trim($number)]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Las devoluciones de un pedido, la más reciente primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $out = [];
        foreach ($this->app->db()->select('SELECT id FROM {returns} WHERE order_id = ? ORDER BY id DESC', [$orderId]) as $row) {
            $return = $this->find((int) $row['id']);
            if ($return !== null) {
                $out[] = $return;
            }
        }

        return $out;
    }

    /**
     * La devolución abierta de un pedido, o null.
     *
     * @return array<string,mixed>|null
     */
    public function openFor(int $orderId): ?array
    {
        $id = $this->app->db()->value("SELECT id FROM {returns} WHERE order_id = ? AND status IN ('requested', 'approved', 'received') ORDER BY id DESC", [$orderId]);

        return $id !== null ? $this->find((int) $id) : null;
    }

    /**
     * El importe propuesto para devolver el dinero de una devolución (ver ReturnAmounts::propose()).
     *
     * @param array<string,mixed> $return
     *
     * @return array{lines:array<int,int>,shipping:int,subtotal:int,total:int,remaining:int,pending:int}
     */
    public function proposal(array $return): array
    {
        return $this->amounts->propose($return);
    }

    /**
     * Las instrucciones de envío que escribió la tienda en los ajustes («{number}» es el número de la devolución), o cadena vacía si
     * no ha escrito ninguna. Los correos usan esta: si está vacía, la plantilla pone el texto modelo en el idioma del pedido.
     */
    public function customInstructions(string $number): string
    {
        return str_replace('{number}', $number, trim($this->app->settings()->str('returns.shipping_instructions')));
    }

    /** El texto de quién paga el envío de vuelta que escribió la tienda, o cadena vacía si no ha escrito ninguno. */
    public function customPayer(): string
    {
        return trim($this->app->settings()->str('returns.return_shipping_payer'));
    }

    /**
     * Las instrucciones de envío de serie: las de los ajustes o, vacías, un texto modelo. «{number}» es el número de la devolución.
     */
    public function instructions(string $number): string
    {
        $text = trim($this->app->settings()->str('returns.shipping_instructions'));
        if ($text === '') {
            $text = t('Pack the products in their original packaging and send them to us with the return number {number} written on the outside.');
        }

        return str_replace('{number}', $number, $text);
    }

    /** El texto de quién paga el envío de vuelta: el de los ajustes o un texto modelo. */
    public function payerText(): string
    {
        $text = trim($this->app->settings()->str('returns.return_shipping_payer'));

        return $text !== '' ? $text : t('The cost of sending the products back is paid by the customer.');
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El cambio de estado: una sola sentencia condicionada al estado de partida (dos pulsaciones a la vez no lo hacen dos veces),
     * el historial y, si se pide, el correo al cliente.
     *
     * @param string[]            $from
     * @param array<string,mixed> $set
     * @param array<string,mixed> $mailExtra
     *
     * @return array<string,mixed>
     */
    private function transition(int $id, array $from, string $to, array $set, string $note, ?int $userId, string $actor, bool $notify, string $template, array $mailExtra = []): array
    {
        $row = $this->row($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown return');
        }
        $current = (string) $row['status'];
        if (!in_array($current, $from, true)) {
            throw new \InvalidArgumentException('The return is not in a state that allows this');
        }
        $now = $this->app->clock()->sql();
        $data = $set + ['status' => $to, 'updated_at' => $now];
        if (in_array($to, ['refunded', 'rejected', 'cancelled'], true)) {
            $data['open_key'] = null;
            $data['closed_at'] = $now;
        }
        if ($this->app->db()->update('returns', $data, ['id' => $id, 'status' => $current]) !== 1) {
            throw new \InvalidArgumentException('The return is not in a state that allows this');
        }
        $return = $this->find($id);
        $order = $this->app->service('orders')->find((int) $row['order_id']);
        $mailId = 0;
        if ($notify && $return !== null && $order !== null) {
            $mailId = $this->mails->toCustomer($return, $order, $template, $mailExtra);
        }
        $this->event($id, 'status', $current, $to, $note, $actor, $userId, $mailId > 0);
        $fresh = $this->find($id);
        if ($fresh === null) {
            throw new \LogicException('The return cannot be read');
        }
        $this->app->hooks()->fire('return.status', $fresh, $current, $to);

        return $fresh;
    }

    private function event(int $returnId, string $kind, ?string $from, ?string $to, string $note, string $actor, ?int $userId, bool $notified): void
    {
        $this->app->db()->insert('return_events', [
            'return_id' => $returnId, 'kind' => $kind, 'from_value' => $from, 'to_value' => $to,
            'note' => $note !== '' ? mb_substr($note, 0, 2000, 'UTF-8') : null, 'actor' => $actor,
            'user_id' => $userId !== null && $userId > 0 ? $userId : null, 'notified' => $notified ? 1 : 0, 'created_at' => $this->app->clock()->sql(),
        ]);
    }

    /** El número «D-2026-000001»: contador propio por año (tabla sequences). */
    private function nextNumber(): string
    {
        $year = (int) substr($this->app->clock()->sql(), 0, 4);
        $n = (new Sequence($this->app))->next('return:' . $year);

        return 'D-' . $year . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     */
    private function failed(string $error, array $errors): array
    {
        return ['ok' => false, 'error' => $error, 'errors' => $errors, 'id' => 0, 'number' => ''];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(int $id): ?array
    {
        return $id > 0 ? $this->app->db()->find('returns', ['id' => $id]) : null;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $db = $this->app->db();
        $id = (int) $row['id'];
        foreach (['id', 'order_id', 'refunded_amount'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['customer_id', 'open_key'] as $key) {
            $row[$key] = $row[$key] !== null && (int) $row[$key] > 0 ? (int) $row[$key] : null;
        }
        $row['full_order'] = !empty($row['full_order']);
        $photos = is_string($row['photos']) && $row['photos'] !== '' ? json_decode($row['photos'], true) : null;
        $row['photos'] = is_array($photos) ? array_values($photos) : [];
        $row['comment'] = (string) ($row['comment'] !== null ? $row['comment'] : '');
        $row['instructions'] = (string) ($row['instructions'] !== null ? $row['instructions'] : '');
        $row['reject_reason'] = (string) ($row['reject_reason'] !== null ? $row['reject_reason'] : '');

        $items = $db->select('SELECT * FROM {return_items} WHERE return_id = ? ORDER BY id', [$id]);
        foreach ($items as $i => $item) {
            foreach (['id', 'return_id', 'order_item_id', 'qty'] as $key) {
                $items[$i][$key] = (int) $item[$key];
            }
            foreach (['product_id', 'variant_id'] as $key) {
                $items[$i][$key] = $item[$key] !== null ? (int) $item[$key] : null;
            }
            $items[$i]['restock'] = !empty($item['restock']);
            $items[$i]['refunded'] = !empty($item['refunded']);
        }
        $row['items'] = $items;

        $events = $db->select('SELECT * FROM {return_events} WHERE return_id = ? ORDER BY id', [$id]);
        foreach ($events as $i => $event) {
            $events[$i]['id'] = (int) $event['id'];
            $events[$i]['return_id'] = (int) $event['return_id'];
            $events[$i]['user_id'] = $event['user_id'] !== null ? (int) $event['user_id'] : null;
            $events[$i]['notified'] = !empty($event['notified']);
        }
        $row['events'] = $events;

        return $row;
    }
}
