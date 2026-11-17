<?php
/**
 * AIFEC — ERP: exportación genérica (formatos, opciones de CSV, filtros, cursor y destinos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Http\HttpResponse;

/** Cada línea del texto como lista de campos (para mirar un CSV sin depender de cómo lo escribió el módulo). */
function erpCsvRows(string $text, string $delimiter = ';'): array
{
    $rows = [];
    foreach (preg_split('/\r\n|\n/', rtrim($text, "\r\n")) as $line) {
        $rows[] = str_getcsv($line, $delimiter, '"', '');
    }

    return $rows;
}

test('el CSV respeta separador, fin de línea, coma decimal, cabeceras renombradas y columnas fijas', function (): void {
    $app = erpApp();
    $id = erpOrder($app, 2, 1999);
    $order = $app->service('orders')->find($id);
    $p = erpExportProfile($app, [
        'csv_delimiter' => ';', 'csv_eol' => 'crlf', 'csv_decimal' => 'comma', 'csv_date_format' => 'es',
        'columns' => [
            ['field' => 'number', 'header' => 'Pedido', 'value' => ''], ['field' => 'total', 'header' => 'Total', 'value' => ''],
            ['field' => 'placed_at', 'header' => 'Fecha', 'value' => ''], ['field' => '_fixed', 'header' => 'Origen', 'value' => 'AIFEC'],
            ['field' => 'items_count', 'header' => '', 'value' => ''],
        ],
    ]);
    $run = erpRun($app, $p);
    same('ok', $run['status']);
    same(1, $run['records']);
    $text = erpRunFile($app, $run);
    contains("\r\n", $text);
    $rows = erpCsvRows($text);
    same(['Pedido', 'Total', 'Fecha', 'Origen', 'items_count'], $rows[0]);
    same((string) $order['number'], $rows[1][0]);
    same(number_format($order['total'] / 100, 2, ',', ''), $rows[1][1]);
    same('12/11/2026 13:00', $rows[1][2]);
    same('AIFEC', $rows[1][3]);
    same('2', $rows[1][4]);
});

test('el CSV admite coma, tabulador y barra como separador, comillas simples y celdas con comillas', function (): void {
    $app = erpApp();
    erpOrder($app);
    $columns = [['field' => '_fixed', 'header' => 'Nota', 'value' => 'dijo "hola", y se fue'], ['field' => 'currency', 'header' => 'Moneda', 'value' => '']];
    // Una celda con comillas o con el separador se entrecomilla y las comillas se duplican.
    foreach ([',' => "\"dijo \"\"hola\"\", y se fue\",EUR", "\t" => "\"dijo \"\"hola\"\", y se fue\"\tEUR", '|' => "\"dijo \"\"hola\"\", y se fue\"|EUR"] as $delimiter => $line) {
        $p = erpExportProfile($app, ['csv_delimiter' => (string) $delimiter, 'columns' => $columns, 'name' => 'Sep ' . bin2hex((string) $delimiter)]);
        $text = erpRunFile($app, erpRun($app, $p));
        same($line, explode("\n", $text)[1]);
    }
    $p = erpExportProfile($app, ['csv_delimiter' => ';', 'csv_quote' => "'", 'columns' => [['field' => '_fixed', 'header' => 'N', 'value' => "it's; here"]], 'name' => 'Comillas simples']);
    same("'it''s; here'", explode("\n", erpRunFile($app, erpRun($app, $p)))[1]);
});

test('el CSV se escribe en UTF-8, UTF-8 con BOM o Windows-1252', function (): void {
    $app = erpApp();
    erpOrder($app);
    $columns = [['field' => '_fixed', 'header' => 'Año', 'value' => 'Camión ñandú']];
    $utf8 = erpRunFile($app, erpRun($app, erpExportProfile($app, ['csv_encoding' => 'utf8', 'columns' => $columns, 'name' => 'u'])));
    same("Año\nCamión ñandú\n", $utf8);
    $bom = erpRunFile($app, erpRun($app, erpExportProfile($app, ['csv_encoding' => 'utf8bom', 'columns' => $columns, 'name' => 'b'])));
    same("\xEF\xBB\xBF" . "Año\nCamión ñandú\n", $bom);
    $win = erpRunFile($app, erpRun($app, erpExportProfile($app, ['csv_encoding' => 'win1252', 'columns' => $columns, 'name' => 'w'])));
    same("A\xF1o\nCami\xF3n \xF1and\xFA\n", $win);
});

