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
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El carrito por API, para un programa que prepara la compra. Trabaja sobre el servicio «cart» de la tienda a través de las
 * herramientas cart_view, cart_add, cart_update y checkout_link: los precios y los totales son los del carrito de la web.
 * No hay pago por API: el cobro lo hace la persona en la tienda (ver el enlace que da link()).
 */
final class CartController
{
    /** El origen que se apunta en el carrito: así se sabe de dónde vienen los pedidos. */
    public const SOURCE = 'api';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $raw = Carts::create($this->app, self::SOURCE);
        Carts::bind($this->app, $raw);
        $response = Agent::json(['token' => $raw, 'cart' => Agent::tool($this->app, 'cart_view')], 201);
        $response->headers['X-Cart-Token'] = $raw;

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params): Response
    {
        Carts::open($this->app, $request);

        return Agent::json(Agent::tool($this->app, 'cart_view'));
    }

    /**
     * @param array<string,string> $params
     */
    public function add(Request $request, array $params): Response
    {
        Carts::open($this->app, $request);
        $body = Agent::body($request);

        return Agent::json(Agent::tool($this->app, 'cart_add', $body), 201);
    }

    /**
     * @param array<string,string> $params key
     */
    public function update(Request $request, array $params): Response
    {
        $raw = Carts::open($this->app, $request);
        $body = Agent::body($request);
        if (!array_key_exists('qty', $body)) {
            throw HttpError::badRequest('The field qty is required');
        }
        $result = Agent::tool($this->app, 'cart_update', ['key' => (string) $params['key'], 'qty' => $body['qty']]);
        Carts::keep($this->app, $raw, self::SOURCE);

        return Agent::json($result);
    }

    /**
     * @param array<string,string> $params key
     */
    public function remove(Request $request, array $params): Response
    {
        $raw = Carts::open($this->app, $request);
        $result = Agent::tool($this->app, 'cart_update', ['key' => (string) $params['key'], 'qty' => 0]);
        Carts::keep($this->app, $raw, self::SOURCE);

        return Agent::json($result);
    }

    /**
     * La dirección que abre este carrito en el navegador de la persona y la lleva a la caja.
     *
     * @param array<string,string> $params
     */
    public function link(Request $request, array $params): Response
    {
        $raw = Carts::open($this->app, $request);
        $result = Agent::tool($this->app, 'checkout_link');
        unset($result['url']);
        // Las dos claves dan la MISMA dirección, la que abre este carrito en el navegador de la persona. «checkout_url» era
        // antes la caja a secas, y un asistente que le daba esa a la persona la mandaba a una caja vacía (16/11/2026).
        $resume = Carts::resumeUrl($this->app, $raw);

        return Agent::json(['url' => $resume, 'checkout_url' => $resume,
            'note' => 'Give the person this address: it opens THIS cart in their browser, at the checkout. The plain checkout page of the shop would show them an empty cart.'] + $result);
    }

    /**
     * GET /<urls.cart>/retomar/{token}: abre un carrito de un programa en el navegador de una persona. Pone la cookie del carrito
     * y la lleva a la caja, con las mismas líneas. La dirección lleva la ficha del carrito: no se indexa ni se guarda.
     *
     * @param array<string,string> $params token
     */
    public function resume(Request $request, array $params): Response
    {
        if (!$this->app->has('cart') || !$this->app->router()->has('checkout')) {
            throw HttpError::notFound();
        }
        $raw = strtolower((string) (isset($params['token']) ? $params['token'] : ''));
        $row = Carts::find($this->app, $raw);
        if ($row === null) {
            throw HttpError::notFound();
        }
        if ($row['order_id'] !== null && (int) $row['order_id'] > 0) {
            // Ese carrito ya es un pedido. Quien vuelve con esta dirección —la persona, o su asistente para decirle el número—
            // va a la página de ESE pedido. Antes daba un 404 y el asistente contestaba «no puedo comprobar tu pedido»
            // (16/11/2026). La ficha del carrito ya daba acceso a esa página por el paso «status»: no se abre nada nuevo.
            $number = (string) $this->app->db()->value('SELECT number FROM {orders} WHERE id = ?', [(int) $row['order_id']]);
            $placedAt = (string) $this->app->db()->value('SELECT placed_at FROM {orders} WHERE id = ?', [(int) $row['order_id']]);
            if ($placedAt !== '' && $this->app->clock()->now() - (int) strtotime($placedAt . ' UTC') > 1200) {
                // Pedido hace horas: es una dirección de otra conversación (17/11/2026: Claude, con memoria, daba a la persona la caja
                // de un carrito de la víspera y le salía «pedido cancelado»). Se vuelve a comprar lo mismo en un carrito nuevo.
                $product = $this->app->db()->value("SELECT product_id FROM {order_items} WHERE order_id = ? AND kind = 'product' AND product_id IS NOT NULL ORDER BY id", [(int) $row['order_id']]);
                if ($product !== null && (int) $product > 0) {
                    $again = Response::redirect(BrowseController::addUrl($this->app, (int) $product));
                    $again->headers['Cache-Control'] = 'no-store, private';
                    $again->headers['X-Robots-Tag'] = 'noindex, nofollow';

                    return $again;
                }
            }
            // La ficha EN CLARO del pedido: en la tabla solo está su resumen, y con él el enlace daba un 404 (17/11/2026).
            $token = $number !== '' && $this->app->has('orders') ? $this->app->service('orders')->viewTokenOf($number) : '';
            if ($token === '' || !$this->app->router()->has('order.thanks')) {
                throw HttpError::notFound();
            }
            $placed = Response::redirect($this->app->url()->route('order.thanks', ['number' => $number, 'secret' => $token]));
            $placed->headers['Cache-Control'] = 'no-store, private';
            $placed->headers['X-Robots-Tag'] = 'noindex, nofollow';
            $placed->headers['Referrer-Policy'] = 'no-referrer';

            return $placed;
        }
        if (\Aifec\Kernel\VisitLog::bot($request->userAgent()) !== null) {
            // Lo abre el lector de un asistente (lo comprueba antes de dárselo a la persona): no se le pone el carrito ni se le
            // manda a la caja, que tiene cerrada; se le dice qué es. Sin esto ChatGPT concluía «la tienda no es accesible» (16/11/2026).
            $text = new Response("This address works: it is for the person who is buying. Give it to them as it is: in their browser it opens this cart, already filled, at the checkout of the shop.\n"
                . t('If you are the person who is buying, open this address in your browser: your cart is ready at the checkout of the shop.') . "\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow',
            ]);

            return $text;
        }
        $this->app->setCookie($this->app->cookieName('k'), $raw, 30);
        $this->app->setCookie($this->app->cookieName('ck'), '1', 30, true);
        $response = Response::redirect($this->app->url()->route('checkout'));
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        $response->headers['Referrer-Policy'] = 'no-referrer';

        return $response;
    }
}
