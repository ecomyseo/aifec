<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Admin\ReportsAdmin;

require_once __DIR__ . '/AdminPanelKit.php';

/*
 * «Ventas › Informes» (/admin/reports). Pedidos sembrados a mano, con las cifras sabidas de antemano. La hora de las pruebas
 * es el 12/11/2026 a las 12:00 UTC y la tienda está en Europe/Madrid (UTC+1 en noviembre, UTC+2 en julio).
 */

if (!function_exists('reportsKitApp')) {
    function reportsKitApp(): App
    {
        $app = testApp(['modules' => ['orders']]);
        $app->boot();

        return $app;
    }

    /**
     * Un pedido escrito directamente en la base. paid = fecha del cobro en UTC («AAAA-MM-DD HH:MM:SS») o null.
     *
     * @param array<string,mixed> $o total, net, paid, currency, method, title, country, email, test, status, items [[id, nombre, unidades, importe]]
     */
    function reportsKitOrder(App $app, array $o): int
    {
        $db = $app->db();
        $paid = isset($o['paid']) ? (string) $o['paid'] : null;
        $placed = isset($o['placed']) ? (string) $o['placed'] : ($paid !== null ? $paid : '2026-11-01 10:00:00');
        $total = isset($o['total']) ? (int) $o['total'] : 1000;
        $net = isset($o['net']) ? (int) $o['net'] : $total;
        $number = 'INF-' . ((int) $db->value('SELECT COUNT(*) FROM {orders}') + 1);
        $id = $db->insert('orders', [
            'number' => $number,
            'email' => isset($o['email']) ? (string) $o['email'] : 'ana@cliente.test',
            'currency' => isset($o['currency']) ? (string) $o['currency'] : 'EUR',
            'status' => isset($o['status']) ? (string) $o['status'] : ($paid !== null ? 'processing' : 'pending_payment'),
            'payment_status' => $paid !== null ? 'paid' : 'pending',
            'payment_method' => isset($o['method']) ? (string) $o['method'] : 'transfer',
            'payment_title' => isset($o['title']) ? (string) $o['title'] : 'Transferencia bancaria',
            'total' => $total, 'total_net' => $net, 'total_tax' => $total - $net, 'paid_total' => $paid !== null ? $total : 0,
            'billing' => (string) json_encode(['first_name' => 'Ana', 'country' => isset($o['country']) ? (string) $o['country'] : 'ES', 'city' => 'Madrid']),
            'test' => !empty($o['test']) ? 1 : 0,
            'placed_at' => $placed, 'paid_at' => $paid, 'created_at' => $placed, 'updated_at' => $placed,
        ]);
        $position = 0;
        foreach (isset($o['items']) && is_array($o['items']) ? $o['items'] : [[1, 'Silla', 1, $total]] as $item) {
            $db->insert('order_items', [
                'order_id' => $id, 'kind' => 'product', 'product_id' => (int) $item[0], 'name' => (string) $item[1], 'qty' => (int) $item[2],
                'unit_price' => (int) round($item[3] / max(1, $item[2])), 'line_total' => (int) $item[3], 'line_net' => (int) $item[3], 'position' => $position++,
            ]);
        }

        return $id;
    }

    function reportsKitRefund(App $app, int $order, int $amount, string $when): void
    {
        $app->db()->insert('order_refunds', ['order_id' => $order, 'amount' => $amount, 'created_at' => $when]);
    }

    /**
     * El mes de noviembre sembrado a mano (periodo del 1 al 10): tres pedidos cobrados dentro (dos en euros y uno en
     * dólares) y todo lo que NO tiene que contar: pendiente, cancelado sin cobrar, de pruebas, cobrado antes y después.
     */
    function reportsKitNovember(App $app): void
    {
        $ana = reportsKitOrder($app, ['paid' => '2026-11-02 10:00:00', 'total' => 12100, 'net' => 10000, 'email' => 'ana@cliente.test', 'country' => 'ES',
            'method' => 'transfer', 'title' => 'Transferencia bancaria', 'items' => [[1, 'Silla & Cía', 2, 12100]]]);
        reportsKitOrder($app, ['paid' => '2026-11-05 09:00:00', 'total' => 6050, 'net' => 5000, 'email' => 'beto@cliente.test', 'country' => 'PT',
            'method' => 'redsys', 'title' => 'Pago con tarjeta', 'items' => [[2, 'Mesa', 1, 6050]]]);
        reportsKitOrder($app, ['paid' => '2026-11-07 18:00:00', 'total' => 5000, 'net' => 5000, 'email' => 'carla@cliente.test', 'country' => 'US',
            'currency' => 'USD', 'method' => 'paypal', 'title' => 'PayPal', 'items' => [[3, 'Lámpara', 1, 5000]]]);
        // Lo que no cuenta.
        $before = reportsKitOrder($app, ['paid' => '2026-10-20 10:00:00', 'total' => 1000, 'email' => 'beto@cliente.test']);
        reportsKitOrder($app, ['placed' => '2026-11-03 10:00:00', 'total' => 99900, 'email' => 'dani@cliente.test']);
        reportsKitOrder($app, ['placed' => '2026-11-04 10:00:00', 'total' => 88800, 'status' => 'cancelled', 'email' => 'eva@cliente.test']);
        reportsKitOrder($app, ['paid' => '2026-11-06 10:00:00', 'total' => 77700, 'test' => true, 'email' => 'fede@cliente.test']);
        reportsKitOrder($app, ['paid' => '2026-11-11 10:00:00', 'total' => 66600, 'email' => 'gema@cliente.test']);
        reportsKitRefund($app, $ana, 2000, '2026-11-08 12:00:00');
        reportsKitRefund($app, $before, 500, '2026-10-25 12:00:00');
    }

    /** @return array<string,mixed> */
    function reportsKitPeriod(App $app, string $from = '2026-11-01', string $to = '2026-11-10'): array
    {
        return ReportsAdmin::period($app, 'custom', $from, $to);
    }

    /** El CSV sin la marca BOM, en filas. @return array<int,string[]> */
    function reportsKitCsv(string $body): array
    {
        check(strpos($body, "\xEF\xBB\xBF") === 0, 'el CSV empieza con la marca BOM (lo abre bien una hoja de cálculo)');
        $lines = preg_split('/\r?\n/', trim(substr($body, 3)));

        return array_map(static function (string $line): array {
            return str_getcsv($line, ';', '"', '\\');
        }, is_array($lines) ? $lines : []);
    }
}

