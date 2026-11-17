<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Modules\Assistant\Masker;
use Aifec\Modules\Assistant\ShopTools;
use Aifec\Modules\Orders\Checkout\BuyerCode;
use Aifec\Modules\Orders\Front\OrderPageController;
use Aifec\Modules\Orders\OrderLog;

/**
 * Las dos últimas piezas de la compra por API: revisar el pedido y hacerlo. Las demás las atienden las herramientas de la caja del
 * asistente (checkout_status, checkout_set_customer…), sin tocarlas.
 *
 * El pedido no lo decide quien llama a solas:
 *   1. review() devuelve el pedido exacto, con su total y su ficha de confirmación (confirm_token, un HMAC con la clave del sitio de
 *      lo que se va a pagar). Si algo cambia después (una cantidad, la dirección, el envío, el pago, un cupón o el total), la ficha
 *      deja de valer y no se crea nada.
 *   2. place() solo hace el pedido con esa ficha, con buyer_confirmed = true (quien llama declara que la PERSONA ha visto el total y
 *      ha dicho que sí) y con una de dos cosas, según el ajuste «Pedidos hechos por agentes» (api.agent_orders, ver BuyerCode):
 *      una clave que tenga el ámbito «orders:create», o el código que la tienda manda por correo al comprador al revisar
 *      (buyer_code). Con el ajuste apagado, ningún programa hace pedidos.
 *   3. Topes: api.max_order_cents (el pedido más alto) y api.max_orders_per_hour (pedidos por clave y hora).
 *   4. El pedido queda con source = api o mcp, apuntado en su historial y en el registro de actividad con la clave que lo hizo.
 * El cobro lo hace la persona: si la forma de pago es una pasarela, la respuesta trae la dirección donde paga; si es transferencia o
 * contra reembolso, las instrucciones. Ninguna pasarela se paga sin ella.
 */
final class Checkout
{
    public const SCOPE = 'orders:create';

    /** ¿Se puede comprar por aquí? El motor de la caja tiene que estar y la compra en el chat, encendida (la misma opción). */
    public static function enabled(App $app): bool
    {
        return $app->has('tools') && $app->service('tools')->checkoutEnabled();
    }

    /** El pedido más alto que se puede hacer por API o MCP, en céntimos (0 = sin tope). */
    public static function maxOrder(App $app): int
    {
        return max(0, $app->settings()->int('api.max_order_cents', 0));
    }

    /**
     * El pedido exacto que se haría ahora.
     *
     * @return array<string,mixed>
     *
     * @throws HttpError 404 si la compra no está disponible, 422 si el carrito está vacío
     */
    public static function review(App $app, ?array $key = null): array
    {
        $co = self::engine($app);
        $r = $co->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            throw new HttpError(422, 'The cart is empty');
        }
        if (!$r['ready']) {
            return ['ready' => false, 'missing' => array_values($r['missing']), 'errors' => $r['errors']];
        }
        $total = (int) $r['total'];
        $max = self::maxOrder($app);
        if ($max > 0 && $total > $max) {
            return [
                'ready' => true, 'over_limit' => true, 'total' => $total, 'total_formatted' => (string) $r['total_formatted'], 'currency' => (string) $r['currency'],
                'checkout_url' => self::checkoutUrl($app),
                'message' => 'This order is above the highest amount that can be placed by API or MCP. Send the person to the checkout page.',
            ];
        }
        $address = $r['needs_shipping'] ? $r['address'] : $r['billing'];
        $next = 'Show the person this total and the terms, ask for a clear yes, and then call place with the confirm_token and buyer_confirmed true.';
        $confirmation = null;
        if (!BuyerCode::trusted($key, self::SCOPE)) {
            $mode = BuyerCode::mode($app);
            if ($mode === BuyerCode::MODE_CODE) {
                // Sin una clave de confianza, la tienda comprueba ella misma que la persona quiere el pedido: le manda un código.
                $phone = is_array($address) && isset($address['phone']) ? (string) $address['phone'] : '';
                $confirmation = BuyerCode::send($app, (string) $r['confirm_token'], (string) $r['email'], (string) $r['total_formatted'], Masker::email((string) $r['email']), $phone, Masker::phone($phone));
                $next = (string) $confirmation['message'];
                if (empty($confirmation['sent'])) {
                    // El mensaje manda «a la caja»: se le da la dirección que abre ESTE carrito, no la caja vacía.
                    $next .= ' The checkout address for this cart, for the person: ' . self::checkoutUrl($app);
                    $confirmation['checkout_url'] = self::checkoutUrl($app);
                }
            } elseif ($mode === BuyerCode::MODE_OFF) {
                $next = 'This shop does not take orders placed by programs. Show the person this total and send them to the checkout page: ' . self::checkoutUrl($app);
            } else {
                $next = 'Show the person this total and the terms and ask for a clear yes. Placing the order needs a key with the scope ' . self::SCOPE . '; without one, send the person to the checkout page: ' . self::checkoutUrl($app);
            }
        }