test('el CSV puede ir sin cabecera y protege las celdas que una hoja de cálculo ejecutaría', function (): void {
    $app = erpApp();
    erpOrder($app);
    $columns = [['field' => '_fixed', 'header' => 'X', 'value' => '=SUMA(A1)'], ['field' => '_fixed', 'header' => 'Y', 'value' => '+34999000001']];
    $safe = erpRunFile($app, erpRun($app, erpExportProfile($app, ['csv_header' => 0, 'columns' => $columns, 'name' => 's1'])));
    same("'=SUMA(A1);'+34999000001\n", $safe);
    $raw = erpRunFile($app, erpRun($app, erpExportProfile($app, ['csv_safe' => 0, 'csv_header' => 0, 'columns' => $columns, 'name' => 's2'])));
    same("=SUMA(A1);+34999000001\n", $raw);
});

test('JSON en lista y en líneas, con los importes como texto decimal y los booleanos como booleanos', function (): void {
    $app = erpApp();
    $id = erpOrder($app, 1, 2420);
    $order = $app->service('orders')->find($id);
    $columns = [['field' => 'number', 'header' => 'numero', 'value' => ''], ['field' => 'total', 'header' => 'total', 'value' => ''], ['field' => 'test', 'header' => 'prueba', 'value' => ''],
        ['field' => 'items_count', 'header' => 'unidades', 'value' => ''], ['field' => 'paid_at', 'header' => 'pagado', 'value' => '']];
    $list = erpRunFile($app, erpRun($app, erpExportProfile($app, ['format' => 'json', 'json_layout' => 'list', 'columns' => $columns, 'csv_decimal' => 'comma', 'name' => 'jl'])));
    $data = json_decode($list, true);
    same(1, count($data));
    same((string) $order['number'], $data[0]['numero']);
    same(number_format($order['total'] / 100, 2, '.', ''), $data[0]['total']);
    same(false, $data[0]['prueba']);
    same(1, $data[0]['unidades']);
    same('2026-11-12T12:00:00Z', $data[0]['pagado']);
    $lines = erpRunFile($app, erpRun($app, erpExportProfile($app, ['format' => 'json', 'json_layout' => 'lines', 'columns' => $columns, 'name' => 'jn'])));
    $rows = array_values(array_filter(explode("\n", $lines)));
    same(1, count($rows));
    same((string) $order['number'], json_decode($rows[0], true)['numero']);
});

test('XML con etiquetas a medida, «&» y comillas escapados y nombres de etiqueta saneados', function (): void {
    $app = erpApp();
    $app->service('customers')->ensureGuest('xml@cliente.test', ['first_name' => 'Pérez & "Hijos"', 'last_name' => '<Ana>']);
    $p = erpExportProfile($app, ['format' => 'xml', 'kind' => 'customers', 'xml_root' => 'clientes', 'xml_item' => 'cliente',
        'columns' => [['field' => 'first_name', 'header' => 'nombre propio', 'value' => ''], ['field' => 'last_name', 'header' => '1apellido', 'value' => ''], ['field' => 'email', 'header' => 'email', 'value' => '']]]);
    $run = erpRun($app, $p);
    $xml = erpRunFile($app, $run);
    $doc = new \DOMDocument();
    check($doc->loadXML($xml), 'es XML bien formado');
    same('clientes', $doc->documentElement->nodeName);
    $item = $doc->getElementsByTagName('cliente')->item(0);
    same('Pérez & "Hijos"', $item->getElementsByTagName('nombre_propio')->item(0)->textContent);
    same('<Ana>', $item->getElementsByTagName('_1apellido')->item(0)->textContent);
    same('xml@cliente.test', $item->getElementsByTagName('email')->item(0)->textContent);
    same('xml', pathinfo((string) $run['file_name'], PATHINFO_EXTENSION));
});

