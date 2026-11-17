<?php
/**
 * AIFEC — pruebas del carrito con precios: cifras hechas a mano, el catálogo que cambia, envío, formas de pago y cupones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Prepara el carrito de las cuentas: A = 2 × 12,10 € (IVA 21 %, 500 g) y B = 1 × 11,00 € (IVA 10 %, 300 g), con el cupón
 * OTONO del 10 % puesto. Devuelve el navegador de mentira con su cookie.
 *
 * @return array{0:TestClient,1:int,2:int}
 */
function cartTotalsFill(\Aifec\Kernel\App $app, int $priceA = 1210, int $priceB = 1100): array
{
    $a = aifecProduct($app, ['name' => 'Producto A', 'price' => $priceA, 'tax_class_id' => 'standard', 'weight' => 500]);
    $b = aifecProduct($app, ['name' => 'Producto B', 'price' => $priceB, 'tax_class_id' => 'reduced', 'weight' => 300]);
    couponsMake($app);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($a, $b): void {
        $cart->add($a, 0, 2);
        $cart->add($b, 0, 1);
        $cart->applyCoupon('otono');
    });

    return [$web, $a, $b];
}

test('cuentas a mano con IVA dentro: dos tipos, envío por peso, cupón del 10 % y forma de pago con recargo', function (): void {
    /*
     * Precios con IVA dentro. A: 2 × 12,10 = 24,20 (21 %). B: 1 × 11,00 (10 %). Artículos 35,20 €. Cupón 10 %: 3,52 €,
     * repartido 2,42 a A y 1,10 a B. A: 21,78 = 18,00 + 3,78. B: 9,90 = 9,00 + 0,90.
     * Peso 2 × 500 + 300 = 1300 g → tramo de 1001 a 5000 g: 7,95 €, con el tipo más alto de las líneas (21 %):
     * 6,57 + 1,38. Recargo de la forma de pago 3,00 € al tipo de la clase por defecto (21 %): 2,48 + 0,52.
     * Base 36,05 €; IVA 6,58 €; total 42,63 €.
     */
    $app = aifecCartApp();
    [$web, $a, $b] = cartTotalsFill($app);
    $app->service('payments')->register(aifecFakePayment('cod', 300));
    aifecCartRun($web, static function ($cart): void {
        $cart->setPaymentMethod('cod');
    });
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $t = $s['totals'];

    same(3, $s['count']);
    same(1300, $s['weight']);
    same(true, $s['needs_shipping']);
    same(3520, $t['items_total']);
    same(352, $t['discount_total']);
    same(300, $t['fee_total']);
    same(795, $t['shipping']['price']);
    same(657, $t['shipping']['net']);
    same(138, $t['shipping']['tax']);
    same(3605, $t['total_net']);
    same(658, $t['total_tax']);
    same(4263, $t['total']);
    same([['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 2705, 'amount' => 568], ['name' => 'IVA 10 %', 'rate' => 1000, 'base' => 900, 'amount' => 90]], $t['taxes']);

    $keyA = Aifec\Modules\Orders\Cart::lineKey($a, 0);
    $keyB = Aifec\Modules\Orders\Cart::lineKey($b, 0);
    $lineA = $s['lines'][$keyA];
    same([2, 1210, 2420, 242, 2178, 1800, 378, 2178, 2100], [$lineA['qty'], $lineA['unit_price'], $lineA['subtotal'], $lineA['discount'], $lineA['total'], $lineA['net'], $lineA['tax'], $lineA['gross'], $lineA['tax_rate']]);
    $lineB = $s['lines'][$keyB];
    same([1, 1100, 1100, 110, 990, 900, 90, 990, 1000], [$lineB['qty'], $lineB['unit_price'], $lineB['subtotal'], $lineB['discount'], $lineB['total'], $lineB['net'], $lineB['tax'], $lineB['gross'], $lineB['tax_rate']]);
    same('Producto A', $lineA['name']);

    same([['code' => 'OTONO', 'label' => 'Cupón OTONO', 'amount' => 352]], $s['coupons']);
    same('cod', $s['payment_method']);
    same([], $s['problems']);
    same(true, $s['display_with_tax']);
    same(['country' => 'ES', 'region' => '', 'postcode' => '', 'city' => ''], $s['destination']);
    same(1, count($s['shipping_options']));
    check($s['carrier_id'] !== null, 'se ha elegido el único transportista');
    same('Pago de mentira cod', $s['payment_methods'][1]['title']);
    same(300, $s['payment_methods'][1]['fee']);
    same('Recargo de pago: Pago de mentira cod', $t['fees'][0]['label']);
});

test('el mismo carrito con destino Canarias: cambian los tipos (IGIC) y el envío y el recargo siguen a las líneas', function (): void {
    /*
     * Mismos precios con impuestos dentro, así que el total no cambia (42,63 €) pero sí el reparto. A al 7 %: 21,78 =
     * 20,36 + 1,42. B al 3 %: 9,90 = 9,61 + 0,29. Envío 7,95 al tipo más alto de las líneas (7 %): 7,43 + 0,52.
     * Recargo 3,00 al 7 % (clase por defecto): 2,80 + 0,20. Base 40,20 €; impuestos 2,43 €.
     */
    $app = aifecCartApp();
    [$web] = cartTotalsFill($app);
    $app->service('payments')->register(aifecFakePayment('cod', 300));
    aifecCartRun($web, static function ($cart): void {
        $cart->setPaymentMethod('cod');
        $cart->setDestination(['country' => 'es', 'postcode' => '35001', 'region' => '', 'city' => 'Las Palmas']);
    });
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $t = $s['totals'];

    same('ES', $s['destination']['country']);
    same('35001', $s['destination']['postcode']);
    same(4263, $t['total']);
    same(4020, $t['total_net']);
    same(243, $t['total_tax']);
    same(743, $t['shipping']['net']);
    same(52, $t['shipping']['tax']);
    same(700, $t['shipping']['tax_rate']);
    same('IGIC 7 %', $t['shipping']['tax_name']);
    same([
        ['name' => 'IGIC 7 %', 'rate' => 700, 'base' => 3059, 'amount' => 214],
        ['name' => 'IGIC 3 %', 'rate' => 300, 'base' => 961, 'amount' => 29],
    ], $t['taxes'], 'IGIC 7 %: línea A + envío + recargo; IGIC 3 %: línea B');
    same([2036, 142], [array_values($s['lines'])[0]['net'], array_values($s['lines'])[0]['tax']]);
    same([961, 29], [array_values($s['lines'])[1]['net'], array_values($s['lines'])[1]['tax']]);
    same(280, $t['fees'][0]['net']);
    same(20, $t['fees'][0]['tax']);
});

test('con precios sin impuestos el IVA se suma encima: la base es lo que se escribió', function (): void {
    /*
     * A: 2 × 10,00 (21 %); B: 1 × 10,00 (10 %). Artículos 30,00. Cupón 10 %: 3,00 (2,00 a A, 1,00 a B).
     * A: 18,00 + 3,78 = 21,78. B: 9,00 + 0,90 = 9,90. Envío 7,95 (1300 g) + 21 % = 1,67 → 9,62.
     * Base 34,95; IVA 6,35; total 41,30.
     */
    $app = aifecCartApp(['prices_include_tax' => false]);
    [$web] = cartTotalsFill($app, 1000, 1000);
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $t = $s['totals'];

    same(false, $t['prices_include_tax']);
    same(3000, $t['items_total']);
    same(300, $t['discount_total']);
    same(795, $t['shipping']['net']);
    same(167, $t['shipping']['tax']);
    same(3495, $t['total_net']);
    same(635, $t['total_tax']);
    same(4130, $t['total']);
    $lines = array_values($s['lines']);
    same([1800, 378, 2178], [$lines[0]['net'], $lines[0]['tax'], $lines[0]['gross']]);
    same([900, 90, 990], [$lines[1]['net'], $lines[1]['tax'], $lines[1]['gross']]);
});

test('el precio cambia en el catálogo: el carrito enseña el nuevo, sin guardar precios en la fila', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'stock' => 10, 'tax_class_id' => 'standard']);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 2);
    });
    same(2000, aifecCartRun($web, static function ($cart): int {
        return array_values($cart->summary()['lines'])[0]['subtotal'];
    }));

    $app->db()->update('products', ['price' => 1500], ['id' => $p]);
    $line = aifecCartRun($web, static function ($cart): array {
        return array_values($cart->summary()['lines'])[0];
    });
    same(1500, $line['unit_price']);
    same(3000, $line['subtotal']);
    notContains('1500', (string) $app->db()->value('SELECT items FROM {carts}'));
    notContains('price', (string) $app->db()->value('SELECT items FROM {carts}'));
});

