<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;
use Aifec\Modules\Assistant\Masker;
use Aifec\Modules\Assistant\ShopTools;
use Aifec\Modules\Orders\Checkout\BuyerCode;
use Aifec\Modules\Orders\Front\OrderPageController;
use Aifec\Modules\Orders\OrderLog;

/**
 * Las dos últimas piezas de la compra por MCP: revisar el pedido (checkout_review) y hacerlo (checkout_place). Las otras seis
 * herramientas de la caja las atiende el servicio «tools» del asistente, tal cual.
 *
 * Es lo mismo que hace la API (/api/v1/carts/checkout/review y /place): el pedido solo se hace con la ficha de confirmación de la
 * revisión (si algo cambia después, deja de valer), con buyer_confirmed = true (quien llama declara que la PERSONA ha visto el total
 * y ha dicho que sí) y con una de dos cosas, según el ajuste «Pedidos hechos por agentes» (api.agent_orders, ver BuyerCode): una
 * clave de la API que tenga el ámbito «orders:create» (cabecera Authorization: Bearer) o el código que la tienda manda por
 * correo al comprador al revisar (buyer_code). Topes:
 * api.max_order_cents y api.max_orders_per_hour, los mismos de la API. El cobro lo hace la persona.
 */
final class Orders
{
    public const SCOPE = 'orders:create';

    /** El pedido más alto que se puede hacer por API o MCP, en céntimos (0 = sin tope). */
    public static function maxOrder(App $app): int
    {
        return max(0, $app->settings()->int('api.max_order_cents', 0));
    }

    /**
     * El pedido exacto que se haría ahora.
     *
     * @return array<string,mixed>
     */
    public static function review(App $app, ?array $key = null): array
    {
        $r = $app->service('checkout')->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            throw new McpFailure('The cart is empty');
        }
        if (!$r['ready']) {
            return ['ready' => false, 'missing' => array_values($r['missing']), 'errors' => $r['errors']];
        }
        $total = (int) $r['total'];
        $max = self::maxOrder($app);
        if ($max > 0 && $total > $max) {
            return [
                'ready' => true, 'over_limit' => true, 'total_cents' => $total, 'total' => (string) $r['total_formatted'], 'currency' => (string) $r['currency'],
                'checkout_url' => self::checkoutUrl($app),
                'message' => 'This order is above the highest amount that can be placed by API or MCP. Send the person to the checkout page.',
            ];
        }
        $address = $r['needs_shipping'] ? $r['address'] : $r['billing'];
        $next = 'Show the person this total and the terms, ask for a clear yes, and then call checkout_place with the confirm_token and buyer_confirmed true.';
        $confirmation = null;
        if (!BuyerCode::trusted($key, self::SCOPE)) {
            $mode = BuyerCode::mode($app);
            if ($mode === BuyerCode::MODE_CODE) {
                // Sin una clave de confianza, la tienda comprueba ella misma que la persona quiere el pedido: le manda un código.
                $phone = is_array($address) && isset($address['phone']) ? (string) $address['phone'] : '';
                $confirmation = BuyerCode::send($app, (string) $r['confirm_token'], (string) $r['email'], (string) $r['total_formatted'], Masker::email((string) $r['email']), $phone, Masker::phone($phone));
                $next = str_replace('call place with', 'call checkout_place with', (string) $confirmation['message']);
                $confirmation['message'] = $next;
            } elseif ($mode === BuyerCode::MODE_OFF) {
                $next = 'This shop does not take orders placed by programs. Show the person this total and send them to the checkout page: ' . self::checkoutUrl($app);
            } else {
                $next = 'Show the person this total and the terms and ask for a clear yes. Placing the order needs an API key with the scope ' . self::SCOPE . '; without one, send the person to the checkout page: ' . self::checkoutUrl($app);
            }
        }

