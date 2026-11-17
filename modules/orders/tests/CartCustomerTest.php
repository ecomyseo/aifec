<?php
/**
 * AIFEC — pruebas del carrito y del cliente: asociar y fundir al entrar, descuento de grupo, exentos, el filtro sellado de
 * ajustes, de carrito a pedido y la caché de página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

/** Entra el cliente en el navegador de mentira (dispara customer.login) y devuelve su id, o null si no entró. */
function cartCustomerLogin(TestClient $web, string $email = 'ana@cliente.test'): ?int
{
    return aifecCartRun($web, static function ($cart, App $app) use ($email): ?int {
        return $app->service('customers')->login($email, 'clave-larga-1234');
    });
}

/** Un pedido mínimo (sin pagar o como se pida) con la ficha $secret, para probar el paso de carrito a pedido. */
function cartCustomerOrder(App $app, string $secret, string $paymentStatus): int
{
    static $n = 0;
    ++$n;
    $now = $app->clock()->sql();

    return $app->db()->insert('orders', [
        'number' => '2026-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'email' => 'ana@cliente.test', 'payment_status' => $paymentStatus,
        'secret' => hash('sha256', $secret), 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

test('un carrito anónimo se asocia al cliente cuando entra', function (): void {
    $app = aifecCartApp();
    $customer = aifecCartCustomer($app);
    $p = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 2);
    });
    same(null, $app->db()->value('SELECT customer_id FROM {carts}'));

    same($customer, cartCustomerLogin($web));
    same($customer, (int) $app->db()->value('SELECT customer_id FROM {carts}'));
    same(1, shippingCount($app, 'carts'));
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same($customer, $s['customer_id']);
    same(2, $s['count']);
});

test('si el cliente ya tenía otro carrito se funden: cantidades sumadas sin pasar de los límites, cupones juntos y queda uno', function (): void {
    $app = aifecCartApp();
    $customer = aifecCartCustomer($app);
    $x = aifecProduct($app, ['name' => 'X', 'stock' => 2]);
    $y = aifecProduct($app, ['name' => 'Y', 'stock' => 10]);
    couponsMake($app, ['code' => 'OTONO', 'combinable' => 1]);
    couponsMake($app, ['code' => 'EXTRA', 'combinable' => 1, 'percent' => 500]);

    // En el ordenador de casa, ya con cuenta: 1 X y el cupón OTONO.
    $home = new TestClient($app);
    cartCustomerLogin($home);
    aifecCartRun($home, static function ($cart) use ($x): void {
        $cart->add($x, 0, 1);
        $cart->applyCoupon('OTONO');
    });
    same(1, shippingCount($app, 'carts'));
    same($customer, (int) $app->db()->value('SELECT customer_id FROM {carts}'));

    // En el móvil, sin entrar: 2 X, 1 Y y el cupón EXTRA. Al entrar se funde.
    $phone = new TestClient($app);
    aifecCartRun($phone, static function ($cart) use ($x, $y): void {
        $cart->add($x, 0, 2);
        $cart->add($y, 0, 1);
        $cart->applyCoupon('EXTRA');
    });
    same(2, shippingCount($app, 'carts'));
    cartCustomerLogin($phone);

    same(1, shippingCount($app, 'carts'), 'queda uno');
    $s = aifecCartRun($phone, static function ($cart): array {
        return $cart->summary();
    });
    $qty = [];
    foreach ($s['lines'] as $line) {
        $qty[$line['name']] = $line['qty'];
    }
    same(['X' => 2, 'Y' => 1], $qty, '1 + 2 de X serían 3, pero solo hay 2 en existencias');
    $codes = array_column($s['coupons'], 'code');
    sort($codes);
    same(['EXTRA', 'OTONO'], $codes, 'los cupones de los dos carritos');
    same([], $s['problems']);
    same($customer, $s['customer_id']);

    // Fundir no inventa carritos: sin cookie en un navegador nuevo no hay nada que fundir.
    $other = new TestClient($app);
    cartCustomerLogin($other);
    same(1, shippingCount($app, 'carts'));
    same(0, aifecCartRun($other, static function ($cart): int {
        return $cart->count();
    }), 'sin cookie no hay carrito, aunque el cliente tenga uno guardado');
});

