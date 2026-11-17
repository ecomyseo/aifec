<?php
/**
 * AIFEC — pruebas del pedido a mano (P103): los productos (buscar, variantes agotadas, unidades con el tope de las existencias,
 * quitar) y el «Precio de esta venta» (permiso propio, motivo, formato, y lo que queda apuntado).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Admin\ManualOrderPrices;

require_once __DIR__ . '/AdminManualOrderKit.php';

if (!function_exists('moProdFind')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function moProdFind(TestClient $web, string $q): array
    {
        $r = $web->get('/admin/orders/new/find/products?q=' . rawurlencode($q), ['accept' => 'application/json']);
        same(200, $r->status);
        $data = json_decode($r->body, true);
        check(is_array($data) && isset($data['results']), 'JSON con resultados');

        return $data['results'];
    }

    /** La clave de la única línea del borrador. */
    function moProdKey(TestClient $web): string
    {
        $q = moQuote($web);
        same(1, count($q['lines']), 'una línea');

        return (string) $q['lines'][0]['key'];
    }
}

test('el buscador de productos encuentra por nombre, por referencia (del producto o de una variante) y por código de barras', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    $byName = moProdFind($web, 'camisa');
    same(3, count($byName), 'las tres variantes de la camisa');
    same([(int) $s['shirt']], array_values(array_unique(array_map(static function (array $r): int {
        return (int) $r['product_id'];
    }, $byName))));
    same(1, count(moProdFind($web, 'LIB-1')), 'por la referencia');
    same((int) $s['book'], (int) moProdFind($web, '8400000000017')[0]['product_id'], 'por el código de barras');
    same((int) $s['shirt'], (int) moProdFind($web, 'CAM-M')[0]['product_id'], 'por la referencia de una variante');
    same([], moProdFind($web, '%'));
    same([], moProdFind($web, 'x'), 'con una letra no se busca');
});

test('la variante agotada sale deshabilitada con «Sin existencias» y no se puede añadir', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $found = [];
    foreach (moProdFind($web, 'camisa') as $row) {
        $found[(int) $row['variant_id']] = $row;
    }
    same(true, $found[$s['v']['CAM-L']]['disabled']);
    same('Sin existencias', $found[$s['v']['CAM-L']]['reason']);
    contains('Sin existencias', (string) $found[$s['v']['CAM-L']]['label']);
    same(false, $found[$s['v']['CAM-S']]['disabled']);
    contains('5 en existencias', (string) $found[$s['v']['CAM-S']]['label']);
    $r = moStep($web, 'line', ['product_id' => $s['shirt'], 'variant_id' => $s['v']['CAM-L'], 'qty' => 1], true);
    $data = json_decode($r->body, true);
    same(false, $data['ok']);
    same([], moQuote($web)['lines'], 'no se ha añadido');
    $page = $web->get('/admin/orders/new?pq=camisa');
    check(preg_match('/<button type="submit" class="af-btn af-btn-sm" disabled>Sin existencias<\/button>/', $page->body) === 1, 'sin JavaScript, el botón sale apagado');
    $none = json_decode(moStep($web, 'line', ['product_id' => $s['shirt'], 'variant_id' => 0, 'qty' => 1], true)->body, true);
    same(false, $none['ok'], 'un producto con variantes pide elegir una');
});

test('más unidades que existencias: error claro y la línea se queda como estaba', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moStep($web, 'line', ['product_id' => $s['shirt'], 'variant_id' => $s['v']['CAM-M'], 'qty' => 1]);
    $key = moProdKey($web);
    $data = json_decode(moStep($web, 'qty', ['key' => $key, 'qty' => 9], true)->body, true);
    same(false, $data['ok']);
    same('Solo quedan 3 unidades', (string) $data['message']);
    same(1, (int) moQuote($web)['lines'][0]['qty']);
    $add = json_decode(moStep($web, 'line', ['product_id' => $s['shirt'], 'variant_id' => $s['v']['CAM-M'], 'qty' => 5], true)->body, true);
    same(false, $add['ok'], 'añadir de más, tampoco');
    same(true, json_decode(moStep($web, 'qty', ['key' => $key, 'qty' => 3], true)->body, true)['ok'], 'hasta el tope, sí');
    same(3, (int) moQuote($web)['lines'][0]['qty']);
    contains('max="3"', $web->get('/admin/orders/new')->body, 'el campo lleva el tope');
});