test('los pedidos van uno por fila o una fila por línea, con los datos del pedido en cada una', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'Taza', 'sku' => 'TAZA', 'price' => 500, 'stock' => 9]);
    $b = aifecProduct($app, ['name' => 'Plato', 'sku' => 'PLATO', 'price' => 700, 'stock' => 9]);
    $id = invoicesOrder($app, [[$a, 0, 2], [$b, 0, 1]]);
    $order = $app->service('orders')->find($id);
    $one = erpRun($app, erpExportProfile($app, ['columns' => [['field' => 'number', 'header' => 'n', 'value' => ''], ['field' => 'items_count', 'header' => 'u', 'value' => '']], 'name' => 'una']));
    same(1, $one['records']);
    same('3', erpCsvRows(erpRunFile($app, $one))[1][1]);
    $lines = erpRun($app, erpExportProfile($app, ['kind' => 'order_lines', 'name' => 'lineas', 'columns' => [
        ['field' => 'number', 'header' => 'n', 'value' => ''], ['field' => 'line_sku', 'header' => 'sku', 'value' => ''], ['field' => 'line_qty', 'header' => 'q', 'value' => ''],
        ['field' => 'line_unit_price', 'header' => 'precio', 'value' => ''], ['field' => 'line_tax_rate', 'header' => 'iva', 'value' => ''], ['field' => 'line_kind', 'header' => 'tipo', 'value' => '']]]));
    $rows = erpCsvRows(erpRunFile($app, $lines));
    same((int) count($order['items']), $lines['records']);
    same(count($order['items']) + 1, count($rows));
    same([(string) $order['number'], 'TAZA', '2', '5.00', '21.00', 'product'], $rows[1]);
    same('PLATO', $rows[2][1]);
    same(array_column($order['items'], 'kind'), array_column(array_slice($rows, 1), 5));
});

test('cada tipo de dato se exporta con sus campos: clientes, productos con variantes, existencias y facturas', function (): void {
    $app = erpApp();
    erpOrder($app, 1, 2420);
    aifecProduct($app, ['name' => 'Con variantes', 'sku' => 'VAR', 'price' => 1000, 'variants' => [['sku' => 'VAR-1', 'price' => 1100, 'stock' => 3, 'label' => 'Uno']]]);
    $expect = ['customers' => ['email', 'ana@cliente.test'], 'products' => ['sku', 'VAR-1'], 'stock' => ['sku', 'VAR-1'], 'invoices' => ['number', null]];
    foreach ($expect as $kind => $pair) {
        $p = erpExportProfile($app, ['kind' => $kind, 'name' => 'tipo ' . $kind]);
        $run = erpRun($app, $p);
        same('ok', $run['status'], $kind);
        $rows = erpCsvRows(erpRunFile($app, $run));
        $header = $rows[0];
        check(in_array($pair[0], $header, true), $kind . ' trae la columna ' . $pair[0]);
        $column = array_search($pair[0], $header, true);
        $values = array_column(array_slice($rows, 1), $column);
        if ($pair[1] !== null) {
            check(in_array($pair[1], $values, true), $kind . ' trae ' . $pair[1]);
        } else {
            check($values !== [] && $values[0] !== '', 'trae el número de factura');
        }
    }
    $products = erpCsvRows(erpRunFile($app, erpRun($app, erpExportProfile($app, ['kind' => 'products', 'name' => 'productos 2']))));
    $skus = array_column(array_slice($products, 1), array_search('sku', $products[0], true));
    $parents = array_column(array_slice($products, 1), array_search('parent_sku', $products[0], true));
    check(in_array('VAR', $skus, true) && in_array('VAR-1', $skus, true), 'el producto y su variante');
    check(in_array('VAR', $parents, true), 'la variante dice de qué producto es');
});

test('los campos exportables de un perfil que no existen para ese tipo se rechazan', function (): void {
    $app = erpApp();
    $errors = $app->service('erp')->profiles()->check([
        'name' => 'X', 'kind' => 'customers', 'format' => 'csv', 'destination' => 'download', 'schedule' => 'manual',
        'columns' => [['field' => 'line_sku', 'header' => '', 'value' => '']],
    ], 'export');
    check(isset($errors['columns']), 'line_sku no es un campo de clientes');
    $errors = $app->service('erp')->profiles()->check(['name' => '', 'kind' => 'nada', 'format' => 'pdf', 'destination' => 'telepatia', 'schedule' => 'cuando-quiera'], 'export');
    foreach (['name', 'kind', 'format', 'destination', 'schedule'] as $field) {
        check(isset($errors[$field]), 'error en ' . $field);
    }
    $errors = $app->service('erp')->profiles()->check(['name' => 'X', 'kind' => 'orders', 'format' => 'csv', 'destination' => 'download', 'schedule' => 'minutes', 'schedule_every' => 2], 'export');
    check(isset($errors['schedule_every']), 'mínimo 5 minutos');
    $errors = $app->service('erp')->profiles()->check(['name' => 'X', 'kind' => 'orders', 'format' => 'csv', 'destination' => 'download', 'schedule' => 'daily', 'schedule_at' => '25:99'], 'export');
    check(isset($errors['schedule_at']), 'hora HH:MM');
});

