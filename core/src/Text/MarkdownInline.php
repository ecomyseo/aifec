<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Text;

/**
 * Parte «en línea» del Markdown: énfasis, código, enlaces, imágenes, enlaces automáticos,
 * escapes y saltos duros. Nunca deja pasar HTML: todo lo que no es una construcción
 * Markdown sale escapado, y las URL pasan por {@see UrlFilter}.
 */
final class MarkdownInline
{
    private const MAX_DEPTH = 6;

    private const PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** @var int */
    private $depth;

    /** @var bool */
    private $inLink;

    private function __construct(int $depth, bool $inLink)
    {
        $this->depth = $depth;
        $this->inLink = $inLink;
    }

    public static function render(string $text): string
    {
        return (new self(0, false))->run($text);
    }

    private function run(string $s): string
    {
        $nodes = $this->tokenize($s);
        $nodes = self::emphasis($nodes);

        $html = '';
        foreach ($nodes as $node) {
            $html .= $node['t'] === 'd' ? str_repeat($node['ch'], $node['n']) : $node['v'];
        }

        return $html;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function tokenize(string $s): array
    {
        $nodes = [];
        $buf = '';
        $len = strlen($s);
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];
            switch ($c) {
                case '\\':
                    $next = $i + 1 < $len ? $s[$i + 1] : '';
                    if ($next === "\n") {
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => "<br>\n"];
                        $i += 2;
                        $i = self::skipBlanks($s, $i, $len);
                    } elseif ($next !== '' && strpos(self::PUNCTUATION, $next) !== false) {
                        $buf .= $next;
                        $i += 2;
                    } else {
                        $buf .= '\\';
                        ++$i;
                    }
                    break;

                case '`':
                    $j = $i;
                    while ($j < $len && $s[$j] === '`') {
                        ++$j;
                    }
                    $run = $j - $i;
                    $close = self::findBackticks($s, $j, $run);
                    if ($close >= 0) {
                        $code = str_replace("\n", ' ', substr($s, $j, $close - $j));
                        if (strlen($code) > 1 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code, ' ') !== '') {
                            $code = substr($code, 1, -1);
                        }
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => '<code>' . self::esc($code) . '</code>'];
                        $i = $close + $run;
                    } else {
                        $buf .= str_repeat('`', $run);
                        $i = $j;
                    }
                    break;

                case '*':
                case '_':
                    $j = $i;
                    while ($j < $len && $s[$j] === $c) {
                        ++$j;
                    }
                    self::flush($nodes, $buf);
                    $nodes[] = self::delimiter($s, $i, $j, $c);
                    $i = $j;
                    break;

                case '&':
                    $handled = false;
                    if (preg_match('/^&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/', substr($s, $i, 40), $m) === 1) {
                        $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($decoded !== $m[0] && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $decoded) !== 1) {
                            $buf .= $decoded;
                            $i += strlen($m[0]);
                            $handled = true;
                        }
                    }
                    if (!$handled) {
                        $buf .= '&';
                        ++$i;
                    }
                    break;

                case '<':
                    $rest = substr($s, $i, 2100);
                    if (preg_match('/^<([A-Za-z][A-Za-z0-9+.\-]{1,31}:[^\s<>]*)>/', $rest, $m) === 1) {
                        $href = UrlFilter::safe($m[1]);
                        if ($href !== null) {
                            self::flush($nodes, $buf);
                            $nodes[] = ['t' => 'h', 'v' => '<a href="' . self::attr(self::encodeUrl($href)) . '">' . self::esc($m[1]) . '</a>'];
                        } else {
                            $buf .= $m[0];
                        }
                        $i += strlen($m[0]);
                    } elseif (preg_match('/^<([A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)>/', $rest, $m) === 1) {
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => '<a href="' . self::attr('mailto:' . $m[1]) . '">' . self::esc($m[1]) . '</a>'];
                        $i += strlen($m[0]);
                    } else {
                        $buf .= '<';
                        ++$i;
                    }
                    break;

                case '!':
                    $parsed = $i + 1 < $len && $s[$i + 1] === '[' ? $this->linkLike($s, $i + 1, true) : null;
                    if ($parsed !== null) {
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => $parsed[0]];
                        $i = $parsed[1];
                    } else {
                        $buf .= '!';
                        ++$i;
                    }
                    break;

                case '[':
                    $parsed = $this->inLink ? null : $this->linkLike($s, $i, false);
                    if ($parsed !== null) {
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => $parsed[0]];
                        $i = $parsed[1];
                    } else {
                        $buf .= '[';
                        ++$i;
                    }
                    break;

                case "\n":
                    if (substr($buf, -2) === '  ') {
                        $buf = rtrim($buf, ' ');
                        self::flush($nodes, $buf);
                        $nodes[] = ['t' => 'h', 'v' => "<br>\n"];
                    } else {
                        $buf = rtrim($buf, ' ') . "\n";
                    }
                    $i = self::skipBlanks($s, $i + 1, $len);
                    break;

                default:
                    $j = $i + strcspn($s, "\\`*_&<![\n", $i);
                    if ($j <= $i) {
                        $j = $i + 1;
                    }
                    $buf .= substr($s, $i, $j - $i);
                    $i = $j;
            }
        }
        self::flush($nodes, $buf);

        return $nodes;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     */
    private static function flush(array &$nodes, string &$buf): void
    {
        if ($buf !== '') {
            $nodes[] = ['t' => 'x', 'v' => self::esc($buf)];
            $buf = '';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function delimiter(string $s, int $start, int $end, string $char): array
    {
        $before = self::charBefore($s, $start);
        $after = self::charAfter($s, $end);
        $spaceBefore = $before === '' || self::isSpace($before);
        $spaceAfter = $after === '' || self::isSpace($after);
        $punctBefore = $before !== '' && self::isPunct($before);
        $punctAfter = $after !== '' && self::isPunct($after);

        $left = !$spaceAfter && (!$punctAfter || $spaceBefore || $punctBefore);
        $right = !$spaceBefore && (!$punctBefore || $spaceAfter || $punctAfter);

        if ($char === '*') {
            $open = $left;
            $close = $right;
        } else {
            $open = $left && (!$right || $punctBefore);
            $close = $right && (!$left || $punctAfter);
        }
        $run = $end - $start;

        return ['t' => 'd', 'ch' => $char, 'n' => $run, 'o' => $run, 'open' => $open, 'close' => $close];
    }

    /**
     * Resuelve los pares de delimitadores (algoritmo de CommonMark, sin la tabla de topes).
     *
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array<string,mixed>>
     */
    private static function emphasis(array $nodes): array
    {
        $ci = 0;
        while ($ci < count($nodes)) {
            $closer = $nodes[$ci];
            if ($closer['t'] !== 'd' || !$closer['close'] || $closer['n'] === 0) {
                ++$ci;
                continue;
            }

            $found = -1;
            for ($oi = $ci - 1; $oi >= 0; --$oi) {
                $opener = $nodes[$oi];
                if ($opener['t'] !== 'd' || $opener['ch'] !== $closer['ch'] || !$opener['open'] || $opener['n'] === 0) {
                    continue;
                }
                $oddMatch = ($opener['close'] || $closer['open'])
                    && ($opener['o'] + $closer['o']) % 3 === 0
                    && !($opener['o'] % 3 === 0 && $closer['o'] % 3 === 0);
                if ($oddMatch) {
                    continue;
                }
                $found = $oi;
                break;
            }
            if ($found < 0) {
                ++$ci;
                continue;
            }

            $use = $nodes[$found]['n'] >= 2 && $closer['n'] >= 2 ? 2 : 1;
            $tag = $use === 2 ? 'strong' : 'em';
            for ($k = $found + 1; $k < $ci; ++$k) {
                if ($nodes[$k]['t'] === 'd') {
                    $nodes[$k]['open'] = false;
                    $nodes[$k]['close'] = false;
                }
            }
            $nodes[$found]['n'] -= $use;
            $nodes[$ci]['n'] -= $use;
            array_splice($nodes, $ci, 0, [['t' => 'h', 'v' => '</' . $tag . '>']]);
            array_splice($nodes, $found + 1, 0, [['t' => 'h', 'v' => '<' . $tag . '>']]);
            $ci += 2;
            if ($nodes[$ci]['n'] === 0) {
                ++$ci;
            }
        }

        return $nodes;
    }

    /**
     * Enlace `[texto](destino "título")` o imagen `![alt](destino)` que empieza en `$open`.
     *
     * @return array{0:string,1:int}|null el HTML y el índice donde sigue el texto
     */
    private function linkLike(string $s, int $open, bool $image): ?array
    {
        $len = strlen($s);

        // Corchete de cierre que casa, saltando escapes y trozos de código.
        $depth = 1;
        $k = $open + 1;
        while ($k < $len) {
            $ch = $s[$k];
            if ($ch === '\\' && $k + 1 < $len) {
                $k += 2;
                continue;
            }
            if ($ch === '`') {
                $j = $k;
                while ($j < $len && $s[$j] === '`') {
                    ++$j;
                }
                $p = self::findBackticks($s, $j, $j - $k);
                $k = $p >= 0 ? $p + ($j - $k) : $j;
                continue;
            }
            if ($ch === '[') {
                ++$depth;
            } elseif ($ch === ']') {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
            ++$k;
        }
        if ($depth !== 0 || $k >= $len) {
            return null;
        }
        $close = $k;
        if ($close + 1 >= $len || $s[$close + 1] !== '(') {
            return null;
        }

        $pos = self::skipSpace($s, $close + 2, $len);
        if ($pos < $len && $s[$pos] === '<') {
            $e = $pos + 1;
            while ($e < $len && $s[$e] !== '>' && $s[$e] !== "\n" && $s[$e] !== '<') {
                if ($s[$e] === '\\' && $e + 1 < $len) {
                    ++$e;
                }
                ++$e;
            }
            if ($e >= $len || $s[$e] !== '>') {
                return null;
            }
            $dest = substr($s, $pos + 1, $e - $pos - 1);
            $pos = $e + 1;
        } else {
            $e = $pos;
            $paren = 0;
            while ($e < $len) {
                $ch = $s[$e];
                if ($ch === '\\' && $e + 1 < $len && strpos(self::PUNCTUATION, $s[$e + 1]) !== false) {
                    $e += 2;
                    continue;
                }
                if (ord($ch) <= 0x20) {
                    break;
                }
                if ($ch === '(') {
                    ++$paren;
                } elseif ($ch === ')') {
                    if ($paren === 0) {
                        break;
                    }
                    --$paren;
                }
                ++$e;
            }
            if ($paren !== 0) {
                return null;
            }
            $dest = substr($s, $pos, $e - $pos);
            $pos = $e;
        }

        $after = self::skipSpace($s, $pos, $len);
        $title = null;
        if ($after > $pos && $after < $len && ($s[$after] === '"' || $s[$after] === "'" || $s[$after] === '(')) {
            $end = $s[$after] === '(' ? ')' : $s[$after];
            $e = $after + 1;
            while ($e < $len && $s[$e] !== $end) {
                if ($s[$e] === '\\' && $e + 1 < $len) {
                    ++$e;
                }
                ++$e;
            }
            if ($e >= $len) {
                return null;
            }
            $title = substr($s, $after + 1, $e - $after - 1);
            $after = self::skipSpace($s, $e + 1, $len);
        }
        if ($after >= $len || $s[$after] !== ')') {
            return null;
        }
        $next = $after + 1;

        $inner = substr($s, $open + 1, $close - $open - 1);
        $href = UrlFilter::safe(self::unescape($dest));
        $titleAttr = $title !== null ? ' title="' . self::attr(self::unescape($title)) . '"' : '';

        if ($image) {
            $alt = $this->nested($inner, true);
            $alt = html_entity_decode(strip_tags($alt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === null) {
                return [self::esc($alt), $next];
            }

            return ['<img src="' . self::attr(self::encodeUrl($href)) . '" alt="' . self::attr($alt) . '"' . $titleAttr . '>', $next];
        }

        $text = $this->nested($inner, true);
        $tag = $href === null ? '<a' . $titleAttr . '>' : '<a href="' . self::attr(self::encodeUrl($href)) . '"' . $titleAttr . '>';

        return [$tag . $text . '</a>', $next];
    }

    private function nested(string $text, bool $inLink): string
    {
        if ($this->depth + 1 >= self::MAX_DEPTH) {
            return self::esc($text);
        }

        return (new self($this->depth + 1, $inLink))->run($text);
    }

    private static function findBackticks(string $s, int $from, int $run): int
    {
        $len = strlen($s);
        $k = $from;
        while ($k < $len) {
            $p = strpos($s, '`', $k);
            if ($p === false) {
                return -1;
            }
            $q = $p;
            while ($q < $len && $s[$q] === '`') {
                ++$q;
            }
            if ($q - $p === $run) {
                return $p;
            }
            $k = $q;
        }

        return -1;
    }

    private static function skipBlanks(string $s, int $i, int $len): int
    {
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t")) {
            ++$i;
        }

        return $i;
    }

    private static function skipSpace(string $s, int $i, int $len): int
    {
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n")) {
            ++$i;
        }

        return $i;
    }

    private static function charBefore(string $s, int $i): string
    {
        if ($i <= 0) {
            return '';
        }
        $p = $i - 1;
        while ($p > 0 && (ord($s[$p]) & 0xC0) === 0x80) {
            --$p;
        }

        return substr($s, $p, $i - $p);
    }

    private static function charAfter(string $s, int $i): string
    {
        if ($i >= strlen($s)) {
            return '';
        }
        $ch = mb_substr(substr($s, $i, 4), 0, 1, 'UTF-8');

        return $ch;
    }

    private static function isSpace(string $ch): bool
    {
        return preg_match('/^[\s\p{Z}]$/u', $ch) === 1;
    }

    private static function isPunct(string $ch): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $ch) === 1;
    }

    /** Quita los escapes con barra y decodifica las entidades de un destino o un título. */
    private static function unescape(string $text): string
    {
        $out = preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])|&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return $m[1];
                }
                $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $decoded;
            },
            $text
        );

        return is_string($out) ? $out : $text;
    }

    private static function encodeUrl(string $url): string
    {
        return str_replace(' ', '%20', $url);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