        return ($confirmation !== null ? ['confirmation' => $confirmation] : []) + [
            'ready' => true,
            'lines' => $r['lines'],
            'discounts' => $r['discounts'],
            'shipping' => $r['shipping'],
            'fees' => $r['fees'],
            'taxes' => $r['taxes'],
            'total_cents' => $total,
            'total' => (string) $r['total_formatted'],
            'currency' => (string) $r['currency'],
            'prices_include_tax' => (bool) $r['prices_include_tax'],
            'email' => Masker::email((string) $r['email']),
            'address' => Masker::address(is_array($address) ? $address : []),
            'payment' => $r['payment'],
            'terms_url' => self::termsUrl($app),
            'confirm_token' => (string) $r['confirm_token'],
            'next' => $next,
        ];
    }

    /**
     * Hace el pedido.
     *
     * @param array<string,mixed>|null $key   la clave de quien llama (null = no manda ninguna)
     * @param array<string,mixed>      $input confirm_token y buyer_confirmed
     *
     * @return array<string,mixed>
     *
     * @throws McpFailure con el motivo, si no se puede hacer (y entonces no se ha creado nada)
     */
    public static function place(App $app, ?array $key, array $input): array
    {
        $co = $app->service('checkout');
        $mode = BuyerCode::mode($app);
        $trusted = BuyerCode::trusted($key, self::SCOPE);
        if ($mode === BuyerCode::MODE_OFF) {
            throw new McpFailure('This shop does not take orders placed by programs. Send the person to the checkout page: ' . self::checkoutUrl($app) . '. No order was placed');
        }
        if (!$trusted && $mode !== BuyerCode::MODE_CODE) {
            if ($key === null) {
                throw new McpFailure('Placing an order needs an API key: send it as Authorization: Bearer <key>. No order was placed');
            }

            throw new McpFailure('This key does not have the scope ' . self::SCOPE . '. No order was placed');
        }
        if (!isset($input['buyer_confirmed']) || $input['buyer_confirmed'] !== true) {
            throw new McpFailure('buyer_confirmed must be true: the person has to have seen the total and said yes. No order was placed');
        }
        $token = isset($input['confirm_token']) && is_string($input['confirm_token']) ? strtolower(trim($input['confirm_token'])) : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new McpFailure('confirm_token is required: it comes from checkout_review. No order was placed');
        }
        if (!$trusted) {
            // Sin clave de confianza manda el código que la tienda envió al comprador: es la persona quien confirma.
            $refusal = BuyerCode::refusal($app, $token, isset($input['buyer_code']) ? $input['buyer_code'] : null);
            if ($refusal !== null) {
                throw new McpFailure($refusal[1]);
            }
        }
        $keyId = $trusted ? (int) $key['id'] : 0;
        $keyName = $trusted ? (string) $key['name'] : 'buyer code';

        $limiter = new RateLimiter($app->db(), $app->clock());
        $bucket = $trusted ? 'api-orders:' . $keyId : 'api-orders-ip:' . (string) $app->request()->ip;
        $hourly = max(0, $app->settings()->int('api.max_orders_per_hour', 10));
        if ($hourly > 0 && $limiter->remaining($bucket, $hourly, 3600) < 1) {
            throw new McpFailure(($trusted ? 'This key has already placed the number of orders it can place in an hour' : 'Too many orders have been placed from this address in an hour') . '. No order was placed');
        }

        $r = $co->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            throw new McpFailure('The cart is empty');
        }
        $total = (int) $r['total'];
        $max = self::maxOrder($app);
        if ($max > 0 && $total > $max) {
            throw new McpFailure('This order is above the highest amount that can be placed by API or MCP. Send the person to the checkout page: ' . self::checkoutUrl($app));
        }

        $result = $co->place($token, [
            'source' => Sessions::SOURCE,
            'ip' => $app->request()->ip,
            'accept_terms' => true,
            'marketing' => false,
            'attribution' => ['channel' => Sessions::SOURCE, 'key_id' => $keyId, 'key_name' => $keyName, 'confirmed_by' => $trusted ? 'key' : 'buyer_code'],
        ]);
        if (empty($result['ok'])) {
            throw new McpFailure((string) $result['error']);
        }
        if ($hourly > 0) {
            $limiter->hit($bucket, $hourly, 3600);
        }
        $orderId = (int) $result['id'];
        $number = (string) $result['number'];
        try {
            $how = $trusted ? ' with the key ' . $keyName : ' by an assistant; the buyer confirmed it with the code sent to their email';
            (new OrderLog($app))->add($orderId, 'note', null, null, 'Placed through the MCP' . $how, false);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'mcp.order.log');
        }
        self::audit($app, ['id' => $keyId, 'name' => $keyName], $orderId, $number);

        $pay = isset($result['payment']) && is_array($result['payment']) ? $result['payment'] : ['type' => 'none'];
        $type = isset($pay['type']) ? (string) $pay['type'] : 'none';
        $payment = ['type' => $type, 'pending' => $type !== 'none'];
        if ($type === 'instructions') {
            $payment['instructions'] = ShopTools::plain(isset($pay['text']) ? (string) $pay['text'] : '', 1500);
        } elseif ($type === 'redirect') {
            $payment['redirect'] = [
                'url' => isset($pay['url']) ? (string) $pay['url'] : '',
                'method' => isset($pay['method']) ? strtolower((string) $pay['method']) : 'get',
                'fields' => isset($pay['fields']) && is_array($pay['fields']) ? $pay['fields'] : [],
                'note' => 'The person pays, not you. Give them the address in pay_url: they open it in their browser and the shop takes them to the payment page. Nothing is charged without them.',
            ];
            $payUrl = OrderPageController::payUrl($app, (string) $result['order_url']);
            if ($payUrl !== '') {
                $payment['pay_url'] = self::abs($app, $payUrl);
            }
        }
        $currency = self::currency($app);

        return [
            'ok' => true,
            'number' => $number,
            'total_cents' => $total,
            'total' => $app->i18n()->money($total, $currency),
            'currency' => $currency,
            'order_page' => self::abs($app, (string) $result['order_url']),
            'payment' => $payment,
        ];
    }

    /**
     * Apunta en el registro de actividad (tabla audit) el pedido y la clave que lo hizo. Nunca lanza.
     *
     * @param array<string,mixed> $key
     */
    private static function audit(App $app, array $key, int $orderId, string $number): void
    {
        try {
            $app->db()->insert('audit', [
                'user_id' => null,
                'user_name' => mb_substr('MCP: ' . (string) $key['name'], 0, 120, 'UTF-8'),
                'action' => 'order.place',
                'entity' => 'order',
                'entity_id' => (string) $orderId,
                'summary' => mb_substr('Order ' . $number . ' placed by key ' . (string) $key['name'], 0, 190, 'UTF-8'),
                'changes' => json_encode(['key_id' => (int) $key['id']]),
                'ip' => $app->request()->ip,
                'created_at' => $app->clock()->sql(),
            ]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'mcp.audit');
        }
    }

    private static function currency(App $app): string
    {
        $currency = strtoupper(trim($app->settings()->str('shop.currency', 'EUR')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'EUR';
    }

    /** Una dirección de la tienda con su dominio completo. */
    private static function abs(App $app, string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') {
            return $app->url()->origin() . $url;
        }

        return $url;
    }

    private static function checkoutUrl(App $app): string
    {
        try {
            return self::abs($app, $app->url()->route('checkout'));
        } catch (\Throwable $e) {
            return self::abs($app, $app->url()->to('/' . trim($app->settings()->str('urls.checkout', 'finalizar-compra'), '/')));
        }
    }

    /** La página de condiciones de venta, con su dominio, o vacío si no hay una elegida que exista. */
    private static function termsUrl(App $app): string
    {
        $id = $app->settings()->int('orders.terms_page_id', 0);
        if ($id < 1) {
            return '';
        }
        try {
            $slugs = $app->slugs();
            if ($slugs->path('page', $id, $app->lang()) === null && $slugs->path('page', $id, $app->defaultLang()) === null) {
                return '';
            }

            return self::abs($app, $app->url()->entity('page', $id));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
