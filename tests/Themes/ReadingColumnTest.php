<?php
/**
 * AIFEC — el texto de entradas, páginas y fichas ocupa todo el ancho del contenedor, como la imagen (orden del usuario del
 * 13/11/2026: primero salía en una columna estrecha pegada a la izquierda; después, centrado pero estrecho: «deben ocupar todo el
 * ancho»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

test('en cada tema el texto (.af-prose, entradas y páginas) no tiene un ancho máximo propio: ocupa el del contenedor', function (): void {
    $root = dirname(__DIR__, 2) . '/themes';
    $checked = 0;
    foreach (glob($root . '/*/assets/css/theme.css') ?: [] as $file) {
        $css = (string) file_get_contents($file);
        if (strpos($css, '.af-prose{') === false) {
            continue;
        }
        ++$checked;
        $theme = basename(dirname(dirname(dirname($file))));
        contains('.af-prose{max-width:none;', $css, $theme . ': .af-prose a todo el ancho');
        contains('.af-post>*,.af-page>*{max-width:none}', $css, $theme . ': entradas y páginas a todo el ancho');
        check(preg_match('/\.af-(?:prose|post|page)[^{]*\{[^}]*max-width:\s*\d+(?:ch|rem|em|px)/', $css) !== 1, $theme . ': ninguna regla del texto lo estrecha');
    }
    check($checked >= 6, 'se han mirado los temas (' . $checked . ')');
});
