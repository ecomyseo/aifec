<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de las plantillas de título y de descripción (librería pura): variables, separadores sueltos, recorte, caracteres raros.
 */

declare(strict_types=1);

use Aifec\Modules\Seo\Templates;
use Aifec\Modules\Seo\Verify;

require_once __DIR__ . '/helpers.php';

test('la plantilla «{name} | {shop}» se rellena con sus variables', function (): void {
    same('Silla Ergo | Muebles Ana', Templates::render('{name} | {shop}', ['name' => 'Silla Ergo', 'shop' => 'Muebles Ana']));
    same('Silla Ergo', Templates::render('{name}', ['name' => '  Silla Ergo  ']), 'las variables se recortan');
});

test('una variable ausente o vacía no deja separadores colgando', function (): void {
    same('Silla | Tienda', Templates::render('{name} | {category} | {shop}', ['name' => 'Silla', 'category' => '', 'shop' => 'Tienda']), 'separador del medio');
    same('Silla | Tienda', Templates::render('{name} | {category} | {shop}', ['name' => 'Silla', 'shop' => 'Tienda']), 'variable que no se pasó');
    same('Silla', Templates::render('{name} - {brand}', ['name' => 'Silla', 'brand' => '']), 'separador final');
    same('Tienda', Templates::render('{name} | {shop}', ['name' => '', 'shop' => 'Tienda']), 'separador inicial');
    same('Silla', Templates::render('{name}: {brand}', ['name' => 'Silla', 'brand' => '']), 'dos puntos pegados a la palabra');
    same('Silla', Templates::render('{name} ({brand})', ['name' => 'Silla', 'brand' => '']), 'paréntesis vacíos');
    same('', Templates::render('{category} | {brand}', []), 'todo vacío');
    same('Silla Ergo', Templates::render('{name} {desconocida}', ['name' => 'Silla Ergo']), 'una variable desconocida sale vacía');
});

test('un guion dentro de una palabra no se toma por un separador', function (): void {
    same('Sofá cama-nido | Tienda', Templates::render('{name} | {shop}', ['name' => 'Sofá cama-nido', 'shop' => 'Tienda']));
    same('Pack - 3 uds | Tienda', Templates::render('{name} | {shop}', ['name' => 'Pack - 3 uds', 'shop' => 'Tienda']), 'el separador que viene del dato se respeta');
});

test('el recorte no parte palabras y respeta el máximo', function (): void {
    same('aaa bbb', Templates::cut('aaa bbb ccc', 8));
    same('aaa bbb ccc', Templates::cut('aaa bbb ccc', 11), 'cabe justo: no se toca');
    same('abcdefgh', Templates::cut('abcdefghijklmnop', 8), 'una palabra más larga que el máximo se corta dura');
    $long = str_repeat('palabra ', 20);
    $cut = Templates::cut($long, Templates::TITLE_MAX);
    check(mb_strlen($cut) <= Templates::TITLE_MAX, 'un título largo queda en 65 o menos');
    check(substr($cut, -7) === 'palabra', 'termina en una palabra entera');
    $description = Templates::cut(str_repeat('Texto largo de la descripción. ', 20), Templates::DESCRIPTION_MAX, '…');
    check(mb_strlen($description) <= Templates::DESCRIPTION_MAX, 'la descripción cabe en 160 contando los puntos suspensivos');
    check(substr($description, -3) === '…' || mb_substr($description, -1) === '…', 'y avisa de que se cortó');
});

test('tildes, eñes, emojis y «&» no rompen el recorte ni la plantilla', function (): void {
    same('Café & Té ☕ | Ñandú', Templates::render('{name} | {shop}', ['name' => 'Café & Té ☕', 'shop' => 'Ñandú']));
    $text = str_repeat('ñandú ', 30);
    $cut = Templates::cut($text, 20);
    same(1, preg_match('//u', $cut), 'sigue siendo UTF-8 válido');
    check(mb_strlen($cut) <= 20, 'en caracteres, no en bytes');
    same('😀 hola', Templates::cut('😀 hola', 7), 'un emoji cuenta como un carácter');
    same('', Templates::cut('', 65), 'cadena vacía');
    same('', Templates::render('', ['name' => 'x']), 'plantilla vacía');
});

test('un código de verificación: solo letras, cifras, guion y guion bajo', function (): void {
    check(Verify::valid('abcDEF123_-xyz'), 'un código normal');
    check(!Verify::valid('abc"def12345'), 'con comillas no');
    check(!Verify::valid('abc<def12345'), 'con «<» no');
    check(!Verify::valid('<meta name="x" content="abcdefgh">'), 'la etiqueta entera no');
    check(!Verify::valid('corto'), 'demasiado corto');
    check(!Verify::valid("abcdefgh12\n"), 'un salto de línea al final tampoco');
    check(!Verify::valid(str_repeat('a', 101)), 'demasiado largo');
});
