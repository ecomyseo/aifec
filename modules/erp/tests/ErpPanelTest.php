<?php
/**
 * AIFEC — ERP: las pantallas del panel (permisos, clave que se ve una sola vez, avisos, perfiles y ajustes).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Http\HttpResponse;

/** Los valores de un formulario de perfil de exportación tal como los manda un navegador (todos los campos). */
function erpProfileForm(array $over = []): array
{
    return $over + [
        'name' => 'Pedidos para Odoo', 'kind' => 'orders', 'format' => 'csv', 'enabled' => '0',
        'columns' => [['field' => 'number', 'header' => 'Pedido', 'value' => ''], ['field' => 'total', 'header' => 'Total', 'value' => '']],
        'csv_delimiter' => ';', 'csv_quote' => '"', 'csv_encoding' => 'utf8bom', 'csv_decimal' => 'comma', 'csv_date_format' => 'iso', 'csv_eol' => 'crlf', 'csv_header' => '1', 'csv_safe' => '1',
        'json_layout' => 'list', 'xml_root' => 'items', 'xml_item' => 'item', 'statuses' => ['processing'], 'only_new' => '1',
        'destination' => 'download', 'dest_folder' => '', 'dest_host' => '', 'dest_port' => '21', 'dest_user' => '', 'ftp_password' => '', 'dest_path' => '', 'dest_passive' => '1',
        'dest_url' => '', 'post_token' => '', 'dest_email' => '', 'schedule' => 'manual', 'schedule_every' => '', 'schedule_at' => '',
    ];
}

test('las pantallas del ERP se abren con permiso y dan 403 sin él', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $urls = ['/admin/erp', '/admin/erp/connections/new', '/admin/erp/connections/' . $c['id'], '/admin/erp/deliveries', '/admin/erp/exports', '/admin/erp/exports/new',
        '/admin/erp/imports', '/admin/erp/imports/new', '/admin/erp/runs', '/admin/settings/erp'];
    $web = adminClient($app);
    foreach ($urls as $url) {
        same(200, $web->get($url)->status, $url);
    }
    $sample = (int) $app->db()->value("SELECT id FROM {erp_exports} WHERE direction = 'export' ORDER BY id");
    same(200, $web->get('/admin/erp/exports/' . $sample)->status);
    same(200, $web->get('/admin/erp/exports/' . $sample . '?preview=1')->status);
    adminOrdersRole($app, 'sin-erp', ['dashboard.view']);
    $none = adminClient($app, 'sin-erp');
    foreach ($urls as $url) {
        same(403, $none->get($url)->status, 'sin permiso: ' . $url);
    }
    adminOrdersRole($app, 'lector', ['dashboard.view', 'erp.view']);
    $reader = adminClient($app, 'lector');
    same(200, $reader->get('/admin/erp')->status);
    same(200, $reader->get('/admin/erp/connections/' . $c['id'])->status);
    same(403, $reader->get('/admin/erp/connections/new')->status);
    same(403, adminOrdersPost($reader, '/admin/erp/connections/' . $c['id'] . '/revoke')->status);
    same(403, adminOrdersPost($reader, '/admin/erp/exports/' . $sample . '/run')->status);
    same(404, $web->get('/admin/erp/connections/9999')->status);
    same(404, $web->get('/admin/erp/imports/' . $sample)->status, 'un perfil de exportación no se abre como importación');
    // Sin sesión, al acceso.
    same(302, (new TestClient($app))->get('/admin/erp')->status);
});

test('la clave se enseña una sola vez, al crear la conexión, y no se guarda en claro', function (): void {
    $app = erpApp();
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/connections/new', ['f' => ['name' => 'Odoo & Cía', 'scopes' => ['erp:orders', 'erp:orders:write']]]);
    same(200, $r->status);
    check(preg_match('/aifec_erp_[a-f0-9]{48}/', $r->body, $m) === 1, 'la clave sale en la respuesta');
    $key = $m[0];
    $connection = $app->db()->row('SELECT * FROM {erp_connections}');
    same('Odoo & Cía', $connection['name']);
    $page = $web->get('/admin/erp/connections/' . $connection['id']);
    notContains($key, $page->body);
    notContains($key, $web->get('/admin/erp')->body);
    contains('aifec_erp_…' . substr($key, -4), $web->get('/admin/erp')->body);
    contains('Odoo &amp; Cía', $web->get('/admin/erp')->body);
    foreach (['api_keys', 'erp_connections', 'settings', 'audit'] as $table) {
        foreach ($app->db()->select('SELECT * FROM {' . $table . '}') as $row) {
            notContains($key, json_encode($row), $table);
        }
    }
    same(200, erpCall($app, 'GET', '/orders', null, $key)->status);
    same(['erp:orders', 'erp:orders:write'], $app->service('erp')->connections()->find((int) $connection['id'])['scopes']);
    same(1, aifecOrderCount($app, 'audit', "action = 'erp.connection.create'"));
});