        return ($confirmation !== null ? ['confirmation' => $confirmation] : []) + [
            'ready' => true,
            'lines' => $r['lines'],
            'discounts' => $r['discounts'],
            'shipping' => $r['shipping'],
            'fees' => $r['fees'],
            'taxes' => $r['taxes'],
            'total' => $total,
            'total_formatted' => (string) $r['total_formatted'],
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
     * @param array<string,mixed>|null $key     la clave de quien llama (null = no manda ninguna)
     * @param array<string,mixed>      $input   confirm_token y buyer_confirmed
     *
     * @return array<string,mixed>
     *
     * @throws HttpError 401 sin clave, 403 sin el ámbito, 400 sin confirmación, 429 si se pasa del tope por hora, 409 si el pedido
     *                   cambió desde la revisión, 422 si no se puede hacer
     */
    public static function place(App $app, ?array $key, array $input, string $channel): array
    {
        $co = self::engine($app);
        $mode = BuyerCode::mode($app);
        $trusted = BuyerCode::trusted($key, self::SCOPE);
        if ($mode === BuyerCode::MODE_OFF) {
            throw new HttpError(403, 'This shop does not take orders placed by programs. Send the person to the checkout page: ' . self::checkoutUrl($app));
        }
        if (!$trusted && $mode !== BuyerCode::MODE_CODE) {
            if ($key === null) {
                throw new HttpError(401, 'Placing an order needs a key: send it as Authorization: Bearer <key>');
            }

            throw new HttpError(403, 'This key does not have the scope ' . self::SCOPE);
        }
        if (!isset($input['buyer_confirmed']) || $input['buyer_confirmed'] !== true) {
            throw HttpError::badRequest('buyer_confirmed must be true: the person has to have seen the total and said yes. No order was placed');
        }
        $token = isset($input['confirm_token']) && is_string($input['confirm_token']) ? strtolower(trim($input['confirm_token'])) : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw HttpError::badRequest('confirm_token is required: it comes from the review. No order was placed');
        }
        if (!$trusted) {
            // Sin clave de confianza manda el código que la tienda envió al comprador: es la persona quien confirma.
            $refusal = BuyerCode::refusal($app, $token, isset($input['buyer_code']) ? $input['buyer_code'] : null);
            if ($refusal !== null) {
                // Con demasiados intentos el mensaje manda a la persona «a la caja»: va la dirección que abre este carrito.
                throw new HttpError($refusal[0], $refusal[1] . ((int) $refusal[0] === 429 ? ': ' . self::checkoutUrl($app) : ''));
            }
        }
        $keyId = $trusted ? (int) $key['id'] : 0;
        $keyName = $trusted ? (string) $key['name'] : 'buyer code';

        $limiter = new RateLimiter($app->db(), $app->clock());
        $bucket = $trusted ? 'api-orders:' . $keyId : 'api-orders-ip:' . (string) $app->request()->ip;
        $hourly = max(0, $app->settings()->int('api.max_orders_per_hour', 10));
        if ($hourly > 0 && $limiter->remaining($bucket, $hourly, 3600) < 1) {
            throw new HttpError(429, $trusted ? 'This key has already placed the number of orders it can place in an hour' : 'Too many orders have been placed from this address in an hour');
        }

        $r = $co->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            throw new HttpError(422, 'The cart is empty');
        }
        $total = (int) $r['total'];
        $max = self::maxOrder($app);
        if ($max > 0 && $total > $max) {
            throw new HttpError(422, 'This order is above the highest amount that can be placed by API or MCP. Send the person to the checkout page: ' . self::checkoutUrl($app));
        }

