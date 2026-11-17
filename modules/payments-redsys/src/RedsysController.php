<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsRedsys;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysNotification;

/**
 * Las tres direcciones de Redsys: el aviso del banco (POST, de servidor a servidor) y las dos vueltas del navegador del
 * cliente (GET, «ok» y «ko»).
 *
 * Solo el aviso, y solo con su firma verificada, da un pedido por pagado. Las vueltas del navegador no marcan nada:
 * «ok» lleva a la página de gracias (que explica que el pago puede tardar unos segundos en confirmarse) y «ko» deja el
 * carrito libre para reintentar.
 */
final class RedsysController
{
    /** Códigos de respuesta que no son un resultado final (esperando datos, redirigido al emisor): no se toca el pedido. */
    private const IN_PROGRESS = [9998, 9999];

    /** Lo que se guarda del aviso en order_payments.raw: nada de tarjeta, solo lo necesario para entender el cobro. */
    private const RAW_KEEP = [
        'ds_order', 'ds_amount', 'ds_currency', 'ds_response', 'ds_authorisationcode', 'ds_date', 'ds_hour', 'ds_terminal',
        'ds_merchantcode', 'ds_transactiontype', 'ds_securepayment', 'ds_card_country', 'ds_card_brand', 'ds_processedpaymethod',
    ];

    /** @var App */
    private $app;

    /** @var RedsysSettings */
    private $settings;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->settings = new RedsysSettings($app);
    }

    // ------------------------------------------------------------------ el aviso del banco

    /**
     * POST /<urls.payment>/redsys/aviso. 403 con la firma mala (no cambia nada), 404 con un número que no es de ningún
     * pedido, 500 si algo falla por dentro (el banco reintenta) y «OK» cuando lo ha procesado, también si ya lo había visto.
     *
     * @param array<string,string> $params
     */
    public function notify(Request $request, array $params): Response
    {
        try {
            return $this->process($request);
        } catch (\Throwable $e) {
            // Solo el error, nunca el aviso: lleva la firma y datos de la operación.
            $this->app->log()->exception($e, 'redsys.notify');

            return self::answer('ERROR', 500);
        }
    }

    private function process(Request $request): Response
    {
        try {
            $notification = RedsysNotification::fromPost($request->post, $this->settings->config());
        } catch (RedsysException $e) {
            $this->app->log()->warning('A Redsys notification was refused', ['reason' => $e->getMessage()]);

            return self::answer('Forbidden', 403);
        }

        $db = $this->app->db();
        $row = $db->row(
            'SELECT order_id FROM {order_payments} WHERE reference = ? AND ((method = ? AND kind = ?) OR (method IN (?, ?) AND kind = ?))',
            [$notification->order, RedsysPayment::CODE_CARD, RedsysPayment::ATTEMPT, RedsysPayment::CODE_CARD, RedsysPayment::CODE_BIZUM, 'charge']
        );
        $orders = $this->app->service('orders');
        $order = $row !== null ? $orders->find((int) $row['order_id']) : null;
        if ($order === null || !in_array((string) $order['payment_method'], [RedsysPayment::CODE_CARD, RedsysPayment::CODE_BIZUM], true)) {
            return self::answer('Not found', 404);
        }
        $orderId = (int) $order['id'];
        $method = (string) $order['payment_method'];

        // Una devolución confirmada o un pago que aún no ha terminado: no son un resultado, no hay nada que apuntar.
        if ($notification->isRefund() || in_array($notification->responseCode, self::IN_PROGRESS, true)) {
            return self::answer('OK');
        }

        $raw = self::safeRaw($notification->raw);
        if ($notification->authorised()) {
            if ($notification->currency !== RedsysSettings::CURRENCY) {
                $note = t('The bank reported a payment in another currency ({currency}). Check it before shipping.', ['currency' => $notification->currency]);
                $orders->setStatus($orderId, 'on_hold', $note, null, false);
            } else {
                // El importe lo comprueba markPaid: si no es el del pedido lo deja «on_hold» con una nota y no lo da por pagado.
                $orders->markPaid($orderId, $method, $notification->order, $notification->amount, $raw, $this->settings->isTest());
            }
            // Con el cobro apuntado, el intento pendiente sobra: la fila del cobro ya sirve para reconocer el aviso si se repite.
            $charged = $db->row('SELECT id FROM {order_payments} WHERE method = ? AND reference = ? AND kind = ?', [$method, $notification->order, 'charge']);
            if ($charged !== null) {
                $db->delete('order_payments', ['method' => RedsysPayment::CODE_CARD, 'kind' => RedsysPayment::ATTEMPT, 'reference' => $notification->order]);
            }
        } else {
            $reason = t('The bank declined the payment (code {code}): {text}', ['code' => $notification->responseCode, 'text' => RedsysResponses::text($notification->responseCode)]);
            $orders->paymentFailed($orderId, $method, $reason, $raw);
            $db->update('order_payments', ['status' => 'failed'], ['method' => RedsysPayment::CODE_CARD, 'kind' => RedsysPayment::ATTEMPT, 'reference' => $notification->order]);
        }

        return self::answer('OK');
    }

    // ------------------------------------------------------------------ las vueltas del navegador

    /**
     * GET /<urls.payment>/redsys/ok/{number}/{secret}: a la página de gracias. No marca nada como pagado.
     *
     * @param array<string,string> $params number y secret
     */
    public function back(Request $request, array $params): Response
    {
        $order = $this->order($params);

        return $this->thanks($order, (string) $params['secret']);
    }

    /**
     * GET /<urls.payment>/redsys/ko/{number}/{secret}: el pago no se completó. Si el pedido sigue sin pagar, el carrito
     * queda libre otra vez y el cliente vuelve a la caja con un aviso.
     *
     * @param array<string,string> $params number y secret
     */
    public function cancelled(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $secret = (string) $params['secret'];
        if ((string) $order['payment_status'] === 'paid') {
            // El banco ya avisó de que sí se cobró: nada que reintentar.
            return $this->thanks($order, $secret);
        }
        $this->app->service('cart')->reopen((int) $order['id'], $secret);
        $this->app->session('customer')->flash('checkout_error', t('The payment was not completed. You can try again.'));

        return Response::redirect($this->app->url()->route('checkout'));
    }

    /**
     * El pedido de la dirección, o 404 si la ficha no es la suya o no se paga por Redsys.
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
        if ($order === null || !in_array((string) $order['payment_method'], [RedsysPayment::CODE_CARD, RedsysPayment::CODE_BIZUM], true)) {
            throw HttpError::notFound();
        }

        return $order;
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

    // ------------------------------------------------------------------ utilidades

    /**
     * Lo que se guarda del aviso: solo los campos de la lista, sin importar cómo vengan escritas las mayúsculas.
     *
     * @param array<string,string> $raw
     *
     * @return array<string,string>
     */
    private static function safeRaw(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (in_array(strtolower((string) $key), self::RAW_KEEP, true)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    /** Respuesta de texto para el banco, que no se guarda en ninguna caché. */
    private static function answer(string $text, int $status = 200): Response
    {
        return Response::text($text, $status)->header('Cache-Control', 'no-store, private');
    }
}
