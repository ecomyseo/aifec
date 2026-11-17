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
 * Markdown a HTML: un subconjunto de CommonMark más tablas.
 *
 * Admite encabezados (ATX y subrayados), párrafos, saltos duros, énfasis, código en línea y
 * con vallas, citas, listas anidadas, enlaces, imágenes, enlaces automáticos, reglas y tablas
 * con alineación. No admite código por sangría ni referencias a pie de página.
 *
 * El HTML que venga dentro del texto NO se interpreta: sale escapado. Las direcciones pasan
 * por {@see UrlFilter}. A cada encabezado se le pone un `id` derivado de su texto.
 */
final class Markdown
{
    private const MAX_DEPTH = 24;

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    /** @var array<string,bool> identificadores de encabezado ya usados en este documento */
    private $usedIds = [];

    private function __construct()
    {
    }

    public static function toHtml(string $markdown): string
    {
        $text = HtmlDom::normalize($markdown);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = self::expandTabs($line);
        }

        $parser = new self();
        $blocks = $parser->blocks($lines, 0);

        $html = [];
        foreach ($blocks as $block) {
            $html[] = $block['h'];
        }

        return rtrim(implode("\n", $html));
    }

    /**
     * @param string[] $lines
     * @return array<int,array<string,mixed>> bloques: t (p|x), h (HTML), i (texto en línea de un párrafo), b (¿línea en blanco antes?)
     */
    private function blocks(array $lines, int $depth): array
    {
        $blocks = [];
        $n = count($lines);
        $i = 0;
        $blank = false;

        while ($i < $n) {
            $line = $lines[$i];
            if (self::isBlank($line)) {
                $blank = true;
                ++$i;
                continue;
            }
            $before = $blank && $blocks !== [];
            $blank = false;

            if ($depth >= self::MAX_DEPTH) {
                $blocks[] = $this->paragraph(self::esc(trim(implode("\n", array_slice($lines, $i)))), $before);
                break;
            }

            // Código con vallas.
            if (preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*(.*)$/', $line, $m) === 1 && !($m[2][0] === '`' && strpos($m[3], '`') !== false)) {
                $indent = strlen($m[1]);
                $mark = $m[2][0];
                $min = strlen($m[2]);
                $words = preg_split('/\s+/', trim($m[3]));
                $lang = is_array($words) ? (string) preg_replace('/[^A-Za-z0-9_+#.\-]/', '', (string) $words[0]) : '';
                $code = [];
                $j = $i + 1;
                while ($j < $n) {
                    if (preg_match('/^ {0,3}' . $mark . '{' . $min . ',}[ \t]*$/', $lines[$j]) === 1) {
                        ++$j;
                        break;
                    }
                    $code[] = self::stripIndent($lines[$j], $indent);
                    ++$j;
                }
                $i = $j;
                $class = $lang !== '' ? ' class="language-' . $lang . '"' : '';
                $body = $code === [] ? '' : implode("\n", $code) . "\n";
                $blocks[] = ['t' => 'x', 'h' => '<pre><code' . $class . '>' . self::esc($body) . '</code></pre>', 'b' => $before];
                continue;
            }

            // Encabezado con almohadillas.
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m) === 1) {
                $blocks[] = $this->heading(strlen($m[1]), isset($m[2]) ? trim($m[2]) : '', $before);
                ++$i;
                continue;
            }

            if (self::isHr($line)) {
                $blocks[] = ['t' => 'x', 'h' => '<hr>', 'b' => $before];
                ++$i;
                continue;
            }

            // Cita.
            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $quote = [];
                $j = $i;
                while ($j < $n) {
                    $l = $lines[$j];
                    if (preg_match('/^ {0,3}> ?(.*)$/', $l, $mm) === 1) {
                        $quote[] = $mm[1];
                    } elseif (!self::isBlank($l) && !self::startsBlock($l) && self::lazyOk($quote)) {
                        $quote[] = ltrim($l);
                    } else {
                        break;
                    }
                    ++$j;
                }
                $i = $j;
                $inner = $this->blocks($quote, $depth + 1);
                $parts = [];
                foreach ($inner as $b) {
                    $parts[] = $b['h'];
                }
                $blocks[] = ['t' => 'x', 'h' => "<blockquote>\n" . ($parts === [] ? '' : implode("\n", $parts) . "\n") . '</blockquote>', 'b' => $before];
                continue;
            }

            // Lista.
            if (self::listMarker($line) !== null) {
                [$html, $i] = $this->parseList($lines, $i, $n, $depth);
                $blocks[] = ['t' => 'x', 'h' => $html, 'b' => $before];
                continue;
            }

            // Tabla.
            if (self::isTableStart($lines, $i, $n)) {
                [$html, $i] = $this->parseTable($lines, $i, $n);
                $blocks[] = ['t' => 'x', 'h' => $html, 'b' => $before];
                continue;
            }

            // Párrafo, o encabezado subrayado.
            $para = [ltrim($line)];
            $j = $i + 1;
            $setext = 0;
            while ($j < $n) {
                $l = $lines[$j];
                if (self::isBlank($l)) {
                    break;
                }
                if (preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $l, $mm) === 1) {
                    $setext = $mm[1][0] === '=' ? 1 : 2;
                    ++$j;
                    break;
                }
                if (self::interrupts($lines, $j, $n)) {
                    break;
                }
                $para[] = ltrim($l);
                ++$j;
            }
            $i = $j;
            $text = trim(implode("\n", $para));
            if ($setext > 0) {
                $blocks[] = $this->heading($setext, $text, $before);
            } else {
                $blocks[] = $this->paragraph(MarkdownInline::render($text), $before);
            }
        }

        return $blocks;
    }

    /**
     * @return array<string,mixed>
     */
    private function paragraph(string $inline, bool $before): array
    {
        return ['t' => 'p', 'h' => '<p>' . $inline . '</p>', 'i' => $inline, 'b' => $before];
    }

    /**
     * @return array<string,mixed>
     */
    private function heading(int $level, string $text, bool $before): array
    {
        $inline = MarkdownInline::render($text);
        $plain = html_entity_decode(strip_tags($inline), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $id = $this->uniqueId($plain);

        return ['t' => 'x', 'h' => '<h' . $level . ' id="' . $id . '">' . $inline . '</h' . $level . '>', 'b' => $before];
    }

    private function uniqueId(string $text): string
    {
        $slug = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(is_string($slug) ? $slug : '', '-');
        if ($slug === '') {
            $slug = 'section';
        }

        $candidate = $slug;
        $count = 1;
        while (isset($this->usedIds[$candidate])) {
            ++$count;
            $candidate = $slug . '-' . $count;
        }
        $this->usedIds[$candidate] = true;

        return $candidate;
    }

    /**
     * @param string[] $lines
     * @return array{0:string,1:int} el HTML y el índice de la línea siguiente
     */
    private function parseList(array $lines, int $i, int $n, int $depth): array
    {
        $first = self::listMarker($lines[$i]);
        if ($first === null) {
            return ['', $i + 1];
        }

        $items = [];
        $loose = false;
        while ($i < $n) {
            $m = self::listMarker($lines[$i]);
            if ($m === null || $m['ordered'] !== $first['ordered'] || $m['char'] !== $first['char'] || self::isHr($lines[$i])) {
                break;
            }
            $offset = $m['offset'];
            $item = [$m['rest']];
            ++$i;
            $pending = 0;
            while ($i < $n) {
                $l = $lines[$i];
                if (self::isBlank($l)) {
                    ++$pending;
                    ++$i;
                    continue;
                }
                if (self::indentOf($l) >= $offset) {
                    for ($k = 0; $k < $pending; ++$k) {
                        $item[] = '';
                    }
                    $pending = 0;
                    $item[] = self::stripIndent($l, $offset);
                    ++$i;
                    continue;
                }
                if ($pending === 0 && !self::startsBlock($l) && self::lazyOk($item)) {
                    $item[] = ltrim($l);
                    ++$i;
                    continue;
                }
                break;
            }

            $inner = $this->blocks($item, $depth + 1);
            foreach ($inner as $index => $block) {
                if ($index > 0 && $block['b']) {
                    $loose = true;
                }
            }
            $items[] = $inner;

            if ($i < $n) {
                $next = self::listMarker($lines[$i]);
                if ($next !== null && $next['ordered'] === $first['ordered'] && $next['char'] === $first['char'] && !self::isHr($lines[$i])) {
                    if ($pending > 0) {
                        $loose = true;
                    }
                    continue;
                }
            }
            // La lista acaba: las líneas en blanco de detrás no son suyas.
            $i -= $pending;
            break;
        }

        $tag = $first['ordered'] ? 'ol' : 'ul';
        $open = $first['ordered'] && $first['num'] !== 1 ? '<ol start="' . $first['num'] . '">' : '<' . $tag . '>';
        $html = $open . "\n";
        foreach ($items as $inner) {
            $html .= '<li>' . self::renderItem($inner, $loose) . "</li>\n";
        }
        $html .= '</' . $tag . '>';

        return [$html, $i];
    }

    /**
     * @param array<int,array<string,mixed>> $inner
     */
    private static function renderItem(array $inner, bool $loose): string
    {
        if ($inner === []) {
            return '';
        }
        $parts = [];
        foreach ($inner as $block) {
            $parts[] = $loose || $block['t'] !== 'p' ? $block['h'] : $block['i'];
        }
        if ($loose) {
            return "\n" . implode("\n", $parts) . "\n";
        }
        $html = implode("\n", $parts);
        if ($inner[count($inner) - 1]['t'] !== 'p') {
            $html .= "\n";
        }

        return $html;
    }

    /**
     * @param string[] $lines
     * @return array{0:string,1:int}
     */
    private function parseTable(array $lines, int $i, int $n): array
    {
        $header = self::splitRow($lines[$i]);
        $cols = count($header);
        $aligns = [];
        foreach (self::splitRow($lines[$i + 1]) as $cell) {
            $left = substr($cell, 0, 1) === ':';
            $right = substr($cell, -1) === ':';
            $aligns[] = $left && $right ? 'center' : ($left ? 'left' : ($right ? 'right' : ''));
        }

        $rows = [];
        $j = $i + 2;
        while ($j < $n && !self::isBlank($lines[$j]) && !self::startsBlock($lines[$j])) {
            $rows[] = array_slice(array_pad(self::splitRow($lines[$j]), $cols, ''), 0, $cols);
            ++$j;
        }

        $cell = static function (string $tag, string $text, string $align): string {
            $style = $align !== '' ? ' style="text-align:' . $align . '"' : '';

            return '<' . $tag . $style . '>' . MarkdownInline::render($text) . '</' . $tag . ">\n";
        };

        $html = "<table>\n<thead>\n<tr>\n";
        foreach ($header as $c => $text) {
            $html .= $cell('th', $text, $aligns[$c]);
        }
        $html .= "</tr>\n</thead>\n";
        if ($rows !== []) {
            $html .= "<tbody>\n";
            foreach ($rows as $row) {
                $html .= "<tr>\n";
                foreach ($row as $c => $text) {
                    $html .= $cell('td', $text, $aligns[$c]);
                }
                $html .= "</tr>\n";
            }
            $html .= "</tbody>\n";
        }
        $html .= '</table>';

        return [$html, $j];
    }

    /**
     * @return string[]
     */
    private static function splitRow(string $line): array
    {
        $line = trim($line);
        if (substr($line, 0, 1) === '|') {
            $line = substr($line, 1);
        }
        if (preg_match('/(?<!\\\\)\|$/', $line) === 1) {
            $line = substr($line, 0, -1);
        }
        $cells = preg_split('/(?<!\\\\)\|/', $line);

        $out = [];
        foreach (is_array($cells) ? $cells : [] as $cell) {
            $out[] = str_replace('\\|', '|', trim($cell));
        }

        return $out;
    }

    /**
     * @param string[] $lines
     */
    private static function isTableStart(array $lines, int $i, int $n): bool
    {
        if ($i + 1 >= $n || strpos($lines[$i], '|') === false) {
            return false;
        }
        $delimiter = $lines[$i + 1];
        if (strpos($delimiter, '|') === false || strpos($delimiter, '-') === false || preg_match('/^ {0,3}[|: \t-]+$/', $delimiter) !== 1) {
            return false;
        }
        $cells = self::splitRow($delimiter);
        foreach ($cells as $cell) {
            if (preg_match('/^:?-+:?$/', $cell) !== 1) {
                return false;
            }
        }

        return count($cells) === count(self::splitRow($lines[$i]));
    }

    /**
     * Línea que empieza un elemento de lista: bullet, ordered, char, num, indent, offset (sangría del contenido), rest.
     *
     * @return array<string,mixed>|null
     */
    private static function listMarker(string $line): ?array
    {
        if (preg_match('/^( {0,3})([-+*]|(\d{1,9})([.)]))([ \t]+|$)(.*)$/', $line, $m) !== 1) {
            return null;
        }
        $indent = strlen($m[1]);
        $ordered = $m[3] !== '';
        $width = strlen($m[2]);
        $rest = $m[6];
        // Anchura visual de lo que sigue al marcador: un tabulador llega a la siguiente columna múltiplo de 4.
        $column = $indent + $width;
        foreach (str_split($m[5]) as $char) {
            $column += $char === "\t" ? 4 - ($column % 4) : 1;
        }
        $spaces = $column - $indent - $width;

        if ($rest === '') {
            $offset = $indent + $width + 1;
        } elseif ($spaces >= 5) {
            $offset = $indent + $width + 1;
            $rest = str_repeat(' ', $spaces - 1) . $rest;
        } else {
            $offset = $indent + $width + $spaces;
        }

        return [
            'ordered' => $ordered,
            'char' => $ordered ? $m[4] : $m[2],
            'num' => $ordered ? (int) $m[3] : 0,
            'indent' => $indent,
            'offset' => $offset,
            'rest' => $rest,
        ];
    }

    private static function isHr(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1;
    }

    /** ¿Empieza aquí un bloque que corta una continuación «perezosa»? */
    private static function startsBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1
            || preg_match('/^ {0,3}#{1,6}([ \t]|$)/', $line) === 1
            || preg_match('/^ {0,3}>/', $line) === 1
            || preg_match('/^ {0,3}=+[ \t]*$/', $line) === 1
            || self::isHr($line)
            || self::listMarker($line) !== null;
    }

    /**
     * @param string[] $lines
     */
    private static function interrupts(array $lines, int $j, int $n): bool
    {
        $line = $lines[$j];
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1
            || preg_match('/^ {0,3}#{1,6}([ \t]|$)/', $line) === 1
            || preg_match('/^ {0,3}>/', $line) === 1
            || self::isHr($line)
        ) {
            return true;
        }
        $marker = self::listMarker($line);
        if ($marker !== null && $marker['rest'] !== '' && (!$marker['ordered'] || $marker['num'] === 1)) {
            return true;
        }

        return self::isTableStart($lines, $j, $n);
    }

    /**
     * ¿La última línea es texto de un párrafo, al que una línea suelta puede seguir?
     *
     * @param string[] $lines
     */
    private static function lazyOk(array $lines): bool
    {
        if ($lines === []) {
            return false;
        }
        $last = $lines[count($lines) - 1];
        if (self::isBlank($last) || self::isHr($last)
            || preg_match('/^ {0,3}#{1,6}([ \t]|$)/', $last) === 1
            || preg_match('/^ {0,3}(`{3,}|~{3,})/', $last) === 1
        ) {
            return false;
        }

        $open = null;
        foreach ($lines as $line) {
            if ($open === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                    $open = [$m[1][0], strlen($m[1])];
                }
            } elseif (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $line, $m) === 1 && $m[1][0] === $open[0] && strlen($m[1]) >= $open[1]) {
                $open = null;
            }
        }

        return $open === null;
    }

    private static function isBlank(string $line): bool
    {
        return trim($line) === '';
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    private static function stripIndent(string $line, int $width): string
    {
        $strip = min($width, self::indentOf($line));

        return substr($line, $strip);
    }

    private static function expandTabs(string $line): string
    {
        if (strpos($line, "\t") === false) {
            return $line;
        }
        $len = strlen($line);
        $col = 0;
        $i = 0;
        $out = '';
        while ($i < $len && ($line[$i] === ' ' || $line[$i] === "\t")) {
            if ($line[$i] === "\t") {
                $fill = 4 - ($col % 4);
                $out .= str_repeat(' ', $fill);
                $col += $fill;
            } else {
                $out .= ' ';
                ++$col;
            }
            ++$i;
        }

        return $out . substr($line, $i);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