        $result = $co->place($token, [
            'source' => $channel,
            'ip' => $app->request()->ip,
            'accept_terms' => true,
            'marketing' => false,
            'attribution' => ['channel' => $channel, 'key_id' => $keyId, 'key_name' => $keyName, 'confirmed_by' => $trusted ? 'key' : 'buyer_code'],
        ]);
        if (empty($result['ok'])) {
            $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];

            throw new HttpError(isset($errors['confirm_token']) ? 409 : 422, (string) $result['error']);
        }
        if ($hourly > 0) {
            $limiter->hit($bucket, $hourly, 3600);
        }
        $orderId = (int) $result['id'];
        $number = (string) $result['number'];
        try {
            $how = $trusted ? ' with the key ' . $keyName : ' by an assistant; the buyer confirmed it with the code sent to their email';
            (new OrderLog($app))->add($orderId, 'note', null, null, 'Placed through the ' . strtoupper($channel) . $how, false);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'api.order.log');
        }
        Keys::audit($app, ['id' => $keyId, 'name' => $keyName], $channel, 'order.place', 'order', (string) $orderId, 'Order ' . $number . ($trusted ? ' placed by key ' . $keyName : ' placed by an assistant and confirmed with the buyer code'));

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
                $payment['pay_url'] = Present::abs($app, $payUrl);
            }
        }

        return [
            'number' => $number,
            'total' => $total,
            'total_formatted' => $app->i18n()->money($total, Present::currency($app)),
            'currency' => Present::currency($app),
            'order_page' => Present::abs($app, (string) $result['order_url']),
            'payment' => $payment,
        ];
    }

    /**
     * El motor de la caja sobre el carrito de la petición.
     *
     * @return \Aifec\Modules\Orders\Checkout\CheckoutSession
     *
     * @throws HttpError 404 si la compra por aquí no está disponible
     */
    private static function engine(App $app)
    {
        if (!self::enabled($app)) {
            throw new HttpError(404, 'This shop does not take orders here');
        }

        return $app->service('checkout');
    }

    /**
     * La dirección a la que se manda a la PERSONA para que termine ella. Si hay un carrito de programa en esta petición, es la
     * que abre ESE carrito en su navegador: la de la caja a secas le enseña una caja vacía, porque el carrito del asistente
     * no es el de su navegador («mi carrito sale vacío en el enlace», 16/11/2026).
     */
    private static function checkoutUrl(App $app): string
    {
        try {
            $cookies = $app->request()->cookies;
            $name = $app->cookieName('k');
            $raw = isset($cookies[$name]) && is_string($cookies[$name]) ? strtolower($cookies[$name]) : '';
            if ($raw !== '' && Carts::valid($raw) && Carts::find($app, $raw) !== null && $app->router()->has('api.cart.resume')) {
                return Carts::resumeUrl($app, $raw);
            }
        } catch (\Throwable $e) {
            // Sin carrito de programa a la vista: la caja a secas.
        }
        try {
            return Present::abs($app, $app->url()->route('checkout'));
        } catch (\Throwable $e) {
            return Present::abs($app, $app->url()->to('/' . trim($app->settings()->str('urls.checkout', 'finalizar-compra'), '/')));
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

            return Present::abs($app, $app->url()->entity('page', $id));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
