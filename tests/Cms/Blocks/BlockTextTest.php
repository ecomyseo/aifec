<?php
/**
 * AIFEC — pruebas del texto plano, el recuento de palabras, los encabezados y la primera imagen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\BlockText;

/** @return array<string,mixed> */
function cmsbT(string $type, array $attrs = [], array $children = []): array
{
    return ['type' => $type, 'attrs' => $attrs, 'children' => $children];
}

test('plain() da el texto visible de cada bloque, uno por línea', function (): void {
    $blocks = [
        cmsbT('heading', ['level' => 2, 'html' => 'Título <em>uno</em>']),
        cmsbT('paragraph', ['html' => 'Tom &amp; Jerry   y «ñandú» 😀']),
        cmsbT('list', ['items' => [['html' => 'a', 'items' => [['html' => 'a1']]], ['html' => 'b']]]),
        cmsbT('table', ['head' => [['H1', 'H2']], 'rows' => [['1', '<b>2</b>']], 'caption' => 'Pie']),
        cmsbT('quote', ['html' => '<p>Cita</p>', 'cite' => 'Yo']),
        cmsbT('image', ['src' => 'https://e.com/i.jpg', 'alt' => 'no cuenta', 'caption' => 'Pie de foto']),
        cmsbT('buttons', ['items' => [['text' => 'Comprar', 'href' => '/x']]]),
        cmsbT('code', ['code' => "a < b\n  c"]),
        cmsbT('separator'),
        cmsbT('products', ['mode' => 'latest']),
    ];
    $expected = implode("\n", [
        'Título uno', 'Tom & Jerry y «ñandú» 😀', 'a', 'a1', 'b', 'H1 H2', '1 2', 'Pie', 'Cita', 'Yo', 'Pie de foto', 'Comprar', 'a < b c',
    ]);
    same($expected, BlockText::plain($blocks));
});

test('plain() recorre los hijos de los contenedores y los resúmenes de los detalles', function (): void {
    $blocks = [cmsbT('group', [], [
        cmsbT('columns', [], [cmsbT('column', [], [cmsbT('paragraph', ['html' => 'dentro'])])]),
        cmsbT('details', ['summary' => '¿Resumen?'], [cmsbT('paragraph', ['html' => 'respuesta'])]),
        cmsbT('cover', ['src' => 'https://e.com/c.jpg'], [cmsbT('heading', ['level' => 1, 'html' => 'Portada'])]),
    ])];
    same("dentro\n¿Resumen?\nrespuesta\nPortada", BlockText::plain($blocks));
});

test('words() cuenta las palabras del texto visible', function (): void {
    $blocks = [
        cmsbT('heading', ['level' => 1, 'html' => 'Uno dos']),
        cmsbT('paragraph', ['html' => 'tres <b>cuatro</b>,  cinco']),
        cmsbT('paragraph', ['html' => '']),
        cmsbT('list', ['items' => [['html' => 'seis siete']]]),
    ];
    same(7, BlockText::words($blocks));
    same(0, BlockText::words([]));
    same(0, BlockText::words([cmsbT('separator'), cmsbT('paragraph', ['html' => '  '])]));
});

test('plain() y words() no lanzan con basura', function (): void {
    $garbage = [['type' => 5], ['type' => 'paragraph'], 'texto', null, ['type' => 'list', 'attrs' => ['items' => 'no']], ['type' => 'table', 'attrs' => ['rows' => [1, 'a', [null]]]], ['type' => 'group', 'children' => 'no']];
    same('', BlockText::plain($garbage));
    same(0, BlockText::words($garbage));
    same([], BlockText::headings($garbage));
    same(null, BlockText::firstImage($garbage));
});

test('headings() devuelve nivel, texto y ancla, recorriendo los contenedores en orden', function (): void {
    $blocks = [
        cmsbT('heading', ['level' => 1, 'html' => 'Primero <b>fuerte</b>']),
        cmsbT('group', [], [cmsbT('heading', ['level' => 3, 'html' => 'Dentro', 'anchor' => 'mi-ancla'])]),
        cmsbT('paragraph', ['html' => 'x']),
        cmsbT('heading', ['level' => 2, 'html' => 'Último']),
    ];
    same([
        ['level' => 1, 'text' => 'Primero fuerte', 'anchor' => 'primero-fuerte'],
        ['level' => 3, 'text' => 'Dentro', 'anchor' => 'mi-ancla'],
        ['level' => 2, 'text' => 'Último', 'anchor' => 'ultimo'],
    ], BlockText::headings($blocks));
});

test('las anclas no se repiten y las explícitas mandan sobre las generadas', function (): void {
    $blocks = [
        cmsbT('heading', ['level' => 2, 'html' => 'Intro']),
        cmsbT('heading', ['level' => 2, 'html' => 'Intro']),
        cmsbT('heading', ['level' => 2, 'html' => 'Otro', 'anchor' => 'intro']),
        cmsbT('heading', ['level' => 2, 'html' => 'Dup', 'anchor' => 'dup']),
        cmsbT('heading', ['level' => 2, 'html' => 'Dup', 'anchor' => 'dup']),
        cmsbT('heading', ['level' => 2, 'html' => '日本語']),
        cmsbT('heading', ['level' => 2, 'html' => '']),
    ];
    $anchors = array_column(BlockText::headings($blocks), 'anchor');
    same(['intro-2', 'intro-3', 'intro', 'dup', 'dup-2', 'section', 'section-2'], $anchors);
    same(count($anchors), count(array_unique($anchors)));
});

test('slug() quita tildes, eñes y signos', function (): void {
    same('que-es-aifec-y-que-no-es', BlockText::slug('¿Qué es AIFEC y qué no es?'));
    same('ano-nuevo-ninos', BlockText::slug('Año nuevo, niños'));
    same('a-b-c', BlockText::slug('  A & B -- C!  '));
    same('', BlockText::slug('😀'));
    check(strlen(BlockText::slug(str_repeat('palabra ', 30))) <= 60, 'longitud acotada');
});

test('firstImage() encuentra la imagen, la de una galería o la de una portada, también anidadas', function (): void {
    $img = ['src' => 'https://e.com/a.jpg', 'alt' => 'a'];
    same($img, BlockText::firstImage([cmsbT('paragraph', ['html' => 'x']), cmsbT('image', $img)]));

    $gallery = cmsbT('gallery', ['images' => [['alt' => 'sin nada'], ['src' => 'https://e.com/g.jpg', 'alt' => 'g']]]);
    same(['src' => 'https://e.com/g.jpg', 'alt' => 'g'], BlockText::firstImage([$gallery]));

    $cover = cmsbT('cover', ['src' => 'https://e.com/c.jpg'], [cmsbT('paragraph', ['html' => 'x'])]);
    same(['src' => 'https://e.com/c.jpg'], BlockText::firstImage([cmsbT('group', [], [$cover])]));

    same(['media_id' => 9], BlockText::firstImage([cmsbT('columns', [], [cmsbT('column', [], [cmsbT('image', ['media_id' => 9])])])]));

    same(null, BlockText::firstImage([cmsbT('paragraph', ['html' => 'x']), cmsbT('image', ['alt' => 'sin foto'])]));
    same(null, BlockText::firstImage([]));
});
