<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsPaypal;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalException;
use Aifec\Payments\PayPal\PayPalFundingSources;

/**
 * Las tres direcciones de PayPal, las mismas para todas sus formas de pago: la vuelta del cliente (GET), su cancelación (GET)
 * y el aviso firmado de PayPal (POST, de servidor a servidor).
 *
 * La vuelta depende de cómo se cobra la forma de pago del pedido (el código del pedido y del intento lo dice):
 * - la cartera y la tarjeta se CAPTURAN en el servidor al volver;
 * - las formas locales (iDEAL, BLIK…) las cobra PayPal al aprobarlas: la vuelta solo CONSULTA la orden. Si el banco aún no ha
 *   confirmado (Trustly puede tardar días), el pedido queda en espera, sin pagar, y lo da por pagado el aviso.
 *
 * El dinero se da por cobrado en dos sitios que se cuidan de no contar dos veces: la vuelta y el aviso de captura completada.
 * Los dos llaman a Orders::markPaid con la misma referencia (el identificador de la captura), y Orders solo cuenta la primera.
 *
 * Estados del intento (fila «attempt» de order_payments, referencia = identificador de la orden de PayPal, método = el código
 * de la forma de pago): pending (nada hecho), capturing (un proceso la está resolviendo), captured (cobrada), review (PayPal la
 * revisa, el banco no ha confirmado o hay algo que mirar), failed (rechazada) y cancelled (el cliente la canceló).
 */
final class PayPalController
{
    /** @var App */
    private $app;

