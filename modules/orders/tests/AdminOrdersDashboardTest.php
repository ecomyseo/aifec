<?php
/**
 * AIFEC — pruebas de lo que los pedidos ponen en el inicio del panel: ventas, por preparar, gráfica, avisos de estado y
 * buscador de órdenes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminKit.php';

test('las cifras del inicio coinciden con los pedidos creados y uno de pruebas no cuenta', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPlace($app, 'gw', [[$p, 0, 1]]);
    aifecOrderPayment($app)->test = true;
    adminOrdersPaid($app, 'gw', [[$p, 0, 3]]);
    aifecOrderPayment($app)->test = false;

    $web = adminClient($app);
    $r = $web->get('/admin');
    same(200, $r->status);
    $body = $r->body;
    preg_match_all('/<div class="af-stat-value">([^<]*)<\/div>/u', $body, $values);
    same([$money(4495 + 2495), $money(4495 + 2495), $money(4495 + 2495)], $values[1], 'hoy, 7 y 30 días llevan lo cobrado sin el pedido de pruebas ni el sin pagar');
    notContains($money(4495 + 2495 + 6495), $body, 'el de pruebas no cuenta');
    preg_match_all('/<div class="af-stat-note">(\d+) pedidos/u', $body, $counts);
    same(['2', '2', '2'], $counts[1], 'el número de pedidos de cada periodo');
    contains('Ventas de hoy', $body);
    contains('Ventas de los últimos 7 días', $body);
    contains('Ventas de los últimos 30 días', $body);
    contains('Ayer no se vendió nada', $body, 'sin ventas el día anterior se dice');

    // Quien no puede ver pedidos no ve nada de esto.
    $editor = adminClient($app, 'editor')->get('/admin')->body;
    notContains('Ventas de hoy', $editor);
    notContains($money(4495 + 2495), $editor);
});

test('un cero con pedidos de prueba detrás dice que esos pedidos no cuentan', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $web = adminClient($app);
    notContains('de prueba, que no cuenta', $web->get('/admin')->body, 'sin pedidos de prueba no se dice nada');
    $app->invalidate(['orders']);
    aifecOrderPayment($app)->test = true;
    adminOrdersPaid($app, 'gw', [[$p, 0, 3]]);
    aifecOrderPayment($app)->test = false;
    $body = $web->get('/admin')->body;
    same(3, substr_count($body, '0 pedidos · 1 pedido de prueba, que no cuenta'), 'hoy, 7 y 30 días: el cero lleva su explicación');
    notContains('6.495', $body, 'y el pedido de pruebas sigue sin sumar');
});

test('cada cifra se compara con el periodo anterior', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $app->clock()->advance(-86400);
    adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $app->clock()->advance(86400);
    adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    contains($money(4495), $body, 'hoy');
    contains('+80 %', $body, 'frente a ayer (24,95 € → 44,95 €)');
    contains('frente a ' . $money(2495) . ' de ayer', $body);
    contains($money(4495 + 2495), $body, 'en 7 y 30 días suma los dos');
    contains('En los 7 días anteriores no se vendió nada', $body);
});

test('la gráfica es un SVG pintado en el servidor con 30 barras y un título por barra', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $app->clock()->advance(-3 * 86400);
    adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $app->clock()->advance(3 * 86400);
    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    check(preg_match('/<svg viewBox="0 0 600 160".*?<\/svg>/s', $body, $m) === 1, 'hay una gráfica');
    $svg = $m[0];
    same(30, substr_count($svg, '<rect'), '30 barras, una por día');
    same(30, substr_count($svg, '<title>'), 'un título por barra');
    same(2, preg_match_all('/<title>[^<]*(?:44,95|24,95)[^<]*<\/title>/u', $svg), 'los dos días con ventas enseñan su importe');
    notContains('<script', $svg);
    contains('role="img"', $svg);
});

test('por preparar enseña los pedidos pagados sin enviar, el más antiguo primero, con enlace al listado filtrado', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['stock' => 50]);
    $ids = [];
    for ($i = 0; $i < 10; ++$i) {
        $app->clock()->advance(60);
        $ids[] = adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['email' => 'cliente' . $i . '@cliente.test']);
    }
    $shipped = adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['email' => 'enviado@cliente.test']);
    $app->service('orders')->setStatus($shipped, 'shipped', '', null, false);
    adminOrdersPlace($app, 'gw', [[$p, 0, 1]], ['email' => 'sinpagar@cliente.test']);

    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    $first = strpos($body, adminOrdersNumber($app, $ids[0]));
    $eighth = strpos($body, adminOrdersNumber($app, $ids[7]));
    check($first !== false && $eighth !== false && $first < $eighth, 'salen los más antiguos, en orden');
    same(false, strpos($body, adminOrdersNumber($app, $ids[8])), 'solo los 8 más antiguos');
    notContains('enviado@cliente.test', $body, 'un pedido enviado no sale');
    notContains('sinpagar@cliente.test', $body, 'uno sin pagar tampoco');
    contains('/admin/orders/' . $ids[0], $body, 'cada pedido enlaza a su ficha');
    contains('f%5Bprepare%5D=1', $body, 'Ver todos lleva al listado filtrado');
});

test('los avisos de estado hablan de pedidos en espera más de 3 días y de cobrados sin enviar más de 2', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPlace($app, 'transfer', [[$p, 0, 1]]);
    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    notContains('más de 3 días en espera', $body, 'recién hechos, no hay aviso');
    notContains('sin enviarse', $body);

    $app->clock()->advance(3 * 86400 + 60);
    $late = adminClient($app, 'manager');
    $body = $late->get('/admin')->body;
    contains('1 pedido lleva más de 3 días en espera.', $body);
    contains('1 pedido cobrado lleva más de 2 días sin enviarse.', $body);
    contains('f%5Bstatus%5D=on_hold', $body, 'enlace al filtro de en espera');
    contains('f%5Bprepare%5D=1', $body);
});

test('el buscador de órdenes encuentra pedidos por número o por correo, solo para quien puede verlos', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['email' => 'busca@cliente.test']);
    adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['email' => 'otro@cliente.test']);
    $number = adminOrdersNumber($app, $id);
    $web = adminClient($app);

    $items = json_decode($web->get('/admin/palette.json?q=' . rawurlencode($number))->body, true)['items'];
    $urls = array_column($items, 'url');
    check(in_array('/admin/orders/' . $id, $urls, true), 'por número');
    $byMail = json_decode($web->get('/admin/palette.json?q=busca@cliente')->body, true)['items'];
    same(1, count(array_filter($byMail, static function (array $item): bool {
        return strpos($item['url'], '/admin/orders/') === 0;
    })), 'por correo, solo el suyo');
    $labels = implode(' ', array_column($byMail, 'label'));
    contains('busca@cliente.test', $labels);
    $percent = json_decode($web->get('/admin/palette.json?q=%25')->body, true)['items'];
    same(0, count(array_filter($percent, static function (array $item): bool {
        return strpos($item['url'], '/admin/orders/') === 0;
    })), 'un «%» no es un comodín');

    $editor = json_decode(adminClient($app, 'editor')->get('/admin/palette.json?q=' . rawurlencode($number))->body, true)['items'];
    same([], array_filter(array_column($editor, 'url'), static function (string $url): bool {
        return strpos($url, '/admin/orders/') === 0;
    }), 'sin permiso no sale ningún pedido');
});

test('pagar o devolver vacía la caché de las cifras en el mismo momento', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    $web = adminClient($app);
    contains($money(0), $web->get('/admin')->body, 'sin ventas, cero euros');
    $versionBefore = $app->cache()->tagVersion('orders');

    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $versionPaid = $app->cache()->tagVersion('orders');
    check($versionPaid > $versionBefore, 'cobrar invalida la etiqueta «orders»');
    contains($money(4495), $web->get('/admin')->body, 'al cobrar, las cifras se ponen al día sin esperar 5 minutos');

    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => '44,95', 'gateway' => '1']);
    same(302, $r->status);
    same('refunded', $app->service('orders')->find($id)['payment_status']);
    check($app->cache()->tagVersion('orders') > $versionPaid, 'devolver también la invalida');
});