test('el descuento del grupo del cliente es el primer descuento, con su etiqueta, y los cupones van después', function (): void {
    $app = aifecCartApp();
    $group = aifecCartGroup($app, 500);
    aifecCartCustomer($app, 'ana@cliente.test', ['group_id' => $group]);
    $p = aifecProduct($app, ['price' => 2000, 'kind' => 'digital', 'stock' => 10]);
    couponsMake($app);
    $web = new TestClient($app);
    cartCustomerLogin($web);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });

    $alone = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $t = $alone['totals'];
    same(2000, $t['items_total']);
    same(100, $t['discount_total'], '5 % de 20,00');
    same(['percent'], array_column($t['discounts'], 'kind'));
    same('Descuento de grupo', $t['discounts'][0]['label']);
    same(1900, $t['total']);
    same([], $alone['coupons'], 'el descuento del grupo no es un cupón');

    aifecCartRun($web, static function ($cart): void {
        $cart->applyCoupon('OTONO');
    });
    $both = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(['Descuento de grupo', 'Cupón OTONO'], array_column($both['totals']['discounts'], 'label'));
    same([100, 190], array_column($both['totals']['discounts'], 'amount'), 'el cupón se aplica sobre lo que queda: 10 % de 19,00');
    same(1710, $both['totals']['total']);
    same([['code' => 'OTONO', 'label' => 'Cupón OTONO', 'amount' => 190]], $both['coupons']);

    // Un anónimo no lo recibe.
    $anon = new TestClient($app);
    aifecCartRun($anon, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    same(2000, aifecCartRun($anon, static function ($cart): int {
        return $cart->summary()['totals']['total'];
    }));
});

test('un cliente de un grupo exento no paga impuestos: se cobra solo la base del precio publicado', function (): void {
    $app = aifecCartApp();
    $group = aifecCartGroup($app, 0, true);
    aifecCartCustomer($app, 'ana@cliente.test', ['group_id' => $group]);
    $p = aifecProduct($app, ['price' => 1210, 'tax_class_id' => 'standard', 'kind' => 'digital', 'stock' => 10]);

    $exempt = new TestClient($app);
    cartCustomerLogin($exempt);
    aifecCartRun($exempt, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    $s = aifecCartRun($exempt, static function ($cart): array {
        return $cart->summary();
    });
    same(true, $s['tax_exempt']);
    same(true, $s['totals']['tax_exempt']);
    same(0, $s['totals']['total_tax']);
    same(1000, $s['totals']['total']);
    same([1000, 0, 1000], [$s['totals']['total_net'], $s['totals']['total_tax'], $s['totals']['total']]);

    $normal = new TestClient($app);
    aifecCartRun($normal, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    $n = aifecCartRun($normal, static function ($cart): array {
        return $cart->summary();
    });
    same(false, $n['tax_exempt']);
    same(1210, $n['totals']['total']);
    same(210, $n['totals']['total_tax']);
});

test('el filtro cart.adjustments está sellado: sin la capacidad pricing no cambia nada, con ella sale una línea de ajuste con su origen', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'kind' => 'digital', 'stock' => 10]);
    $hooks = $app->hooks();
    $adjust = static function (string $source): callable {
        return static function (array $adjustments, array $summary) use ($source): array {
            $adjustments[] = ['label' => 'Recargo de prueba', 'amount' => 500, 'source' => $source, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'];

            return $adjustments;
        };
    };

    $hooks->setCurrentModule('plugin-sin-permiso');
    $hooks->filter('cart.adjustments', $adjust('plugin-sin-permiso'));
    $hooks->setCurrentModule('core');
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    $without = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same([], $without['totals']['adjustments'], 'sin la capacidad el filtro ni se engancha');
    same(1000, $without['totals']['total']);
    $errors = $hooks->errors();
    same('cart.adjustments', $errors[count($errors) - 1]['hook']);
    contains('Sealed hook', $errors[count($errors) - 1]['error']);

    $hooks->grant('plugin-con-permiso', 'pricing');
    $hooks->setCurrentModule('plugin-con-permiso');
    $hooks->filter('cart.adjustments', $adjust('plugin-con-permiso'));
    $hooks->setCurrentModule('core');
    $with = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $t = $with['totals'];
    same(1, count($t['adjustments']));
    same('plugin-con-permiso', $t['adjustments'][0]['source']);
    same('Recargo de prueba', $t['adjustments'][0]['label']);
    same(500, $t['adjustments'][0]['amount']);
    same(500, $t['adjustment_total']);
    same([413, 87], [$t['adjustments'][0]['net'], $t['adjustments'][0]['tax']]);
    same(1500, $t['total']);
});

test('un ajuste negativo que dejaría el carrito por debajo de cero no se aplica', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'kind' => 'digital', 'stock' => 10]);
    $hooks = $app->hooks();
    $hooks->grant('plugin-generoso', 'pricing');
    $hooks->setCurrentModule('plugin-generoso');
    $hooks->filter('cart.adjustments', static function (array $adjustments, array $summary): array {
        $adjustments[] = ['label' => 'Regalo', 'amount' => -5000, 'source' => 'plugin-generoso'];
        $adjustments[] = ['label' => '', 'amount' => 0, 'source' => 'plugin-generoso'];
        $adjustments[] = 'basura';

        return $adjustments;
    });
    $hooks->setCurrentModule('core');
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same([], $s['totals']['adjustments']);
    same(1000, $s['totals']['total']);
});