    /** @var PayPalSettings */
    private $settings;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->settings = new PayPalSettings($app);
    }

    // ------------------------------------------------------------------ la vuelta del cliente

    /**
     * GET /<urls.payment>/paypal/vuelta/{number}/{secret}?token=…: captura (o consulta) el pago en el servidor.
     *
     * @param array<string,string> $params number y secret
     */
    public function back(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $secret = (string) $params['secret'];
        $orderId = (int) $order['id'];
        $token = $request->get('token');
        $token = is_string($token) ? trim($token) : '';
        $attempt = $token !== '' ? $this->attempt($orderId, (string) $order['payment_method'], $token) : null;
        if ($attempt === null) {
            // El «token» tiene que ser la orden de PayPal que se apuntó para ESTE pedido.
            throw HttpError::notFound();
        }

        if ((string) $order['payment_status'] === 'paid') {
            return $this->thanks($order, $secret);
        }
        if ((string) $attempt['status'] !== 'pending') {
            return $this->settled($order, (string) $attempt['status'], $secret);
        }

        // Solo una petición se queda el intento: si el cliente abre la vuelta dos veces a la vez, la segunda no captura.
        $db = $this->app->db();
        $claimed = $db->run("UPDATE {order_payments} SET status = 'capturing' WHERE id = ? AND status = 'pending'", [(int) $attempt['id']]);
        if ($claimed !== 1) {
            return $this->thanks($order, $secret);
        }

        $source = (string) PayPalSources::source((string) $order['payment_method']);
        $known = PayPalFundingSources::get($source);
        $byServer = $known !== null && $known['capture'] === PayPalFundingSources::CAPTURE_SERVER;
        try {
            $client = $this->settings->client();
            // La tarjeta en la página o Google Pay que vuelven por aquí (3-D Secure fuera de la página): sin captura si el banco
            // no la respalda.
            $refused = $byServer ? PayPalOutcome::threeDsRefused($client, $source, $token, $this->app->clock()->now()) : '';
            if ($refused !== '') {
                return $this->fail($order, (int) $attempt['id'], $secret, t('3-D Secure did not authenticate the card ({result}). The payment was not captured.', ['result' => $refused]), []);
            }
            // Las formas locales ya las cobra PayPal al aprobarlas: capturarlas daría error. Se consulta la orden.
            $capture = $byServer ? $client->captureOrder($token, $this->app->clock()->now()) : $client->getOrder($token, $this->app->clock()->now());
        } catch (PayPalException $e) {
            $this->app->log()->warning('PayPal did not capture an order', ['order' => (string) $order['number'], 'method' => (string) $order['payment_method'], 'status' => $e->status(), 'debug_id' => $e->debugId(), 'error' => $e->getMessage()]);
            if ($e->status() === 422) {
                // PayPal ha dicho que no (instrumento rechazado, orden sin aprobar): el pago no se hará con esta orden.
                return $this->fail($order, (int) $attempt['id'], $secret, t('PayPal refused the payment: {reason}', ['reason' => $e->getMessage()]), []);
            }
            // Un fallo que no es un «no» (red, PayPal caído): el intento queda libre para volver a probar al recargar.
            $db->update('order_payments', ['status' => 'pending'], ['id' => (int) $attempt['id']]);

            return $this->app->errorResponse(503, t('PayPal is not answering. Reload this page in a moment to finish the payment.'));
        } catch (\Throwable $e) {
            $db->update('order_payments', ['status' => 'pending'], ['id' => (int) $attempt['id']]);

            throw $e;
        }

        if (!$byServer && in_array(strtoupper((string) $capture['status']), ['CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED'], true) && (string) $capture['captureStatus'] === '') {
            // El comprador aún no ha aprobado en su banco: el intento queda libre y el pedido, esperando el pago.
            $db->update('order_payments', ['status' => 'pending'], ['id' => (int) $attempt['id']]);

            return $this->thanks($order, $secret);
        }

        return $this->settle($order, (int) $attempt['id'], $secret, $capture);
    }

    /**
     * Lo que dice la captura (o la orden consultada): cobrada, pendiente o en revisión, o rechazada.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $capture la captura ya normalizada por PayPalClient
     */
    private function settle(array $order, int $attemptId, string $secret, array $capture): Response
    {
        $orderId = (int) $order['id'];
        $db = $this->app->db();
        $status = strtoupper((string) $capture['captureStatus']);
        $orderStatus = strtoupper((string) $capture['status']);
        $currency = (string) $capture['currency'];
        $raw = self::safeRaw($capture);

        if ($status === 'COMPLETED') {
            if ($currency !== (string) $order['currency']) {
                $this->hold($orderId, t('PayPal reported a payment in another currency ({currency}). Check it before shipping.', ['currency' => $currency]));
                $db->update('order_payments', ['status' => 'review'], ['id' => $attemptId]);
            } elseif ((string) $capture['reference'] !== '' && (string) $capture['reference'] !== (string) $order['number']) {
                $this->hold($orderId, t('The PayPal payment is for another order reference ({reference}). Check it before shipping.', ['reference' => (string) $capture['reference']]));
                $db->update('order_payments', ['status' => 'review'], ['id' => $attemptId]);
            } else {
                // El importe lo comprueba markPaid: si no es el del pedido lo deja «on_hold» con una nota y no lo da por pagado.
                $this->paid($order, (string) $capture['captureId'], (int) $capture['amount'], $raw);
                $db->update('order_payments', ['status' => 'captured'], ['id' => $attemptId]);
            }

            return $this->thanks($order, $secret);
        }

        if (in_array($status, ['DECLINED', 'DENIED', 'FAILED', 'VOIDED'], true) || in_array($orderStatus, ['VOIDED', 'DECLINED'], true)) {
            return $this->fail($order, $attemptId, $secret, t('PayPal declined the payment ({status}).', ['status' => $status !== '' ? $status : $orderStatus]), $raw);
        }

        // PENDING u otro estado que aún no es un resultado: PayPal lo revisa o el banco no ha confirmado. El aviso lo cerrará.
        $source = (string) PayPalSources::source((string) $order['payment_method']);
        $known = PayPalFundingSources::get($source);
        if ($known !== null && $known['capture'] === PayPalFundingSources::CAPTURE_SERVER) {
            $this->hold($orderId, t('PayPal is reviewing the payment ({status}). The order stays on hold until PayPal confirms it.', ['status' => $status !== '' ? $status : 'PENDING']));
        } else {
            // En espera y no «pendiente de pago»: la tarea que cancela los pedidos sin pagar no debe llevarse un pago que llega
            // días después (Trustly). El pedido sigue sin pagar hasta el aviso.
            $this->hold($orderId, t('{method}: the bank has not confirmed the payment yet ({status}). The order waits, unpaid, and is marked as paid when PayPal notifies the payment.', [
                'method' => PayPalSources::name($source), 'status' => $status !== '' ? $status : $orderStatus,
            ]));
        }
        $db->update('order_payments', ['status' => 'review'], ['id' => $attemptId]);

        return $this->thanks($order, $secret);
    }

    /**
     * Da el pedido por pagado con la forma de pago del pedido y, la primera vez, apunta con qué se pagó («Pagado con iDEAL por
     * PayPal»).
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $raw
     */
    private function paid(array $order, string $captureId, int $amount, array $raw): void
    {
        PayPalOutcome::paid($this->app, $this->settings->isTest(), $order, $captureId, $amount, $raw);
    }

    /**
     * Pago rechazado: el pedido pasa a fallido, el carrito queda libre y el cliente vuelve a la caja con un aviso.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $raw
     */
    private function fail(array $order, int $attemptId, string $secret, string $reason, array $raw): Response
    {
        $orderId = (int) $order['id'];
        $this->app->service('orders')->paymentFailed($orderId, (string) $order['payment_method'], $reason, $raw);
        $this->app->db()->update('order_payments', ['status' => 'failed'], ['id' => $attemptId]);

        return $this->backToCheckout($orderId, $secret);
    }

    /**
     * La vuelta se abre otra vez con el intento ya resuelto: no se captura de nuevo.
     *
     * @param array<string,mixed> $order
     */
    private function settled(array $order, string $attemptStatus, string $secret): Response
    {
        if (in_array($attemptStatus, ['failed', 'cancelled'], true)) {
            return $this->backToCheckout((int) $order['id'], $secret);
        }

        return $this->thanks($order, $secret);
    }

    // ------------------------------------------------------------------ la cancelación del cliente

    /**
     * GET /<urls.payment>/paypal/cancelado/{number}/{secret}: el cliente no aprobó el pago (o el banco dio un error). Si el
     * pedido sigue sin pagar, el carrito queda libre otra vez y el cliente vuelve a la caja.
     *
     * @param array<string,string> $params number y secret
     */
    public function cancelled(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $secret = (string) $params['secret'];
        if ((string) $order['payment_status'] === 'paid') {
            return $this->thanks($order, $secret);
        }
        // Una orden cancelada no se captura aunque el cliente vuelva a abrir su dirección de vuelta.
        $this->app->db()->update('order_payments', ['status' => 'cancelled'], ['order_id' => (int) $order['id'], 'method' => (string) $order['payment_method'], 'kind' => PayPalPayment::ATTEMPT, 'status' => 'pending']);

        return $this->backToCheckout((int) $order['id'], $secret);
    }

    // ------------------------------------------------------------------ el aviso de PayPal

    /**
     * POST /<urls.payment>/paypal/aviso. 400 si PayPal no confirma la firma (no cambia nada), 500 si algo falla por dentro
     * (PayPal reintenta) y «OK» cuando lo ha procesado, también si ya lo había visto o es un evento que no usamos.
     *
     * @param array<string,string> $params
     */
    public function notify(Request $request, array $params): Response
    {
        try {
            return $this->process($request);
        } catch (\Throwable $e) {
            // Solo el error, nunca el aviso: lleva datos del comprador.
            $this->app->log()->exception($e, 'paypal.notify');

            return self::answer('ERROR', 500);
        }
    }

    private function process(Request $request): Response
    {
        $environment = $this->settings->environment();
        if (!$this->settings->complete() || $this->settings->webhookId($environment) === '') {
            $this->app->log()->warning('A PayPal notification arrived but the account is not set up to verify it');

            return self::answer('Bad request', 400);
        }
        // El cuerpo se verifica tal como llegó. Si PayPal falla al verificar, lanza y se contesta 500: PayPal reintenta.
        $verified = $this->settings->client()->verifyWebhook($request->headers, $request->body, $this->app->clock()->now());
        if (!$verified) {
            $this->app->log()->warning('A PayPal notification was refused');

            return self::answer('Bad request', 400);
        }
        $event = json_decode($request->body, true);
        if (!is_array($event)) {
            return self::answer('Bad request', 400);
        }
        $type = isset($event['event_type']) && is_string($event['event_type']) ? $event['event_type'] : '';
        $resource = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : [];

        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            $this->onCompleted($resource);
        } elseif ($type === 'PAYMENT.CAPTURE.DENIED') {
            $this->onDenied($resource);
        } elseif ($type === 'PAYMENT.CAPTURE.REFUNDED') {
            $this->onRefunded($resource);
        }

        return self::answer('OK');
    }

    /**
     * Captura completada: da el pedido por pagado, por si el cliente no volvió o el banco confirmó tarde. Idempotente con la
     * vuelta y con el mismo aviso repetido.
     *
     * @param array<string,mixed> $resource
     */
    private function onCompleted(array $resource): void
    {
        $found = $this->orderOfCapture($resource);
        if ($found === null) {
            return;
        }
        list($order, $attemptId) = $found;
        $orderId = (int) $order['id'];
        $currency = self::currencyOf($resource);
        $amount = self::amountOf($resource, $currency);
        if ($amount === null) {
            $this->app->log()->warning('A PayPal capture has no readable amount', ['order' => (string) $order['number']]);

            return;
        }
        $captureId = self::str($resource, 'id');
        if ($currency !== (string) $order['currency']) {
            if ((string) $order['payment_status'] !== 'paid') {
                $this->hold($orderId, t('PayPal reported a payment in another currency ({currency}). Check it before shipping.', ['currency' => $currency]));
            }

            return;
        }
        $raw = [
            'paypal_order' => self::paypalOrderOf($resource), 'capture' => $captureId, 'status' => self::str($resource, 'status'), 'amount' => $amount,
            'currency' => $currency, 'source' => (string) PayPalSources::source((string) $order['payment_method']),
        ];
        $this->paid($order, $captureId, $amount, $raw);
        if ($attemptId > 0) {
            // Un intento «review» o «capturing» pasa a cobrado; uno ya cobrado se queda como está.
            $this->app->db()->run("UPDATE {order_payments} SET status = 'captured' WHERE id = ? AND status IN ('pending', 'capturing', 'review')", [$attemptId]);
        }
    }

    /**
     * Captura rechazada: el pedido pasa a fallido si aún estaba esperando el pago.
     *
     * @param array<string,mixed> $resource
     */
    private function onDenied(array $resource): void
    {
        $found = $this->orderOfCapture($resource);
        if ($found === null) {
            return;
        }
        list($order, $attemptId) = $found;
        $raw = ['paypal_order' => self::paypalOrderOf($resource), 'capture' => self::str($resource, 'id'), 'status' => self::str($resource, 'status')];
        $this->app->service('orders')->paymentFailed((int) $order['id'], (string) $order['payment_method'], t('PayPal denied the payment ({id}).', ['id' => self::str($resource, 'id')]), $raw);
        if ($attemptId > 0) {
            $this->app->db()->run("UPDATE {order_payments} SET status = 'failed' WHERE id = ? AND status IN ('pending', 'capturing', 'review')", [$attemptId]);
        }
    }

    /**
     * Devolución hecha desde PayPal: se apunta como nota en el historial. NO se devuelve dinero otra vez (ya lo devolvió
     * PayPal). Una devolución hecha desde la tienda ya está apuntada con su identificador y no genera nota.
     *
     * @param array<string,mixed> $resource la devolución
     */
    private function onRefunded(array $resource): void
    {
        $refundId = self::str($resource, 'id');
        if ($refundId === '') {
            return;
        }
        $orderId = 0;
        foreach (self::links($resource, 'up') as $href) {
            $captureId = basename((string) parse_url($href, PHP_URL_PATH));
            $row = $captureId !== '' ? $this->paymentRow('order_id', 'charge', $captureId) : null;
            if ($row !== null) {
                $orderId = (int) $row['order_id'];
                break;
            }
        }
        $orders = $this->app->service('orders');
        $order = $orderId > 0 ? $orders->find($orderId) : null;
        if ($order === null) {
            $order = $this->orderByNumbers($resource);
        }
        if ($order === null || PayPalSources::source((string) $order['payment_method']) === null) {
            return;
        }
        $orderId = (int) $order['id'];

        // Ya apuntada: por la tienda (fila de devolución con ese identificador) o por un aviso anterior (la nota lo lleva).
        if ($this->paymentRow('id', 'refund', $refundId) !== null) {
            return;
        }
        foreach (isset($order['history']) && is_array($order['history']) ? $order['history'] : [] as $entry) {
            if (is_array($entry) && isset($entry['note']) && is_string($entry['note']) && strpos($entry['note'], $refundId) !== false) {
                return;
            }
        }
        $currency = self::currencyOf($resource);
        $amount = self::amountOf($resource, $currency !== '' ? $currency : (string) $order['currency']);
        $orders->addNote($orderId, t('A refund of {amount} was made from PayPal (refund {id}). The order was not changed: record the refund here if it applies.', [
            'amount' => $amount !== null ? money($amount, $currency !== '' ? $currency : (string) $order['currency']) : '?',
            'id' => $refundId,
        ]), false);
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * Una fila de order_payments de cualquier forma de pago de PayPal, por tipo y referencia.
     *
     * @return array<string,mixed>|null
     */
    private function paymentRow(string $columns, string $kind, string $reference): ?array
    {
        $codes = PayPalSources::codes();
        $marks = implode(', ', array_fill(0, count($codes), '?'));

        return $this->app->db()->row(
            'SELECT ' . ($columns === 'order_id' ? 'id, order_id' : 'id') . ' FROM {order_payments} WHERE kind = ? AND reference = ? AND method IN (' . $marks . ')',
            array_merge([$kind, $reference], $codes)
        );
    }

    /**
     * El pedido al que pertenece una captura de un aviso y el intento (0 si no se conoce): primero por la orden de PayPal
     * apuntada y, si no, por la referencia propia (el número de pedido).
     *
     * @param array<string,mixed> $resource
     *
     * @return array{0:array<string,mixed>,1:int}|null
     */
    private function orderOfCapture(array $resource): ?array
    {
        $orders = $this->app->service('orders');
        $order = null;
        $attemptId = 0;
        $paypalOrder = self::paypalOrderOf($resource);
        if ($paypalOrder !== '') {
            $row = $this->paymentRow('order_id', PayPalPayment::ATTEMPT, $paypalOrder);
            if ($row !== null) {
                $order = $orders->find((int) $row['order_id']);
                $attemptId = (int) $row['id'];
            }
        }
        if ($order === null) {
            $order = $this->orderByNumbers($resource);
        }
        if ($order === null || PayPalSources::source((string) $order['payment_method']) === null) {
            $this->app->log()->info('A PayPal notification is not for any order of this shop', ['paypal_order' => $paypalOrder]);

            return null;
        }

        return [$order, $attemptId];
    }

    /**
     * El pedido que lleva en «custom_id» o «invoice_id» (nuestra referencia: el número de pedido).
     *
     * @param array<string,mixed> $resource
     *
     * @return array<string,mixed>|null
     */
    private function orderByNumbers(array $resource): ?array
    {
        foreach (['custom_id', 'invoice_id'] as $key) {
            $number = self::str($resource, $key);
            if ($number !== '') {
                $order = $this->app->service('orders')->findByNumber($number);
                if ($order !== null) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * El pedido de la dirección, o 404 si la ficha no es la suya o no se paga por PayPal.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function order(array $params): array
    {
        $number = isset($params['number']) ? (string) $params['number'] : '';
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        $order = $number !== '' && $secret !== '' ? $this->app->service('orders')->findBySecret($number, $secret) : null;
        if ($order === null || PayPalSources::source((string) $order['payment_method']) === null) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * La fila del intento de este pedido con esa orden de PayPal (y la forma de pago del pedido).
     *
     * @return array<string,mixed>|null
     */
    private function attempt(int $orderId, string $code, string $paypalOrder): ?array
    {
        return $this->app->db()->row(
            'SELECT id, status FROM {order_payments} WHERE order_id = ? AND method = ? AND kind = ? AND reference = ?',
            [$orderId, $code, PayPalPayment::ATTEMPT, $paypalOrder]
        );
    }

    /**
     * Deja el pedido en espera con una nota solo para quien lleva la tienda. Si el pedido ya estaba en espera, o ya no se puede
     * poner (pagado, cancelado), no cambia nada y tampoco repite la nota.
     */
    private function hold(int $orderId, string $note): void
    {
        PayPalOutcome::hold($this->app, $orderId, $note);
    }

    private function backToCheckout(int $orderId, string $secret): Response
    {
        $this->app->service('cart')->reopen($orderId, $secret);
        $this->app->session('customer')->flash('checkout_error', t('The payment was not completed. You can try again.'));

        return Response::redirect($this->app->url()->route('checkout'));
    }

    /**
     * @param array<string,mixed> $order
     */
    private function thanks(array $order, string $secret): Response
    {
        if ($this->app->router()->has('order.thanks')) {
            return Response::redirect($this->app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => $secret]));
        }

        return Response::redirect($this->app->url()->to('/'));
    }

    /**
     * Lo que se guarda del cobro en order_payments.raw: solo lo necesario para entenderlo, nada del comprador.
     *
     * @param array<string,mixed> $capture
     *
     * @return array<string,mixed>
     */
    private static function safeRaw(array $capture): array
    {
        return [
            'paypal_order' => (string) $capture['id'], 'capture' => (string) $capture['captureId'], 'status' => (string) $capture['captureStatus'],
            'amount' => (int) $capture['amount'], 'currency' => (string) $capture['currency'],
            'source' => isset($capture['source']) ? (string) $capture['source'] : '',
        ];
    }

    /**
     * @param array<string,mixed> $a
     */
    private static function str(array $a, string $key): string
    {
        return isset($a[$key]) && (is_string($a[$key]) || is_int($a[$key])) ? (string) $a[$key] : '';
    }

    /**
     * La orden de PayPal a la que pertenece una captura de un aviso.
     *
     * @param array<string,mixed> $resource
     */
    private static function paypalOrderOf(array $resource): string
    {
        $supplementary = isset($resource['supplementary_data']) && is_array($resource['supplementary_data']) ? $resource['supplementary_data'] : [];
        $related = isset($supplementary['related_ids']) && is_array($supplementary['related_ids']) ? $supplementary['related_ids'] : [];

        return self::str($related, 'order_id');
    }

    /**
     * @param array<string,mixed> $resource
     */
    private static function currencyOf(array $resource): string
    {
        $amount = isset($resource['amount']) && is_array($resource['amount']) ? $resource['amount'] : [];

        return strtoupper(self::str($amount, 'currency_code'));
    }

    /**
     * El importe de una captura o devolución en céntimos, o null si no se puede leer.
     *
     * @param array<string,mixed> $resource
     */
    private static function amountOf(array $resource, string $currency): ?int
    {
        $amount = isset($resource['amount']) && is_array($resource['amount']) ? $resource['amount'] : [];
        if (!isset($amount['value']) || $currency === '') {
            return null;
        }
        try {
            return PayPalClient::parseAmount($amount['value'], $currency);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Las direcciones de los enlaces de un recurso con esa relación.
     *
     * @param array<string,mixed> $resource
     *
     * @return string[]
     */
    private static function links(array $resource, string $rel): array
    {
        $out = [];
        foreach (isset($resource['links']) && is_array($resource['links']) ? $resource['links'] : [] as $link) {
            if (is_array($link) && isset($link['rel'], $link['href']) && $link['rel'] === $rel && is_string($link['href'])) {
                $out[] = $link['href'];
            }
        }

        return $out;
    }

    /** Respuesta de texto para PayPal, que no se guarda en ninguna caché. */
    private static function answer(string $text, int $status = 200): Response
    {
        return Response::text($text, $status)->header('Cache-Control', 'no-store, private');
    }
}
