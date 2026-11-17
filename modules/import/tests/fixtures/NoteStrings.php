<?php
/**
 * AIFEC — el extractor de las cadenas en inglés que los escritores pasan a note(), noteOnce(), result() y unmapped(): recorre el código con el
 * analizador de PHP (token_get_all) y saca las cadenas literales que son texto («Some images could not be brought»), enteras o a trozos cuando
 * el escritor las compone con variables («The coupon lost a feature that does not exist here («» y «»)»).
 *
 * El extractor normal del sistema no las ve: no pasan por t() en el código, se traducen al enseñarlas. Esta es la lista de lo que hay que
 * traducir, y la prueba de las notas la compara con el catálogo.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

if (!function_exists('e2eNoteStrings')) {
    /**
     * Las cadenas de texto que un fichero PHP pasa a las funciones de notas.
     *
     * @param string[] $calls nombres de método que se vigilan
     *
     * @return array<int,array{line:int,text:string}>
     */
    function e2eNoteStrings(string $file, array $calls = ['note', 'noteOnce', 'result', 'unmapped']): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = count($tokens);
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                // Una lista de motivos que luego se junta con implode(): «$review[] = '...'».
                if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$review' && isset($tokens[$i + 1], $tokens[$i + 2]) && $tokens[$i + 1] === '[' && $tokens[$i + 2] === ']') {
                    $j = $i + 3;
                    $depth = 0;
                    for (; $j < $count; ++$j) {
                        if ($tokens[$j] === ';' && $depth === 0) {
                            break;
                        }
                        if ($tokens[$j] === '(' || $tokens[$j] === '[') {
                            ++$depth;
                        } elseif ($tokens[$j] === ')' || $tokens[$j] === ']') {
                            --$depth;
                        } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                            $text = e2eUnquote($tokens[$j][1]);
                            if (preg_match('/[A-Za-z]{2,} [A-Za-z]{2,}/', $text) === 1) {
                                $out[] = ['line' => (int) $tokens[$j][2], 'text' => $text];
                            }
                        }
                    }
                }
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || !in_array($tokens[$j][1], $calls, true)) {
                continue;
            }
            ++$j;
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
                continue;
            }
            $depth = 0;
            for (; $j < $count; ++$j) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[') {
                    ++$depth;
                } elseif ($t === ')' || $t === ']') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $text = e2eUnquote($t[1]);
                    if (preg_match('/[A-Za-z]{2,} [A-Za-z]{2,}/', $text) === 1) {
                        $out[] = ['line' => (int) $t[2], 'text' => $text];
                    }
                }
            }
        }

        return $out;
    }

    /** El contenido de un literal de PHP entre comillas simples o dobles. */
    function e2eUnquote(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }

    /**
     * Todas las cadenas de notas de una carpeta de código.
     *
     * @return array<string,array<int,string>> texto => ficheros:línea donde está
     */
    function e2eNoteStringsOf(string $dir): array
    {
        $found = [];
        $files = glob(rtrim($dir, '/') . '/*.php');
        foreach (is_array($files) ? $files : [] as $file) {
            foreach (e2eNoteStrings($file) as $item) {
                $found[$item['text']][] = basename($file) . ':' . $item['line'];
            }
        }
        ksort($found);

        return $found;
    }
}
