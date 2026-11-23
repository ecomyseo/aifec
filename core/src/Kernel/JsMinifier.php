<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Minificador de JavaScript escrito en PHP, sin dependencias y sin renombrar nada: lo único que hace es quitar los comentarios y los
 * espacios que sobran. Trocea el guion en elementos (palabras, números, cadenas, plantillas con «${…}», expresiones regulares y
 * signos), así que una cadena o una expresión regular con «//» o «/*» no se toca, y decide qué espacio o salto de línea tiene que
 * quedar entre cada dos elementos:
 *
 *   - un espacio entre dos palabras (o «+ +», «- -», «/ /», «1 .x») para que no se peguen;
 *   - un salto de línea donde quitarlo cambiaría la inserción automática de punto y coma (tras «return», «break», «continue»,
 *     «throw», «yield» y «async»; antes de «++», «--», «!», «{», una palabra o una cadena; tras «)», «]», «}», «++» y «--»).
 *
 * Una «/» es división si lo anterior es un valor (palabra que no sea «return», «typeof»…, número, cadena, «)», «]», «}», «++», «--»)
 * y principio de expresión regular en cualquier otro caso. Si no consigue leer el guion (comilla sin cerrar), lo devuelve igual.
 */
final class JsMinifier
{
    /** Palabras tras las que una «/» abre una expresión regular. */
    private const REGEX_AFTER_WORD = [
        'return' => 1, 'typeof' => 1, 'instanceof' => 1, 'in' => 1, 'of' => 1, 'new' => 1, 'delete' => 1, 'void' => 1,
        'throw' => 1, 'case' => 1, 'do' => 1, 'else' => 1, 'yield' => 1, 'await' => 1,
    ];

    /** Palabras tras las que un salto de línea cambia el programa (el punto y coma se inserta ahí). */
    private const RESTRICTED = ['return' => 1, 'break' => 1, 'continue' => 1, 'throw' => 1, 'yield' => 1, 'async' => 1];

    /** Signos que, delante de otro elemento, dejan quitar un salto de línea sin cambiar nada (la instrucción sigue). */
    private const DROP_BEFORE = [
        ')' => 1, ']' => 1, '}' => 1, ',' => 1, ';' => 1, ':' => 1, '.' => 1, '?.' => 1, '?' => 1, '=' => 1, '=>' => 1, '==' => 1,
        '===' => 1, '!=' => 1, '!==' => 1, '<=' => 1, '>=' => 1, '<' => 1, '>' => 1, '*' => 1, '/' => 1, '%' => 1, '&' => 1, '|' => 1,
        '^' => 1, '&&' => 1, '||' => 1, '??' => 1, '+=' => 1, '-=' => 1, '*=' => 1, '/=' => 1, '%=' => 1, '&=' => 1, '|=' => 1, '^=' => 1,
        '<<=' => 1, '>>=' => 1, '>>>=' => 1, '**=' => 1, '&&=' => 1, '||=' => 1, '??=' => 1, '**' => 1, '<<' => 1, '>>' => 1, '>>>' => 1,
        '(' => 1, '[' => 1, '+' => 1, '-' => 1,
    ];

    /** Los signos de varios caracteres, de mayor a menor. */
    private const PUNCT = [
        '>>>=', '...', '===', '!==', '**=', '<<=', '>>=', '>>>', '&&=', '||=', '??=', '=>', '==', '!=', '<=', '>=', '&&', '||', '??',
        '?.', '++', '--', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<', '>>', '**',
    ];

    public static function minify(string $js): string
    {
        $tokens = self::tokenize($js);
        if ($tokens === null) {
            return $js;
        }
        $out = '';
        $prev = null;
        foreach ($tokens as $tok) {
            if ($prev !== null) {
                $out .= self::between($prev, $tok);
            }
            $out .= $tok['t'];
            $prev = $tok;
        }

        return $out . "\n";
    }

