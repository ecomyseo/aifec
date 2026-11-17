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
use Aifec\Kernel\Csp;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalException;
use Aifec\Payments\PayPal\PayPalSdk;
use Aifec\Security\Csrf;

/**
 * La página de pago de las formas que necesitan el guion de PayPal (Paga más tarde, tarjeta en la página, Google Pay, Apple
 * Pay, Venmo) y sus dos llamadas. La caja crea el pedido «pendiente de pago» y, al hacer el pedido, trae aquí al cliente.
 *
 *   GET  /<urls.payment>/paypal/pagar/{number}/{secret}      la página: el guion de PayPal, el botón o los campos
 *   POST /<urls.payment>/paypal/orden/{number}/{secret}      crea la orden en PayPal con el importe DEL PEDIDO (JSON)
 *   POST /<urls.payment>/paypal/capturar/{number}/{secret}   la captura en el servidor y marca el pedido (JSON)
 *   GET  /.well-known/apple-developer-merchantid-domain-association   el fichero que acredita el dominio para Apple Pay
 *
 * El navegador solo aprueba. Las dos llamadas exigen la ficha del pedido en la dirección, que esta sesión haya abierto su
 * página de pago (si no, 404) y la ficha del formulario que lleva la página (si no, 403). El importe y la moneda salen siempre
 * del pedido guardado; lo que mande el navegador se ignora.
 *
 * La política de contenido: la página deja en la petición los orígenes que necesita su forma de pago y un nonce, y el filtro
 * «csp» del módulo (policy()) los añade solo a esa respuesta. El resto de la tienda sigue con la política de siempre.
 */
final class PayPalSdkController
{
    /** Atributo de la petición con lo que la página de pago necesita en la política de contenido. */
    public const CSP_ATTR = 'payments-paypal.csp';

    /** Los pedidos cuya página de pago ha abierto esta sesión. */
    private const SESSION_KEY = 'paypal.pay';

    /** Órdenes de PayPal que se pueden crear para un mismo pedido (reintentos tras una tarjeta rechazada). */
    public const MAX_ATTEMPTS = 10;

    /** @var App */
    private $app;

