<?php
/**
 * AIFEC — pruebas de lo que el servidor entrega al editor de bloques del panel: en qué pantallas se carga, los textos y nombres de
 * tipo en JSON seguro, las direcciones y la lista de formularios, y que cada clave de texto que usa el guion existe de verdad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Modules\Cms\BlocksEditor;

require_once __DIR__ . '/helpers.php';

/**
 * El JSON de textos que va dentro de la pantalla, ya descodificado.
 *
 * @return array<string,mixed>
 */
function cmsEditorConfig(string $html): array
{
    check(preg_match('#<script type="application/json" id="af-blocks-i18n">(.*?)</script>#s', $html, $m) === 1, 'la pantalla lleva el JSON de textos');
    $data = json_decode($m[1], true);
    check(is_array($data), 'el JSON de textos es válido');

    return is_array($data) ? $data : [];
}

test('el editor se carga en la ficha de una página y no en el inicio del panel', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $home = $web->get('/admin');
    notContains('af-blocks-i18n', $home->body, 'el inicio no lo necesita');
    notContains('blocks-editor.js', $home->body);
    $screen = $web->get('/admin/r/pages/new');
    contains('id="af-blocks-i18n"', $screen->body);
    contains('blocks-editor.js', $screen->body);
    contains('blocks-editor.css', $screen->body);
    contains('data-af-blocks', $screen->body, 'y la ficha pinta el campo de bloques que el guion mejora');
});

test('quien pinta un campo de bloques en otra pantalla lo puede avisar', function (): void {
    $app = cmsApp();
    adminClient($app);
    notContains('blocks-editor.js', BlocksEditor::assets($app), 'la petición de arranque no es una pantalla de contenido');
    BlocksEditor::painted($app);
    contains('blocks-editor.js', BlocksEditor::assets($app));
});

test('el JSON de textos trae el nombre de todos los tipos, las direcciones y los formularios', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $config = cmsEditorConfig($web->get('/admin/r/pages/new')->body);
    foreach (array_keys(BlockSchema::types()) as $type) {
        check(isset($config['types'][$type]) && $config['types'][$type] !== '', 'falta el nombre del tipo ' . $type);
    }
    foreach (['import', 'render', 'search', 'picker'] as $key) {
        check(isset($config['urls'][$key]) && $config['urls'][$key] !== '', 'falta la dirección ' . $key);
    }
    contains('/admin/cms/blocks/import', (string) $config['urls']['import']);
    contains('/admin/cms/blocks/render', (string) $config['urls']['render']);
    contains('/admin/r/products/search.json', (string) $config['urls']['search']);
    contains('/admin/media/picker', (string) $config['urls']['picker']);
    check(count($config['forms']) >= 1, 'el formulario de contacto de serie está en la lista');
    same('Contacto', $config['forms'][0]['name'], 'el nombre sale traducido');
    check(is_int($config['forms'][0]['id']), 'el identificador es un número');
});

test('los textos salen en el idioma del panel y los nombres de tipo no se quedan en inglés', function (): void {
    $app = cmsApp(['cms'], ['i18n.languages' => ['es', 'en']]);
    $web = adminClient($app);
    $config = cmsEditorConfig($web->get('/admin/r/pages/new')->body);
    same('Párrafo', $config['types']['paragraph']);
    same('Deshacer', $config['t']['undo']);
    same('Añadir bloque', $config['t']['add_block']);
    same('Columna {n}', $config['t']['column_n'], 'las marcas {n} llegan intactas para que el guion las sustituya');
});

test('cada clave de texto que usa el guion existe en lo que entrega el servidor', function (): void {
    $js = (string) file_get_contents(__DIR__ . '/../assets/blocks-editor.js');
    check($js !== '', 'el guion existe');
    $keys = [];
    if (preg_match_all("/\\bT\\('([a-z_0-9]+)'/", $js, $m) > 0) {
        $keys = array_merge($keys, $m[1]);
    }
    if (preg_match_all("/\\bT\\([^'()]*\\?\\s*'([a-z_0-9]+)'\\s*:\\s*'([a-z_0-9]+)'/", $js, $m2) > 0) {
        $keys = array_merge($keys, $m2[1], $m2[2]);
    }
    $keys = array_values(array_unique($keys));
    check(count($keys) > 60, 'el guion usa muchas claves de texto (' . count($keys) . ')');

    $app = cmsApp();
    $web = adminClient($app);
    $config = cmsEditorConfig($web->get('/admin/r/pages/new')->body);
    $missing = [];
    foreach ($keys as $key) {
        if (!isset($config['t'][$key]) || $config['t'][$key] === '') {
            $missing[] = $key;
        }
    }
    same([], $missing, 'claves de texto que el guion pide y el servidor no entrega');
});

test('el guion no lleva cadenas visibles escritas: ni cuadros de aviso ni textos en castellano o inglés', function (): void {
    $js = (string) file_get_contents(__DIR__ . '/../assets/blocks-editor.js');
    // Sin comentarios: lo que queda es código.
    $code = preg_replace('#/\*.*?\*/#s', '', $js);
    $code = preg_replace('#^\s*//.*$#m', '', (string) $code);
    notContains('alert(', (string) $code);
    check(preg_match('/\\bconfirm\\s*\\(/', (string) $code) === 0, 'sin confirm()');
    check(preg_match('/prompt\\(\\s*[\'"]/', (string) $code) === 0, 'el texto de prompt() viene de la traducción');
    // Ninguna cadena con dos palabras seguidas de letras minúsculas que no sea un selector, una clase o una consulta.
    check(preg_match("/'[A-Z][a-z]+ [a-z]+ [a-z]+[^']*'/", (string) $code) === 0, 'ninguna frase en inglés dentro del guion');
});

test('un nombre de formulario con etiquetas y comillas no rompe el JSON ni cierra la etiqueta', function (): void {
    $app = cmsApp();
    $now = '2026-01-01 00:00:00';
    $evil = '</script><img src=x onerror=alert(1)> "A" & \'B\'';
    $app->db()->insert('forms', ['code' => 'raro', 'name' => $evil, 'fields' => '[]', 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    $web = adminClient($app);
    $html = $web->get('/admin/r/pages/new')->body;
    $config = cmsEditorConfig($html);
    $names = array_column($config['forms'], 'name');
    check(in_array($evil, $names, true), 'el nombre vuelve intacto');
    $block = (string) strstr($html, 'id="af-blocks-i18n"');
    $json = substr($block, 0, (int) strpos($block, '</script>'));
    notContains('<img', $json, 'dentro del JSON no queda ninguna etiqueta suelta');
});
