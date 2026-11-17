<?php
/**
 * AIFEC — la comprobación final con sus tres líneas nuevas («precios con impuestos idénticos: n de n», «direcciones antiguas que redirigen:
 * n de n» y «textos recortados: n» con enlace al informe) y la ayuda de import:run, que nombra cada opción que el código lee.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Modules\Import\PriceCheck;
use Aifec\Modules\Import\Report;
use Aifec\Modules\Import\Types;
use Aifec\Modules\Import\Verify;

require_once __DIR__ . '/fixtures/E2eKit.php';

/**
 * Las filas de una parte de la comprobación.
 *
 * @param array<string,mixed> $run
 *
 * @return array<int,array<string,mixed>>
 */
function mcRows(array $run, string $section): array
{
    return array_values(array_filter($run['checksums']['verify']['rows'], static function (array $row) use ($section): bool {
        return $row['section'] === $section;
    }));
}

/** La fila resumen de los precios. @return array<string,mixed> */
function mcPrices(array $run): array
{
    $rows = array_values(array_filter(mcRows($run, 'prices'), static function (array $row): bool {
        return $row['label'] === 'Prices with tax identical';
    }));
    same(1, count($rows), 'una fila resumen de precios');

    return $rows[0];
}

/**
 * Una orden de consola de la migración.
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string}
 */
function mcCli(\Aifec\Kernel\App $app, string $command, array $args): array
{
    $io = new Console(false);
    $commands = $io->commands($app);
    $code = $commands[$command]['run']($app, $args, $io);

    return [(int) $code, $io->output];
}

/**
 * Una migración entera de la copia de WooCommerce de ejemplo.
 *
 * @return array{0:\Aifec\Kernel\App,1:array<string,mixed>,2:int}
 */
function mcWoo(): array
{
    [$app] = e2eApp();
    $id = e2eWooDraft($app, e2eWooPdo(true, true), ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], 'la migración termina: ' . (string) $run['pause_reason']);

    return [$app, $run, $id];
}

e2eTest('comprobación: «precios con impuestos idénticos» cuenta TODOS los productos, ofertas y variantes, no una muestra', function (): void {
    [$app, $run] = e2ePsMigrated();
    $prices = mcPrices($run);
    same($prices['source'], $prices['here'], 'n de n');
    $products = (int) $app->db()->value("SELECT COUNT(*) FROM {import_map} WHERE type = 'products'");
    $variants = (int) $app->db()->value("SELECT COUNT(*) FROM {import_map} WHERE type = 'variants'");
    check($prices['source'] >= $products + $variants, 'al menos un precio por producto y por variante: ' . $prices['source'] . ' contra ' . ($products + $variants));
    same((int) $app->db()->value('SELECT COUNT(*) FROM {import_map} WHERE type = ?', [PriceCheck::TYPE]), $prices['source'], 'se comprobaron todos los apuntados');
});

e2eTest('comprobación: un precio de variante estropeado después sale en rojo con su producto y su variante (import:verify)', function (): void {
    [$app, , $id] = e2ePsMigrated();
    $app->db()->run("UPDATE {variants} SET price = price + 1 WHERE sku = 'SILLA-G-ROB'");
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code, $out);
    contains('WRONG Prices with tax identical', $out);
    contains('variant SILLA-G-ROB: price with tax', $out);
    $prices = mcPrices(importRun($app, $id));
    same(1, (int) $prices['source'] - (int) $prices['here'], 'y en el recuento falta exactamente uno');
});

e2eTest('comprobación: un precio de oferta estropeado después también sale en rojo', function (): void {
    [$app, , $id] = e2ePsMigrated();
    $app->db()->run("UPDATE {products} SET sale_price = sale_price + 1 WHERE sku = 'SILLON-4'");
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code, $out);
    contains('sale price with tax', $out);
    contains('SILLON-4', $out);
});

