<?php
/**
 * AIFEC — pruebas del CSV de ida y vuelta de la interfaz (también por el panel y por la consola) y de la lista real de cadenas
 * sacada del código.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Modules\Translations\Csv;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\SiteCatalog;
use Aifec\Modules\Translations\Strings;

require_once __DIR__ . '/TranslationsKit.php';

test('el CSV lleva la marca UTF-8, la cabecera y cada cadena con la traducción que tiene ahora (la del sitio antes que la de serie)', function (): void {
    $app = trxApp();
    trxCodeCatalog($app, 'fr', ['Add to cart' => 'Ajouter', 'Checkout' => 'Commander']);
    (new SiteCatalog($app))->update('fr', ['Checkout' => 'Payer']);
    $csv = Csv::export($app, 'fr');
    same("\xEF\xBB\xBF", substr($csv, 0, 3));
    $rows = Csv::parse(substr($csv, 3));
    same(['source', 'translation', 'area', 'origin'], $rows[0]);
    same(7, count($rows), 'cabecera y las seis cadenas');
    $byKey = [];
    foreach (array_slice($rows, 1) as $row) {
        $byKey[$row[0]] = $row;
    }
    same(['Add to cart', 'Ajouter', 'shop', 'catalog'], $byKey['Add to cart']);
    same('Payer', $byKey['Checkout'][1]);
    same('', $byKey['Hello {name}'][1]);
    same('panel', $byKey['Orders'][2]);
});

test('ida y vuelta con «&», comillas y saltos de línea: lo importado es lo exportado', function (): void {
    $app = trxApp();
    $tricky = "Payer & \"vite\", «ya»\nsegunda línea; con punto y coma";
    (new SiteCatalog($app))->update('fr', ['Checkout' => $tricky, 'Hello {name}' => 'Bonjour {name}, ça va ?']);
    $csv = Csv::export($app, 'fr');
    $other = trxApp();
    $result = Csv::import($other, 'fr', $csv);
    same(2, $result['saved']);
    same([], $result['rejected']);
    same(['Checkout' => $tricky, 'Hello {name}' => 'Bonjour {name}, ça va ?'], (new SiteCatalog($other))->all('fr'));
    same(0, Csv::import($other, 'fr', $csv)['saved'], 'importar otra vez lo mismo no cambia nada');
    same(2, Csv::import($other, 'fr', $csv)['unchanged']);
});

test('se lee con punto y coma y sin cabecera (la primera columna es el original)', function (): void {
    $app = trxApp();
    $result = Csv::import($app, 'fr', "Checkout;Commander\r\n\"Add to cart\";\"Ajouter \"\"vite\"\"\"\r\n");
    same(2, $result['saved']);
    same(['Add to cart' => 'Ajouter "vite"', 'Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'));
});

test('al importar se rechaza cada fila que no vale, con su línea y su motivo, y se guardan las buenas', function (): void {
    $app = trxApp();
    trxCodeCatalog($app, 'fr', ['Orders' => 'Commandes']);
    $csv = "source,translation\n"
        . "Hello {name},Bonjour\n"
        . "\"{n} item|{n} items\",{n} articles\n"
        . "Inventada,X\n"
        . "Checkout,\n"
        . "Orders,Commandes\n"
        . "Add to cart,<b>Ajouter</b>\n"
        . "Save,Enregistrer\n";
    $result = Csv::import($app, 'fr', $csv);
    same(1, $result['saved']);
    same(1, $result['unchanged'], 'igual a la de serie');
    same(1, $result['skipped'], 'vacía');
    same([2, 3, 4, 7], array_column($result['rejected'], 'line'));
    contains('{name}', $result['rejected'][0]['reason']);
    contains('2 formas', $result['rejected'][1]['reason']);
    contains('no está en el código', $result['rejected'][2]['reason']);
    contains('HTML', $result['rejected'][3]['reason']);
    same(['Save' => 'Enregistrer'], (new SiteCatalog($app))->all('fr'));
});

test('importar una traducción quita su propuesta pendiente de la IA', function (): void {
    $app = trxApp();
    (new Proposals($app))->put('fr', 'shop', 'Checkout', 'Checkout', 'Passer commande');
    Csv::import($app, 'fr', "Checkout,Commander\n");
    same(0, (new Proposals($app))->count('fr', 'shop'));
});

test('por el panel: el CSV se descarga como fichero y se importa pegado, con el resumen y las filas rechazadas', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    contains('data-tr-export', $page->body);
    $file = $web->get('/admin/translations/fr/export.csv');
    same(200, $file->status);
    contains('text/csv', $file->headers['Content-Type']);
    contains('attachment; filename="translations-fr.csv"', $file->headers['Content-Disposition']);
    contains('Hello {name}', $file->body);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/fr/import', ['_token' => aifecToken($web), 'csv' => "Checkout,Commander\nHello {name},Bonjour\n"]);
    same(['Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'));
    $back = $web->follow()->body;
    contains('1 guardadas', $back);
    contains('Línea 2: Falta el marcador {name}', $back);
    $web->post('/admin/translations/fr/import', ['_token' => aifecToken($web), 'csv' => '']);
    contains('Pega el CSV o elige un fichero', $web->follow()->body);
});

test('por la consola: exportar a un fichero e importarlo en otro sitio', function (): void {
    $app = trxApp();
    (new SiteCatalog($app))->update('fr', ['Checkout' => 'Commander & payer']);
    $file = tmpDir('aifec-trx-csv') . '/fr.csv';
    $io = new Console(false);
    $commands = $io->commands($app);
    same(0, $commands['translations:export']['run']($app, ['fr', 'out' => $file], $io));
    contains('Written: ', $io->output);
    $other = trxApp();
    $io2 = new Console(false);
    same(0, $io2->commands($other)['translations:import']['run']($other, ['fr', $file], $io2));
    contains('saved 1', $io2->output);
    same('Commander & payer', (new SiteCatalog($other))->all('fr')['Checkout']);
    file_put_contents($file, "Hello {name},Bonjour\n");
    $io3 = new Console(false);
    same(1, $io3->commands($other)['translations:import']['run']($other, ['fr', $file], $io3));
    contains('line 1', $io3->output);
    same(2, $io3->commands($other)['translations:import']['run']($other, ['xx', $file], $io3));
});

test('la lista real de cadenas sale del código y separa la tienda del panel', function (): void {
    $app = testApp(['modules' => ['translations', 'catalog'], 'settings' => ['i18n.languages' => ['es', 'fr']]]);
    $app->boot();
    $all = Strings::all($app);
    check(count($all) > 100, 'muchas cadenas (' . count($all) . ')');
    same('shop', $all['In stock']['area']);
    same('catalog', $all['In stock']['origin']);
    same('panel', $all['See what is translated in each language']['area'], 'los permisos de admin.php son del panel');
    same('translations', $all['See what is translated in each language']['origin']);
    check(isset($all['{n} item|{n} items']), 'los plurales salen con su barra');
    same($all, Strings::all($app), 'la segunda vez sale de la caché, igual');
    same('panel', Strings::area('modules/mail/views/admin/edit.php'));
    same('panel', Strings::area('core/src/Admin/Menu.php'));
    same('shop', Strings::area('themes/base/templates/product.php'));
});