test('una conexión sin nombre o sin ámbitos no se crea', function (): void {
    $app = erpApp();
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/connections/new', ['f' => ['name' => '', 'scopes' => ['erp:orders']]]);
    same(422, $r->status);
    $r2 = adminOrdersPost($web, '/admin/erp/connections/new', ['f' => ['name' => 'Sin ámbitos']]);
    same(422, $r2->status);
    same(0, aifecOrderCount($app, 'erp_connections'));
    same(0, aifecOrderCount($app, 'api_keys'));
});

test('apagar, encender y revocar una conexión desde el panel', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $web = adminClient($app);
    same(302, adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/toggle')->status);
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/toggle');
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/revoke');
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    same('1', (string) (int) ($app->service('erp')->connections()->find($c['id'])['revoked_at'] !== null));
    // Una revocada no se enciende otra vez.
    adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/toggle');
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    same(3, aifecOrderCount($app, 'audit', "action LIKE 'erp.connection.%' AND action <> 'erp.connection.create'"));
});

test('los avisos se configuran en la conexión: el secreto se enseña una vez y la dirección tiene que ser https', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $web = adminClient($app);
    $bad = adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/webhook', ['f' => ['url' => 'http://erp.example.com/h', 'events' => ['order.paid'], 'active' => '1']]);
    same(422, $bad->status);
    same(0, aifecOrderCount($app, 'erp_webhooks'));
    $none = adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/webhook', ['f' => ['url' => 'https://erp.example.com/h', 'active' => '1']]);
    same(422, $none->status);
    $ok = adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/webhook', ['f' => ['url' => 'https://erp.example.com/h', 'events' => ['order.paid', 'invoice.issued'], 'active' => '1']]);
    same(200, $ok->status);
    check(preg_match('/whsec_[a-f0-9]{48}/', $ok->body, $m) === 1, 'el secreto sale una vez');
    notContains($m[0], $web->get('/admin/erp/connections/' . $c['id'])->body);
    $hook = $app->service('erp')->webhooks()->find($c['id']);
    same('https://erp.example.com/h', $hook['url']);
    same(['order.paid', 'invoice.issued'], $hook['events']);
    // Cambiarla después no vuelve a enseñar secreto.
    $again = adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/webhook', ['f' => ['url' => 'https://erp.example.com/h2', 'events' => ['order.paid'], 'active' => '1']]);
    same(302, $again->status);
    same('https://erp.example.com/h2', $app->service('erp')->webhooks()->find($c['id'])['url']);
    // Un secreto nuevo, solo a petición.
    $rotated = adminOrdersPost($web, '/admin/erp/connections/' . $c['id'] . '/secret');
    check(preg_match('/whsec_[a-f0-9]{48}/', $rotated->body, $m2) === 1 && $m2[0] !== $m[0], 'un secreto nuevo');
});

test('Probar, Reactivar y Reenviar desde el panel', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $c = erpConnection($app);
    $sub = erpSubscribe($app, $c['id'], ['order.paid']);
    $web = adminClient($app);
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    $r = adminOrdersPost($web, '/admin/erp/webhooks/' . $sub['id'] . '/test');
    same(302, $r->status);
    same(1, count($app->http()->requests));
    same('webhook.test', json_decode((string) $app->http()->requests[0]->body, true)['event']);
    $app->db()->update('erp_webhooks', ['paused' => 1, 'failures' => 5, 'paused_reason' => 'x'], ['id' => $sub['id']]);
    contains('af-alert-bad', $web->get('/admin/erp/connections/' . $c['id'])->body);
    adminOrdersPost($web, '/admin/erp/webhooks/' . $sub['id'] . '/reactivate');
    same(false, $app->service('erp')->webhooks()->find($c['id'])['paused']);
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    erpOrder($app);
    $app->service('erp')->webhooks()->sweep();
    $delivery = (int) $app->db()->value("SELECT id FROM {erp_deliveries} WHERE event = 'order.paid'");
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    $before = count($app->http()->requests);
    adminOrdersPost($web, '/admin/erp/deliveries/' . $delivery . '/resend');
    same($before + 1, count($app->http()->requests));
    same(200, $web->get('/admin/erp/deliveries?status=sent')->status);
    same(404, adminOrdersPost($web, '/admin/erp/deliveries/9999/resend')->status);
});

