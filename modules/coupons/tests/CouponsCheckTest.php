<?php
/**
 * AIFEC — pruebas del servicio de cupones: motivos de rechazo, forma del descuento, alcance y varios cupones a la vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CouponsKit.php';

test('el código vale en minúsculas y con espacios alrededor, y la etiqueta lleva el código guardado', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'OTONO']);
    couponsMake($app, ['code' => 'OTOÑO']);
    $svc = couponsService($app);

    $r = $svc->check("  otono \n", couponsCart());
    same(true, $r['ok']);
    same('', $r['error']);
    same('OTONO', $r['discount']['code']);
    same('Cupón OTONO', $r['discount']['label']);
    same('OTONO', $r['coupon']['code']);

    $r = $svc->check(' otoño ', couponsCart());
    same(true, $r['ok'], 'la eñe también se pasa a mayúsculas');
    same('Cupón OTOÑO', $r['discount']['label']);
});

test('un código vacío, uno que no existe y uno apagado se rechazan; los dos últimos con el mismo mensaje', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'APAGADO', 'active' => 0]);
    $svc = couponsService($app);

    $empty = $svc->check('   ', couponsCart());
    same(false, $empty['ok']);
    same('Escribe un código de cupón', $empty['error']);
    same(null, $empty['discount']);

    $missing = $svc->check('NOEXISTE', couponsCart());
    $off = $svc->check('apagado', couponsCart());
    same(false, $missing['ok']);
    same(false, $off['ok']);
    same('Este código de cupón no es válido', $missing['error']);
    same($missing['error'], $off['error'], 'no se cuenta qué códigos existen');
    same(null, $off['coupon'], 'del cupón apagado no sale ni la fila');
});

test('un texto raro como código no rompe nada: comillas, SQL, emojis y «&»', function (): void {
    $app = couponsTestApp();
    couponsMake($app);
    $svc = couponsService($app);
    foreach (["'; DROP TABLE af_coupons; --", 'OTONO" OR "1"="1', '🎁 & <b>', str_repeat('A', 500)] as $weird) {
        $r = $svc->check($weird, couponsCart());
        same(false, $r['ok'], 'rechaza: ' . $weird);
        same('Este código de cupón no es válido', $r['error']);
    }
    same(1, couponsCount($app, 'coupons'), 'la tabla sigue entera');
});

test('un cupón que todavía no ha empezado dice cuándo empieza', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['starts_at' => '2026-12-01 00:00:00']);
    $r = couponsService($app)->check('OTONO', couponsCart());
    same(false, $r['ok']);
    contains('Este cupón todavía no es válido. Empieza el', $r['error']);
    contains('2026', $r['error'], 'lleva la fecha');
    notContains('{date}', $r['error']);

    $app->clock()->set((int) strtotime('2026-12-01 00:00:00 UTC'));
    same(true, couponsService($app)->check('OTONO', couponsCart())['ok'], 'el mismo instante de inicio ya vale');
});

test('un cupón caducado se rechaza, y el último segundo todavía vale', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['ends_at' => '2026-11-12 12:00:00']);   // justo ahora
    $svc = couponsService($app);
    same(true, $svc->check('OTONO', couponsCart())['ok'], 'en el segundo exacto del fin vale');

    $app->clock()->advance(1);
    $r = $svc->check('OTONO', couponsCart());
    same(false, $r['ok']);
    same('Este cupón ha caducado', $r['error']);
});

test('un cupón sin usos disponibles se rechaza', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['max_uses' => 3, 'uses' => 2]);
    $svc = couponsService($app);
    same(true, $svc->check('OTONO', couponsCart())['ok'], 'queda un uso');

    $app->db()->update('coupons', ['uses' => 3], ['code' => 'OTONO']);
    $r = $svc->check('OTONO', couponsCart());
    same(false, $r['ok']);
    same('Este cupón se ha agotado', $r['error']);
});

test('los usos por cliente se cuentan por identificador y por correo', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses_per_customer' => 1]);
    couponsUse($app, $id, 1, 7, null);
    couponsUse($app, $id, 2, null, 'ana@tienda.test');
    $svc = couponsService($app);
    $msg = 'Ya has usado este cupón todas las veces permitidas';

    $r = $svc->check('OTONO', couponsCart(null, ['customer_id' => 7]));
    same(false, $r['ok'], 'el cliente 7 ya lo usó');
    same($msg, $r['error']);

    $r = $svc->check('OTONO', couponsCart(null, ['email' => ' ANA@Tienda.test ']));
    same(false, $r['ok'], 'el correo ya lo usó, aunque venga con mayúsculas y espacios');
    same($msg, $r['error']);

    same(true, $svc->check('OTONO', couponsCart(null, ['customer_id' => 8, 'email' => 'otra@tienda.test']))['ok'], 'otra persona sí');
    same(true, $svc->check('OTONO', couponsCart())['ok'], 'sin identificar todavía no se puede saber: vale');

    $app->db()->update('coupons', ['max_uses_per_customer' => 2], ['id' => $id]);
    same(true, $svc->check('OTONO', couponsCart(null, ['customer_id' => 7]))['ok'], 'con dos usos permitidos, uno gastado, vale');
});

test('un cupón de un cliente solo lo usa ese cliente', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['customer_id' => 5]);
    $svc = couponsService($app);
    $msg = 'Este cupón es de otro cliente';

    same($msg, $svc->check('OTONO', couponsCart(null, ['customer_id' => 6]))['error']);
    same($msg, $svc->check('OTONO', couponsCart())['error'], 'un invitado tampoco');
    same(true, $svc->check('OTONO', couponsCart(null, ['customer_id' => 5]))['ok']);
});

test('solo para el primer pedido: lo bloquean los usos de cualquier cupón, por cliente o por correo', function (): void {
    $app = couponsTestApp();
    $first = couponsMake($app, ['code' => 'BIENVENIDA', 'first_order_only' => 1]);
    $other = couponsMake($app, ['code' => 'OTRO']);
    $svc = couponsService($app);
    same(true, $svc->check('BIENVENIDA', couponsCart(null, ['customer_id' => 7, 'email' => 'ana@tienda.test']))['ok'], 'quien no ha usado nada, vale');

    couponsUse($app, $other, 1, 7, null);
    $r = $svc->check('BIENVENIDA', couponsCart(null, ['customer_id' => 7]));
    same(false, $r['ok'], 'el cliente 7 ya usó otro cupón');
    same('Este cupón solo vale para tu primer pedido', $r['error']);

    couponsUse($app, $other, 2, null, 'bea@tienda.test');
    same(false, $svc->check('BIENVENIDA', couponsCart(null, ['email' => 'BEA@tienda.test']))['ok'], 'y por correo');
    same(true, $svc->check('BIENVENIDA', couponsCart(null, ['customer_id' => 9, 'email' => 'nueva@tienda.test']))['ok']);
    same(true, $svc->check('BIENVENIDA', couponsCart())['ok'], 'sin identificar se deja pasar: se vuelve a mirar al hacer el pedido');
    check($first > 0);
});

test('solo para el primer pedido: también lo bloquea un pedido pagado, no uno pendiente', function (): void {
    $app = couponsTestApp();
    couponsMakeOrdersTable($app);
    couponsMake($app, ['first_order_only' => 1]);
    $svc = couponsService($app);
    $app->db()->insert('orders', ['number' => 'A-1', 'customer_id' => 3, 'email' => 'ana@tienda.test', 'payment_status' => 'pending']);
    same(true, $svc->check('OTONO', couponsCart(null, ['customer_id' => 3]))['ok'], 'un pedido sin pagar no cuenta');

    $app->db()->insert('orders', ['number' => 'A-2', 'customer_id' => 3, 'email' => 'ana@tienda.test', 'payment_status' => 'paid']);
    same(false, $svc->check('OTONO', couponsCart(null, ['customer_id' => 3]))['ok'], 'por cliente');
    same(false, $svc->check('OTONO', couponsCart(null, ['email' => 'ANA@tienda.test']))['ok'], 'por correo');
    same(true, $svc->check('OTONO', couponsCart(null, ['customer_id' => 4, 'email' => 'otra@tienda.test']))['ok']);
});

test('el importe mínimo dice cuánto falta', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['min_subtotal' => 20000]);   // 200,00 €; el carrito tiene 150,00 €
    $svc = couponsService($app);
    $r = $svc->check('OTONO', couponsCart());
    same(false, $r['ok']);
    $falta = $app->i18n()->money(5000, 'EUR');
    contains('50,00', $falta, 'el formato de dinero es el de la tienda');
    same('Añade ' . $falta . ' más a tu pedido para usar este cupón', $r['error']);

    $r = $svc->check('OTONO', couponsCart(null, ['items_total' => 19999]));
    same('Añade ' . $app->i18n()->money(1, 'EUR') . ' más a tu pedido para usar este cupón', $r['error'], 'falta un céntimo');
    same(true, $svc->check('OTONO', couponsCart(null, ['items_total' => 20000]))['ok'], 'justo el mínimo vale');
});

test('cada tipo da el descuento con la forma que espera Totals', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'DIEZ', 'kind' => 'percent', 'percent' => 1000]);
    couponsMake($app, ['code' => 'CINCO', 'kind' => 'fixed', 'amount' => 500]);
    couponsMake($app, ['code' => 'GRATIS', 'kind' => 'free_shipping']);
    $svc = couponsService($app);

    same(['kind' => 'percent', 'percent' => 1000, 'code' => 'DIEZ', 'label' => 'Cupón DIEZ'], $svc->check('DIEZ', couponsCart())['discount']);
    same(['kind' => 'fixed', 'amount' => 500, 'code' => 'CINCO', 'label' => 'Cupón CINCO'], $svc->check('CINCO', couponsCart())['discount']);
    same(['kind' => 'free_shipping', 'code' => 'GRATIS', 'label' => 'Cupón GRATIS'], $svc->check('GRATIS', couponsCart())['discount']);
});

test('el descuento de cada tipo, pasado por Totals, da el total hecho a mano', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'DIEZ', 'kind' => 'percent', 'percent' => 1000]);
    couponsMake($app, ['code' => 'CINCO', 'kind' => 'fixed', 'amount' => 500]);
    couponsMake($app, ['code' => 'GRATIS', 'kind' => 'free_shipping']);
    couponsMake($app, ['code' => 'ENORME', 'kind' => 'fixed', 'amount' => 99900]);
    $svc = couponsService($app);
    $envio = ['label' => 'GLS 24 h', 'price' => 495, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'];

    // Carrito: 10000 + 2 × 2500 = 15000 con IVA dentro. 10 % → 1500 de descuento → 13500.
    $t = couponsTotals([$svc->check('DIEZ', couponsCart())['discount']]);
    same(1500, $t['discount_total']);
    same(13500, $t['total']);
    same('Cupón DIEZ', $t['discounts'][0]['label'], 'el importe sale en el desglose con su nombre');
    same(1500, $t['discounts'][0]['amount']);

    // Fijo de 5,00 € → 15000 − 500 = 14500.
    $t = couponsTotals([$svc->check('CINCO', couponsCart())['discount']]);
    same(500, $t['discount_total']);
    same(14500, $t['total']);

    // Envío gratis: 15000 + 495 = 15495 sin cupón; con cupón el envío vale 0 y el total es 15000.
    same(15495, couponsTotals([], $envio)['total']);
    $t = couponsTotals([$svc->check('GRATIS', couponsCart())['discount']], $envio);
    same(15000, $t['total']);
    same(true, $t['shipping']['free']);

    // Un fijo mayor que el carrito nunca lo deja en negativo: 15000 − 15000 = 0.
    $t = couponsTotals([$svc->check('ENORME', couponsCart())['discount']]);
    same(15000, $t['discount_total']);
    same(0, $t['total']);
});

test('limitado a productos: keys solo con las líneas que entran, y Totals descuenta solo de ellas', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['kind' => 'percent', 'percent' => 2000, 'products' => json_encode([2])]);
    $svc = couponsService($app);
    $r = $svc->check('OTONO', couponsCart());
    same(true, $r['ok']);
    same(['p2'], $r['discount']['keys']);

    // 20 % de las líneas p2 (2 × 2500 = 5000) = 1000 → 15000 − 1000 = 14000; p1 no se toca.
    $t = couponsTotals([$r['discount']]);
    same(1000, $t['discount_total']);
    same(14000, $t['total']);
    same(0, $t['lines']['p1']['discount']);
    same(1000, $t['lines']['p2']['discount']);
});

test('limitado a categorías, a ambas cosas a la vez, y sin límites no hay keys', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['categories' => json_encode([10])]);
    $svc = couponsService($app);
    same(['p1'], $svc->check('OTONO', couponsCart())['discount']['keys'], 'por categoría');

    $app->db()->update('coupons', ['products' => json_encode([2])], ['id' => $id]);
    same(['p1', 'p2'], $svc->check('OTONO', couponsCart())['discount']['keys'], 'con productos Y categorías entra lo que coincida con una de las dos');

    $app->db()->update('coupons', ['products' => '[]', 'categories' => '[]'], ['id' => $id]);
    $r = $svc->check('OTONO', couponsCart());
    same(true, $r['ok']);
    check(!array_key_exists('keys', $r['discount']), 'vacías = todo el carrito, sin keys');

    $app->db()->update('coupons', ['products' => 'esto no es json', 'categories' => null], ['id' => $id]);
    $r = $svc->check('OTONO', couponsCart());
    same(true, $r['ok'], 'un dato roto cuenta como vacío');
    check(!array_key_exists('keys', $r['discount']));
});

test('sin artículos en oferta: las líneas rebajadas quedan fuera', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['exclude_sale' => 1]);
    $svc = couponsService($app);
    $cart = couponsCart([couponsLine('p1', 1, 10000, 1, [10], true), couponsLine('p2', 2, 2500, 2, [20])]);
    $r = $svc->check('OTONO', $cart);
    same(true, $r['ok']);
    same(['p2'], $r['discount']['keys']);

    $todo = couponsCart([couponsLine('p1', 1, 10000, 1, [10], true), couponsLine('p2', 2, 2500, 2, [20], true)]);
    $r = $svc->check('OTONO', $todo);
    same(false, $r['ok']);
    same('Este cupón no se puede aplicar a ningún artículo de tu carrito', $r['error']);
});

test('si ningún artículo entra en el cupón, se rechaza', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['products' => json_encode([99])]);
    $svc = couponsService($app);
    $msg = 'Este cupón no se puede aplicar a ningún artículo de tu carrito';
    same($msg, $svc->check('OTONO', couponsCart())['error'], 'producto que no está');

    $app->db()->update('coupons', ['products' => null, 'categories' => json_encode([77])], ['id' => $id]);
    same($msg, $svc->check('OTONO', couponsCart())['error'], 'categoría que no está');

    same($msg, $svc->check('OTONO', couponsCart([]))['error'], 'carrito vacío');
    same($msg, $svc->check('OTONO', couponsCart([couponsLine('p1', 1, 0, 1, [77])]))['error'], 'una línea gratis tampoco cuenta');
});

test('varios cupones: valen juntos si todos son combinables', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'UNO', 'combinable' => 1]);
    couponsMake($app, ['code' => 'DOS', 'combinable' => 1, 'kind' => 'fixed', 'amount' => 500]);
    $r = couponsService($app)->combine(['uno', ' dos '], couponsCart());
    same([], $r['rejected']);
    same(2, count($r['accepted']));
    same(['UNO', 'DOS'], array_map(static function (array $d): string {
        return $d['code'];
    }, $r['discounts']));

    // Uno detrás de otro sobre lo que va quedando: 15000 − 10 % (1500) = 13500; − 500 = 13000.
    same(13000, couponsTotals($r['discounts'])['total']);
});

test('varios cupones: uno que no es combinable se queda solo y se explica por qué se descarta el otro', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'SOLO', 'combinable' => 0]);
    couponsMake($app, ['code' => 'SUMA', 'combinable' => 1]);
    couponsMake($app, ['code' => 'OTRO', 'combinable' => 0]);
    $svc = couponsService($app);
    $msg = 'Este cupón no se puede sumar a otros cupones';

    $r = $svc->combine(['SOLO', 'SUMA'], couponsCart());
    same(['SOLO'], array_map(static function (array $a): string {
        return $a['coupon']['code'];
    }, $r['accepted']), 'se queda el primero');
    same([['code' => 'SUMA', 'error' => $msg]], $r['rejected']);

    $r = $svc->combine(['SUMA', 'SOLO'], couponsCart());
    same(1, count($r['accepted']), 'el combinable primero tampoco deja entrar al que no lo es');
    same([['code' => 'SOLO', 'error' => $msg]], $r['rejected']);

    $r = $svc->combine(['SOLO', 'OTRO'], couponsCart());
    same([['code' => 'OTRO', 'error' => $msg]], $r['rejected'], 'dos no combinables: el primero');
});

test('varios cupones: los inválidos y los repetidos no estorban y llevan su motivo', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'BUENO', 'combinable' => 1]);
    couponsMake($app, ['code' => 'VIEJO', 'combinable' => 1, 'ends_at' => '2026-01-01 00:00:00']);
    $r = couponsService($app)->combine(['VIEJO', 'bueno', 'BUENO', '', 'NOHAY'], couponsCart());
    same(1, count($r['accepted']));
    same('BUENO', $r['accepted'][0]['coupon']['code']);
    same([['code' => 'VIEJO', 'error' => 'Este cupón ha caducado'], ['code' => 'NOHAY', 'error' => 'Este código de cupón no es válido']], $r['rejected']);
    same([], couponsService($app)->combine([], couponsCart())['accepted'], 'sin códigos, nada');
});

test('un código nuevo generado es libre, tiene el formato y no repite uno existente', function (): void {
    $app = couponsTestApp();
    $svc = couponsService($app);
    $seen = [];
    for ($i = 0; $i < 30; ++$i) {
        $code = $svc->generateCode();
        same(1, preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code), 'formato de ' . $code);
        $seen[$code] = true;
        couponsMake($app, ['code' => $code]);
    }
    same(30, count($seen), 'ninguno repetido');
});