test('el resumen cuenta solo lo cobrado en el periodo: ventas con y sin impuestos, pedidos, ticket medio y unidades, por moneda', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    same([
        ['currency' => 'EUR', 'n' => 2, 'gross' => 18150, 'net' => 15000, 'units' => 3, 'average' => 9075],
        ['currency' => 'USD', 'n' => 1, 'gross' => 5000, 'net' => 5000, 'units' => 1, 'average' => 5000],
    ], ReportsAdmin::summary($app, reportsKitPeriod($app)), 'ni el pendiente, ni el cancelado, ni el de pruebas, ni lo de fuera; y sin sumar monedas');
});

test('lo más vendido: los productos por unidades y luego por importe, cada uno en su moneda', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $rows = ReportsAdmin::products($app, reportsKitPeriod($app));
    same(['Silla & Cía', 'Mesa', 'Lámpara'], array_column($rows, 'name'));
    same([2, 1, 1], array_column($rows, 'units'));
    same([12100, 6050, 5000], array_column($rows, 'amount'));
    same(['EUR', 'EUR', 'USD'], array_column($rows, 'currency'));
});

test('lo más vendido se queda en diez', function (): void {
    $app = reportsKitApp();
    for ($i = 1; $i <= 12; $i++) {
        reportsKitOrder($app, ['paid' => '2026-11-03 10:00:00', 'total' => 1000 * $i, 'items' => [[$i, 'Producto ' . $i, $i, 1000 * $i]]]);
    }
    $rows = ReportsAdmin::products($app, reportsKitPeriod($app));
    same(10, count($rows));
    same('Producto 12', $rows[0]['name'], 'el que más unidades vendió, primero');
    same('Producto 3', $rows[9]['name']);
});