test('solo lo nuevo: tres pasadas seguidas sin repetir ni perder ningún pedido', function (): void {
    $app = erpApp();
    $p = erpExportProfile($app, ['only_new' => 1, 'statuses' => ['processing'], 'columns' => [['field' => 'number', 'header' => 'n', 'value' => '']]]);
    $exported = [];
    $pass = static function () use ($app, $p, &$exported): array {
        $run = erpRun($app, $p);
        if ($run['status'] === 'ok') {
            foreach (array_slice(erpCsvRows(erpRunFile($app, $run)), 1) as $row) {
                $exported[] = $row[0];
            }
        }

        return $run;
    };
    $app->clock()->advance(60);
    $a = erpOrder($app);
    $app->clock()->advance(60);
    $r1 = $pass();
    same(1, $r1['records']);
    $r1b = $pass();
    same('empty', $r1b['status']);
    same(0, $r1b['records']);
    $app->clock()->advance(60);
    $b = erpOrder($app);
    $c = erpOrder($app);
    $app->clock()->advance(60);
    same(2, $pass()['records']);
    same('empty', $pass()['status']);
    // Un pedido más, en el mismo segundo que otro ya exportado: tampoco se pierde.
    $d = erpOrder($app);
    same(1, $pass()['records']);
    $numbers = array_map(static function (int $id) use ($app): string {
        return adminOrdersNumber($app, $id);
    }, [$a, $b, $c, $d]);
    sort($numbers);
    $sorted = $exported;
    sort($sorted);
    same($numbers, $sorted, 'cada pedido sale una vez, ni uno repetido ni uno perdido');
});

test('solo lo nuevo avanza por lo entregado: lo que cambia de estado después también sale', function (): void {
    $app = erpApp();
    $p = erpExportProfile($app, ['only_new' => 1, 'statuses' => ['shipped'], 'columns' => [['field' => 'number', 'header' => 'n', 'value' => '']]]);
    $app->clock()->advance(60);
    $id = erpOrder($app);
    $app->clock()->advance(60);
    same('empty', erpRun($app, $p)['status'], 'un pedido en «processing» no entra en un perfil de «shipped»');
    $app->clock()->advance(60);
    $app->service('orders')->setStatus($id, 'shipped', '', null, false);
    $app->clock()->advance(60);
    $run = erpRun($app, $p);
    same(1, $run['records']);
    same(adminOrdersNumber($app, $id), erpCsvRows(erpRunFile($app, $run))[1][0]);
});

test('un envío que falla no mueve el cursor y la pasada siguiente lo vuelve a intentar', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $p = erpExportProfile($app, ['only_new' => 1, 'destination' => 'post', 'dest_url' => 'https://erp.test/entrada', 'columns' => [['field' => 'number', 'header' => 'n', 'value' => '']]]);
    $cursors = new \Aifec\Modules\Erp\Cursors($app);
    $start = $cursors->get('export:' . $p);
    check($start !== null, 'el perfil nace con su cursor sembrado');
    $app->clock()->advance(60);
    $id = erpOrder($app);
    $app->clock()->advance(60);
    $app->http()->queue(new HttpResponse(500, [], 'caído'), new HttpResponse(200, [], 'ok'));
    $bad = erpRun($app, $p);
    same('failed', $bad['status']);
    contains('500', (string) $bad['error']);
    same($start, $cursors->get('export:' . $p), 'el cursor no se ha movido');
    $good = erpRun($app, $p);
    same('ok', $good['status']);
    same(1, $good['records']);
    check($cursors->get('export:' . $p) !== $start, 'ahora sí avanza');
    same(adminOrdersNumber($app, $id), erpCsvRows((string) $app->http()->requests[1]->body)[1][0]);
    same('empty', erpRun($app, $p)['status']);
    // El fichero de la pasada fallida queda para descargarlo.
    check($app->service('erp')->exporter()->download((int) $bad['id']) !== null, 'el fichero de la pasada que falló se conserva');
});

