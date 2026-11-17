<?php
/**
 * AIFEC — pruebas de Arr (arrays).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Arr;

test('Arr::get lee rutas con puntos, índices y valores null existentes', function (): void {
    $a = ['a' => ['b' => ['x', 'y']], 'n' => null];
    same('y', Arr::get($a, 'a.b.1'));
    same('def', Arr::get($a, 'a.z', 'def'));
    same(null, Arr::get($a, 'n', 'def'));
    same('def', Arr::get($a, 'a.b.5', 'def'));
    same('def', Arr::get($a, 'a.b.1.x', 'def'));
});

test('Arr::set crea niveles y sustituye escalares por arrays', function (): void {
    $a = [];
    Arr::set($a, 'x.y.z', 1);
    same(['x' => ['y' => ['z' => 1]]], $a);

    $b = ['x' => 5];
    Arr::set($b, 'x.y', 2);
    same(['x' => ['y' => 2]], $b);
});

test('Arr::has distingue una clave con null de una clave ausente', function (): void {
    $a = ['a' => ['b' => 1], 'n' => null];
    check(Arr::has($a, 'a.b'), 'ruta existente');
    check(Arr::has($a, 'n'), 'null existente cuenta');
    check(!Arr::has($a, 'zz'), 'ruta ausente');
    check(!Arr::has($a, 'a.b.c'), 'ruta más profunda que el valor');
});

test('Arr::forget quita una ruta y no hace nada si falta un nivel', function (): void {
    $c = ['a' => ['b' => 1, 'c' => 2]];
    Arr::forget($c, 'a.b');
    same(['a' => ['c' => 2]], $c);
    Arr::forget($c, 'x.y');
    same(['a' => ['c' => 2]], $c);
});

test('only y except filtran por claves', function (): void {
    same(['a' => 1, 'c' => 3], Arr::only(['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c', 'z']));
    same(['b' => 2], Arr::except(['a' => 1, 'b' => 2], ['a']));
    same([], Arr::only([], ['a']));
});

test('pluck saca una columna y puede indexarla por otra', function (): void {
    $rows = [['id' => 1, 'n' => 'a'], ['id' => 2, 'n' => 'b']];
    same(['a', 'b'], Arr::pluck($rows, 'n'));
    same([1 => 'a', 2 => 'b'], Arr::pluck($rows, 'n', 'id'));
    same([null], Arr::pluck([['x' => 1]], 'n'));
});

test('groupBy agrupa por valor y mete las filas sin clave en el grupo vacío', function (): void {
    $rows = [['t' => 'x', 'v' => 1], ['t' => 'y', 'v' => 2], ['t' => 'x', 'v' => 3]];
    same(['x' => [$rows[0], $rows[2]], 'y' => [$rows[1]]], Arr::groupBy($rows, 't'));
    same([''=> [['v' => 9]]], Arr::groupBy([['v' => 9]], 't'));
});

test('keyBy indexa por clave y deja al final las filas sin clave válida', function (): void {
    same([7 => ['id' => 7, 'v' => 'a']], Arr::keyBy([['id' => 7, 'v' => 'a']], 'id'));
    same([0 => ['v' => 'sin id']], Arr::keyBy([['v' => 'sin id']], 'id'));
});

test('first devuelve el primer valor que cumple o el valor por defecto', function (): void {
    same(2, Arr::first([1, 2, 3], function ($v) { return $v > 1; }));
    same('def', Arr::first([], null, 'def'));
    same(1, Arr::first([1]));
    same(null, Arr::first([null, 5]));
});

test('flatten aplana hasta la profundidad pedida', function (): void {
    same([1, 2, 3, 4], Arr::flatten([1, [2, [3, [4]]]]));
    same([1, 2, [3]], Arr::flatten([1, [2, [3]]], 1));
    same([], Arr::flatten([]));
});

test('wrap: null y false dan vacío, arrays pasan tal cual, lo demás se envuelve', function (): void {
    same([], Arr::wrap(null));
    same([], Arr::wrap(false));
    same([1], Arr::wrap([1]));
    same(['x'], Arr::wrap('x'));
    same([0], Arr::wrap(0));
    same([''], Arr::wrap(''));
});

test('isAssoc separa listas de mapas', function (): void {
    check(Arr::isAssoc(['a' => 1]), 'clave de texto');
    check(!Arr::isAssoc([1, 2]), 'lista');
    check(!Arr::isAssoc([]), 'vacío');
    check(Arr::isAssoc([1 => 'a']), 'empieza en 1');
});

test('sortBy ordena por columna de forma estable, también al revés', function (): void {
    $rows = [['n' => 'b', 'i' => 1], ['n' => 'a', 'i' => 2], ['n' => 'b', 'i' => 3], ['n' => 'a', 'i' => 4]];
    $asc = array_column(Arr::sortBy($rows, 'n'), 'i');
    same([2, 4, 1, 3], $asc);
    $desc = array_column(Arr::sortBy($rows, 'n', true), 'i');
    same([1, 3, 2, 4], $desc);
    same([], Arr::sortBy([], 'n'));
});