test('un producto deja de estar disponible: la línea avisa, no cuenta en el total y el problema impide comprar', function (): void {
    $app = aifecCartApp();
    $a = aifecProduct($app, ['name' => 'Gorra', 'price' => 1000, 'stock' => 10]);
    $b = aifecProduct($app, ['name' => 'Bufanda', 'price' => 2000, 'stock' => 10]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($a, $b): void {
        $cart->add($a, 0, 1);
        $cart->add($b, 0, 1);
    });
    $app->db()->update('products', ['status' => 'draft'], ['id' => $b]);

    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $gorra = array_values(array_filter($s['lines'], static function (array $l): bool {
        return $l['name'] === 'Gorra';
    }))[0];
    $bufanda = array_values(array_filter($s['lines'], static function (array $l): bool {
        return $l['name'] === 'Bufanda';
    }))[0];
    same([], $gorra['warnings']);
    same(['Este producto no está disponible'], $bufanda['warnings']);
    same(0, $bufanda['total']);
    same(false, $bufanda['available']);
    same(['Bufanda: Este producto no está disponible'], $s['problems']);
    same(1000, $s['totals']['items_total'], 'lo que no se puede comprar no suma');
    same(2, $s['count'], 'pero sigue en el carrito para que el cliente lo vea y lo quite');
});