test('ventas por forma de pago y por país, de más a menos', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $period = reportsKitPeriod($app);
    same([
        ['method' => 'transfer', 'title' => 'Transferencia bancaria', 'currency' => 'EUR', 'n' => 1, 'amount' => 12100],
        ['method' => 'redsys', 'title' => 'Pago con tarjeta', 'currency' => 'EUR', 'n' => 1, 'amount' => 6050],
        ['method' => 'paypal', 'title' => 'PayPal', 'currency' => 'USD', 'n' => 1, 'amount' => 5000],
    ], ReportsAdmin::methods($app, $period));
    same([
        ['country' => 'ES', 'currency' => 'EUR', 'n' => 1, 'amount' => 12100],
        ['country' => 'PT', 'currency' => 'EUR', 'n' => 1, 'amount' => 6050],
        ['country' => 'US', 'currency' => 'USD', 'n' => 1, 'amount' => 5000],
    ], ReportsAdmin::countries($app, $period));
});

test('un pedido sin país en la dirección sale como «—», sin inventarlo', function (): void {
    $app = reportsKitApp();
    $id = reportsKitOrder($app, ['paid' => '2026-11-03 10:00:00', 'total' => 2500]);
    $app->db()->update('orders', ['billing' => null], ['id' => $id]);
    same([['country' => '', 'currency' => 'EUR', 'n' => 1, 'amount' => 2500]], ReportsAdmin::countries($app, reportsKitPeriod($app)));
    $web = adminClient($app);
    $body = $web->get('/admin/reports?period=custom&from=2026-11-01&to=2026-11-10')->body;
    $table = substr($body, (int) strpos($body, 'data-af-report="countries"'));
    contains('<td>—</td>', substr($table, 0, (int) strpos($table, '</table>')));
});

test('clientes nuevos y que repiten, y devoluciones del periodo por la fecha de la devolución', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $period = reportsKitPeriod($app);
    same(['buyers' => 3, 'new' => 2, 'returning' => 1], ReportsAdmin::customers($app, $period), 'beto ya había pagado en octubre');
    same([['currency' => 'EUR', 'n' => 1, 'amount' => 2000]], ReportsAdmin::refunds($app, $period), 'la devolución de octubre no cuenta');
});

test('ventas por día: un punto por día del periodo, también los vacíos, solo de la moneda de la tienda', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $series = ReportsAdmin::series($app, reportsKitPeriod($app), 'EUR');
    same('day', $series['by']);
    same(10, count($series['points']));
    same(['key' => '2026-11-02', 'n' => 1, 'amount' => 12100], $series['points'][1]);
    same(['key' => '2026-11-05', 'n' => 1, 'amount' => 6050], $series['points'][4]);
    same(['key' => '2026-11-07', 'n' => 0, 'amount' => 0], $series['points'][6], 'el pedido en dólares no entra en la serie en euros');
    same(18150, array_sum(array_column($series['points'], 'amount')), 'la serie suma lo mismo que el resumen');
});