test('la primera pasada de un perfil nuevo con 50 pedidos antiguos exporta cero', function (): void {
    $app = erpApp();
    erpOrderRows($app, 50);
    $app->clock()->advance(3600);
    $p = erpExportProfile($app, ['only_new' => 1]);
    $run = erpRun($app, $p);
    same('empty', $run['status']);
    same(0, $run['records']);
    same(null, $run['file']);
    $all = erpExportProfile($app, ['only_new' => 0, 'name' => 'Todo']);
    same(50, erpRun($app, $all)['records']);
});

test('el perfil de ejemplo sin cursor siembra «ahora» en su primera pasada y no exporta lo antiguo', function (): void {
    $app = erpApp();
    erpOrderRows($app, 20);
    $sample = $app->db()->row("SELECT * FROM {erp_exports} WHERE kind = 'order_lines'");
    same(0, (int) $sample['enabled']);
    $cursors = new \Aifec\Modules\Erp\Cursors($app);
    same(null, $cursors->get('export:' . $sample['id']));
    $run = erpRun($app, (int) $sample['id']);
    same('empty', $run['status']);
    same(0, $run['records']);
    check($cursors->get('export:' . $sample['id']) !== null, 'la primera pasada siembra el cursor');
    contains('starting point', (string) $run['error']);
});

test('un intervalo de fechas solo exporta si se confirma el número de registros y no mueve el cursor', function (): void {
    $app = erpApp();
    erpOrderRows($app, 50, '2026-10-01 10:00:00');
    $app->clock()->advance(60);
    $p = erpExportProfile($app, ['only_new' => 1]);
    $app->clock()->advance(60);
    $exporter = $app->service('erp')->exporter();
    same(50, $exporter->countRange($p, '2026-10-01 00:00:00', '2026-10-01 23:59:59'));
    $no = erpRun($app, $p, ['range' => ['from' => '2026-10-01 00:00:00', 'to' => '2026-10-01 23:59:59']]);
    same('rejected', $no['status']);
    same(0, $no['records']);
    contains('50', (string) $no['error']);
    $wrong = erpRun($app, $p, ['range' => ['from' => '2026-10-01 00:00:00', 'to' => '2026-10-01 23:59:59', 'confirm' => 49]]);
    same('rejected', $wrong['status']);
    $before = (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $p);
    $yes = erpRun($app, $p, ['range' => ['from' => '2026-10-01 00:00:00', 'to' => '2026-10-01 23:59:59', 'confirm' => 50]]);
    same('ok', $yes['status']);
    same(50, $yes['records']);
    same($before, (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $p));
    // El «solo lo nuevo» sigue desde donde estaba: solo sale el pedido nuevo.
    erpOrder($app);
    same(1, erpRun($app, $p)['records']);
});

test('«Probar» enseña las 10 primeras filas sin mover el cursor, sin guardar y sin enviar', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    erpOrderRows($app, 25);
    $p = erpExportProfile($app, ['only_new' => 0, 'destination' => 'post', 'dest_url' => 'https://erp.test/x', 'columns' => [['field' => 'number', 'header' => 'Pedido', 'value' => ''], ['field' => 'total', 'header' => 'Total', 'value' => '']]]);
    $runsBefore = aifecOrderCount($app, 'erp_export_runs');
    $preview = $app->service('erp')->exporter()->preview($p);
    same(['Pedido', 'Total'], $preview['header']);
    same(10, count($preview['rows']));
    same('T-000001', $preview['rows'][0][0]);
    same('10.00', $preview['rows'][0][1]);
    same(0, count($app->http()->requests));
    same($runsBefore, aifecOrderCount($app, 'erp_export_runs'));
    // Con «solo lo nuevo» tampoco mueve el cursor.
    $n = erpExportProfile($app, ['only_new' => 1, 'name' => 'Nuevo']);
    $cursor = (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $n);
    $app->clock()->advance(60);
    erpOrderRows($app, 2, $app->clock()->sql());
    same(2, count($app->service('erp')->exporter()->preview($n)['rows']));
    same(2, count($app->service('erp')->exporter()->preview($n)['rows']));
    same($cursor, (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $n));
});