test('la cantidad sobra tras bajar las existencias: aviso en la línea y en los problemas', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Taza', 'price' => 1000, 'stock' => 10]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 5);
    });
    $app->db()->update('products', ['stock' => 2], ['id' => $p]);

    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $line = array_values($s['lines'])[0];
    same(['Solo quedan 2 unidades'], $line['warnings']);
    same(2, $line['max_qty']);
    same(['Taza: Solo quedan 2 unidades'], $s['problems']);
});

test('un cupón que caduca sale del carrito, con su aviso, y el total se recalcula', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'stock' => 10, 'kind' => 'digital']);
    couponsMake($app, ['code' => 'VIERNES', 'ends_at' => '2026-11-13 12:00:00']);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
        $cart->applyCoupon('viernes');
    });
    $before = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(900, $before['totals']['total']);
    same('VIERNES', $before['coupons'][0]['code']);

    $app->clock()->advance(2 * 86400);
    $after = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same([], $after['coupons']);
    same(1000, $after['totals']['total']);
    same(['Se ha quitado el cupón VIERNES: Este cupón ha caducado'], $after['problems']);
    same('[]', $app->db()->value('SELECT coupons FROM {carts}'), 'el cupón ya no está en la fila');
});

test('envío: se puede elegir transportista; sin elegir sale el más barato y, si el elegido desaparece, vuelve a él', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'stock' => 10, 'weight' => 200]);
    $fast = shippingAddCarrier($app, 'rapido', ['name' => 'Rápido', 'position' => 5]);
    shippingAddRate($app, $fast, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 995);
    // El más barato va el último en el orden de la tienda: elegirlo no es quedarse con el primero.
    $eco = shippingAddCarrier($app, 'economico', ['name' => 'Económico', 'position' => 9]);
    shippingAddRate($app, $eco, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 295);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });

    $default = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(3, count($default['shipping_options']));
    same($eco, $default['carrier_id'], 'sin elegir: el más barato');
    same(295, $default['totals']['shipping']['price']);

    aifecCartRun($web, static function ($cart) use ($fast): void {
        $cart->setCarrier($fast);
    });
    $chosen = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same($fast, $chosen['carrier_id']);
    same(995, $chosen['totals']['shipping']['price']);
    same('Rápido', $chosen['totals']['shipping']['label']);

    $app->db()->update('carriers', ['active' => 0], ['id' => $fast]);
    $gone = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same($eco, $gone['carrier_id'], 'el elegido ya no se ofrece: vuelve al más barato');
    same(295, $gone['totals']['shipping']['price']);
    same(2, count($gone['shipping_options']));
});

test('solo productos digitales: no hace falta envío, no hay opciones ni problema', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 2100, 'kind' => 'digital', 'weight' => 900]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(false, $s['needs_shipping']);
    same([], $s['shipping_options']);
    same(null, $s['carrier_id']);
    same(null, $s['totals']['shipping']);
    same(0, $s['weight']);
    same(2100, $s['totals']['total']);
    same([], $s['problems']);

    // Un físico a su lado vuelve a pedir envío.
    $physical = aifecProduct($app, ['price' => 1000, 'weight' => 100]);
    aifecCartRun($web, static function ($cart) use ($physical): void {
        $cart->add($physical, 0, 1);
    });
    $mixed = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(true, $mixed['needs_shipping']);
    same(100, $mixed['weight'], 'solo pesa lo que se envía');
    same(495, $mixed['totals']['shipping']['price']);
});

