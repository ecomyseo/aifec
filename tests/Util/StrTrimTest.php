<?php
/**
 * AIFEC — pruebas de Str::trim (recortar con caracteres de cualquier alfabeto) y la guarda que impide volver a usar el trim() de
 * PHP con una lista de caracteres que no sea ASCII.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Str;

test('Str::trim recorta por caracteres enteros: no rompe los que comparten bytes con los de la lista', function (): void {
    // El trim() de PHP, byte a byte, deja «¿» sin su primer byte: esto es lo que se evita.
    check(trim('¿Tienes más?', " \"'«»“”") !== '¿Tienes más?', 'el trim() de PHP rompe el «¿» (si un día deja de hacerlo, esta guarda sobra)');

    same('¿Tienes más?', Str::trim(' «¿Tienes más?» ', " \"'«»“”"));
    same('… y más baratos', Str::trim('“… y más baratos”', " \"'«»“”"));
    same('¡Oferta!', Str::trim(' · ¡Oferta!', ' ·'), 'una etiqueta que empieza por «¡» detrás del separador');
    same('12,00 € · Nuevo', Str::trim('12,00 € · Nuevo · ', ' ·'));
    same('€ 12', Str::trim(' — € 12', ' —'), 'el «€» comparte su primer byte con la raya');
    same('Товар', Str::trim('Товар — ', ' —'), 'una palabra en cirílico que acaba en «р» (su último byte es uno de los de la raya)');
    same('', Str::trim(' · · ', ' ·'));
    same('hola', Str::trim("  hola\n"), 'sin lista, como trim()');
    same('a-b', Str::trim('--a-b--', '-'), 'el guion de la lista no se toma por un rango');
    same('a]b', Str::trim(']^a]b^]', ']^'), 'ni el corchete o el acento circunflejo por sintaxis');
    same('x', Str::trim("\0x\0", "\0"), 'el byte nulo de la lista no rompe la expresión');
    same('  x  ', Str::trim('  x  ', ''), 'con la lista vacía no se toca');
    same("caf\xE9 ", Str::trim("caf\xE9 ", ' ·'), 'un texto que no es UTF-8 se devuelve tal cual, no vacío');
    same("x\n", Str::rtrim("x\n ·", ' ·'));
    same("x ·\n", Str::rtrim("x ·\n", ' ·'), 'el final es el final del texto, no el de la línea: detrás hay un salto que no está en la lista');
});

test('Str::ltrim y Str::rtrim recortan un solo lado, también por caracteres enteros', function (): void {
    same('Chaqueta Gore-Tex™', Str::rtrim('Chaqueta Gore-Tex™ – ', " ,;:|·•–—-/"), 'el «™» acaba en el mismo byte que «•»: no se rompe');
    same(' · sofá', Str::rtrim(' · sofá · ', ' ·'), 'la izquierda no se toca');
    same('¡Hola! · ', Str::ltrim(' · ¡Hola! · ', ' ·'), 'ni la derecha');
    same('  x', Str::rtrim("  x \n"));
    same('x  ', Str::ltrim("\t x  "));
});

test('ningún fichero del programa llama a trim(), ltrim() o rtrim() con una lista de caracteres que no sea ASCII', function (): void {
    $root = dirname(__DIR__, 2);
    $names = ['trim' => true, 'ltrim' => true, 'rtrim' => true];
    $found = [];
    $files = 0;
    foreach (['core', 'modules', 'plugins', 'themes'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false) {
                continue;
            }
            $code = (string) file_get_contents($path);
            if (strpos($code, 'trim(') === false) {
                continue;
            }
            ++$files;
            $tokens = token_get_all($code);
            $n = count($tokens);
            for ($i = 0; $i < $n; ++$i) {
                $t = $tokens[$i];
                if (!is_array($t)) {
                    continue;
                }
                $isName = $t[0] === T_STRING || (defined('T_NAME_FULLY_QUALIFIED') && $t[0] === constant('T_NAME_FULLY_QUALIFIED'));
                if (!$isName || !isset($names[strtolower(ltrim($t[1], '\\'))])) {
                    continue;
                }
                // Str::trim(), $x->trim() y «function trim» no son el trim() de PHP.
                $p = $i - 1;
                while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    --$p;
                }
                if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION], true)) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    ++$j;
                }
                if ($j >= $n || $tokens[$j] !== '(') {
                    continue;
                }
                // Los argumentos de la llamada: la lista de caracteres es el segundo, a la profundidad de la propia llamada.
                $depth = 0;
                $arg = 0;
                for ($k = $j; $k < $n; ++$k) {
                    $tk = $tokens[$k];
                    if ($tk === '(' || $tk === '[' || $tk === '{') {
                        ++$depth;
                    } elseif ($tk === ')' || $tk === ']' || $tk === '}') {
                        if (--$depth === 0) {
                            break;
                        }
                    } elseif ($tk === ',' && $depth === 1) {
                        ++$arg;
                    } elseif ($arg === 1 && $depth === 1 && is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                        && preg_match('/[\x80-\xFF]/', $tk[1]) === 1) {
                        $found[] = substr($path, strlen($root) + 1) . ':' . $t[2];
                    }
                }
            }
        }
    }
    check($files > 50, 'se han mirado los ficheros del programa (' . $files . ')');
    same([], $found, 'trim() va byte a byte: con una lista que no es ASCII se usa Str::trim()');
});