e2eTest('comprobación: al dejar el precio como estaba, import:verify vuelve al verde', function (): void {
    [$app, , $id] = e2ePsMigrated();
    $app->db()->run("UPDATE {variants} SET price = price + 7 WHERE sku = 'SILLA-G-ROB'");
    [$code] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code);
    $app->db()->run("UPDATE {variants} SET price = price - 7 WHERE sku = 'SILLA-G-ROB'");
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code, $out);
});

e2eTest('comprobación: WooCommerce también cuenta todos sus precios con impuestos', function (): void {
    [, $run] = mcWoo();
    $prices = mcPrices($run);
    same($prices['source'], $prices['here']);
    check($prices['source'] >= 6, 'productos y variaciones: ' . $prices['source']);
});

e2eTest('comprobación: las direcciones antiguas son una muestra de hasta 50 con su suma «n de n»', function (): void {
    [, $run] = e2ePsMigrated();
    $rows = mcRows($run, 'addresses');
    same('Old addresses that work or redirect', $rows[0]['label'], 'la suma va primero');
    same($rows[0]['source'], $rows[0]['here'], 'todas');
    same(count($rows) - 1, $rows[0]['source'], 'y cada una con su fila');
    same(50, Verify::SAMPLE_PATHS);
    check($rows[0]['source'] <= 50 && $rows[0]['source'] > 10, 'más que las diez de antes: ' . $rows[0]['source']);
});

e2eTest('comprobación: una dirección antigua que se borra después sale en rojo y la suma lo cuenta', function (): void {
    [$app, $run, $id] = e2ePsMigrated();
    $path = $run['cursor']['ev']['paths'][0];
    $app->db()->run('DELETE FROM {slugs} WHERE path = ?', [\Aifec\Kernel\Slugs::clean((string) $path[3])]);
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code, $out);
    contains('WRONG Old addresses that work or redirect', $out);
    $summary = mcRows(importRun($app, $id), 'addresses')[0];
    same(1, (int) $summary['source'] - (int) $summary['here'], 'falta una');
});

e2eTest('comprobación: una dirección de un producto sin publicar no cuenta (en el origen tampoco respondía)', function (): void {
    [, $run] = mcWoo();
    foreach (array_column($run['cursor']['ev']['paths'], 3) as $path) {
        notContains('lampara-borrador', (string) $path, 'el borrador no entra en la muestra');
    }
});

e2eTest('comprobación: «textos recortados» cuenta lo recortado, no es un fallo y enlaza al informe', function (): void {
    [$app] = e2eApp();
    $pdo = e2ePsPdo();
    $long = trim(str_repeat('Mesa ñandú árbol café roble ', 20));
    $pdo->prepare('UPDATE `ps_product_lang` SET name = ? WHERE id_product = 3 AND id_lang = 1')->execute([$long]);
    $id = e2ePsDraft($app, $pdo, ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    $rows = array_values(array_filter(mcRows($run, 'counts'), static function (array $row): bool {
        return $row['label'] === 'Texts cut to fit their field';
    }));
    same(1, count($rows), 'la fila está');
    check((int) $rows[0]['here'] >= 1, 'cuenta lo recortado: ' . $rows[0]['here']);
    same(true, $rows[0]['ok'], 'no es un fallo');
    same('notes', $rows[0]['link'], 'y apunta a las notas del informe');
    same([], e2eRed($run['checksums']['verify']), 'la comprobación sigue en verde');
    $shown = array_values(array_filter((new Report($app, e2eService($app)->runs()))->verifyRows($run, 'text'), static function (array $row): bool {
        return $row['label'] === 'Texts cut to fit their field';
    }));
    same('notes', $shown[0]['link']);
    $notes = implode(' ', array_column(e2eService($app)->runs()->logs($id, 'products', 'note', 50), 'reason'));
    contains('product_i18n.name (', $notes);
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code, $out);
    contains('Texts cut to fit their field', $out);
});

