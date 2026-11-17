<?php
/**
 * AIFEC — comprueba que todo texto que ve quien instala tiene su traducción castellana.
 * El instalador corre antes de que exista ningún sitio y por eso lleva su propio catálogo
 * (core/views/install/lang/es.php); el comprobador general de traducciones no mira estas llamadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Install\Requirements;

require_once __DIR__ . '/support.php';

/**
 * Cadenas de texto que el instalador pasa por la traducción, con el fichero donde están.
 *
 * @return array<string,string> cadena => fichero
 */
function aifecInstStrings(): array
{
    $root = aifecInstRoot();
    $files = array_merge(
        (array) glob($root . '/core/views/install/*.php'),
        (array) glob($root . '/core/src/Install/*.php')
    );
    $literal = "'((?:[^'\\\\]|\\\\.)*)'";
    $patterns = [
        '/(?<![A-Za-z0-9_])\$t\(\s*' . $literal . '/',
        '/->tr\(\s*' . $literal . '/',
        '/\'title\' => ' . $literal . '/',
        '/Exception\(' . $literal . '\)/',
        '/\$add\(\'[a-z_]+\', ' . $literal . '/',
    ];
    $found = [];
    // Lo que deja de funcionar si falta cada extensión viaja en una constante, no en una llamada con la cadena delante.
    foreach (Requirements::IMPACT as $impact) {
        $found[$impact] = 'core/src/Install/Requirements.php';
    }
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $m) > 0) {
                foreach ($m[1] as $text) {
                    $found[str_replace(["\\'", '\\\\'], ["'", '\\'], $text)] = substr($file, strlen($root) + 1);
                }
            }
        }
    }

    return $found;
}

test('todo texto del instalador tiene su traducción castellana', function (): void {
    $catalog = require aifecInstRoot() . '/core/views/install/lang/es.php';
    $core = require aifecInstRoot() . '/core/lang/es.php';
    $strings = aifecInstStrings();
    check(count($strings) > 60, 'el comprobador encuentra las cadenas (' . count($strings) . ')');
    $missing = [];
    foreach ($strings as $text => $file) {
        if (!isset($catalog[$text]) && !isset($core[$text])) {
            $missing[] = $text . '   (' . $file . ')';
        }
    }
    same([], $missing, 'faltan traducciones en core/views/install/lang/es.php');
});

test('el catálogo castellano del instalador no tiene entradas vacías ni textos sin usar', function (): void {
    $catalog = require aifecInstRoot() . '/core/views/install/lang/es.php';
    $strings = aifecInstStrings();
    $unused = [];
    foreach ($catalog as $key => $value) {
        check(is_string($value) && trim($value) !== '', 'traducción vacía: ' . $key);
        if (!isset($strings[$key]) && !in_array($key, ['Not installed', 'Not writable', 'The pdo_sqlite extension is not installed on this server'], true)) {
            $unused[] = $key;
        }
    }
    same([], $unused, 'entradas que ya no se usan en ningún sitio');
});

test('las etiquetas y los detalles de las comprobaciones del servidor están traducidos', function (): void {
    $catalog = require aifecInstRoot() . '/core/views/install/lang/es.php';
    $file = tmpDir('aifec-inst-file') . '/soy-un-fichero';
    file_put_contents($file, 'x');
    foreach ([Requirements::check(tmpDir('aifec-inst-data'), tmpDir('aifec-inst-public')), Requirements::check($file . '/a', $file . '/b')] as $rows) {
        foreach ($rows as $row) {
            check(isset($catalog[$row['label']]), 'sin traducir la etiqueta: ' . $row['label']);
            check(isset($catalog[$row['impact']]), 'sin traducir lo que deja de funcionar: ' . $row['impact']);
            if (!$row['ok']) {
                check(isset($catalog[$row['detail']]), 'sin traducir el detalle: ' . $row['detail']);
            }
        }
    }
});
