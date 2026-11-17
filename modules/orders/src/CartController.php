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

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las rutas del carrito (nombres reservados del contrato § 3): add, update, remove, coupon, destination y summary.json.
 *
 * Cada acción contesta JSON si la petición lo espera y, si no, redirige a la página del carrito con el aviso en la
 * sesión de cliente (clave «cart_error») solo cuando ha habido un error. Todas las acciones van detrás del
 * intermediario «csrf»: un envío desde otra web se rechaza con 403.
 */
final class CartController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * La página del carrito. La pinta la plantilla orders::front/cart (la pone el paquete de la tienda); mientras no
     * exista, esta dirección lleva a la portada.
     *
     * @param array<string,string> $params
     */
    public function page(Request $request, array $params): Response
    {
        if (!$this->app->view()->exists('orders::front/cart')) {
            return Response::redirect(url('/'));
        }
        $session = $this->app->session('customer');
        $page = ['title' => t('Your cart'), 'type' => 'cart'];
        $response = $this->app->render('orders::front/cart', ['cart' => $this->cart()->summary(), 'page' => $page, 'flash' => $session->pullFlash()]);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * GET /cart, /cesta: al carrito de la tienda.
     *
     * @param array<string,string> $params
     */
    public function toCart(Request $request, array $params): Response
    {
        return Response::redirect(route('cart'), 301);
    }

    /**
     * GET /checkout, /caja: a la caja de la tienda.
     *
     * @param array<string,string> $params
     */
    public function toCheckout(Request $request, array $params): Response
    {
        return Response::redirect(route('checkout'), 301);
    }

    /**
     * POST add: product_id, variant_id, qty y data[…].
     *
     * @param array<string,string> $params
     */
    public function add(Request $request, array $params): Response
    {
        $variantId = $request->int('variant_id');
        // Lo marcado en la ficha manda sobre la variante de serie: sin JavaScript el campo oculto se queda con la primera, y una
        // talla sin elegir no se compra en la de otro (14/11/2026: se añadía la 46 sin haberla elegido).
        $opt = $request->arr('opt');
        if ($opt !== [] && $this->app->has('catalog')) {
            $product = $this->app->service('catalog')->product($request->int('product_id'));
            if (is_array($product) && !empty($product['has_variants'])) {
                $resolved = \Aifec\Modules\Catalog\Front\Variants::resolve($product, $opt);
                if ($resolved === null) {
                    // Sin JavaScript, de vuelta a la ficha con el aviso junto a las opciones (no al carrito vacío).
                    $back = isset($product['url']) && is_string($product['url']) ? $product['url'] : '';
                    if (!$request->wantsJson() && $back !== '') {
                        return Response::redirect($back . (strpos($back, '?') === false ? '?' : '&') . 'falta=opcion#af-opciones');
                    }

                    return $this->reply($request, ['ok' => false, 'error' => t('Choose an option first'), 'key' => ''], '', true);
                }
                $variantId = $resolved;
            }
        }
        $result = $this->cart()->add($request->int('product_id'), $variantId, $request->int('qty', 1), $request->arr('data'));

        return $this->reply($request, $result, $result['key'], true);
    }

    /**
     * POST update: key y qty (0 quita la línea).
     *
     * @param array<string,string> $params
     */
    public function update(Request $request, array $params): Response
    {
        // Lo que no es un número entero (vacío, «abc», «-5») no quita la línea en silencio: es una cantidad no válida.
        $raw = $request->input('qty');
        $qty = is_numeric($raw) ? (int) $raw : -1;
        $result = $this->cart()->update($request->str('key'), $qty);

        return $this->reply($request, $result, $result['key']);
    }

    /**
     * POST remove: key.
     *
     * @param array<string,string> $params
     */
    public function remove(Request $request, array $params): Response
    {
        $this->cart()->remove($request->str('key'));

        return $this->reply($request, ['ok' => true, 'error' => ''], null);
    }

    /**
     * POST coupon: code para aplicarlo, o remove con el código que se quita.
     *
     * @param array<string,string> $params
     */
    public function coupon(Request $request, array $params): Response
    {
        $remove = $request->str('remove');
        if ($remove !== '') {
            $this->cart()->removeCoupon($remove);

            return $this->reply($request, ['ok' => true, 'error' => ''], null);
        }
        $result = $this->cart()->applyCoupon($request->str('code'));
        $error = (string) $result['error'];
        if (empty($result['ok']) && $error !== '') {
            // Si ya había un cupón puesto, se dice que sigue (14/11/2026: «no es válido» con el descuento anterior debajo confundía).
            $kept = [];
            foreach ($this->cart()->summary()['coupons'] as $coupon) {
                $kept[] = (string) $coupon['code'];
            }
            if ($kept !== []) {
                $error .= '. ' . tn('The coupon {codes} is still applied.', 'The coupons {codes} are still applied.', count($kept), ['codes' => implode(', ', $kept)]);
            }
        }

        return $this->reply($request, ['ok' => $result['ok'], 'error' => $error], null);
    }

    /**
     * POST destination: country, region, postcode, city y carrier_id. Lo que no viene se queda como estaba.
     *
     * @param array<string,string> $params
     */
    public function destination(Request $request, array $params): Response
    {
        $cart = $this->cart();
        $current = $cart->summary()['destination'];
        $destination = [];
        foreach (['country', 'region', 'postcode', 'city'] as $field) {
            $destination[$field] = $request->input($field) !== null ? $request->str($field) : (string) $current[$field];
        }
        $cart->setDestination($destination);
        if ($request->input('carrier_id') !== null) {
            $cart->setCarrier($request->int('carrier_id'));
        }

        return $this->reply($request, ['ok' => true, 'error' => ''], null);
    }

    /**
     * GET summary.json: lo que necesita el minicarrito. Sin carrito contesta {count: 0, lines: []} sin tocar la base de
     * datos y sin poner ninguna cookie.
     *
     * @param array<string,string> $params
     */
    public function summaryJson(Request $request, array $params): Response
    {
        $cart = $this->cart();
        if (!$cart->exists()) {
            return Response::json(['count' => 0, 'lines' => []]);
        }

        return Response::json(CartView::mini($this->app, $cart->summary()));
    }

    /**
     * La respuesta de una acción: JSON {ok, error, message, count, line, totals} o una redirección al carrito.
     *
     * «message» es lo que la tienda le enseña al visitante: el error, o —al añadir— «Añadido al carrito: <producto>». Al
     * añadir van además «added» (lo que dice el botón un momento) y «cart» (el enlace «Ver el carrito» del aviso). Sin
     * «message» la tienda no enseñaba nada al añadir: solo cambiaba el número del carrito (lo vio el usuario, 13/11/2026).
     *
     * @param array<string,mixed> $result
     * @param bool                $added  la acción es «añadir»
     */
    private function reply(Request $request, array $result, ?string $key, bool $added = false): Response
    {
        $ok = !empty($result['ok']);
        $error = isset($result['error']) ? (string) $result['error'] : '';
        if ($request->wantsJson()) {
            $summary = $this->cart()->summary();
            $line = null;
            if ($key !== null && $key !== '' && isset($summary['lines'][$key])) {
                $line = CartView::line($this->app, $summary['lines'][$key], $summary);
            }
            $json = [
                'ok' => $ok,
                'error' => $error,
                'message' => $ok ? '' : $error,
                'count' => (int) $summary['count'],
                'line' => $line,
                // El mismo total que enseña la página del carrito y summary.json (sin envío mientras no hay destino).
                'totals' => ['items_total' => (int) $summary['totals']['items_total'], 'total' => (int) \Aifec\Modules\Orders\Front\TotalsView::forCartPage($this->app, $summary)['total']],
            ];
            if ($ok && $added) {
                $name = $line !== null ? trim((string) $line['name']) : '';
                $json['message'] = $name !== '' ? t('Added to your cart: {name}', ['name' => $name]) : t('Added to your cart');
                $json['added'] = t('Added');
                $json['cart'] = ['url' => route('cart'), 'label' => t('View cart')];
            }

            return Response::json($json, $ok ? 200 : 422);
        }
        if (!$ok && $error !== '') {
            $this->app->session('customer')->flash('cart_error', $error);
        }

        return Response::redirect(route('cart'));
    }

    /** @return Cart */
    private function cart()
    {
        return $this->app->service('cart');
    }
}
