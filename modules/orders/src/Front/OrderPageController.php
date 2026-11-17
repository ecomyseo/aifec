<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\OrderLog;
use Aifec\Modules\Orders\Orders;

/**
 * La página del pedido (GET /pedido/{número}/{ficha}, nombre «order.thanks») y el botón de descarga de lo comprado.
 *
 * La ficha de la dirección es la que da acceso: sin cuenta ni sesión. Una ficha que no es la del pedido da un 404 igual
 * que un pedido que no existe. La página nunca se guarda en ninguna caché y no se indexa.
 */
final class OrderPageController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params number y secret
     */
    public function show(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $app = $this->app;
        $instructions = OrderView::instructions($order);
        $status = OrderView::status($order, $instructions);
        $paid = in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true);

        // Mientras la pasarela no confirma, la página se refresca sola unas cuantas veces; el contador va en la dirección.
        $count = max(0, $request->int('r'));
        $refresh = '';
        $exhausted = false;
        if ($status['pending']) {
            if ($count < OrderView::MAX_REFRESHES) {
                $refresh = route('order.thanks', ['number' => (string) $order['number'], 'secret' => (string) $params['secret'], 'r' => $count + 1]);
            } else {
                $exhausted = true;
            }
        }

        $customer = $app->has('customers') ? $app->service('customers')->current() : null;
        $owner = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : null;
        $account = ['url' => '', 'label' => ''];
        if ($customer !== null && $order['customer_id'] !== null && (int) $customer['id'] === (int) $order['customer_id']) {
            $account = ['url' => route('account.order', ['number' => (string) $order['number']]), 'label' => t('View in my account')];
        } elseif ($owner !== null && empty($owner['guest'])) {
            $account = ['url' => route('account.login', ['next' => route('account.order', ['number' => (string) $order['number']])]), 'label' => t('Sign in to follow your orders')];
        } elseif ($app->router()->has('account.register')) {
            $account = ['url' => route('account.register'), 'label' => t('Create an account to follow your orders')];
        }

        $downloads = $paid ? $this->downloads($order, (string) $params['secret']) : [];
        $tracking = ['code' => (string) ($order['tracking_code'] !== null ? $order['tracking_code'] : ''), 'url' => OrderView::trackingUrl($app, $order)];
        $first = $this->firstPaidView($order);

        $view = [
            'status' => $status,
            'instructions' => $instructions,
            'tracking' => $tracking,
            'totals' => TotalsView::forOrder($app, $order),
            'lines' => OrderView::lines($app, $order),
            'billing' => OrderView::addressLines($app, is_array($order['billing']) ? $order['billing'] : [], true),
            'shipping' => OrderView::addressLines($app, is_array($order['shipping']) ? $order['shipping'] : [], false),
            'downloads' => $downloads,
            'account' => $account,
            'pay_url' => self::payable($app, $order) ? route('order.pay', ['number' => (string) $order['number'], 'secret' => (string) $params['secret']]) : '',
            'refresh' => $refresh,
            'refresh_seconds' => OrderView::REFRESH_SECONDS,
            'exhausted' => $exhausted,
            'first' => $first,
            'note' => trim((string) ($order['customer_note'] !== null ? $order['customer_note'] : '')),
            'placed' => $app->i18n()->date(Clock::parse((string) $order['placed_at']), 'medium'),
        ];
        $page = ['title' => t('Order {number}', ['number' => (string) $order['number']]), 'type' => 'order'];
        $response = $app->render('orders::front/order', ['page' => $page, 'order' => $order, 'v' => $view]);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * POST …/download/{id}: da un enlace nuevo de una descarga del pedido y lleva a él. El enlace del correo deja de valer
     * (los enlaces no se guardan en claro, solo su resumen), por eso solo se emite cuando el cliente lo pide.
     *
     * @param array<string,string> $params number, secret e id
     */
    public function download(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $orders = $this->orders();
        $allowed = false;
        foreach ($orders->downloads()->forOrder((int) $order['id']) as $download) {
            if ((int) $download['id'] === $id && !empty($download['available'])) {
                $allowed = true;
            }
        }
        $url = $allowed ? $orders->downloads()->renew($id, (int) $order['id']) : null;
        if ($url === null) {
            return Response::redirect(route('order.thanks', ['number' => (string) $order['number'], 'secret' => (string) $params['secret']]), 303);
        }

        return Response::redirect($url, 303);
    }

    /**
     * El pedido de la dirección o un 404.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function order(array $params): array
    {
        $number = isset($params['number']) ? (string) $params['number'] : '';
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        $order = $number !== '' && $secret !== '' ? $this->orders()->findBySecret($number, $secret) : null;
        if ($order === null) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * Las descargas pagadas del pedido: nombre, lo que queda y el formulario que da el enlace.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    private function downloads(array $order, string $secret): array
    {
        $out = [];
        foreach ($this->orders()->downloads()->forOrder((int) $order['id']) as $download) {
            $out[] = $download + ['action' => route('order.thanks.download', ['number' => (string) $order['number'], 'secret' => $secret, 'id' => (int) $download['id']])];
        }

        return $out;
    }

    /**
     * ¿Es esta la primera vez que se enseña el pedido ya pagado? Solo entonces se mide la compra. Se apunta una marca en
     * el historial y gana la de número más bajo: dos visitas a la vez no pueden ser las dos «la primera».
     *
     * @param array<string,mixed> $order
     */
    private function firstPaidView(array $order): bool
    {
        if ((string) $order['payment_status'] !== 'paid') {
            return false;
        }
        $db = $this->app->db();
        $id = (int) $order['id'];
        $where = "order_id = ? AND kind = 'system' AND to_value = 'thanks_shown'";
        if ((int) $db->value('SELECT COUNT(*) FROM {order_history} WHERE ' . $where, [$id]) > 0) {
            return false;
        }
        $mine = (new OrderLog($this->app))->add($id, 'system', null, 'thanks_shown');

        return (int) $db->value('SELECT MIN(id) FROM {order_history} WHERE ' . $where, [$id]) === $mine;
    }

    /**
     * Lleva a la persona a pagar un pedido que sigue sin cobrar: vuelve a pedirle a su forma de pago por dónde se paga y la
     * manda allí (un formulario que se envía solo o una redirección). Con el pedido ya pagado, cancelado o de una forma de
     * pago que no se cobra en línea, vuelve a la página del pedido.
     *
     * @param array<string,string> $params number y secret
     */
    public function pay(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $number = (string) $order['number'];
        $secret = (string) $params['secret'];
        $back = Response::redirect(route('order.thanks', ['number' => $number, 'secret' => $secret]), 303);
        if (!self::payable($this->app, $order)) {
            return $back;
        }
        $action = null;
        try {
            $action = $this->app->service('payments')->get((string) $order['payment_method'])->start(['secret' => $secret] + $order);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.pay:' . (string) $order['payment_method']);
        }
        if (!$action instanceof PaymentAction || !self::safeUrl((string) $action->url)) {
            return $back;
        }
        if ($action->type === PaymentAction::FORM) {
            $fields = [];
            foreach ($action->fields as $name => $value) {
                $fields[(string) $name] = (string) $value;
            }
            $page = ['title' => t('Redirecting to the payment'), 'type' => 'checkout'];
            $response = $this->app->render('orders::front/pay-redirect', ['page' => $page, 'action' => (string) $action->url, 'fields' => $fields, 'number' => $number]);
            $response->headers['Cache-Control'] = 'no-store, private';
            $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

            return $response;
        }
        if ($action->type === PaymentAction::REDIRECT) {
            return Response::redirect((string) $action->url, 303);
        }

        return $back;
    }

    /** La dirección de pago de un pedido a partir de la de su página, o '' si esta instalación no la tiene. */
    public static function payUrl(App $app, string $orderUrl): string
    {
        return $orderUrl !== '' && $app->router()->has('order.pay') ? rtrim($orderUrl, '/') . '/pay' : '';
    }

    /**
     * ¿Se puede pagar ahora este pedido en línea? Sin cobrar, sin cancelar y con una forma de pago que cobra en una pasarela.
     *
     * @param array<string,mixed> $order
     */
    public static function payable(App $app, array $order): bool
    {
        if (in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'refunded', 'authorized'], true)
            || in_array((string) $order['status'], ['cancelled', 'failed'], true) || !$app->has('payments') || !$app->router()->has('order.pay')) {
            return false;
        }
        $code = (string) $order['payment_method'];
        $method = $app->service('payments')->get($code);
        if ($method === null) {
            return false;
        }
        $offline = method_exists($method, 'isOffline') ? (bool) $method->isOffline() : in_array($code, ['transfer', 'cod'], true);

        return !$offline && $code !== 'simulated';
    }

    private static function safeUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 || preg_match('#^/(?![/\\\\])#', $url) === 1;
    }

    private function orders(): Orders
    {
        return $this->app->service('orders');
    }
}
