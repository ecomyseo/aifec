<?php
/**
 * AIFEC — los guiones se publican sin comentarios ni sangrías, y siguen siendo el mismo programa.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\AssetServer;

test('minifyJs quita comentarios de línea entera, sangrías y líneas vacías, y no toca cadenas ni lo que va detrás de código', function (): void {
    $js = "/**\n * Cabecera con `document`.\n */\n(function () {\n  'use strict';\n  // un comentario\n\n  var url = 'https://x.test/a'; // detrás de código se queda\n"
        . "  var re = /\\/\\*no es comentario\\*\\//;\n  /* bloque */ var b = 1;\n  var c = \"/* cadena */\";\n  /*\n   * varias\n   */\n  return a\n    * 2;\n})();\n";
    $min = AssetServer::minifyJs($js);
    same("(function () {\n'use strict';\nvar url = 'https://x.test/a'; // detrás de código se queda\nvar re = /\\/\\*no es comentario\\*\\//;\nvar b = 1;\nvar c = \"/* cadena */\";\nreturn a\n* 2;\n})();\n", $min);
});

test('los guiones de verdad del programa pierden peso al publicarse y siguen teniendo sus funciones', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['themes/base/assets/js/theme.js', 'modules/assistant/assets/assistant.js', 'core/assets/admin/admin.js'] as $file) {
        $src = (string) file_get_contents($root . '/' . $file);
        $min = AssetServer::minifyJs($src);
        check(strlen($min) < strlen($src) * 0.8, $file . ' baja al menos un 20 % (' . strlen($src) . ' → ' . strlen($min) . ')');
        same(substr_count($src, '{') - substr_count(implode("\n", preg_grep('#^\s*(//|\*|/\*)#', preg_split('/\n/', $src) ?: []) ?: []), '{'),
            substr_count($min, '{'), $file . ': las llaves del código son las mismas');
    }
    $public = tmpDir('aifec-minify');
    $out = AssetServer::publish($root, $public);
    $js = glob($public . '/assets/m/assistant/' . $out['build'] . '/assistant.js');
    check(is_array($js) && count($js) === 1, 'se publica');
    notContains('AIFEC — asistente de la tienda', (string) file_get_contents($js[0]), 'sin la cabecera de comentario');
});