test('un perfil de exportación se crea desde el formulario con todas sus pestañas y se guarda lo que se envía', function (): void {
    $app = erpApp();
    $web = adminClient($app);
    $page = $web->get('/admin/erp/exports/new');
    foreach (['erpmain', 'erpcolumns', 'erpformat', 'erpfilter', 'erpdest', 'erpwhen'] as $tab) {
        contains('data-af-tab="' . $tab . '"', $page->body);
    }
    $r = adminOrdersPost($web, '/admin/erp/exports/save', ['f' => erpProfileForm(['name' => 'Pedidos & albaranes', 'schedule' => 'daily', 'schedule_at' => '03:30', 'enabled' => '1'])]);
    same(302, $r->status);
    $row = $app->db()->row("SELECT * FROM {erp_exports} WHERE name = 'Pedidos & albaranes'");
    check($row !== null, 'se creó');
    same('export', $row['direction']);
    same('orders', $row['kind']);
    same(1, (int) $row['enabled']);
    same('daily', $row['schedule']);
    same('03:30', $row['schedule_at']);
    check($row['next_run_at'] !== null, 'tiene próxima hora');
    $options = json_decode((string) $row['options'], true);
    same(';', $options['delimiter']);
    same('utf8bom', $options['encoding']);
    same('comma', $options['decimal']);
    same('crlf', $options['eol']);
    same(['processing'], json_decode((string) $row['filter'], true)['statuses']);
    same(true, json_decode((string) $row['filter'], true)['only_new']);
    same(['number', 'total'], array_column(json_decode((string) $row['columns'], true), 'field'));
    // El cursor «solo lo nuevo» se sembró al crear.
    check((new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $row['id']) !== null, 'cursor sembrado');
    // Al volver a abrirlo sale lo guardado.
    $again = $web->get('/admin/erp/exports/' . $row['id']);
    same(200, $again->status);
    contains('Pedidos &amp; albaranes', $again->body);
    same('03:30', inputValue($again->body, 'f[schedule_at]'));
    // Lo cambia y no duplica.
    adminOrdersPost($web, '/admin/erp/exports/save', ['id' => (string) $row['id'], 'f' => erpProfileForm(['name' => 'Pedidos & albaranes v2', 'only_new' => '1'])]);
    same(1, aifecOrderCount($app, 'erp_exports', "name LIKE 'Pedidos & albaranes%'"));
    // Y lo borra, con su cursor.
    adminOrdersPost($web, '/admin/erp/exports/' . $row['id'] . '/delete');
    same(0, aifecOrderCount($app, 'erp_exports', "name LIKE 'Pedidos & albaranes%'"));
    same(null, (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $row['id']));
});

test('la contraseña del FTP no se guarda en el perfil sino cifrada, y no vuelve a la pantalla', function (): void {
    $app = erpApp();
    $ftp = erpFtp($app);
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/exports/save', ['f' => erpProfileForm(['name' => 'Por FTP', 'destination' => 'ftp', 'dest_host' => 'ftp.erp.test', 'dest_user' => 'erp', 'ftp_password' => 'clave-de-mentira', 'dest_path' => '/in'])]);
    same(302, $r->status);
    $id = (int) $app->db()->value("SELECT id FROM {erp_exports} WHERE name = 'Por FTP'");
    same('clave-de-mentira', $app->secret('erp.ftp.' . $id));
    notContains('clave-de-mentira', json_encode($app->db()->select('SELECT * FROM {erp_exports}')));
    notContains('clave-de-mentira', json_encode($app->db()->select('SELECT * FROM {settings}')));
    notContains('clave-de-mentira', $web->get('/admin/erp/exports/' . $id)->body);
    // Dejar la contraseña en blanco no la borra.
    adminOrdersPost($web, '/admin/erp/exports/save', ['id' => (string) $id, 'f' => erpProfileForm(['name' => 'Por FTP', 'destination' => 'ftp', 'dest_host' => 'ftp.erp.test', 'dest_user' => 'erp', 'ftp_password' => '', 'dest_path' => '/in'])]);
    same('clave-de-mentira', $app->secret('erp.ftp.' . $id));
    check($ftp instanceof AifecErpFakeFtp, 'el servidor de mentira está puesto');
});