test('el fichero de la pasada se descarga con permiso y caduca a los 7 días', function (): void {
    $app = erpApp();
    erpOrder($app);
    $p = erpExportProfile($app);
    $run = erpRun($app, $p);
    $web = adminClient($app);
    $r = $web->get('/admin/erp/runs/' . $run['id'] . '/download');
    same(200, $r->status);
    contains('attachment', $r->headers['Content-Disposition']);
    check($r->file !== null && is_file($r->file), 'se envía el fichero');
    // Sin permiso para cambiar la configuración del ERP no se descarga (lleva datos de clientes).
    adminOrdersRole($app, 'lector', ['dashboard.view', 'erp.view']);
    $reader = adminClient($app, 'lector');
    same(403, $reader->get('/admin/erp/runs/' . $run['id'] . '/download')->status);
    same(200, $reader->get('/admin/erp/runs/' . $run['id'])->status);
    $path = $r->file;
    $app->clock()->advance(7 * 86400 + 60);
    same(null, $app->service('erp')->exporter()->download((int) $run['id']), 'pasado el plazo ya no se descarga');
    $purged = $app->service('erp')->exporter()->purge();
    same(1, $purged['files']);
    check(!is_file($path), 'la limpieza borra el fichero');
    same(1, aifecOrderCount($app, 'erp_export_runs'), 'la pasada queda en el historial');
});

test('con los avisos apagados los destinos que salen de la tienda no se ejecutan, y descargar o una carpeta privada sí', function (): void {
    $app = erpApp();
    erpOrder($app);
    erpFtp($app);
    $post = erpRun($app, erpExportProfile($app, ['destination' => 'post', 'dest_url' => 'https://erp.test/x', 'name' => 'post']));
    same('blocked', $post['status']);
    $mail = erpRun($app, erpExportProfile($app, ['destination' => 'email', 'dest_email' => 'contable@ejemplo.test', 'name' => 'mail']));
    same('blocked', $mail['status']);
    $ftp = erpRun($app, erpExportProfile($app, ['destination' => 'ftp', 'dest_host' => 'ftp.erp.test', 'dest_user' => 'u', 'name' => 'ftp']));
    same('blocked', $ftp['status']);
    same(0, count($app->http()->requests));
    same(0, count(aifecOrderMails($app, 'erp_export_file')));
    same('ok', erpRun($app, erpExportProfile($app, ['destination' => 'download', 'name' => 'dl']))['status']);
    same('ok', erpRun($app, erpExportProfile($app, ['destination' => 'folder', 'name' => 'dir']))['status']);
    // Una pasada bloqueada no mueve el cursor de un perfil «solo lo nuevo».
    $new = erpExportProfile($app, ['only_new' => 1, 'destination' => 'post', 'dest_url' => 'https://erp.test/x', 'name' => 'nuevo']);
    $cursor = (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $new);
    $app->clock()->advance(60);
    erpOrder($app);
    same('blocked', erpRun($app, $new)['status']);
    same($cursor, (new \Aifec\Modules\Erp\Cursors($app))->get('export:' . $new));
});

test('a una carpeta privada del sitio: el fichero llega a la carpeta del perfil', function (): void {
    $app = erpApp();
    erpOrder($app);
    $p = erpExportProfile($app, ['destination' => 'folder', 'dest_folder' => 'contabilidad']);
    $run = erpRun($app, $p);
    same('ok', $run['status']);
    $files = glob($app->site()->path('private/erp/out/contabilidad') . '/*.csv');
    same(1, count($files));
    same(erpRunFile($app, $run), (string) file_get_contents($files[0]));
    check(is_file($app->site()->path('private/erp') . '/.htaccess'), 'la carpeta lleva el acceso web denegado');
    // La limpieza quita lo que lleva más de los días del ajuste.
    $app->clock()->advance(20 * 86400);
    touch($files[0], $app->clock()->now() - 20 * 86400);
    $app->service('erp')->exporter()->purge();
    check(!is_file($files[0]), 'el fichero de la carpeta caduca');
});

