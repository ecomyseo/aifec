<?php
/**
 * AIFEC — pruebas de las piezas del CSV: lectura por posición, reconocimiento de cabeceras y validación de una fila.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Tools\CsvColumns;
use Aifec\Modules\Tools\CsvStream;
use Aifec\Modules\Tools\RowError;
use Aifec\Modules\Tools\RowParser;

require_once __DIR__ . '/helpers.php';

test('el lector reconoce el separador por la cabecera, quita la marca BOM y respeta las comillas con saltos de línea', function (): void {
    foreach ([';' => 'puntoycoma', ',' => 'coma', "\t" => 'tabulador', '|' => 'barra'] as $sep => $name) {
        $text = "\xEF\xBB\xBF" . 'sku' . $sep . 'name' . $sep . 'summary' . "\r\n"
            . 'A1' . $sep . '"Con ' . $sep . ' dentro"' . $sep . '"línea 1' . "\n" . 'línea 2"' . "\r\n"
            . "\r\n"
            . 'A2' . $sep . 'Dos' . $sep . '' . "\r\n";
        $stream = CsvStream::open(toolsWriteCsv($text, $name . '.csv'));
        same(['sku', 'name', 'summary'], $stream->header(), 'cabecera con ' . $name);
        same($sep, $stream->delimiter());
        same(['A1', 'Con ' . $sep . ' dentro', "línea 1\nlínea 2"], $stream->next());
        same(['A2', 'Dos', ''], $stream->next(), 'la línea vacía se salta');
        same(null, $stream->next());
        $stream->close();
    }
});

test('el lector retoma por la posición donde se quedó, sin volver a leer lo anterior', function (): void {
    $text = "sku;name\nA;Uno\nB;Dos\nC;Tres\nD;Cuatro\n";
    $path = toolsWriteCsv($text);
    $first = CsvStream::open($path);
    same(['A', 'Uno'], $first->next());
    same(['B', 'Dos'], $first->next());
    $position = $first->position();
    $first->close();

    $second = CsvStream::open($path, ';', $position);
    same(['sku', 'name'], $second->header());
    same(['C', 'Tres'], $second->next());
    same(['D', 'Cuatro'], $second->next());
    same(null, $second->next());
    $second->close();
});

test('el lector pasa a UTF-8 un fichero guardado en Windows-1252 y falla con un fichero vacío o inexistente', function (): void {
    $latin = mb_convert_encoding("sku;name\nA;Año nuevo, camión\n", 'Windows-1252', 'UTF-8');
    check(!Str_isUtf8($latin), 'el fichero de prueba no es UTF-8');
    $stream = CsvStream::open(toolsWriteCsv($latin));
    same(['A', 'Año nuevo, camión'], $stream->next());
    $stream->close();

    throws(function (): void {
        CsvStream::open(toolsWriteCsv(''));
    }, \RuntimeException::class);
    throws(function (): void {
        CsvStream::open('/no/existe.csv');
    }, \RuntimeException::class);
});

test('las cabeceras se reconocen, lo que no se conoce se avisa y los atributos nuevos se anuncian', function (): void {
    $app = toolsCsvApp();
    $app->db()->insert('attributes', ['code' => 'material', 'type' => 'select', 'unit' => null, 'filterable' => 1, 'visible' => 1, 'variant' => 0, 'position' => 0]);
    $a = CsvColumns::analyse(['SKU', ' Price ', 'attribute:Material', 'attribute:talla', 'name:en', 'name:fr', 'name:es', 'color', 'attribute:mal código', 'price', '', 'parent_sku'], $app);

    same(['kind' => 'fixed', 'key' => 'sku'], $a['columns'][0]);
    same(['kind' => 'fixed', 'key' => 'price'], $a['columns'][1]);
    same(['kind' => 'attribute', 'key' => 'material'], $a['columns'][2]);
    same(['kind' => 'attribute', 'key' => 'talla'], $a['columns'][3]);
    same(['kind' => 'name', 'key' => 'en'], $a['columns'][4]);
    same(['kind' => 'fixed', 'key' => 'name'], $a['columns'][6], 'name:es (el idioma principal) es el nombre');
    same(['kind' => 'fixed', 'key' => 'parent_sku'], $a['columns'][11]);
    same(['name:fr', 'color', 'attribute:mal código', 'price'], $a['unknown'], 'un idioma que no hay, una columna ajena, un código malo y una repetida');
    same(['talla'], $a['new_attributes']);
    check(CsvColumns::hasSku($a['columns']));
    check(!CsvColumns::hasSku(CsvColumns::analyse(['name', 'price'], $app)['columns']));
});

test('la cabecera de exportación lleva las columnas fijas, los atributos del sitio y un nombre por cada otro idioma', function (): void {
    $app = toolsCsvApp();
    $app->db()->insert('attributes', ['code' => 'color', 'type' => 'select', 'unit' => null, 'filterable' => 1, 'visible' => 1, 'variant' => 1, 'position' => 1]);
    $header = CsvColumns::exportHeader($app);
    same(array_merge(CsvColumns::FIXED, ['attribute:color', 'meta_title', 'meta_description', 'name:en']), $header);
});

test('una fila bien escrita se convierte en datos tipados y lo que viene vacío queda sin cambiar', function (): void {
    $columns = [
        0 => ['kind' => 'fixed', 'key' => 'sku'], 1 => ['kind' => 'fixed', 'key' => 'price'], 2 => ['kind' => 'fixed', 'key' => 'stock'],
        3 => ['kind' => 'fixed', 'key' => 'status'], 4 => ['kind' => 'fixed', 'key' => 'categories'], 5 => ['kind' => 'fixed', 'key' => 'images'],
        6 => ['kind' => 'attribute', 'key' => 'color'], 7 => ['kind' => 'name', 'key' => 'en'], 8 => ['kind' => 'fixed', 'key' => 'name'],
        9 => ['kind' => 'fixed', 'key' => 'weight'],
    ];
    $row = RowParser::parse([' P1 ', '1.999,50', '12', 'Published', 'Muebles > Sillas | Hogar|  | ', 'https://x.test/a.jpg|https://x.test/b.png#top', 'Rojo', 'Chair', '', ''], $columns);
    same('P1', $row['sku']);
    same(199950, $row['price']);
    same(12, $row['stock']);
    same('published', $row['status']);
    same([['Muebles', 'Sillas'], ['Hogar']], $row['categories']);
    same(['https://x.test/a.jpg', 'https://x.test/b.png#top'], $row['images']);
    same(['color' => 'Rojo'], $row['attributes']);
    same(['en' => 'Chair'], $row['names']);
    same(null, $row['name'], 'una celda vacía no cambia nada');
    same(null, $row['weight']);
    same(null, $row['compare_price'], 'una columna que no está tampoco');
});

test('una fila mal escrita lanza RowError con su motivo', function (): void {
    $columns = [0 => ['kind' => 'fixed', 'key' => 'sku'], 1 => ['kind' => 'fixed', 'key' => 'price'], 2 => ['kind' => 'fixed', 'key' => 'stock'],
        3 => ['kind' => 'fixed', 'key' => 'status'], 4 => ['kind' => 'fixed', 'key' => 'images'], 5 => ['kind' => 'fixed', 'key' => 'name']];
    $cases = [
        [['', '1', '1', '', '', 'x'], 'sku is missing'],
        [['A', 'abc', '1', '', '', 'x'], 'invalid amount'],
        [['A', '1', '-3', '', '', 'x'], 'whole number'],
        [['A', '1', '1,5', '', '', 'x'], 'whole number'],
        [['A', '1', '1', 'activo', '', 'x'], 'draft, published or archived'],
        [['A', '1', '1', '', 'ftp://x.test/a.jpg', 'x'], 'image address'],
        [[str_repeat('a', 65), '1', '1', '', '', 'x'], 'longer than 64'],
        [['A', '1', '1', '', '', str_repeat('ñ', 192)], 'longer than 191'],
    ];
    foreach ($cases as $case) {
        try {
            RowParser::parse($case[0], $columns);
            check(false, 'debería fallar: ' . $case[1]);
        } catch (RowError $e) {
            contains($case[1], $e->getMessage());
        }
    }
});

test('el apóstrofo que protege una fórmula se quita; los demás apóstrofos se quedan', function (): void {
    same('=SUMA(1)', RowParser::unguard("'=SUMA(1)"));
    same('-5% de oferta', RowParser::unguard("'-5% de oferta"));
    same('+34 999 000 001', RowParser::unguard("'+34 999 000 001"));
    same('@casa', RowParser::unguard("'@casa"));
    same("'Hola", RowParser::unguard("'Hola"));
    same("'", RowParser::unguard("'"));
    same('', RowParser::unguard(''));
});

if (!function_exists('Str_isUtf8')) {
    function Str_isUtf8(string $s): bool
    {
        return \Aifec\Util\Str::isUtf8($s);
    }
}