test('quitar líneas; al quitar la última el pedido sigue con su cliente, su dirección y su pago', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moReady($web, $s, [[$s['mug'], 0, 1], [$s['book'], 0, 2]]);
    $lines = moQuote($web)['lines'];
    same(2, count($lines));
    $first = json_decode(moStep($web, 'remove', ['key' => (string) $lines[0]['key'], 'qty' => '1'], true)->body, true);
    same(true, $first['ok']);
    contains('Quitado: Taza', (string) $first['message']);
    moStep($web, 'remove', ['key' => (string) $lines[1]['key']]);
    $q = moQuote($web);
    same([], $q['lines']);
    same('Añade al menos un producto.', (string) $q['missing']['products']);
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    $again = moQuote($web);
    same(true, $again['ready'], 'cliente, dirección y pago siguen elegidos: ' . json_encode($again['missing']));
    same('in_person', $again['payment_method']);
    same(303, moStep($web, 'qty', ['key' => (string) $again['lines'][0]['key'], 'qty' => 0])->status);
    same([], moQuote($web)['lines'], 'cero unidades quita la línea');
});

test('el buscador de productos está acotado: como mucho 10 productos', function (): void {
    $s = moShop();
    $app = $s['app'];
    for ($i = 1; $i <= 12; $i++) {
        aifecProduct($app, ['name' => 'Vela aromática ' . $i, 'price' => 500, 'stock' => 3]);
    }
    $web = moClient($app);
    same(10, count(moProdFind($web, 'Vela')));
    same('10 resultados', json_decode($web->get('/admin/orders/new/find/products?q=Vela', ['accept' => 'application/json'])->body, true)['status']);
});

test('«Precio de esta venta» sin el permiso: el formulario no sale y mandarlo a mano da 403 (también al crear el pedido)', function (): void {
    $s = moShop();
    $app = $s['app'];
    $manager = moClient($app, 'manager');
    moStep($manager, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    $key = moProdKey($manager);
    notContains('Precio de esta venta', $manager->get('/admin/orders/new')->body, '«orders.*» no lo incluye: el gestor no lo ve');
    same(403, moStep($manager, 'price', ['key' => $key, 'price' => '5,00', 'reason' => 'Cliente de siempre'])->status);
    same(403, moStep($manager, 'price-clear', ['key' => $key])->status);
    same(null, moQuote($manager)['lines'][0]['price'], 'no se ha guardado nada');
    same([], moQuote($manager)['adjustments']);
});

test('«Precio de esta venta» con el permiso: exige motivo, rechaza lo negativo y lo raro, y el resumen lo cuenta con su línea', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    $key = moProdKey($web);
    moStep($web, 'qty', ['key' => $key, 'qty' => 2]);
    contains('Precio de esta venta', $web->get('/admin/orders/new')->body);
    $none = json_decode(moStep($web, 'price', ['key' => $key, 'price' => '9,50', 'reason' => ''], true)->body, true);
    same(false, $none['ok']);
    same('Escribe por qué esta venta lleva otro precio.', (string) $none['message']);
    foreach (['-5', '5,999', 'diez', '1e3', '', '5;00', '0x10'] as $bad) {
        $data = json_decode(moStep($web, 'price', ['key' => $key, 'price' => $bad, 'reason' => 'Prueba'], true)->body, true);
        same(false, $data['ok'], 'rechaza «' . $bad . '»');
    }
    same(null, moQuote($web)['lines'][0]['price']);
    $ok = json_decode(moStep($web, 'price', ['key' => $key, 'price' => '9,50', 'reason' => 'Cliente de siempre & amigo'], true)->body, true);
    same(true, $ok['ok']);
    $q = moQuote($web);
    same(950, (int) $q['lines'][0]['price']);
    same(2400, (int) $q['items'], 'el catálogo sigue a 12,00 €');
    same(-500, (int) $q['adjustments'][0]['amount'], '2 × (9,50 − 12,00)');
    same('Precio de esta venta: Taza', (string) $q['adjustments'][0]['label']);
    contains('Cliente de siempre &amp; amigo', $web->get('/admin/orders/new')->body, 'el motivo sale escapado');
    same(true, json_decode(moStep($web, 'price', ['key' => $key, 'price' => '0', 'reason' => 'Regalo'], true)->body, true)['ok'], 'cero vale (un regalo)');
    same(-2400, (int) moQuote($web)['adjustments'][0]['amount']);
    moStep($web, 'price-clear', ['key' => $key]);
    same([], moQuote($web)['adjustments'], 'vuelve al precio de catálogo');
});

