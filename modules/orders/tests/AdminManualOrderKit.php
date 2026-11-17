<?php
/**
 * AIFEC — ayudas de prueba del pedido a mano (P103). No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   moShop(array $opciones = []): array
 *       La tienda de las pruebas del panel de pedidos (adminOrdersApp: correo, «gw» y «transfer»), con:
 *         shirt  Camisa (IVA 21 %) con variantes: CAM-S (20,00 €, 5 uds), CAM-M (25,00 €, 3 uds), CAM-L (20,00 €, agotada)
 *         book   Libro (IVA 10 %, 15,00 €, 10 uds, referencia LIB-1 y código de barras 8400000000017)
 *         mug    Taza (IVA 21 %, 12,00 €, 5 uds)
 *         coupon OTONO, 10 %
 *         ana    cliente con cuenta (ana@cliente.test, 999000001) y su dirección de Madrid (address)
 *       Devuelve ['app' => App, 'shirt', 'book', 'mug', 'coupon', 'ana', 'address', 'v' => [referencia => variante]].
 *       $opciones se pasan a adminOrdersApp() (settings, prices_include_tax…).
 *
 *   moClient(App $app, string $papel = 'admin'): TestClient
 *       Persona del equipo con sesión iniciada; todas sus peticiones llevan la ficha del panel en X-CSRF-Token.
 *
 *   moStep(TestClient $web, string $paso, array $datos = [], bool $json = false): Response
 *       POST /admin/orders/new/<paso> como lo manda un navegador (sin JavaScript) o como lo manda el guion ($json).
 *
 *   moQuote(TestClient $web): array
 *       POST /admin/orders/new/quote y su JSON.
 *
 *   moReady(TestClient $web, array $s, array $lineas, string $pago = 'paid', array $mas = []): array
 *       Rellena el borrador entero: cliente Ana, las líneas ([[producto, variante, unidades], …]), su dirección, el envío
 *       más barato, el cupón si $mas['coupon'] y el pago ('paid' con «Efectivo», o 'method:transfer'). Devuelve el resumen.
 *
 *   moPlace(TestClient $web, array $cambios = []): Response
 *       Abre la pantalla, lee el formulario de «Crear el pedido» como lo manda un navegador y lo envía (con $cambios encima).
 *
 *   moShopOrder(App $app, array $lineas, string $metodo, array $cupones = []): array
 *       El MISMO carrito comprado en la tienda (otro navegador, la caja de siempre): devuelve el pedido (Orders::find).
 *
 *   moLastOrder(App $app): array            el último pedido creado (Orders::find)
 *   moCustomerMails(App $app, string $correo): int   correos de confirmación (pagado o en espera) a ese cliente
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/AdminKit.php';
require_once __DIR__ . '/AdminPanelKit.php';

if (!function_exists('moShop')) {
    /**
     * @param array<string,mixed> $opciones
     *
     * @return array<string,mixed>
     */
    function moShop(array $opciones = []): array
    {
        $app = adminOrdersApp($opciones);
        aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M', 'l' => 'L'], ['variant' => 1]);
        $shirt = aifecProduct($app, [
            'name' => 'Camisa & co', 'price' => 2000, 'tax_class_id' => 'standard', 'weight' => 300,
            'variants' => [
                ['sku' => 'CAM-S', 'stock' => 5, 'values' => ['talla' => 's']],
                ['sku' => 'CAM-M', 'stock' => 3, 'price' => 2500, 'values' => ['talla' => 'm']],
                ['sku' => 'CAM-L', 'stock' => 0, 'values' => ['talla' => 'l']],
            ],
        ]);
        $book = aifecProduct($app, ['name' => 'Libro de cocina', 'price' => 1500, 'stock' => 10, 'tax_class_id' => 'reduced', 'weight' => 400, 'sku' => 'LIB-1', 'gtin' => '8400000000017']);
        $mug = aifecProduct($app, ['name' => 'Taza', 'price' => 1200, 'stock' => 5, 'tax_class_id' => 'standard', 'weight' => 300]);
        $coupon = couponsMake($app, ['code' => 'OTONO', 'kind' => 'percent', 'percent' => 1000]);
        $ana = aifecCartCustomer($app, 'ana@cliente.test', ['phone' => '999000001']);
        $address = (int) $app->service('customers')->saveAddress($ana, [
            'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES', 'phone' => '999000001',
        ]);
        $variants = [];
        foreach ($app->db()->select('SELECT id, sku FROM {variants} WHERE product_id = ?', [$shirt]) as $row) {
            $variants[(string) $row['sku']] = (int) $row['id'];
        }
        $app->invalidate(['catalog']);

        return ['app' => $app, 'shirt' => $shirt, 'book' => $book, 'mug' => $mug, 'coupon' => $coupon, 'ana' => $ana, 'address' => $address, 'v' => $variants];
    }

    function moClient(App $app, string $papel = 'admin'): TestClient
    {
        $web = adminClient($app, $papel);
        $web->headers['x-csrf-token'] = aifecToken($web);

        return $web;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function moStep(TestClient $web, string $paso, array $datos = [], bool $json = false): Response
    {
        $headers = $json ? ['accept' => 'application/json', 'x-requested-with' => 'fetch'] : [];

        return $web->post('/admin/orders/new/' . $paso, $datos, $headers);
    }

    /**
     * @return array<string,mixed>
     */
    function moQuote(TestClient $web): array
    {
        $r = $web->post('/admin/orders/new/quote', [], ['accept' => 'application/json', 'x-requested-with' => 'fetch']);
        $data = json_decode($r->body, true);
        check(is_array($data), 'el resumen es JSON: ' . substr($r->body, 0, 200));

        return $data;
    }

    /**
     * @param array<string,mixed>       $s
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $mas
     *
     * @return array<string,mixed>
     */
    function moReady(TestClient $web, array $s, array $lineas, string $pago = 'paid', array $mas = []): array
    {
        same(303, moStep($web, 'customer', ['customer_id' => (int) $s['ana']])->status, 'elige el cliente');
        foreach ($lineas as $linea) {
            moStep($web, 'line', ['product_id' => $linea[0], 'variant_id' => isset($linea[1]) ? $linea[1] : 0, 'qty' => 1]);
            $q = moQuote($web);
            $key = '';
            foreach ($q['lines'] as $line) {
                if ((int) $line['product_id'] === (int) $linea[0] && (int) $line['variant_id'] === (int) (isset($linea[1]) ? $linea[1] : 0)) {
                    $key = (string) $line['key'];
                }
            }
            check($key !== '', 'la línea está en el carrito');
            if (isset($linea[2]) && (int) $linea[2] !== 1) {
                moStep($web, 'qty', ['key' => $key, 'qty' => (int) $linea[2]]);
            }
        }
        moStep($web, 'address', ['address_id' => (int) $s['address']]);
        $q = moQuote($web);
        if (!empty($q['needs_shipping']) && isset($q['shipping']['carrier_id'])) {
            moStep($web, 'shipping', ['carrier_id' => (int) $q['shipping']['carrier_id']]);
        }
        if (isset($mas['coupon'])) {
            moStep($web, 'coupon', ['code' => (string) $mas['coupon']]);
        }
        if ($pago === 'paid') {
            moStep($web, 'payment', ['mode' => 'paid', 'paid_with' => isset($mas['paid_with']) ? (string) $mas['paid_with'] : 'Efectivo']);
        } else {
            moStep($web, 'payment', ['mode' => $pago]);
        }

        return moQuote($web);
    }

    /**
     * @param array<string,mixed> $cambios
     */
    function moPlace(TestClient $web, array $cambios = []): Response
    {
        $page = $web->get('/admin/orders/new');
        same(200, $page->status, 'la pantalla se abre');

        return $web->post('/admin/orders/new/place', panelKitForm($page->body, '/admin/orders/new/place', null, $cambios));
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param string[]                  $cupones
     *
     * @return array<string,mixed>
     */
    function moShopOrder(App $app, array $lineas, string $metodo, array $cupones = []): array
    {
        $web = new TestClient($app);
        $placed = aifecCartRun($web, static function ($cart, App $app) use ($lineas, $metodo, $cupones): array {
            foreach ($lineas as $linea) {
                $added = $cart->add((int) $linea[0], isset($linea[1]) ? (int) $linea[1] : 0, isset($linea[2]) ? (int) $linea[2] : 1);
                check(!empty($added['ok']), 'la tienda añade la línea: ' . (string) $added['error']);
            }
            foreach ($cupones as $code) {
                check(!empty($cart->applyCoupon($code)['ok']), 'la tienda acepta el cupón');
            }
            $cart->setPaymentMethod($metodo);

            return $app->service('orders')->place(aifecOrderCheckout(['email' => 'otra@cliente.test']));
        });
        check(!empty($placed['ok']), 'la tienda crea el pedido: ' . (string) $placed['error']);
        $order = $app->service('orders')->find((int) $placed['id']);
        check($order !== null, 'el pedido de la tienda existe');

        return $order;
    }

    /**
     * @return array<string,mixed>
     */
    function moLastOrder(App $app): array
    {
        $id = (int) $app->db()->value('SELECT MAX(id) FROM {orders}');
        $order = $id > 0 ? $app->service('orders')->find($id) : null;
        check(is_array($order), 'hay un pedido');

        return $order;
    }

    function moCustomerMails(App $app, string $correo): int
    {
        return (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE to_email = ? AND template IN ('order_confirmed', 'order_on_hold')", [$correo]);
    }
}
