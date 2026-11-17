<?php
/**
 * AIFEC — pruebas de Csv (lectura y escritura en flujo).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Csv;

test('Csv ida y vuelta con punto y coma, comillas, saltos de línea, emojis y celdas vacías', function (): void {
    $dir = tmpDir('csv');
    $file = $dir . '/ida.csv';
    $rows = [
        ['nombre' => 'Ana; López', 'nota' => "línea 1\nlínea 2 \"citada\""],
        ['nombre' => 'Ñu & Co 😀', 'nota' => ''],
    ];
    same(2, Csv::write($file, ['nombre', 'nota'], $rows));
    same($rows, iterator_to_array(Csv::read($file), false));
    rmTree($dir);
});

test('Csv detecta el delimitador punto y coma', function (): void {
    $dir = tmpDir('csv');
    file_put_contents($dir . '/a.csv', "nombre;nota\nAna;1,50\n");
    same([['nombre' => 'Ana', 'nota' => '1,50']], iterator_to_array(Csv::read($dir . '/a.csv'), false));
    rmTree($dir);
});

test('Csv detecta tabulador, barra vertical y coma', function (): void {
    $dir = tmpDir('csv');
    file_put_contents($dir . '/t.csv', "a\tb\n1\t2\n");
    file_put_contents($dir . '/p.csv', "a|b\n1|2\n");
    file_put_contents($dir . '/c.csv', "a,b\n1,2\n");
    same([['a' => '1', 'b' => '2']], iterator_to_array(Csv::read($dir . '/t.csv'), false));
    same([['a' => '1', 'b' => '2']], iterator_to_array(Csv::read($dir . '/p.csv'), false));
    same([['a' => '1', 'b' => '2']], iterator_to_array(Csv::read($dir . '/c.csv'), false));
    rmTree($dir);
});

test('Csv quita el BOM y convierte Windows-1252 a UTF-8', function (): void {
    $dir = tmpDir('csv');
    file_put_contents($dir . '/bom.csv', "\xEF\xBB\xBFa;b\n1;2\n");
    file_put_contents($dir . '/win.csv', "nombre;ciudad\nJos\xE9;M\xE1laga\n");
    same([['a' => '1', 'b' => '2']], iterator_to_array(Csv::read($dir . '/bom.csv'), false));
    same([['nombre' => 'José', 'ciudad' => 'Málaga']], iterator_to_array(Csv::read($dir . '/win.csv'), false));
    rmTree($dir);
});

test('Csv sin cabecera devuelve filas indexadas y salta las líneas vacías', function (): void {
    $dir = tmpDir('csv');
    file_put_contents($dir . '/h.csv', "1;2\n\n3;4\n\n");
    same([['1', '2'], ['3', '4']], iterator_to_array(Csv::read($dir . '/h.csv', ['header' => false]), false));
    file_put_contents($dir . '/b.csv', "a;b\n\n1;2\n\n");
    same([['a' => '1', 'b' => '2']], iterator_to_array(Csv::read($dir . '/b.csv'), false));
    rmTree($dir);
});

test('Csv lanza RuntimeException si el fichero no existe', function (): void {
    throws(function (): void {
        iterator_to_array(Csv::read(sys_get_temp_dir() . '/no-existe-aifec-' . bin2hex('x') . '.csv'));
    }, \RuntimeException::class);
});

test('Csv neutraliza fórmulas con apóstrofo delante', function (): void {
    same("a;b\n'=1+1;'-x\n'@SUM;'+3\n", Csv::toString(['a', 'b'], [['a' => '=1+1', 'b' => '-x'], ['a' => '@SUM', 'b' => '+3']]));
});

test('Csv escapa comillas dobles dentro de una celda y escribe nulos vacíos', function (): void {
    same("t\n\"di \"\"hola\"\"\"\n", Csv::toString(['t'], [['t' => 'di "hola"']]));
    same("t;u\n;0\n", Csv::toString(['t', 'u'], [['t' => null, 'u' => false]]));
});

test('Csv write con BOM escribe la marca al principio', function (): void {
    $dir = tmpDir('csv');
    Csv::write($dir . '/bom.csv', ['x'], [['x' => 1]]);
    same("\xEF\xBB\xBFx\n1\n", file_get_contents($dir . '/bom.csv'));
    rmTree($dir);
});