test('de carrito a pedido: markConverted deja el carrito sin cookie y el resumen vacío', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 2);
    });
    $orderId = cartCustomerOrder($app, 'ficha-de-mentira', 'pending');

    aifecCartRun($web, static function ($cart) use ($orderId): void {
        $cart->markConverted($orderId);
        same(0, $cart->count(), 'dentro de la misma petición también');
        same(null, $cart->summary()['id']);
    });
    check(!isset($web->cookies[$app->cookieName('k')]), 'la cookie se olvida');
    same($orderId, (int) $app->db()->value('SELECT order_id FROM {carts}'));
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
    same([], aifecCartRun($web, static function ($cart): array {
        return $cart->summary()['lines'];
    }));
    // Y una cookie vieja de ese carrito tampoco lo resucita.
    $stale = new TestClient($app);
    $stale->cookies[$app->cookieName('k')] = str_repeat('a', 48);
    same(0, aifecCartRun($stale, static function ($cart): int {
        return $cart->count();
    }));
});

test('reopen: con la ficha buena el pedido sin pagar devuelve su carrito, con sus líneas y una cookie nueva; con otra, o ya pagado, no', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 10, 'price' => 1000]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 2);
    });
    $oldToken = $web->cookies[$app->cookieName('k')];
    $orderId = cartCustomerOrder($app, 'ficha-de-mentira', 'pending');
    aifecCartRun($web, static function ($cart) use ($orderId): void {
        $cart->markConverted($orderId);
    });

    same(false, aifecCartRun($web, static function ($cart) use ($orderId): bool {
        return $cart->reopen($orderId, 'ficha-equivocada');
    }), 'ficha mala');
    same(false, aifecCartRun($web, static function ($cart) use ($orderId): bool {
        return $cart->reopen($orderId, '');
    }), 'ficha vacía');
    same(false, aifecCartRun($web, static function ($cart): bool {
        return $cart->reopen(99999, 'ficha-de-mentira');
    }), 'pedido que no existe');
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }), 'un intento fallido no devuelve nada');

    $app->db()->update('orders', ['payment_status' => 'paid'], ['id' => $orderId]);
    same(false, aifecCartRun($web, static function ($cart) use ($orderId): bool {
        return $cart->reopen($orderId, 'ficha-de-mentira');
    }), 'pedido ya pagado');
    same($orderId, (int) $app->db()->value('SELECT order_id FROM {carts}'), 'el carrito sigue siendo del pedido');

    $app->db()->update('orders', ['payment_status' => 'failed'], ['id' => $orderId]);
    same(true, aifecCartRun($web, static function ($cart) use ($orderId): bool {
        return $cart->reopen($orderId, 'ficha-de-mentira');
    }), 'un cobro fallido deja volver al carrito');
    $name = $app->cookieName('k');
    check(isset($web->cookies[$name]), 'cookie nueva');
    check($web->cookies[$name] !== $oldToken, 'con ficha nueva');
    same(null, $app->db()->value('SELECT order_id FROM {carts}'), 'el carrito queda libre');
    same(hash('sha256', $web->cookies[$name]), $app->db()->value('SELECT token FROM {carts}'));
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(2, $s['count']);
    same(2000, $s['totals']['items_total']);
});

test('una página pública pedida sin cookie de carrito sigue siendo cacheable después de que otro visitante cree el suyo', function (): void {
    $app = aifecCartApp();
    $app->router()->get('/pagina-publica', static function (Request $request, App $app): Response {
        return Response::html('<p>Una página cualquiera</p>')->cacheable(600, ['pages']);
    });
    $p = aifecProduct($app, ['stock' => 10]);
    $first = new TestClient($app);
    $second = new TestClient($app);

    same('MISS', $first->get('/pagina-publica')->headers['X-Cache'], 'sin carrito, la página se guarda');
    aifecCartRun($first, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    check(isset($first->cookies[$app->cookieName('k')]), 'el primer visitante ya tiene carrito');

    $again = $second->get('/pagina-publica');
    same('MISS', $again->headers['X-Cache'], 'el segundo, sin cookie, sigue pudiendo guardarla (no es BYPASS)');
    same([], $again->cookies, 'y su respuesta no pone ninguna cookie');
    same('MISS', $first->get('/pagina-publica')->headers['X-Cache'], 'quien tiene carrito también la comparte: la cookie no cambia la copia');

    $withCart = Request::create('GET', '/pagina-publica', [], $first->cookies, ['sec-fetch-site' => 'same-origin']);
    $withCart->host = 'tienda.test';
    $withCart->secure = true;
    $without = Request::create('GET', '/pagina-publica', [], [], ['sec-fetch-site' => 'same-origin']);
    $without->host = 'tienda.test';
    $without->secure = true;
    same(PageCache::key($app->site(), $without), PageCache::key($app->site(), $withCart), 'misma clave de caché con y sin la cookie del carrito');
    $hit = PageCache::serve($app->site(), $withCart, $app->clock());
    check($hit !== null, 'y la copia guardada se sirve también a quien tiene carrito');
    same('HIT', $hit->headers['X-Cache']);
});