    /** @var PayPalSettings */
    private $settings;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->settings = new PayPalSettings($app);
    }

    /**
     * El filtro «csp»: los orígenes de PayPal (y de Google para Google Pay) y el nonce, solo en la respuesta de la página de
     * pago que los ha pedido.
     */
    public static function policy(Csp $csp, Request $request): void
    {
        $data = $request->attr(self::CSP_ATTR);
        if (!is_array($data)) {
            return;
        }
        foreach (isset($data['origins']) && is_array($data['origins']) ? $data['origins'] : [] as $origin) {
            foreach (['script-src', 'connect-src', 'frame-src', 'img-src', 'style-src'] as $directive) {
                $csp->add($directive, (string) $origin);
            }
        }
        if (isset($data['nonce']) && is_string($data['nonce']) && preg_match('/^[a-f0-9]{32}$/', $data['nonce']) === 1) {
            // Los guiones que el de PayPal escribe en la página llevan este nonce (data-csp-nonce): nada de 'unsafe-inline'.
            $csp->add('script-src', "'nonce-" . $data['nonce'] . "'");
        }
    }

    // ------------------------------------------------------------------ la página de pago

    /**
     * @param array<string,string> $params number y secret
     */
    public function page(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $secret = (string) $params['secret'];
        if ((string) $order['payment_status'] === 'paid' || !$this->payable($order)) {
            return Response::redirect($this->thanksUrl($order, $secret));
        }
        $code = (string) $order['payment_method'];
        $source = (string) PayPalSources::source($code);
        $number = (string) $order['number'];
        $session = $this->app->session('customer');
        $opened = $session->get(self::SESSION_KEY, []);
        $opened = is_array($opened) ? array_values(array_filter($opened, 'is_string')) : [];
        if (!in_array($number, $opened, true)) {
            $opened[] = $number;
        }
        $session->set(self::SESSION_KEY, array_slice($opened, -5));

        $currency = strtoupper((string) $order['currency']);
        $total = (int) $order['total'];
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $country = isset($billing['country']) && is_string($billing['country']) ? strtoupper($billing['country']) : '';
        $message = $source === 'paylater' && $this->settings->payLaterMessage();
        $nonce = bin2hex(random_bytes(16));
        $request->setAttr(self::CSP_ATTR, ['nonce' => $nonce, 'origins' => PayPalSdk::origins($source)]);

        $url = $this->app->url();
        $routeParams = ['number' => $number, 'secret' => $secret];
        $config = [
            'source' => $source,
            'sdk' => $this->settings->complete() ? PayPalSdk::url($source, $this->settings->clientId($this->settings->environment()), $currency, $this->settings->isTest(), $country, $message) : '',
            'scripts' => PayPalSdk::scripts($source),
            'nonce' => $nonce,
            'create' => $url->route('payment.paypal.create', $routeParams),
            'capture' => $url->route('payment.paypal.capture', $routeParams),
            'token' => Csrf::issue($session->csrfSecret(), 'front', $this->app->clock()->now()),
            'amount' => PayPalClient::formatAmount($total, $currency),
            'currency' => $currency,
            'country' => $country,
            'test' => $this->settings->isTest(),
            'label' => $this->app->settings()->str('store.name', ''),
            'message' => $message,
        ];
        $page = ['title' => t('Pay order {number}', ['number' => $number]), 'type' => 'checkout'];
        $response = $this->app->render('payments-paypal::front/pay', [
            'page' => $page, 'number' => $number, 'source' => $source, 'title' => PayPalSources::title($source),
            'total' => money($total, $currency), 'message' => $message, 'cancel' => $url->route('payment.paypal.cancelled', $routeParams),
            'config' => (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    // ------------------------------------------------------------------ crear la orden

    /**
     * Crea la orden de PayPal del pedido con SU importe y SU moneda. Contesta {"id": "<orden de PayPal>"}.
     *
     * @param array<string,string> $params number y secret
     */
    public function create(Request $request, array $params): Response
    {
        $order = $this->guarded($request, $params);
        $secret = (string) $params['secret'];
        if ((string) $order['payment_status'] === 'paid') {
            return self::json(['ok' => true, 'redirect' => $this->thanksUrl($order, $secret)]);
        }
        if (!$this->payable($order)) {
            return self::json(['ok' => false, 'error' => 'not_payable'], 409);
        }
        $code = (string) $order['payment_method'];
        $method = $this->app->service('payments')->get($code);
        if (!$method instanceof PayPalPayment || !$this->settings->complete() || !$method->fits($order)) {
            return self::json(['ok' => false, 'error' => 'not_available'], 409);
        }
        $db = $this->app->db();
        $attempts = (int) $db->value('SELECT COUNT(*) FROM {order_payments} WHERE order_id = ? AND method = ? AND kind = ?', [(int) $order['id'], $code, PayPalPayment::ATTEMPT]);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return self::json(['ok' => false, 'error' => 'too_many'], 429);
        }

        // Lo que mande el navegador (un importe, una moneda) no se lee: todo sale del pedido guardado.
        $input = $method->orderInput($order, $secret) + ['attempt' => $attempts + 1];
        try {
            $created = $this->settings->client()->createOrder($input, $this->app->clock()->now());
        } catch (PayPalException $e) {
            $this->app->log()->warning('PayPal refused to create an order', ['order' => (string) $order['number'], 'method' => $code, 'status' => $e->status(), 'debug_id' => $e->debugId(), 'error' => $e->getMessage()]);
            if (!$e->isTransient() && $e->status() > 0) {
                $this->app->service('orders')->addNote((int) $order['id'], PayPalSources::explain($method->source(), $e->issue()), false);
            }

            return self::json(['ok' => false, 'error' => 'paypal'], $e->isTransient() || $e->status() === 0 ? 503 : 502);
        } catch (\InvalidArgumentException $e) {
            $this->app->log()->warning('An order cannot be sent to PayPal', ['order' => (string) $order['number'], 'method' => $code, 'error' => $e->getMessage()]);

            return self::json(['ok' => false, 'error' => 'invalid'], 409);
        }
        $db->insertIgnore('order_payments', [
            'order_id' => (int) $order['id'], 'method' => $code, 'kind' => PayPalPayment::ATTEMPT, 'reference' => $created['id'],
            'amount' => (int) $order['total'], 'currency' => (string) $order['currency'], 'status' => 'pending',
            'test' => $this->settings->isTest() ? 1 : 0, 'raw' => null, 'created_at' => $this->app->clock()->sql(),
        ]);

        return self::json(['ok' => true, 'id' => $created['id']]);
    }

    // ------------------------------------------------------------------ capturar

    /**
     * Captura en el servidor la orden que el navegador ha aprobado y marca el pedido. Contesta {"ok": true, "redirect": …} o
     * {"ok": false, "error": …}: una tarjeta rechazada deja el pedido sin pagar (se puede reintentar) y lo apunta.
     *
     * @param array<string,string> $params number y secret
     */
    public function capture(Request $request, array $params): Response
    {
        $order = $this->guarded($request, $params);
        $secret = (string) $params['secret'];
        $code = (string) $order['payment_method'];
        $paypalOrder = trim($request->str('paypal_order'));
        if ((string) $order['payment_status'] === 'paid') {
            return self::json(['ok' => true, 'redirect' => $this->thanksUrl($order, $secret)]);
        }
        $db = $this->app->db();
        $attempt = $paypalOrder === '' ? null : $db->row(
            'SELECT id, status FROM {order_payments} WHERE order_id = ? AND method = ? AND kind = ? AND reference = ?',
            [(int) $order['id'], $code, PayPalPayment::ATTEMPT, $paypalOrder]
        );
        if ($attempt === null) {
            // Una orden de PayPal que no se creó para ESTE pedido no se captura aquí.
            throw HttpError::notFound();
        }
        if (in_array((string) $attempt['status'], ['captured', 'review'], true)) {
            return self::json(['ok' => true, 'redirect' => $this->thanksUrl($order, $secret)]);
        }
        $claimed = $db->run("UPDATE {order_payments} SET status = 'capturing' WHERE id = ? AND status = 'pending'", [(int) $attempt['id']]);
        if ($claimed !== 1) {
            return self::json(['ok' => false, 'error' => 'busy'], 409);
        }

        $source = (string) PayPalSources::source($code);
        $now = $this->app->clock()->now();
        try {
            $client = $this->settings->client();
            $refused = PayPalOutcome::threeDsRefused($client, $source, $paypalOrder, $now);
            if ($refused !== '') {
                return $this->declined($order, (int) $attempt['id'], t('3-D Secure did not authenticate the card ({result}). The payment was not captured.', ['result' => $refused]));
            }
            $capture = $client->captureOrder($paypalOrder, $now);
        } catch (PayPalException $e) {
            $this->app->log()->warning('PayPal did not capture an order', ['order' => (string) $order['number'], 'method' => $code, 'status' => $e->status(), 'debug_id' => $e->debugId(), 'error' => $e->getMessage()]);
            if ($e->status() === 422) {
                return $this->declined($order, (int) $attempt['id'], t('PayPal refused the payment: {reason}', ['reason' => $e->getMessage()]));
            }
            $db->update('order_payments', ['status' => 'pending'], ['id' => (int) $attempt['id']]);

            return self::json(['ok' => false, 'error' => 'unavailable'], 503);
        } catch (\Throwable $e) {
            $db->update('order_payments', ['status' => 'pending'], ['id' => (int) $attempt['id']]);

            throw $e;
        }

        $status = strtoupper((string) $capture['captureStatus']);
        $raw = [
            'paypal_order' => (string) $capture['id'], 'capture' => (string) $capture['captureId'], 'status' => $status,
            'amount' => (int) $capture['amount'], 'currency' => (string) $capture['currency'], 'source' => $source,
        ];
        if ($status === 'COMPLETED') {
            if ((string) $capture['currency'] !== (string) $order['currency']) {
                PayPalOutcome::hold($this->app, (int) $order['id'], t('PayPal reported a payment in another currency ({currency}). Check it before shipping.', ['currency' => (string) $capture['currency']]));
                $db->update('order_payments', ['status' => 'review'], ['id' => (int) $attempt['id']]);
            } elseif ((string) $capture['reference'] !== '' && (string) $capture['reference'] !== (string) $order['number']) {
                PayPalOutcome::hold($this->app, (int) $order['id'], t('The PayPal payment is for another order reference ({reference}). Check it before shipping.', ['reference' => (string) $capture['reference']]));
                $db->update('order_payments', ['status' => 'review'], ['id' => (int) $attempt['id']]);
            } else {
                // El importe lo comprueba markPaid: si no es el del pedido lo deja «on_hold» con una nota y no lo da por pagado.
                PayPalOutcome::paid($this->app, $this->settings->isTest(), $order, (string) $capture['captureId'], (int) $capture['amount'], $raw);
                $db->update('order_payments', ['status' => 'captured'], ['id' => (int) $attempt['id']]);
            }

            return self::json(['ok' => true, 'redirect' => $this->thanksUrl($order, $secret)]);
        }
        if (in_array($status, ['DECLINED', 'DENIED', 'FAILED', 'VOIDED'], true)) {
            return $this->declined($order, (int) $attempt['id'], t('PayPal declined the payment ({status}).', ['status' => $status]));
        }
        // PENDING: PayPal lo revisa. El aviso de captura completada lo cerrará.
        PayPalOutcome::hold($this->app, (int) $order['id'], t('PayPal is reviewing the payment ({status}). The order stays on hold until PayPal confirms it.', ['status' => $status !== '' ? $status : 'PENDING']));
        $db->update('order_payments', ['status' => 'review'], ['id' => (int) $attempt['id']]);

        return self::json(['ok' => true, 'redirect' => $this->thanksUrl($order, $secret)]);
    }

    // ------------------------------------------------------------------ Apple Pay

    /** El fichero que acredita el dominio para Apple Pay, tal como lo pegó el comerciante; 404 si no hay. */
    public function applePayDomain(Request $request, array $params): Response
    {
        $file = $this->settings->applePayFile();
        if ($file === '') {
            throw HttpError::notFound();
        }

        return Response::text($file, 200, 'application/octet-stream')->header('Cache-Control', 'public, max-age=3600');
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * Una tarjeta (o un monedero) rechazada: el intento queda fallido, el pedido SIGUE sin pagar (el cliente puede probar otra
     * vez o elegir otra forma de pago) y el historial lo dice.
     *
     * @param array<string,mixed> $order
     */
    private function declined(array $order, int $attemptId, string $note): Response
    {
        $this->app->db()->update('order_payments', ['status' => 'failed'], ['id' => $attemptId]);
        $this->app->service('orders')->addNote((int) $order['id'], $note, false);

        return self::json(['ok' => false, 'error' => 'declined'], 422);
    }

    /**
     * El pedido de la dirección, comprobado para una llamada de la página de pago: que esta sesión haya abierto su página
     * (si no, 404) y que traiga la ficha del formulario de esa página (si no, 403).
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function guarded(Request $request, array $params): array
    {
        $order = $this->order($params);
        $session = $this->app->session('customer');
        $opened = $session->get(self::SESSION_KEY, []);
        if (!is_array($opened) || !in_array((string) $order['number'], $opened, true)) {
            throw HttpError::notFound();
        }
        $token = $request->input('_token');
        if (!is_string($token) || $token === '' || !Csrf::check($session->csrfSecret(), 'front', $token, $this->app->clock()->now(), 86400)) {
            throw HttpError::forbidden('The form expired. Reload the page and try again.');
        }

        return $order;
    }

    /**
     * El pedido de la dirección, o 404 si la ficha no es la suya o no se paga con una forma del guion de PayPal.
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
        $source = $order !== null ? PayPalSources::source((string) $order['payment_method']) : null;
        if ($order === null || $source === null || !PayPalSources::usesSdk($source)) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * ¿Se puede pagar todavía? Sin pagar y esperando el pago. Los cancelados y los fallidos no; tampoco uno en espera, que
     * tiene un cobro en revisión: pagarlo otra vez cobraría dos veces.
     *
     * @param array<string,mixed> $order
     */
    private function payable(array $order): bool
    {
        return (string) $order['payment_status'] === 'pending' && (string) $order['status'] === 'pending_payment';
    }

    /**
     * @param array<string,mixed> $order
     */
    private function thanksUrl(array $order, string $secret): string
    {
        if ($this->app->router()->has('order.thanks')) {
            return $this->app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => $secret]);
        }

        return $this->app->url()->to('/');
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
