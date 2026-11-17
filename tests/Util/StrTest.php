<?php
/**
 * AIFEC — pruebas de Str (cadenas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Str;

test('slug: diez ejemplos con tildes, eñes, emojis y signos', function (): void {
    same('anadir-mas', Str::slug('¡Añadir & Más!'));
    same('cancion-del-nino', Str::slug('Canción del niño'));
    same('nandu-u', Str::slug('Ñandú Ü'));
    same('strasse', Str::slug('Straße'));
    same('oeuvre-aether', Str::slug('Œuvre Æther'));
    same('', Str::slug('  --  '));
    same('', Str::slug(''));
    same('creme-brulee-a-la-facon', Str::slug('Crème brûlée à la façon'));
    same('hola', Str::slug('😀 Hola 😀'));
    same('ca-va-yes-no', Str::slug('Ça va? Yes/No'));
});

test('slug: letra combinada (a + acento suelto) se trata como la letra acentuada', function (): void {
    same('abc', Str::slug("a\u{0301}bc"));
});

test('slug: corte por guion sin pasar de la longitud', function (): void {
    same('uno-dos', Str::slug('uno dos tres', 7));
    same('uno', Str::slug('uno dos tres', 6));
    same('abcd', Str::slug('abcdefghij', 4));
    same('', Str::slug('x', 0));
});

test('studly, kebab, snake y camel convierten entre convenciones', function (): void {
    same('CmsPages', Str::studly('cms-pages'));
    same('MiModuloV2', Str::studly('mi_modulo v2'));
    same('', Str::studly(''));
    same('cms-pages', Str::kebab('CmsPages'));
    same('abc-def', Str::kebab('ABCDef'));
    same('cms_pages', Str::snake('CmsPages'));
    same('cmsPages', Str::camel('cms-pages'));
    same('', Str::camel(''));
});

test('limit corta por palabra, cuenta caracteres y añade el final', function (): void {
    same('Hola mundo…', Str::limit('Hola mundo cruel', 10));
    same('Hola…', Str::limit('Hola mundo cruel', 8));
    same('corto', Str::limit('corto', 10));
    same('😀😀…', Str::limit('😀😀😀 abc', 2));
    same('abc', Str::limit('abcdefgh', 3, ''));
    same('', Str::limit('', 0));
});

test('words toma las primeras n palabras', function (): void {
    same('uno dos…', Str::words('uno dos tres cuatro', 2));
    same('uno dos', Str::words('uno dos', 5));
    same('', Str::words('   ', 2));
});

test('contains, startsWith y endsWith con y sin mayúsculas', function (): void {
    check(Str::contains('Añadir al carrito', 'AL', true), 'contains sin distinguir mayúsculas');
    check(!Str::contains('abc', 'X'), 'contains no encuentra');
    check(Str::contains('abc', ''), 'contains con cadena vacía');
    check(Str::startsWith('Hola', 'ho', true), 'startsWith sin mayúsculas');
    check(!Str::startsWith('Hola', 'ho'), 'startsWith distingue mayúsculas');
    check(Str::endsWith('archivo.PDF', '.pdf', true), 'endsWith sin mayúsculas');
    check(!Str::endsWith('ab', 'abc'), 'endsWith con cadena más larga');
    check(Str::endsWith('', ''), 'endsWith vacío');
});

test('between devuelve el trozo entre dos marcas o cadena vacía', function (): void {
    same('a', Str::between('[a] texto [b]', '[', ']'));
    same('1', Str::between('x=1;y=2', 'x=', ';'));
    same('', Str::between('x=1;y=2', 'y=', ';'));
    same('', Str::between('abc', 'z', 'c'));
    same('ab', Str::between('abc', '', 'c'));
});

test('titleName respeta partículas, mayúsculas internas y guiones', function (): void {
    same('Ana Ruiz', Str::titleName('ana ruiz'));
    same('Ana Ruiz de la Fuente', Str::titleName('ana ruiz de la fuente'));
    same('Juan Carlos de la Fuente', Str::titleName('juan carlos de la fuente'));
    same('María del Mar', Str::titleName('MARÍA DEL MAR'));
    same('McDonald', Str::titleName('McDonald'));
    same("O'Connor", Str::titleName("o'connor"));
    same('Ana-María', Str::titleName('ana-maría'));
    same('De la Cruz', Str::titleName('de la cruz'));
    same('José María da Silva', Str::titleName('JOSÉ MARÍA DA SILVA'));
    same('', Str::titleName(''));
    same('   ', Str::titleName('   '));
});

test('mask oculta el centro y deja los extremos', function (): void {
    same('cl*****34', Str::mask('clave1234'));
    same('**', Str::mask('ab'));
    same('', Str::mask(''));
    same('ña***12', Str::mask('ñandú12'));
    same('a*****', Str::mask('abcdef', 1, 0));
});

test('isUtf8 distingue UTF-8 válido de Windows-1252', function (): void {
    check(Str::isUtf8('ñ'), 'UTF-8 válido');
    check(Str::isUtf8(''), 'vacío es UTF-8');
    check(!Str::isUtf8("caf\xE9"), 'é suelta en 1252 no es UTF-8');
});

test('toUtf8 convierte Windows-1252 y deja el UTF-8 intacto', function (): void {
    same('café', Str::toUtf8("caf\xE9"));
    same('ya es UTF-8 ñ', Str::toUtf8('ya es UTF-8 ñ'));
    same('€ y ñ', Str::toUtf8("\x80 y \xF1"));
});

test('stripBom quita la marca solo al principio', function (): void {
    same('hola', Str::stripBom("\xEF\xBB\xBFhola"));
    same('hola', Str::stripBom('hola'));
    same('', Str::stripBom("\xEF\xBB\xBF"));
    same("a\xEF\xBB\xBFb", Str::stripBom("a\xEF\xBB\xBFb"));
});

test('template sustituye variables y deja las llaves sin variable', function (): void {
    same('Hola Ana & Lu, tienes 3 pedidos', Str::template('Hola {nombre}, tienes {n} pedidos', ['nombre' => 'Ana & Lu', 'n' => 3]));
    same('x {b} {}', Str::template('{a} {b} {}', ['a' => 'x']));
    same('', Str::template('{x}', ['x' => null]));
    same('{x}', Str::template('{x}', ['x' => ['no']]));
    same('"comillas" y ñ', Str::template('{t} y {n}', ['t' => '"comillas"', 'n' => 'ñ']));
});