test('por FTP: se sube con un nombre provisional y se renombra; el fallo no mueve el cursor', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $ftp = erpFtp($app);
    $p = erpExportProfile($app, ['only_new' => 1, 'destination' => 'ftp', 'dest_host' => 'ftp.erp.test', 'dest_port' => 2121, 'dest_user' => 'erp', 'dest_path' => '/entrada', 'ftp_password' => 'clave-de-mentira']);
    same('clave-de-mentira', $app->secret('erp.ftp.' . $p));
    foreach ($app->db()->select('SELECT dest FROM {erp_exports}') as $row) {
        notContains('clave-de-mentira', (string) $row['dest']);
    }
    $app->clock()->advance(60);
    erpOrder($app);
    $app->clock()->advance(60);
    $cursors = new \Aifec\Modules\Erp\Cursors($app);
    $start = $cursors->get('export:' . $p);
    $ftp->fail = true;
    $bad = erpRun($app, $p);
    same('failed', $bad['status']);
    contains('refused', (string) $bad['error']);
    same($start, $cursors->get('export:' . $p));
    $ftp->fail = false;
    $ok = erpRun($app, $p);
    same('ok', $ok['status']);
    $names = array_keys($ftp->files);
    same(1, count($names));
    check(str_starts_with($names[0], '/entrada/') && str_ends_with($names[0], '.csv'), 'sube a la carpeta pedida: ' . $names[0]);
    same('ftp.erp.test', $ftp->connections[1]['host']);
    same(2121, $ftp->connections[1]['port']);
    same('clave-de-mentira', $ftp->connections[1]['password']);
    same(false, $ftp->connections[1]['secure']);
    $ftps = erpExportProfile($app, ['destination' => 'ftps', 'dest_host' => 'ftp.erp.test', 'dest_user' => 'erp', 'name' => 'seguro']);
    erpRun($app, $ftps);
    same(true, end($ftp->connections)['secure']);
});

test('por POST a una dirección https con la clave del perfil y el nombre del fichero', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    erpOrder($app);
    $p = erpExportProfile($app, ['destination' => 'post', 'dest_url' => 'https://erp.test/ficheros', 'post_token' => 'token-de-mentira', 'format' => 'json']);
    $app->http()->queue(new HttpResponse(201, [], 'creado'));
    $run = erpRun($app, $p);
    same('ok', $run['status']);
    $request = $app->http()->requests[0];
    same('https://erp.test/ficheros', $request->url);
    same('Bearer token-de-mentira', $request->headers['Authorization']);
    same('application/json', $request->headers['Content-Type']);
    same($run['file_name'], $request->headers['X-Filename']);
    same(1, count(json_decode((string) $request->body, true)));
    // Una dirección sin https no es un destino válido.
    $errors = $app->service('erp')->profiles()->check(['name' => 'X', 'kind' => 'orders', 'format' => 'csv', 'destination' => 'post', 'dest_url' => 'http://erp.test/ficheros', 'schedule' => 'manual'], 'export');
    check(isset($errors['dest_url']), 'solo https');
});

test('por correo con el fichero adjunto', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $orderId = erpOrder($app);
    $p = erpExportProfile($app, ['destination' => 'email', 'dest_email' => 'contable@ejemplo.test', 'name' => 'Cierre mensual']);
    $run = erpRun($app, $p);
    same('ok', $run['status']);
    $mails = aifecOrderMails($app, 'erp_export_file');
    same(1, count($mails));
    same('contable@ejemplo.test', $mails[0]['to_email']);
    contains('Cierre mensual', (string) $mails[0]['subject']);
    $envelope = json_decode((string) $mails[0]['data'], true);
    same($run['file_name'], $envelope['attachments'][0]['name']);
    contains(adminOrdersNumber($app, $orderId), erpRunFile($app, $run));
});

test('FTP y FTPS solo se ofrecen si existe la extensión ftp de PHP', function (): void {
    $app = erpApp();
    $options = \Aifec\Modules\Erp\Destinations::available($app);
    same(function_exists('ftp_connect'), isset($options['ftp']));
    same(function_exists('ftp_ssl_connect'), isset($options['ftps']));
    foreach (['download', 'folder', 'post', 'email'] as $always) {
        check(isset($options[$always]), 'siempre está ' . $always);
    }
    if (!isset($options['ftp'])) {
        $errors = $app->service('erp')->profiles()->check(['name' => 'X', 'kind' => 'orders', 'format' => 'csv', 'destination' => 'ftp', 'dest_host' => 'h', 'dest_user' => 'u', 'schedule' => 'manual'], 'export');
        check(isset($errors['destination']), 'sin la extensión no se puede elegir');
    }
    check(!isset(\Aifec\Modules\Erp\Destinations::available($app, true)['post']), 'una importación no «envía»');
});