test('envío gratis a partir de un importe (free_from) y destino sin cobertura', function (): void {
    $app = aifecCartApp();
    $app->db()->update('carriers', ['free_from' => 3000], ['code' => 'std']);
    $cheap = aifecProduct($app, ['price' => 1000, 'weight' => 100]);
    $dear = aifecProduct($app, ['price' => 3000, 'weight' => 100]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($cheap): void {
        $cart->add($cheap, 0, 1);
    });
    $small = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(495, $small['totals']['shipping']['price']);
    same(false, $small['shipping_options'][0]['free']);

    aifecCartRun($web, static function ($cart) use ($dear): void {
        $cart->add($dear, 0, 1);
    });
    $big = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(0, $big['totals']['shipping']['price']);
    same(true, $big['totals']['shipping']['free']);
    same(true, $big['shipping_options'][0]['free']);
    same(4000, $big['totals']['total'], 'solo los artículos');

    aifecCartRun($web, static function ($cart): void {
        $cart->setDestination(['country' => 'FR']);
    });
    $abroad = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same([], $abroad['shipping_options']);
    same(null, $abroad['totals']['shipping']);
    same(['No podemos enviar a este destino'], $abroad['problems'], 'sin cobertura la caja no deja terminar');
});

test('formas de pago: el orden del ajuste, el recargo de la elegida y las que no valen para ese país no salen', function (): void {
    $app = aifecCartApp(['settings' => ['orders.payment_order' => 'cod,fake']]);
    $cod = aifecFakePayment('cod', 300);
    $spain = aifecFakePayment('solo-es', 0);
    $spain->countries = ['ES'];
    $big = aifecFakePayment('grande', 0);
    $big->minTotal = 100000;
    $app->service('payments')->register($cod);
    $app->service('payments')->register($spain);
    $app->service('payments')->register($big);
    $p = aifecProduct($app, ['price' => 1000, 'kind' => 'digital']);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 1);
    });

    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(['cod', 'fake', 'solo-es'], array_column($s['payment_methods'], 'code'), 'primero el ajuste, después el orden de registro; la de importe mínimo no sale');
    same(null, $s['payment_method'], 'nada elegido todavía');
    same(1000, $s['totals']['total']);

    aifecCartRun($web, static function ($cart): void {
        $cart->setPaymentMethod('cod');
    });
    $withFee = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same('cod', $withFee['payment_method']);
    same(1300, $withFee['totals']['total'], 'el recargo entra como una línea más');
    same(300, $withFee['totals']['fee_total']);

    aifecCartRun($web, static function ($cart): void {
        $cart->setPaymentMethod('no-existe');
    });
    same(null, aifecCartRun($web, static function ($cart): ?string {
        return $cart->summary()['payment_method'];
    }), 'un código desconocido se olvida');

    aifecCartRun($web, static function ($cart): void {
        $cart->setPaymentMethod('solo-es');
        $cart->setDestination(['country' => 'PT']);
    });
    $abroad = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(['cod', 'fake'], array_column($abroad['payment_methods'], 'code'), 'la que solo vale en España ya no sale');
    same(null, $abroad['payment_method'], 'y si estaba elegida, deja de estarlo');

    $big->minTotal = 500;
    $p2 = aifecProduct($app, ['price' => 2000, 'kind' => 'digital']);
    aifecCartRun($web, static function ($cart) use ($p2): void {
        $cart->add($p2, 0, 1);
    });
    same(['cod', 'fake', 'grande'], array_column(aifecCartRun($web, static function ($cart): array {
        return $cart->summary()['payment_methods'];
    }), 'code'), 'con más importe, la de mínimo ya sale');
});

test('sin el módulo de cupones ni el de envíos el carrito funciona sin esa parte', function (): void {
    $app = testApp(['modules' => ['orders'], 'settings' => ['tax.prices_include_tax' => true]]);
    $app->boot();
    $app->service('payments')->register(aifecFakePayment());
    $p = aifecProduct($app, ['price' => 1210, 'tax_class_id' => 'standard', 'weight' => 400]);
    $web = new TestClient($app);
    $factory = new ReflectionProperty($app, 'factories');
    $factory->setAccessible(true);
    $all = $factory->getValue($app);
    unset($all['coupons'], $all['shipping']);
    $factory->setValue($app, $all);
    $services = new ReflectionProperty($app, 'services');
    $services->setAccessible(true);
    $loaded = $services->getValue($app);
    unset($loaded['coupons'], $loaded['shipping']);
    $services->setValue($app, $loaded);
    same(false, $app->has('coupons'));
    same(false, $app->has('shipping'));

    $added = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 1);
    });
    same(true, $added['ok']);
    $coupon = aifecCartRun($web, static function ($cart): array {
        return $cart->applyCoupon('OTONO');
    });
    same(false, $coupon['ok']);
    same('Los cupones no están disponibles', $coupon['error']);
    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(1210, $s['totals']['total']);
    same(null, $s['totals']['shipping']);
    same([], $s['shipping_options']);
    same([], $s['problems']);
});