test('el borde del periodo: las 00:00:00 y las 23:59:59 son de la zona horaria de la tienda, en invierno y en verano', function (): void {
    $app = reportsKitApp();
    // 5 de noviembre en Madrid = del 4 a las 23:00:00 UTC al 5 a las 22:59:59 UTC.
    reportsKitOrder($app, ['paid' => '2026-11-04 22:59:59', 'total' => 100]);
    reportsKitOrder($app, ['paid' => '2026-11-04 23:00:00', 'total' => 200]);
    reportsKitOrder($app, ['paid' => '2026-11-05 22:59:59', 'total' => 400]);
    reportsKitOrder($app, ['paid' => '2026-11-05 23:00:00', 'total' => 800]);
    $day = ReportsAdmin::period($app, 'custom', '2026-11-05', '2026-11-05');
    same('2026-11-04 23:00:00', $day['start']);
    same('2026-11-05 22:59:59', $day['end']);
    same(600, ReportsAdmin::summary($app, $day)[0]['gross'], 'cuentan el de las 00:00:00 y el de las 23:59:59, y nada más');
    same([['key' => '2026-11-05', 'n' => 2, 'amount' => 600]], ReportsAdmin::series($app, $day, 'EUR')['points']);
    same(100, ReportsAdmin::summary($app, ReportsAdmin::period($app, 'custom', '2026-11-04', '2026-11-04'))[0]['gross'], 'el de las 23:59:59 del día antes es del día antes');
    same(800, ReportsAdmin::summary($app, ReportsAdmin::period($app, 'custom', '2026-11-06', '2026-11-06'))[0]['gross'], 'y el de las 00:00:00 del siguiente, del siguiente');

    // En verano, Madrid va dos horas por delante.
    reportsKitOrder($app, ['paid' => '2026-07-14 21:59:59', 'total' => 10]);
    reportsKitOrder($app, ['paid' => '2026-07-14 22:00:00', 'total' => 20]);
    $summer = ReportsAdmin::period($app, 'custom', '2026-07-15', '2026-07-15');
    same('2026-07-14 22:00:00', $summer['start']);
    same(20, ReportsAdmin::summary($app, $summer)[0]['gross']);
});

test('los periodos de la pantalla: hoy, 7 días, 30 días, este mes, el mes pasado y entre dos fechas', function (): void {
    $app = reportsKitApp();
    $cases = [
        'today' => ['2026-11-12', '2026-11-12', 1],
        '7d' => ['2026-11-06', '2026-11-12', 7],
        '30d' => ['2026-10-14', '2026-11-12', 30],
        'month' => ['2026-11-01', '2026-11-12', 12],
        'last_month' => ['2026-10-01', '2026-10-31', 31],
        'cualquier-cosa' => ['2026-10-14', '2026-11-12', 30],
    ];
    foreach ($cases as $key => $expected) {
        $p = ReportsAdmin::period($app, $key);
        same($expected, [$p['from'], $p['to'], $p['days']], $key);
    }
    $swapped = ReportsAdmin::period($app, 'custom', '2026-11-10', '2026-11-01');
    same(['2026-11-01', '2026-11-10', false], [$swapped['from'], $swapped['to'], $swapped['invalid']], 'dos fechas al revés se dan la vuelta');
    foreach (['2026-02-30', 'ayer', '', '2026-1-5', "2026-11-01'--"] as $bad) {
        $p = ReportsAdmin::period($app, 'custom', $bad, '2026-11-10');
        check($p['invalid'] && $p['key'] === '30d', 'una fecha que no vale cae en los 30 días: ' . $bad);
    }
});