test('programación: la hora siguiente sale de ahora, se reclama una vez y una parada larga no se recupera de golpe', function (): void {
    $app = erpApp();
    erpOrder($app);
    $p = erpExportProfile($app, ['enabled' => 1, 'schedule' => 'minutes', 'schedule_every' => 15]);
    same('2026-11-12 12:15:00', (string) $app->db()->value('SELECT next_run_at FROM {erp_exports} WHERE id = ?', [$p]));
    same(null, \Aifec\Modules\Erp\ErpTasks::profiles($app), 'todavía no toca');
    $app->clock()->advance(16 * 60);
    same('1 profiles run', \Aifec\Modules\Erp\ErpTasks::profiles($app));
    same(1, aifecOrderCount($app, 'erp_export_runs', "trigger_kind = 'schedule'"));
    same('2026-11-12 12:31:00', (string) $app->db()->value('SELECT next_run_at FROM {erp_exports} WHERE id = ?', [$p]));
    same(null, \Aifec\Modules\Erp\ErpTasks::profiles($app), 'otra llamada en el mismo minuto no repite');
    // Cinco días parado: una sola pasada, no 480.
    $app->clock()->advance(5 * 86400);
    same('1 profiles run', \Aifec\Modules\Erp\ErpTasks::profiles($app));
    same(2, aifecOrderCount($app, 'erp_export_runs', "trigger_kind = 'schedule'"));
    $next = \Aifec\Kernel\Clock::parse((string) $app->db()->value('SELECT next_run_at FROM {erp_exports} WHERE id = ?', [$p]));
    check($next > $app->clock()->now(), 'la siguiente está en el futuro');
    // Apagado, no corre.
    $app->db()->update('erp_exports', ['enabled' => 0], ['id' => $p]);
    $app->clock()->advance(86400);
    same(null, \Aifec\Modules\Erp\ErpTasks::profiles($app));
});

test('programación diaria: a su hora en el huso del sitio, y cada hora', function (): void {
    $app = erpApp();
    // El sitio está en Europe/Madrid: en noviembre es UTC+1.
    same('2026-11-12 22:30:00', \Aifec\Modules\Erp\Profiles::nextRun($app, 'daily', null, '23:30', testNow()));
    same('2026-11-13 11:00:00', \Aifec\Modules\Erp\Profiles::nextRun($app, 'daily', null, '12:00', testNow()));
    same('2026-11-12 13:00:00', \Aifec\Modules\Erp\Profiles::nextRun($app, 'hourly', null, null, testNow()));
    same(null, \Aifec\Modules\Erp\Profiles::nextRun($app, 'manual', null, null, testNow()));
    // La tarea de cron está declarada.
    $names = array_column($app->service('jobs')->tasks(), 'task');
    foreach (['erp.deliveries', 'erp.profiles', 'erp.purge'] as $task) {
        check(in_array($task, $names, true), 'tarea ' . $task);
    }
});

test('cada pasada queda en el historial con sus cifras y un perfil no mueve el cursor de otro', function (): void {
    $app = erpApp();
    $a = erpExportProfile($app, ['only_new' => 1, 'name' => 'A']);
    $b = erpExportProfile($app, ['only_new' => 1, 'name' => 'B']);
    $app->clock()->advance(60);
    erpOrder($app);
    $app->clock()->advance(60);
    $run = erpRun($app, $a, ['trigger' => 'cli']);
    same(1, $run['records']);
    $row = $app->db()->find('erp_export_runs', ['id' => $run['id']]);
    same('ok', $row['status']);
    same('cli', $row['trigger_kind']);
    same('download', $row['destination']);
    check((int) $row['bytes'] > 10, 'apunta el tamaño');
    check($row['finished_at'] !== null && $row['expires_at'] !== null, 'apunta cuándo acabó y cuándo caduca el fichero');
    same(1, erpRun($app, $b)['records'], 'el otro perfil tiene su propio cursor');
    same('empty', erpRun($app, $a)['status']);
});

test('un tanda más grande que el tope del ajuste se reparte en varias pasadas sin perder nada', function (): void {
    $app = erpApp();
    $p = erpExportProfile($app, ['only_new' => 1, 'columns' => [['field' => 'number', 'header' => 'n', 'value' => '']]]);
    $app->clock()->advance(60);
    erpOrderRows($app, 5, $app->clock()->sql());
    $app->clock()->advance(60);
    $app->settings()->set('erp.export_max_rows', 2);
    $seen = [];
    for ($i = 0; $i < 4; ++$i) {
        $run = erpRun($app, $p);
        if ($run['status'] === 'ok') {
            foreach (array_slice(erpCsvRows(erpRunFile($app, $run)), 1) as $row) {
                $seen[] = $row[0];
            }
        }
    }
    same(5, count($seen));
    same(5, count(array_unique($seen)));
});
