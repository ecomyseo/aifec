<?php
/**
 * AIFEC — pruebas del panel de facturas: listado y filtros, descarga, emisión a mano, exportación CSV para la gestoría y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el listado se abre con permiso y enseña número, fecha, pedido, cliente, base, impuesto y total; filtra por serie, tipo y fechas y busca', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $a = invoicesOrder($app, [[$p, 0, 1]], ['email' => 'ana@cliente.test']);
    $app->clock()->advance(3 * 86400);
    $b = invoicesOrder($app, [[$p, 0, 1]], ['email' => 'luis@cliente.test']);
    invoicesOrder($app, [[$p, 0, 1]], ['email' => 'prueba@cliente.test'], true);
    $app->service('orders')->refund($a, 500, [], 'Parcial', false, false);
    $web = adminClient($app);

    $list = $web->get('/admin/invoices');
    same(200, $list->status);
    foreach (['F2026-00001', 'F2026-00002', 'R2026-00001', 'TEST-00001'] as $number) {
        contains($number, $list->body);
    }
    contains('/admin/orders/' . $a, $list->body);
    contains('Ana Ruiz', $list->body);
    contains('24,95', $list->body);
    contains('/admin/invoices/1/pdf', $list->body);
    contains('Emitir la factura de un pedido', $list->body);

    $series = $web->get('/admin/invoices?f[series]=R')->body;
    contains('R2026-00001', $series);
    notContains('F2026-00002', $series);
    $credits = $web->get('/admin/invoices?f[type]=credit')->body;
    contains('R2026-00001', $credits);
    notContains('F2026-00001', $credits);
    $from = $web->get('/admin/invoices?f[from]=2026-11-14&f[type]=invoice')->body;
    contains('F2026-00002', $from);
    notContains('F2026-00001', $from);
    $to = $web->get('/admin/invoices?f[to]=2026-11-13&f[series]=F')->body;
    contains('F2026-00001', $to);
    notContains('F2026-00002', $to);
    contains('F2026-00002', $web->get('/admin/invoices?q=luis')->body);
    notContains('F2026-00001', $web->get('/admin/invoices?q=luis')->body);
    contains('F2026-00002', $web->get('/admin/invoices?q=' . urlencode((string) $app->service('orders')->find($b)['number']))->body);
});

test('el PDF se descarga desde el panel con permiso y no se sirve a quien no lo tiene', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $invoice = $app->service('invoices')->forOrder(invoicesOrder($app, [[$p, 0, 1]]))[0];
    $web = adminClient($app);

    $r = $web->get('/admin/invoices/' . $invoice['id'] . '/pdf');
    same(200, $r->status);
    same('application/pdf', $r->headers['Content-Type']);
    contains('attachment; filename="F2026-00001.pdf"', $r->headers['Content-Disposition']);
    same('%PDF', substr((string) file_get_contents($r->file), 0, 4));
    same(404, $web->get('/admin/invoices/99999/pdf')->status);
    same(200, adminClient($app, 'support')->get('/admin/invoices/' . $invoice['id'] . '/pdf')->status, 'soporte puede ver y descargar');
    same(403, adminClient($app, 'editor')->get('/admin/invoices/' . $invoice['id'] . '/pdf')->status, 'el editor, no');
});

test('la exportación CSV lleva una fila por factura y tipo de impuesto, con las rectificativas en negativo, y respeta los filtros', function (): void {
    $app = invoicesApp();
    $a = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'tax_class_id' => 'standard', 'stock' => 50]);
    $b = aifecProduct($app, ['name' => 'Libro', 'price' => 1100, 'tax_class_id' => 'reduced', 'stock' => 50]);
    $mixed = invoicesOrder($app, [[$a, 0, 1], [$b, 0, 1]], ['billing' => [
        'first_name' => '=SUMA(1)', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678',
    ]]);
    $single = invoicesOrder($app, [[$a, 0, 1]], ['email' => 'otro@cliente.test']);
    $app->service('orders')->refund($single, 1000, [], 'Parcial', false, false);
    $web = adminClient($app);

    $r = $web->get('/admin/invoices/export.csv');

    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment; filename="invoices-20261112.csv"', $r->headers['Content-Disposition']);
    same("\xEF\xBB\xBF", substr($r->body, 0, 3), 'con la marca de orden de bytes para las hojas de cálculo');
    $rows = invoicesCsvRows($r->body);
    same(['Número', 'Tipo', 'Fecha', 'Pedido', 'Cliente', 'NIF', 'País', 'Impuesto', 'Tipo impositivo', 'Base imponible', 'Cuota', 'Total', 'Moneda'], $rows[0]);

    $mixedRows = array_values(array_filter($rows, static function (array $row): bool {
        return $row[0] === 'F2026-00001';
    }));
    $order = $app->service('orders')->find($mixed);
    same(count($order['taxes']), count($mixedRows), 'una fila por tipo de impuesto');
    $total = 0;
    foreach ($mixedRows as $i => $row) {
        same('Factura', $row[1]);
        same('2026-11-12', $row[2]);
        same($order['number'], $row[3]);
        same('ES', $row[6]);
        same(invoicesComma($order['taxes'][$i]['base']), $row[9]);
        same(invoicesComma($order['taxes'][$i]['amount']), $row[10]);
        $total += $order['taxes'][$i]['base'] + $order['taxes'][$i]['amount'];
    }
    same($order['total'], $total, 'las filas suman el total del pedido');
    same("'=SUMA(1) Ruiz", $mixedRows[0][4], 'un nombre que empieza por = se neutraliza');

    $credit = array_values(array_filter($rows, static function (array $row): bool {
        return $row[0] === 'R2026-00001';
    }));
    check(count($credit) >= 1, 'la rectificativa sale');
    same('Factura rectificativa', $credit[0][1]);
    same('-10,00', $credit[0][11], 'el total de la rectificativa, en negativo y sin apóstrofo');
    check(strpos($credit[0][9], '-') === 0 && strpos($credit[0][10], '-') === 0, 'base y cuota en negativo');

    $filtered = invoicesCsvRows($web->get('/admin/invoices/export.csv?f[type]=credit')->body);
    same(2, count($filtered), 'cabecera y la fila de la rectificativa');
    same(200, adminClient($app, 'support')->get('/admin/invoices/export.csv')->status, 'soporte puede exportar');
    same(403, adminClient($app, 'editor')->get('/admin/invoices/export.csv')->status);
});

test('emitir a mano: un pedido existente emite, repetir avisa, y uno que no existe o está cancelado da error', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->service('orders')->find($id)['number'];
    $cancelled = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $app->service('orders')->cancel((int) $cancelled['id'], 'No lo quiere');
    $cancelledNumber = (string) $app->service('orders')->find((int) $cancelled['id'])['number'];
    $web = adminClient($app);
    $web->get('/admin/invoices');

    $r = $web->post('/admin/invoices/issue', ['_token' => aifecToken($web), 'order' => $number]);
    same(302, $r->status);
    contains('Factura F2026-00001 emitida.', $web->follow()->body);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'));

    $web->post('/admin/invoices/issue', ['_token' => aifecToken($web), 'order' => $number]);
    contains('El pedido ya tenía la factura F2026-00001.', $web->follow()->body);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'));
    same(1, invoicesCounter($app, 'F'));

    $web->post('/admin/invoices/issue', ['_token' => aifecToken($web), 'order' => 'NO-EXISTE']);
    contains('No hay ningún pedido con ese número.', $web->follow()->body);
    $web->post('/admin/invoices/issue', ['_token' => aifecToken($web), 'order' => $cancelledNumber]);
    contains('No se puede emitir una factura de un pedido cancelado.', $web->follow()->body);
    same(1, invoicesCounter($app, 'F'), 'los errores no gastan número');
    $actions = array_column($app->db()->select("SELECT action FROM {audit} WHERE entity = 'invoice'"), 'action');
    same(['issue'], $actions);
});

test('sin permiso no se entra: el editor recibe 403 en todo y soporte, que solo ve, recibe 403 al emitir y no ve el formulario', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->service('orders')->find($id)['number'];

    $editor = adminClient($app, 'editor');
    $editorToken = aifecToken($editor);
    same(403, $editor->get('/admin/invoices')->status);
    same(403, $editor->post('/admin/invoices/issue', ['_token' => $editorToken, 'order' => $number])->status);

    $support = adminClient($app, 'support');
    $list = $support->get('/admin/invoices');
    same(200, $list->status);
    notContains('name="order"', $list->body, 'sin permiso de emitir no sale el formulario');
    $token = aifecToken($support);
    same(403, $support->post('/admin/invoices/issue', ['_token' => $token, 'order' => $number])->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'), 'no se emitió nada');
    same(403, $support->get('/admin/settings/invoices')->status, 'soporte no cambia los ajustes de facturas');

    $anonymous = new TestClient($app);
    check($anonymous->get('/admin/invoices')->status !== 200, 'sin sesión no se ve el listado');
});

test('el panel declara sus permisos y su entrada de menú, y los ajustes de facturas se guardan desde un solo formulario con pestañas', function (): void {
    $app = invoicesApp();
    $web = adminClient($app);

    contains('/admin/invoices', $web->get('/admin')->body);
    $caps = \Aifec\Admin\Admin::capabilities($app);
    check(isset($caps['invoices.view']) && isset($caps['invoices.edit']), 'los permisos están declarados');

    $page = $web->get('/admin/settings/invoices');
    same(200, $page->status);
    foreach (['ivgeneral', 'ivnumbers', 'ivlegal'] as $tab) {
        contains($tab, $page->body, 'pestaña ' . $tab);
    }
    $web->post('/admin/settings/invoices', ['_token' => aifecToken($web), 'f' => [
        'invoices.auto' => '0', 'invoices.attach' => '1', 'invoices.series' => 'B', 'invoices.format' => '{series}{number:4}',
        'invoices.credit_series' => 'C', 'invoices.credit_format' => '{series}{number:4}', 'invoices.legal_text' => 'Pie con & ampersand',
    ]]);
    $app->settings()->reload();
    same(false, $app->settings()->bool('invoices.auto', true), 'el interruptor se puede apagar');
    same(true, $app->settings()->bool('invoices.attach'));
    same('B', $app->settings()->str('invoices.series'));
    same('{series}{number:4}', $app->settings()->str('invoices.format'));
    same('Pie con & ampersand', $app->settings()->str('invoices.legal_text'));
});

/**
 * Las filas de un CSV de la exportación (separador «;»), sin la marca de orden de bytes.
 *
 * @return array<int,array<int,string>>
 */
function invoicesCsvRows(string $csv): array
{
    $csv = substr($csv, 0, 3) === "\xEF\xBB\xBF" ? substr($csv, 3) : $csv;
    $rows = [];
    foreach (preg_split('/\R/', trim($csv)) ?: [] as $line) {
        $rows[] = str_getcsv($line, ';', '"', '');
    }

    return $rows;
}

/** Céntimos como los escribe la exportación: «1234,56». */
function invoicesComma(int $cents): string
{
    return \Aifec\Modules\Invoices\InvoiceExport::decimal($cents);
}