test('un perfil con datos mal puestos vuelve con los errores y no se guarda', function (): void {
    $app = erpApp();
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/exports/save', ['f' => erpProfileForm(['name' => '', 'destination' => 'post', 'dest_url' => 'http://sin-https.test', 'schedule' => 'minutes', 'schedule_every' => '1'])]);
    same(422, $r->status);
    contains('af-field-invalid', $r->body);
    same(0, aifecOrderCount($app, 'erp_exports', "direction = 'export' AND name = ''"));
    $before = aifecOrderCount($app, 'erp_exports');
    adminOrdersPost($web, '/admin/erp/exports/save', ['f' => erpProfileForm(['columns' => [['field' => 'line_sku', 'header' => '', 'value' => '']], 'kind' => 'customers'])]);
    same($before, aifecOrderCount($app, 'erp_exports'), 'un campo que no es de ese tipo no vale');
});

test('un perfil de importación se crea desde el formulario y se ejecuta con el botón', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1]);
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/imports/save', ['f' => [
        'name' => 'Existencias de Odoo', 'format' => 'csv', 'enabled' => '0', 'col_sku' => 'sku', 'col_stock' => 'stock', 'stock_mode' => 'absolute', 'col_price' => '', 'col_sale_price' => '',
        'prices_include_tax' => '1', 'destination' => 'folder', 'dest_folder' => 'odoo', 'dest_pattern' => '*.csv', 'dest_host' => '', 'dest_port' => '21', 'dest_user' => '', 'ftp_password' => '',
        'dest_path' => '', 'dest_passive' => '1', 'dest_url' => '', 'schedule' => 'manual', 'schedule_every' => '', 'schedule_at' => '',
    ]]);
    same(302, $r->status);
    $row = $app->db()->row("SELECT * FROM {erp_exports} WHERE name = 'Existencias de Odoo'");
    same('import', $row['direction']);
    same('sku', json_decode((string) $row['options'], true)['sku_col']);
    erpDrop($app, 'odoo', 'hoy.csv', "sku;stock\nIM-A;31\n");
    $run = adminOrdersPost($web, '/admin/erp/imports/' . $row['id'] . '/run');
    same(302, $run->status);
    same(31, aifecOrderStock($app, $a));
    contains('Existencias de Odoo', $web->get('/admin/erp/runs')->body);
    same(404, adminOrdersPost($web, '/admin/erp/exports/' . $row['id'] . '/run')->status, 'una importación no se ejecuta como exportación');
});

test('Ejecutar ahora una exportación deja la pasada y su fichero para descargar', function (): void {
    $app = erpApp();
    erpOrder($app);
    $p = erpExportProfile($app);
    $web = adminClient($app);
    $r = adminOrdersPost($web, '/admin/erp/exports/' . $p . '/run');
    same(302, $r->status);
    contains('/admin/erp/runs/', $r->headers['Location']);
    $page = $web->follow();
    same(200, $page->status);
    contains('/download', $page->body);
    $dl = $web->get('/admin/erp/runs/' . aifecOrderCount($app, 'erp_export_runs') . '/download');
    same(200, $dl->status);
    // La vista previa de 10 filas se ve en el perfil y no crea pasadas.
    $before = aifecOrderCount($app, 'erp_export_runs');
    $prev = $web->get('/admin/erp/exports/' . $p . '?preview=1');
    contains('<table', $prev->body);
    same($before, aifecOrderCount($app, 'erp_export_runs'));
});