test('un periodo de más de dos años se recorta a los dos últimos, y lo dice', function (): void {
    $app = reportsKitApp();
    reportsKitOrder($app, ['paid' => '2024-11-11 12:00:00', 'total' => 111]);
    reportsKitOrder($app, ['paid' => '2024-11-12 12:00:00', 'total' => 222]);
    $p = ReportsAdmin::period($app, 'custom', '2020-01-01', '2026-11-12');
    same(['2024-11-12', '2026-11-12', ReportsAdmin::MAX_DAYS, true], [$p['from'], $p['to'], $p['days'], $p['clipped']]);
    same(222, ReportsAdmin::summary($app, $p)[0]['gross'], 'lo de antes del recorte no cuenta');
    $series = ReportsAdmin::series($app, $p, 'EUR');
    same('month', $series['by'], 'un periodo largo va por meses');
    same(25, count($series['points']));
    $web = adminClient($app);
    $r = $web->get('/admin/reports?period=custom&from=2020-01-01&to=2026-11-12');
    same(200, $r->status);
    contains('El periodo pasaba de dos años: se ha recortado a los dos últimos años.', $r->body);
    contains('Ventas por mes', $r->body);
    same(ReportsAdmin::MAX_DAYS, ReportsAdmin::period($app, 'custom', '2024-11-12', '2026-11-12')['days'], 'justo dos años no se recorta');
    check(!ReportsAdmin::period($app, 'custom', '2024-11-12', '2026-11-12')['clipped']);
});

test('la pantalla de informes: cifras, gráfica con su tabla, lo más vendido, formas de pago y países', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $web = adminClient($app);
    $r = $web->get('/admin/reports?period=custom&from=2026-11-01&to=2026-11-10');
    same(200, $r->status);
    contains('1 pedido de prueba de este periodo no cuenta en ninguna cifra.', $r->body, 'el pedido de pruebas no cuenta y la pantalla lo dice');
    preg_match_all('#data-af-report-stat>([^<]*)<#', $r->body, $stats);
    same([e(money(18150, 'EUR')), e(money(15000, 'EUR')), '2', e(money(9075, 'EUR')), '3', '2', '1', e(money(2000, 'EUR')) . ' · 1 devolución'], $stats[1]);
    contains('Del ', $r->body);
    contains('10 días. Solo pedidos cobrados.', $r->body);
    check(preg_match('#<svg viewBox="0 0 600 160"[^>]*role="img" aria-label="Ventas por día">#', $r->body) === 1, 'la gráfica del inicio, reutilizada');
    same(10, substr_count(substr($r->body, (int) strpos($r->body, 'aria-label="Ventas por día">')), '<rect') >= 10 ? 10 : 0, 'una barra por día');
    $series = substr($r->body, (int) strpos($r->body, 'data-af-report="series"'));
    same(10, substr_count(substr($series, 0, (int) strpos($series, '</table>')), '<tr><td>'), 'y debajo, la tabla con un día por fila');
    contains('<caption class="af-sr">Ventas por día</caption>', $r->body);
    contains('Silla &amp; Cía', $r->body, 'los nombres, escapados');
    contains('data-af-report="summary"', $r->body, 'con dos monedas sale la tabla por moneda');
    contains(e(money(5000, 'USD')), $r->body);
    foreach (['summary', 'series', 'products', 'methods', 'countries', 'customers', 'refunds'] as $table) {
        contains('/admin/reports/export.csv?table=' . $table . '&amp;period=custom&amp;from=2026-11-01&amp;to=2026-11-10', $r->body, 'exportar ' . $table . ' con el mismo periodo');
    }
    notContains('onclick', $r->body);
    same(1, preg_match_all('#<script>#', $r->body), 'sin guiones escritos en la página (el del tema aparte)');
});

test('el formulario del periodo se envía como lo manda un navegador y elige el periodo', function (): void {
    $app = reportsKitApp();
    reportsKitOrder($app, ['paid' => '2026-11-12 08:00:00', 'total' => 4321]);
    $web = adminClient($app);
    $page = $web->get('/admin/reports');
    contains('<option value="30d" selected>', $page->body, 'de entrada, los últimos 30 días');
    $data = panelKitForm($page->body, '/admin/reports', null, ['period' => 'today']);
    same(['period', 'from', 'to'], array_keys($data), 'viaja el periodo y las dos fechas, nada más');
    $r = $web->get('/admin/reports?' . http_build_query($data));
    same(200, $r->status);
    contains('<option value="today" selected>', $r->body);
    preg_match_all('#data-af-report-stat>([^<]*)<#', $r->body, $stats);
    same(e(money(4321, 'EUR')), $stats[1][0]);
});