test('«Precio de esta venta» queda en el pedido, en su historial y en el registro de actividad, y el total es el de ese precio', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $q = moReady($web, $s, [[$s['mug'], 0, 2]]);
    $key = (string) $q['lines'][0]['key'];
    moStep($web, 'price', ['key' => $key, 'price' => '10', 'reason' => 'Tara en el asa']);
    $q = moQuote($web);
    same(true, $q['ready']);
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    same($q['total'], (int) $order['total']);
    same(2000 + 495, (int) $order['total'], '2 × 10,00 € más el envío');
    $adjustment = null;
    foreach ($order['items'] as $item) {
        if ((string) $item['kind'] === 'discount' && !empty($item['data']['adjustment'])) {
            $adjustment = $item;
        }
    }
    check($adjustment !== null, 'la línea de ajuste está en el pedido');
    same('admin', (string) $adjustment['data']['source']);
    same(-400, (int) $adjustment['line_total']);
    $notes = implode("\n", array_map(static function (array $h): string {
        return (string) $h['note'];
    }, $order['history']));
    contains('Precio de esta venta para Taza: ' . money(1000) . ' en vez de ' . money(1200) . '. Motivo: Tara en el asa', $notes);
    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'order.price'");
    check($audit !== null, 'en el registro de actividad');
    contains('Tara en el asa', (string) $audit['changes']);
    same((string) $order['id'], (string) $audit['entity_id']);
});

test('un papel con «orders.price_override» por su nombre puede; si se lo quitan con un precio puesto, crear el pedido da 403', function (): void {
    $s = moShop();
    $app = $s['app'];
    adminOrdersRole($app, 'vendedor', ['orders.view', 'orders.create', 'orders.price_override']);
    $web = moClient($app, 'vendedor');
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $key = (string) moQuote($web)['lines'][0]['key'];
    same(303, moStep($web, 'price', ['key' => $key, 'price' => '11', 'reason' => 'Precio de feria'])->status);
    same(1100, (int) moQuote($web)['lines'][0]['price']);
    $app->db()->update('roles', ['caps' => json_encode(['orders.view', 'orders.create'])], ['code' => 'vendedor']);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {orders}');
    same(403, moPlace($web)->status);
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'), 'no se crea');
});

test('el ajuste de «Precio de esta venta» con precios sin impuestos: se cuenta sobre la base', function (): void {
    same(-500, ManualOrderPrices::difference(950, 2, 1200, 2100, true), 'con impuestos dentro: la diferencia tal cual');
    same(1570 - 2000, ManualOrderPrices::difference(950, 2, 1000, 2100, false), 'sin impuestos: 19,00 € con IVA son 15,70 € de base');
    same(0, ManualOrderPrices::difference(1200, 3, 1200, 2100, true));
    same('Camisa (M)', ManualOrderPrices::lineName(['name' => 'Camisa', 'variant_label' => 'M']));
    same('Taza', ManualOrderPrices::lineName(['name' => 'Taza', 'variant_label' => '']));
});