e2eTest('comprobación: sin nada recortado no sale la fila de textos recortados', function (): void {
    [, $run] = e2ePsMigrated();
    same([], array_values(array_filter(mcRows($run, 'counts'), static function (array $row): bool {
        return $row['label'] === 'Texts cut to fit their field';
    })));
});

test('la ayuda de import:run nombra CADA opción que el código de la orden lee', function (): void {
    $help = (new Console(false))->commands(importApp())['import:run']['help'];
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/ImportCli.php');
    $read = [];
    foreach (['run', 'connection'] as $method) {
        $start = (int) strpos($source, 'function ' . $method . '(');
        $end = strpos($source, "\n    /**", $start);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
        preg_match_all("/\\\$args\\['([a-z][a-z-]*)'\\]/", $body, $found);
        foreach ($found[1] as $name) {
            $read[$name] = true;
        }
        preg_match_all("/self::option\\(\\\$args, '([a-z][a-z_-]*)'\\)/", $body, $found);
        foreach ($found[1] as $name) {
            $read[str_replace('_', '-', $name)] = true;
        }
    }
    check(count($read) >= 15, 'el código lee muchas opciones: ' . implode(', ', array_keys($read)));
    foreach (array_keys($read) as $name) {
        contains('--' . $name, $help, 'la ayuda nombra --' . $name);
    }
});

test('import:run --only y --types: la ayuda los nombra y hacen lo mismo', function (): void {
    $app = importApp();
    importFiveTypes();
    $help = (new Console(false))->commands($app)['import:run']['help'];
    contains('--only=', $help);
    contains('--types=', $help);
    $source = ['source' => 'woocommerce', 'mode' => 'public', 'url' => 'https://origen.test'];
    [$code, $out] = mcCli($app, 'import:run', ['dry-run' => true, 'only' => 'categories,settings'] + $source);
    same(0, $code, $out);
    same(['settings', 'categories'], importRun($app, 1)['options']['types'], '--only elige los tipos, en el orden de siempre');
    [$code, $out] = mcCli($app, 'import:run', ['dry-run' => true, 'types' => 'products'] + $source);
    same(0, $code, $out);
    same(['products'], importRun($app, 2)['options']['types'], '--types es lo mismo');
    [$code, $out] = mcCli($app, 'import:run', ['dry-run' => true, 'only' => 'cosas'] + $source);
    same(2, $code, 'un tipo que no existe se rechaza');
    contains('Unknown types', $out);
});

test('la ayuda de import:run conserva lo de siempre: --yes, --dry-run y --since', function (): void {
    $help = (new Console(false))->commands(importApp())['import:run']['help'];
    contains('--yes', $help);
    contains('--dry-run', $help);
    contains('--since=YYYY-MM-DD', $help);
    check(count(Types::ORDER) >= 15);
});

test('las cadenas nuevas de notas y de la comprobación están traducidas en lang/es.php', function (): void {
    $catalog = require dirname(__DIR__) . '/lang/es.php';
    foreach ([
        'Some texts were longer than their field and were cut: {fields}',
        'A price cannot be written to the cent with prices without taxes: it differs by a cent from the source',
        'The source writes prices without taxes with more than two decimals: here prices keep including taxes so that every product costs exactly the same',
        'The stock was shared by all the variations in the source: here every variation has its own, so each one got the whole quantity',
        'Some variations had no stock control in the source and have no stock here: here the stock is controlled for the whole product',
        'Prices with tax identical', 'Old addresses that work or redirect', 'Texts cut to fit their field', 'Prices with tax', 'See the notes',
    ] as $key) {
        check(isset($catalog[$key]) && $catalog[$key] !== '', 'traducida: ' . $key);
    }
    contains('Some texts were longer than their field and were cut: {fields}', implode("\n", Report::templates()));
    contains('product_i18n.name (500 → 191)', Report::translateNote('Some texts were longer than their field and were cut: product_i18n.name (500 → 191)'), 'el valor se conserva al traducir');
});