test('el intervalo de fechas pide confirmar cuántos registros son antes de exportar', function (): void {
    $app = erpApp();
    erpOrderRows($app, 12, '2026-10-05 09:00:00');
    $p = erpExportProfile($app, ['only_new' => 1]);
    $web = adminClient($app);
    $count = adminOrdersPost($web, '/admin/erp/exports/' . $p . '/interval', ['from' => '2026-10-05', 'to' => '2026-10-05']);
    same(200, $count->status);
    contains('name="confirm" value="12"', $count->body);
    same(0, aifecOrderCount($app, 'erp_export_runs'), 'contar no exporta nada');
    $go = adminOrdersPost($web, '/admin/erp/exports/' . $p . '/interval', ['from' => '2026-10-05', 'to' => '2026-10-05', 'confirm' => '12']);
    same(302, $go->status);
    same(12, (int) $app->db()->value('SELECT records FROM {erp_export_runs}'));
    $wrong = adminOrdersPost($web, '/admin/erp/exports/' . $p . '/interval', ['from' => '2026-10-05', 'to' => '2026-10-05', 'confirm' => '3']);
    same('rejected', (string) $app->db()->value('SELECT status FROM {erp_export_runs} ORDER BY id DESC'));
    same(302, adminOrdersPost($web, '/admin/erp/exports/' . $p . '/interval', ['from' => 'ayer'])->status);
});

test('los ajustes del ERP se guardan en un solo formulario con pestañas y los avisos nacen apagados', function (): void {
    $app = erpApp();
    $web = adminClient($app);
    $r = $web->get('/admin/settings/erp');
    same(200, $r->status);
    contains('data-af-tab="erpgeneral"', $r->body);
    contains('data-af-tab="erpfiles"', $r->body);
    same(false, $app->settings()->bool('erp.outbound', false), 'apagado de serie');
    same('300', inputValue($r->body, 'f[erp.rate_per_minute]'));
    $save = adminOrdersPost($web, '/admin/settings/erp', ['f' => [
        'erp.outbound' => '1', 'erp.rate_per_minute' => '120', 'erp.idempotency_hours' => '12', 'erp.keep_days' => '3', 'erp.folder_days' => '30', 'erp.export_max_rows' => '1000',
    ], '_tab' => 'erpfiles']);
    same(302, $save->status);
    same(true, $app->settings()->bool('erp.outbound'));
    same(120, $app->settings()->int('erp.rate_per_minute'));
    same(12, $app->settings()->int('erp.idempotency_hours'));
    same(3, $app->settings()->int('erp.keep_days'));
    same(1000, $app->settings()->int('erp.export_max_rows'));
    same('120', inputValue($web->get('/admin/settings/erp')->body, 'f[erp.rate_per_minute]'));
});

test('la ficha del pedido enseña qué ERP lo ha recibido solo a quien puede ver el ERP', function (): void {
    $app = erpApp();
    $c = erpConnection($app, null, 'Holded');
    $id = erpOrder($app);
    erpCall($app, 'POST', '/orders/' . adminOrdersNumber($app, $id) . '/ack', ['external_id' => 'HLD-77'], $c['key']);
    $web = adminClient($app);
    $seen = $web->get('/admin/orders/' . $id)->body;
    contains('data-erp-card', $seen);
    contains('HLD-77', $seen);
    adminOrdersRole($app, 'pedidos', ['dashboard.view', 'orders.view']);
    $orders = adminClient($app, 'pedidos');
    $page = $orders->get('/admin/orders/' . $id);
    same(200, $page->status);
    notContains('data-erp-card', $page->body);
});

test('un dato con «&» y comillas sale escapado en las pantallas', function (): void {
    $app = erpApp();
    $c = erpConnection($app, null, '<b>Odoo</b> & "Cía"');
    $sub = erpSubscribe($app, $c['id'], ['order.paid'], 'https://erp.test/hook?a=1&b=2');
    $web = adminClient($app);
    $page = $web->get('/admin/erp/connections/' . $c['id'])->body;
    notContains('<b>Odoo</b>', $page);
    contains('&lt;b&gt;Odoo&lt;/b&gt; &amp; &quot;Cía&quot;', $page);
    contains('a=1&amp;b=2', $page);
    $list = $web->get('/admin/erp')->body;
    notContains('<b>Odoo</b>', $list);
    check($sub['id'] > 0, 'hay suscripción');
});