test('exportar CSV: cada tabla con sus cabeceras, importes con coma y el mismo periodo que la pantalla', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $web = adminClient($app);
    $q = '&period=custom&from=2026-11-01&to=2026-11-10';
    $r = $web->get('/admin/reports/export.csv?table=summary' . $q);
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    same('attachment; filename="report-summary-2026-11-01-2026-11-10.csv"', $r->headers['Content-Disposition']);
    same([
        ['Moneda', 'Ventas con impuestos', 'Ventas sin impuestos', 'Pedidos', 'Ticket medio', 'Unidades'],
        ['EUR', '181,50', '150,00', '2', '90,75', '3'],
        ['USD', '50,00', '50,00', '1', '50,00', '1'],
    ], reportsKitCsv($r->body));
    same([['Id. del producto', 'Producto', 'Unidades', 'Importe', 'Moneda'], ['1', 'Silla & Cía', '2', '121,00', 'EUR'], ['2', 'Mesa', '1', '60,50', 'EUR'], ['3', 'Lámpara', '1', '50,00', 'USD']],
        reportsKitCsv($web->get('/admin/reports/export.csv?table=products' . $q)->body));
    same(['Código', 'Forma de pago', 'Pedidos', 'Importe', 'Moneda'], reportsKitCsv($web->get('/admin/reports/export.csv?table=methods' . $q)->body)[0]);
    same(['ES', '1', '121,00', 'EUR'], reportsKitCsv($web->get('/admin/reports/export.csv?table=countries' . $q)->body)[1]);
    same([['Clientes que compraron', 'Clientes nuevos', 'Clientes que repiten'], ['3', '2', '1']], reportsKitCsv($web->get('/admin/reports/export.csv?table=customers' . $q)->body));
    same([['Moneda', 'Devoluciones del periodo', 'Importe'], ['EUR', '1', '20,00']], reportsKitCsv($web->get('/admin/reports/export.csv?table=refunds' . $q)->body));
    $series = reportsKitCsv($web->get('/admin/reports/export.csv?table=series' . $q)->body);
    same(['Día', 'Pedidos', 'Ventas con impuestos', 'Moneda'], $series[0]);
    same(['2026-11-02', '1', '121,00', 'EUR'], $series[2]);
    same(11, count($series), 'cabecera y diez días');
    same(7, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'export' AND entity = 'reports'"), 'cada exportación queda apuntada');
});

test('exportar una tabla que no existe da 404, y nada pasa por la consulta', function (): void {
    $app = reportsKitApp();
    $web = adminClient($app);
    foreach (['orders', 'x', '', 'summary; DROP TABLE af_orders', '../summary'] as $table) {
        same(404, $web->get('/admin/reports/export.csv?table=' . rawurlencode($table))->status, $table);
    }
    check($app->db()->tableExists('orders'), 'la tabla sigue ahí');
});

test('sin permiso de ver pedidos, 403 en la pantalla y en las exportaciones, y no sale en el menú', function (): void {
    $app = reportsKitApp();
    $app->db()->insert('roles', ['code' => 'catalogo', 'name' => 'Catálogo', 'caps' => json_encode(['catalog.view']), 'system' => 0]);
    $web = adminClient($app, 'catalogo');
    same(403, $web->get('/admin/reports')->status);
    same(403, $web->get('/admin/reports/export.csv?table=summary')->status);
    notContains('/admin/reports', $web->get('/admin')->body);
    $admin = adminClient($app);
    contains('href="/admin/reports"', $admin->get('/admin')->body, 'quien puede, lo tiene en «Ventas»');
});

