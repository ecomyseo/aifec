<?php
/**
 * AIFEC — ERP: importación programada de existencias y precios (lo que el ERP deja para la tienda).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Http\HttpResponse;

test('existencias y precios por SKU desde un CSV de una carpeta privada', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'price' => 1000, 'stock' => 1]);
    $b = aifecProduct($app, ['name' => 'B', 'sku' => 'IM-B', 'price' => 2000, 'stock' => 2]);
    $p = erpImportProfile($app, ['col_stock' => 'Cantidad', 'col_price' => 'Precio', 'col_sku' => 'Referencia']);
    $path = erpDrop($app, 'existencias', 'stock-1.csv', "Referencia;Cantidad;Precio\nIM-A;25;19,99\nIM-B;0;1.234,50\n");
    $runs = $app->service('erp')->importer()->run($p);
    same(1, count($runs));
    same('ok', $runs[0]['status']);
    same(2, $runs[0]['records']);
    same(25, aifecOrderStock($app, $a));
    same(0, aifecOrderStock($app, $b));
    same(1999, (int) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$a]));
    same(123450, (int) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$b]));
    check(!is_file($path), 'el fichero leído sale de la carpeta');
    check(is_file(dirname($path) . '/processed/stock-1.csv'), 'y se aparta en «processed»');
    same('import', $app->db()->value('SELECT direction FROM {erp_export_runs} WHERE id = ?', [$runs[0]['id']]));
    same('stock-1.csv', $app->db()->value('SELECT file_name FROM {erp_export_runs} WHERE id = ?', [$runs[0]['id']]));
    // Sin ficheros pendientes, la pasada dice que no hay nada.
    same('empty', $app->service('erp')->importer()->run($p)[0]['status']);
});

test('el CSV puede traer solo precios, ofertas o solo existencias, y respeta el criterio de impuestos', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'price' => 1000, 'stock' => 5, 'tax_class_id' => 'standard']);
    $p = erpImportProfile($app, ['col_stock' => '', 'col_price' => 'precio', 'col_sale_price' => 'oferta', 'prices_include_tax' => 0, 'name' => 'Precios']);
    erpDrop($app, 'existencias', 'precios.csv', "sku,precio,oferta\nIM-A,100.00,80.00\n");
    $run = $app->service('erp')->importer()->run($p)[0];
    same('ok', $run['status']);
    $row = $app->db()->row('SELECT price, sale_price, stock FROM {products} WHERE id = ?', [$a]);
    same(12100, (int) $row['price']);
    same(9680, (int) $row['sale_price']);
    same(5, (int) $row['stock']);
});

test('en modo cambio la columna de existencias suma y resta', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 10]);
    $p = erpImportProfile($app, ['stock_mode' => 'delta']);
    erpDrop($app, 'existencias', 'mov.csv', "sku;stock\nIM-A;-3\nIM-A;+5\n");
    same('ok', $app->service('erp')->importer()->run($p)[0]['status']);
    same(12, aifecOrderStock($app, $a));
});

test('un fichero con el 30 % de líneas malas no aplica nada y queda rechazado con su informe', function (): void {
    $app = erpApp();
    $ids = [];
    foreach (range(1, 7) as $n) {
        $ids[$n] = aifecProduct($app, ['name' => 'P' . $n, 'sku' => 'IM-' . $n, 'stock' => 1, 'price' => 1000]);
    }
    $p = erpImportProfile($app, ['col_price' => 'precio']);
    $lines = ["sku;stock;precio"];
    foreach (range(1, 7) as $n) {
        $lines[] = 'IM-' . $n . ';50;12,00';
    }
    $lines[] = 'NO-EXISTE-1;3;1,00';
    $lines[] = 'IM-1;muchas;1,00';
    $lines[] = ';3;1,00';
    erpDrop($app, 'existencias', 'malo.csv', implode("\n", $lines) . "\n");
    $run = $app->service('erp')->importer()->run($p)[0];
    same('rejected', $run['status']);
    same(0, $run['records']);
    contains('3 of 10', (string) $run['error']);
    foreach ($ids as $id) {
        same(1, aifecOrderStock($app, $id), 'no se ha tocado nada');
    }
    $report = json_decode((string) $run['report'], true);
    same(10, $report['total']);
    same(3, $report['bad']);
    same(true, $report['rejected']);
    same(3, count($report['errors']));
    $web = adminClient($app);
    $page = $web->get('/admin/erp/runs/' . $run['id']);
    same(200, $page->status);
    contains('NO-EXISTE-1', $page->body);
});

test('un fichero con el 20 % justo de líneas malas sí se aplica y el informe dice las malas', function (): void {
    $app = erpApp();
    foreach (range(1, 8) as $n) {
        aifecProduct($app, ['name' => 'P' . $n, 'sku' => 'IM-' . $n, 'stock' => 1]);
    }
    $p = erpImportProfile($app);
    $lines = ['sku;stock'];
    foreach (range(1, 8) as $n) {
        $lines[] = 'IM-' . $n . ';9';
    }
    $lines[] = 'NO-EXISTE;4';
    $lines[] = 'OTRO-NO;4';
    erpDrop($app, 'existencias', 'casi.csv', implode("\n", $lines) . "\n");
    $run = $app->service('erp')->importer()->run($p)[0];
    same('ok', $run['status']);
    same(8, $run['records']);
    $report = json_decode((string) $run['report'], true);
    same(2, $report['bad']);
    same(['NO-EXISTE', 'OTRO-NO'], array_column($report['errors'], 'sku'));
    same(10, count($report['lines']), 'el informe tiene una apuntación por línea');
    same(8, count(array_filter($report['lines'], static function (array $l): bool {
        return $l['status'] === 'done';
    })));
    contains('There is no product', $report['errors'][0]['message']);
    same(9, (int) $app->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['IM-3']));
});

test('un SKU desconocido va al informe y nunca crea ni borra un producto', function (): void {
    $app = erpApp();
    aifecProduct($app, ['name' => 'Uno', 'sku' => 'IM-1', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Dos', 'sku' => 'IM-2', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Tres', 'sku' => 'IM-3', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Cuatro', 'sku' => 'IM-4', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Cinco', 'sku' => 'IM-5', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Seis', 'sku' => 'IM-6', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Siete', 'sku' => 'IM-7', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Ocho', 'sku' => 'IM-8', 'stock' => 1]);
    aifecProduct($app, ['name' => 'Nueve', 'sku' => 'IM-9', 'stock' => 1]);
    $before = aifecOrderCount($app, 'products');
    $p = erpImportProfile($app);
    erpDrop($app, 'existencias', 'uno.csv', "sku;stock\nIM-1;5\nIM-2;5\nIM-3;5\nIM-4;5\nIM-5;5\nIM-6;5\nIM-7;5\nIM-8;5\nIM-9;5\nFANTASMA;5\n");
    $run = $app->service('erp')->importer()->run($p)[0];
    same('ok', $run['status']);
    same($before, aifecOrderCount($app, 'products'));
    same(0, aifecOrderCount($app, 'products', 'sku = ?', ['FANTASMA']));
    same(['FANTASMA'], array_column(json_decode((string) $run['report'], true)['errors'], 'sku'));
});

test('la importación lee JSON como lista o como {items}', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1, 'price' => 1000]);
    $p = erpImportProfile($app, ['format' => 'json', 'dest_pattern' => '*.json', 'col_sku' => 'ref', 'col_stock' => 'qty', 'col_price' => 'pvp']);
    erpDrop($app, 'existencias', 'a.json', json_encode([['ref' => 'IM-A', 'qty' => 7, 'pvp' => '15.50']]));
    same('ok', $app->service('erp')->importer()->run($p)[0]['status']);
    same(7, aifecOrderStock($app, $a));
    same(1550, (int) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$a]));
    erpDrop($app, 'existencias', 'b.json', json_encode(['items' => [['ref' => 'IM-A', 'qty' => 9]]]));
    same('ok', $app->service('erp')->importer()->run($p)[0]['status']);
    same(9, aifecOrderStock($app, $a));
    erpDrop($app, 'existencias', 'c.json', '{esto no es json');
    $bad = $app->service('erp')->importer()->run($p)[0];
    same('failed', $bad['status']);
    contains('JSON', (string) $bad['error']);
});

test('un fichero al que le falta la columna que el perfil espera falla sin tocar nada', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1]);
    $p = erpImportProfile($app, ['col_stock' => 'Existencias']);
    erpDrop($app, 'existencias', 'x.csv', "sku;stock\nIM-A;99\n");
    $run = $app->service('erp')->importer()->run($p)[0];
    same('failed', $run['status']);
    contains('Existencias', (string) $run['error']);
    same(1, aifecOrderStock($app, $a));
});

test('varios ficheros pendientes se leen del más antiguo al más nuevo, cada uno en su pasada', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1]);
    $p = erpImportProfile($app);
    $one = erpDrop($app, 'existencias', 'dos.csv', "sku;stock\nIM-A;20\n");
    $two = erpDrop($app, 'existencias', 'uno.csv', "sku;stock\nIM-A;10\n");
    touch($one, testNow() - 100);
    touch($two, testNow() - 200);
    $runs = $app->service('erp')->importer()->run($p);
    same(2, count($runs));
    same(['uno.csv', 'dos.csv'], array_column($runs, 'file_name'));
    same(20, aifecOrderStock($app, $a), 'el más nuevo gana');
});

test('desde una dirección https o desde FTP, y con los avisos apagados no sale a buscarlos', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1]);
    $url = erpImportProfile($app, ['destination' => 'url', 'dest_url' => 'https://erp.test/stock.csv', 'name' => 'por url']);
    $blocked = $app->service('erp')->importer()->run($url)[0];
    same('blocked', $blocked['status']);
    same(0, count($app->http()->requests));
    $app->settings()->set('erp.outbound', true);
    $app->http()->queue(new HttpResponse(200, [], "sku;stock\nIM-A;33\n"));
    same('ok', $app->service('erp')->importer()->run($url)[0]['status']);
    same(33, aifecOrderStock($app, $a));
    $ftp = erpFtp($app);
    $ftp->files['/salida/stock.csv'] = "sku;stock\nIM-A;44\n";
    $byFtp = erpImportProfile($app, ['destination' => 'ftp', 'dest_host' => 'ftp.erp.test', 'dest_user' => 'erp', 'dest_path' => '/salida', 'name' => 'por ftp']);
    same('ok', $app->service('erp')->importer()->run($byFtp)[0]['status']);
    same(44, aifecOrderStock($app, $a));
    check(isset($ftp->files['/salida/processed/stock.csv']), 'el fichero leído se aparta en el servidor');
    $app->http()->queue(new HttpResponse(404, [], 'no hay'));
    $missing = $app->service('erp')->importer()->run($url)[0];
    same('failed', $missing['status']);
    contains('404', (string) $missing['error']);
});

test('una importación programada corre a su hora y las órdenes de consola funcionan', function (): void {
    $app = erpApp();
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'IM-A', 'stock' => 1]);
    $p = erpImportProfile($app, ['enabled' => 1, 'schedule' => 'hourly', 'name' => 'Cada hora']);
    erpDrop($app, 'existencias', 'h.csv', "sku;stock\nIM-A;8\n");
    same(null, \Aifec\Modules\Erp\ErpTasks::profiles($app));
    $app->clock()->advance(3601);
    same('1 profiles run', \Aifec\Modules\Erp\ErpTasks::profiles($app));
    same(8, aifecOrderStock($app, $a));
    $console = new \Aifec\Cli\Console(false);
    $commands = $app->modules()->definition('erp', 'cli');
    check(isset($commands['erp:export'], $commands['erp:import'], $commands['erp:deliveries']), 'las tres órdenes de consola existen');
    erpDrop($app, 'existencias', 'consola.csv', "sku;stock\nIM-A;77\n");
    same(0, $commands['erp:import']['run']($app, ['Cada hora'], $console));
    same(77, aifecOrderStock($app, $a));
    same(2, $commands['erp:import']['run']($app, ['no-existe'], $console));
    $export = erpExportProfile($app, ['name' => 'Para la consola']);
    same(0, $commands['erp:export']['run']($app, [(string) $export], $console));
    same(2, $commands['erp:export']['run']($app, ['Cada hora'], $console), 'una importación no se exporta');
    same(0, $commands['erp:deliveries']['run']($app, [], $console));
});

test('la tabla de campos exportables está completa para cada tipo', function (): void {
    foreach (array_keys(\Aifec\Modules\Erp\Sources::KINDS) as $kind) {
        $fields = \Aifec\Modules\Erp\Sources::fields($kind);
        check(count($fields) >= 8, $kind . ' tiene campos');
        foreach ($fields as $code => $def) {
            check(in_array($def[1], ['text', 'int', 'money', 'rate', 'date', 'bool'], true), $kind . '.' . $code . ' tiene un tipo conocido');
        }
        same(array_keys($fields), array_column(\Aifec\Modules\Erp\Sources::defaultColumns($kind), 'field'));
    }
});