    /**
     * @return array<int,array{k:string,t:string,nl:bool}>|null k: word, num, str, regex, punct, tplhead, tplmid, tpltail; nl: había un salto de línea antes
     */
    public static function tokenize(string $s): ?array
    {
        $n = strlen($s);
        $i = 0;
        $tokens = [];
        $nl = false;
        $stack = [];
        $prevKind = '';
        $prevText = '';
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $i = 3;
        }
        if (strncmp(substr($s, $i), '#!', 2) === 0) {
            $e = strpos($s, "\n", $i);
            $i = $e === false ? $n : $e;
        }
        while ($i < $n) {
            $c = $s[$i];
            if ($c === "\n" || $c === "\r") {
                $nl = true;
                ++$i;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\f" || $c === "\v" || ($c === "\xC2" && ($s[$i + 1] ?? '') === "\xA0")) {
                $i += $c === "\xC2" ? 2 : 1;
                continue;
            }
            $two = substr($s, $i, 2);
            if ($two === '//') {
                $e = strpos($s, "\n", $i);
                $i = $e === false ? $n : $e;
                continue;
            }
            if ($two === '/*') {
                $e = strpos($s, '*/', $i + 2);
                if ($e === false) {
                    return null;
                }
                if (strpos(substr($s, $i, $e - $i), "\n") !== false) {
                    $nl = true;
                }
                $i = $e + 2;
                continue;
            }
            $kind = '';
            $text = '';
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $s[$j] !== $c) {
                    $j += $s[$j] === '\\' ? 2 : 1;
                }
                if ($j >= $n) {
                    return null;
                }
                $kind = 'str';
                $text = substr($s, $i, $j + 1 - $i);
                $i = $j + 1;
            } elseif ($c === '`' || ($c === '}' && $stack !== [] && end($stack) === 't')) {
                $r = self::template($s, $i);
                if ($r === null) {
                    return null;
                }
                [$text, $i, $open] = $r;
                if ($c === '`') {
                    $kind = $open ? 'tplhead' : 'str';
                } else {
                    array_pop($stack);
                    $kind = $open ? 'tplmid' : 'tpltail';
                }
                if ($open) {
                    $stack[] = 't';
                }
            } elseif ($c === '/' && self::regexAllowed($prevKind, $prevText)) {
                $j = $i + 1;
                $inClass = false;
                while ($j < $n) {
                    $d = $s[$j];
                    if ($d === "\n") {
                        return null;
                    }
                    if ($d === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($d === '[') {
                        $inClass = true;
                    } elseif ($d === ']') {
                        $inClass = false;
                    } elseif ($d === '/' && !$inClass) {
                        break;
                    }
                    ++$j;
                }
                if ($j >= $n) {
                    return null;
                }
                ++$j;
                while ($j < $n && preg_match('/[A-Za-z0-9_$]/', $s[$j]) === 1) {
                    ++$j;
                }
                $kind = 'regex';
                $text = substr($s, $i, $j - $i);
                $i = $j;
            } elseif (preg_match('/\G(?:0[xXbBoO][0-9a-fA-F_]+n?|(?:[0-9][0-9_]*\.?[0-9_]*|\.[0-9][0-9_]*)(?:[eE][+-]?[0-9]+)?n?)/', $s, $m, 0, $i) === 1) {
                $kind = 'num';
                $text = $m[0];
                $i += strlen($text);
            } elseif (preg_match('/\G(?:[A-Za-z_$#\x80-\xFF]|\\\\u)(?:[A-Za-z0-9_$\x80-\xFF]|\\\\u[0-9a-fA-F{}]+)*/', $s, $m, 0, $i) === 1) {
                $kind = 'word';
                $text = $m[0];
                $i += strlen($text);
            } else {
                $kind = 'punct';
                $text = $c;
                foreach (self::PUNCT as $p) {
                    if (substr($s, $i, strlen($p)) === $p) {
                        $text = $p;
                        break;
                    }
                }
                if ($text === '?.' && isset($s[$i + 2]) && ctype_digit($s[$i + 2])) {
                    $text = '?';
                }
                if ($text === '{') {
                    $stack[] = 'b';
                } elseif ($text === '}') {
                    array_pop($stack);
                }
                $i += strlen($text);
            }
            $tokens[] = ['k' => $kind, 't' => $text, 'nl' => $nl];
            $nl = false;
            $prevKind = $kind;
            $prevText = $text;
        }

        return $stack === [] ? $tokens : null;
    }

    /** ¿Una «/» que viene detrás de este elemento abre una expresión regular? */
    private static function regexAllowed(string $kind, string $text): bool
    {
        if ($kind === '') {
            return true;
        }
        if ($kind === 'num' || $kind === 'str' || $kind === 'regex' || $kind === 'tpltail') {
            return false;
        }
        if ($kind === 'word') {
            return isset(self::REGEX_AFTER_WORD[$text]);
        }
        if ($kind === 'punct') {
            return !in_array($text, [')', ']', '}', '++', '--'], true);
        }

        return true;
    }

    /**
     * Lee un trozo de plantilla desde $i (la «`» de abrir, o la «}» que cierra una sustitución).
     *
     * @return array{0:string,1:int,2:bool}|null texto, posición siguiente y si termina en «${»
     */
    private static function template(string $s, int $i): ?array
    {
        $n = strlen($s);
        $j = $i + 1;
        while ($j < $n) {
            $d = $s[$j];
            if ($d === '\\') {
                $j += 2;
                continue;
            }
            if ($d === '`') {
                return [substr($s, $i, $j + 1 - $i), $j + 1, false];
            }
            if ($d === '$' && ($s[$j + 1] ?? '') === '{') {
                return [substr($s, $i, $j + 2 - $i), $j + 2, true];
            }
            ++$j;
        }

        return null;
    }

    private static function isWordChar(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || $c === '\\' || ord($c) >= 0x80);
    }

    /**
     * Lo que va entre dos elementos seguidos.
     *
     * @param array{k:string,t:string,nl:bool} $a
     * @param array{k:string,t:string,nl:bool} $b
     */
    private static function between(array $a, array $b): string
    {
        $al = substr($a['t'], -1);
        $bf = $b['t'][0];
        $needSpace = (self::isWordChar($al) && self::isWordChar($bf))
            || ($al === '+' && $bf === '+') || ($al === '-' && $bf === '-')
            || ($al === '/' && $bf === '/' && $a['k'] === 'punct')
            || ($a['k'] === 'num' && $b['t'] === '.' && preg_match('/^[0-9_]+$/', $a['t']) === 1)
            || ($a['k'] === 'punct' && $a['t'] === '<' && $bf === '!');
        if (!$b['nl']) {
            return $needSpace ? ' ' : '';
        }
        // Había un salto de línea: se quita solo si el programa no cambia.
        if ($a['k'] === 'word' && isset(self::RESTRICTED[$a['t']])) {
            return "\n";
        }
        $dropAfter = ($a['k'] === 'punct' && !in_array($a['t'], [')', ']', '}', '++', '--'], true)) || $a['k'] === 'tplhead';
        $dropBefore = ($b['k'] === 'punct' && isset(self::DROP_BEFORE[$b['t']]))
            || $b['k'] === 'tplmid' || $b['k'] === 'tpltail' || ($b['k'] === 'tplhead' && $b['t'][0] === '`') || ($b['k'] === 'str' && $bf === '`');
        if ($dropAfter || $dropBefore) {
            return $needSpace ? ' ' : '';
        }

        return "\n";
    }
}