test('el inicio enlaza a los informes desde «Ventas por día»', function (): void {
    $app = reportsKitApp();
    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    $card = substr($body, (int) strpos($body, '<h2>Ventas por día</h2>'));
    contains('<a class="af-btn af-btn-sm af-btn-ghost" href="/admin/reports">Ver los informes</a>', substr($card, 0, 200));
    check(preg_match('/<svg viewBox="0 0 600 160".*?<\/svg>/s', $card, $m) === 1);
    same(30, substr_count($m[0], '<rect'), 'la gráfica del inicio sigue igual: 30 barras');
});

test('los informes solo leen: abrir la pantalla y exportar no cambia ni una fila', function (): void {
    $app = reportsKitApp();
    reportsKitNovember($app);
    $web = adminClient($app);
    $count = static function () use ($app): string {
        return implode(',', [
            $app->db()->value('SELECT COUNT(*) FROM {orders}'), $app->db()->value('SELECT SUM(total) FROM {orders}'),
            $app->db()->value('SELECT COUNT(*) FROM {order_items}'), $app->db()->value('SELECT COUNT(*) FROM {order_refunds}'),
            $app->db()->value('SELECT COUNT(*) FROM {order_history}'), $app->db()->value('SELECT MAX(updated_at) FROM {orders}'),
        ]);
    };
    $before = $count();
    $web->get('/admin/reports?period=custom&from=2020-01-01&to=2026-11-12');
    $web->get('/admin/reports/export.csv?table=summary');
    same($before, $count());
});

test('las consultas de los informes van por el índice de la fecha del cobro y el de la fecha de la devolución', function (): void {
    $app = reportsKitApp();
    $plan = static function (string $sql) use ($app): string {
        $rows = $app->db()->select('EXPLAIN QUERY PLAN ' . $sql, ['2026-11-01 00:00:00', '2026-11-10 23:59:59']);

        return implode(' | ', array_map(static function (array $row): string {
            return (string) $row['detail'];
        }, $rows));
    };
    contains('paid_at', $plan('SELECT COUNT(*) FROM {orders} o WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ?'));
    notContains('SCAN o', str_replace('SCAN o USING', 'USING', $plan('SELECT COUNT(*) FROM {orders} o WHERE o.test = 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? AND o.paid_at <= ?')), 'no recorre la tabla entera');
    contains('created_at', $plan('SELECT COUNT(*) FROM {order_refunds} r WHERE r.created_at >= ? AND r.created_at <= ?'));
});

test('sin ventas, la pantalla sale con ceros, la gráfica plana y el aviso de que no se vendió nada', function (): void {
    $app = reportsKitApp();
    $web = adminClient($app);
    $r = $web->get('/admin/reports');
    same(200, $r->status);
    preg_match_all('#data-af-report-stat>([^<]*)<#', $r->body, $stats);
    same([e(money(0, 'EUR')), e(money(0, 'EUR')), '0', e(money(0, 'EUR')), '0', '0', '0', e(money(0, 'EUR')) . ' · 0 devoluciones'], $stats[1]);
    contains('No se vendió nada en este periodo.', $r->body);
    notContains('data-af-report-tests', $r->body, 'sin pedidos de prueba no hay nota');
    notContains('data-af-report="summary"', $r->body, 'sin otras monedas, sin tabla por moneda');
    same(30, substr_count(substr($r->body, (int) strpos($r->body, 'aria-label="Ventas por día">')), '<rect') >= 30 ? 30 : 0, 'treinta días, treinta barras');
});

test('«Informes» va en Ventas y marca su entrada al abrirla', function (): void {
    $app = reportsKitApp();
    $web = adminClient($app);
    $body = $web->get('/admin/reports')->body;
    contains('<a href="/admin/reports" aria-current="page">Informes</a>', $body);
    contains('<h1>Informes</h1>', $body);
    $web->get('/admin/palette.json?q=informes');
    same('/admin/reports', array_column($web->json()['items'] ?? [], 'url', 'label')['Informes'] ?? '');
});
