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
 * HTML a Markdown: la versión en texto limpio de una página, pensada para máquinas.
 *
 * Convierte encabezados, párrafos, énfasis, código, listas anidadas, enlaces, imágenes, citas,
 * reglas, tablas y desplegables. Descarta `script`, `style`, `nav`, `form`, `button`, `svg`,
 * `noscript`, lo que lleve `aria-hidden="true"` y lo que lleve el atributo `data-nomd`.
 */
final class HtmlToMarkdown
{
    private const BREAK = "\x01";

    private const SKIP = [
        'script', 'style', 'nav', 'form', 'button', 'svg', 'noscript', 'template', 'head', 'title', 'meta',
        'link', 'base', 'iframe', 'object', 'embed', 'input', 'select', 'textarea', 'math', 'canvas',
        'audio', 'video',
    ];

    private const BLOCK = [
        'p' => true, 'div' => true, 'section' => true, 'article' => true, 'main' => true, 'header' => true,
        'footer' => true, 'aside' => true, 'address' => true, 'figure' => true, 'figcaption' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'ul' => true, 'ol' => true, 'li' => true, 'dl' => true, 'dt' => true, 'dd' => true,
        'blockquote' => true, 'pre' => true, 'hr' => true, 'table' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true, 'tr' => true, 'td' => true, 'th' => true, 'caption' => true,
        'details' => true, 'summary' => true, 'fieldset' => true, 'legend' => true, 'center' => true,
        'body' => true, 'html' => true,
    ];

    private function __construct()
    {
    }

    public static function convert(string $html): string
    {
        $body = HtmlDom::body($html);
        if ($body === null) {
            return '';
        }
        $converter = new self();
        $parts = [];
        foreach ($converter->blocks($body) as $block) {
            $parts[] = $block['md'];
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Bloques de Markdown de los hijos de un nodo; el texto suelto se junta en párrafos.
     *
     * @return array<int,array{k:string,md:string}> k: p (párrafo), ul, ol, o (otro)
     */
    private function blocks(\DOMNode $parent): array
    {
        $out = [];
        $inline = '';
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $inline .= self::textNode($child->data);
                continue;
            }
            if (!($child instanceof \DOMElement) || $this->skip($child)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (!isset(self::BLOCK[$name])) {
                $inline .= $this->inlineElement($child, $name);
                continue;
            }
            $this->flush($inline, $out);
            $previous = $out === [] ? '' : $out[count($out) - 1]['k'];
            foreach ($this->blockElement($child, $name, $previous) as $block) {
                $out[] = $block;
            }
        }
        $this->flush($inline, $out);

        return $out;
    }

    /**
     * @param array<int,array{k:string,md:string}> $out
     */
    private function flush(string &$inline, array &$out): void
    {
        $text = self::finishInline($inline);
        $inline = '';
        if ($text !== '') {
            $out[] = ['k' => 'p', 'md' => $text];
        }
    }

    private function skip(\DOMElement $el): bool
    {
        if (in_array(strtolower($el->nodeName), self::SKIP, true)) {
            return true;
        }
        if (strtolower(trim($el->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }

        return $el->hasAttribute('data-nomd');
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function blockElement(\DOMElement $el, string $name, string $previous): array
    {
        switch ($name) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = $this->flat($el);

                return $text === '' ? [] : [['k' => 'o', 'md' => str_repeat('#', (int) substr($name, 1)) . ' ' . $text]];

            case 'ul':
            case 'ol':
                return $this->listBlock($el, $name === 'ol', $previous);

            case 'blockquote':
                return $this->quoteBlock($el);

            case 'pre':
                return $this->codeBlock($el);

            case 'hr':
                return [['k' => 'o', 'md' => '---']];

            case 'table':
                return $this->tableBlock($el);

            case 'dt':
            case 'summary':
                $text = $this->flat($el);

                return $text === '' ? [] : [['k' => 'p', 'md' => '**' . $text . '**']];
        }

        // Contenedores: p, div, section, li suelto, dd, details, figure…
        return $this->blocks($el);
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function listBlock(\DOMElement $el, bool $ordered, string $previous): array
    {
        $number = 1;
        if ($ordered && preg_match('/^-?\d{1,9}$/', trim($el->getAttribute('start'))) === 1) {
            $number = (int) trim($el->getAttribute('start'));
        }

        $items = [];
        foreach ($el->childNodes as $child) {
            if (!($child instanceof \DOMElement) || $this->skip($child)) {
                continue;
            }
            $inner = strtolower($child->nodeName) === 'li' ? $this->blocks($child) : $this->blockOrInline($child);
            if ($inner !== []) {
                $items[] = $inner;
            }
        }
        if ($items === []) {
            return [];
        }

        $loose = false;
        foreach ($items as $inner) {
            $plain = 0;
            foreach ($inner as $block) {
                if ($block['k'] !== 'ul' && $block['k'] !== 'ol') {
                    ++$plain;
                }
            }
            if ($plain > 1) {
                $loose = true;
            }
        }

        $alternate = $previous === ($ordered ? 'ol' : 'ul');
        $bullet = $alternate ? '*' : '-';
        $delimiter = $alternate ? ')' : '.';

        $rendered = [];
        foreach ($items as $inner) {
            $marker = $ordered ? $number . $delimiter . ' ' : $bullet . ' ';
            ++$number;
            $pad = str_repeat(' ', strlen($marker));

            $text = '';
            foreach ($inner as $index => $block) {
                if ($index > 0) {
                    $tight = !$loose && $inner[$index - 1]['k'] === 'p' && ($block['k'] === 'ul' || $block['k'] === 'ol');
                    $text .= $tight ? "\n" : "\n\n";
                }
                $text .= $block['md'];
            }

            $lines = explode("\n", $text);
            foreach ($lines as $n => $line) {
                if ($n === 0) {
                    $lines[$n] = rtrim($marker . $line);
                } else {
                    $lines[$n] = $line === '' ? '' : $pad . $line;
                }
            }
            $rendered[] = implode("\n", $lines);
        }

        return [['k' => $ordered ? 'ol' : 'ul', 'md' => implode($loose ? "\n\n" : "\n", $rendered)]];
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function blockOrInline(\DOMElement $el): array
    {
        $name = strtolower($el->nodeName);
        if (isset(self::BLOCK[$name])) {
            return $this->blockElement($el, $name, '');
        }
        $text = self::finishInline($this->inlineElement($el, $name));

        return $text === '' ? [] : [['k' => 'p', 'md' => $text]];
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function quoteBlock(\DOMElement $el): array
    {
        $parts = [];
        foreach ($this->blocks($el) as $block) {
            $parts[] = $block['md'];
        }
        if ($parts === []) {
            return [];
        }
        $lines = explode("\n", implode("\n\n", $parts));
        foreach ($lines as $n => $line) {
            $lines[$n] = $line === '' ? '>' : '> ' . $line;
        }

        return [['k' => 'o', 'md' => implode("\n", $lines)]];
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function codeBlock(\DOMElement $el): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $el->textContent);
        if (strncmp($text, "\n", 1) === 0) {
            $text = substr($text, 1);
        }
        $text = rtrim($text, "\n");

        $lang = '';
        $classes = $el->getAttribute('class');
        foreach ($el->getElementsByTagName('code') as $code) {
            $classes .= ' ' . $code->getAttribute('class');
        }
        if (preg_match('/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.\-]+)/', $classes, $m) === 1) {
            $lang = $m[1];
        }

        $longest = 0;
        if (preg_match_all('/`+/', $text, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return [['k' => 'o', 'md' => $fence . $lang . "\n" . $text . ($text === '' ? '' : "\n") . $fence]];
    }

    /**
     * @return array<int,array{k:string,md:string}>
     */
    private function tableBlock(\DOMElement $table): array
    {
        $caption = '';
        $rows = [];
        $this->collectRows($table, $rows, $caption);

        $out = [];
        if ($caption !== '') {
            $out[] = ['k' => 'p', 'md' => '**' . $caption . '**'];
        }
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }
        if ($rows === [] || $cols === 0) {
            return $out;
        }

        $aligns = [];
        for ($c = 0; $c < $cols; ++$c) {
            $aligns[] = isset($rows[0][$c]) ? $rows[0][$c]['align'] : '';
        }

        $lines = [];
        foreach ($rows as $r => $row) {
            $cells = [];
            for ($c = 0; $c < $cols; ++$c) {
                $cells[] = isset($row[$c]) ? $row[$c]['text'] : '';
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
            if ($r === 0) {
                $marks = [];
                foreach ($aligns as $align) {
                    $marks[] = $align === 'center' ? ':---:' : ($align === 'left' ? ':---' : ($align === 'right' ? '---:' : '---'));
                }
                $lines[] = '| ' . implode(' | ', $marks) . ' |';
            }
        }
        $out[] = ['k' => 'o', 'md' => implode("\n", $lines)];

        return $out;
    }

    /**
     * @param array<int,array<int,array{text:string,align:string}>> $rows
     */
    private function collectRows(\DOMElement $node, array &$rows, string &$caption): void
    {
        foreach ($node->childNodes as $child) {
            if (!($child instanceof \DOMElement) || $this->skip($child)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name === 'caption') {
                $caption = $this->flat($child);
            } elseif ($name === 'thead' || $name === 'tbody' || $name === 'tfoot') {
                $this->collectRows($child, $rows, $caption);
            } elseif ($name === 'tr') {
                $row = [];
                foreach ($child->childNodes as $cell) {
                    if (!($cell instanceof \DOMElement) || $this->skip($cell)) {
                        continue;
                    }
                    $cellName = strtolower($cell->nodeName);
                    if ($cellName !== 'td' && $cellName !== 'th') {
                        continue;
                    }
                    $text = str_replace('|', '\\|', $this->flat($cell));
                    $align = '';
                    $hint = strtolower($cell->getAttribute('align') . ' ' . $cell->getAttribute('style'));
                    if (preg_match('/(?:text-align\s*:\s*|^\s*)(left|center|right)\b/', $hint, $m) === 1) {
                        $align = $m[1];
                    }
                    $row[] = ['text' => $text, 'align' => $align];
                    $span = (int) $cell->getAttribute('colspan');
                    for ($extra = 1; $extra < min($span, 20); ++$extra) {
                        $row[] = ['text' => '', 'align' => ''];
                    }
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
        }
    }

    /** Texto en una sola línea, sin estructura de bloques. */
    private function flat(\DOMNode $node): string
    {
        $text = str_replace(self::BREAK, ' ', $this->inlineChildren($node));
        $text = preg_replace('/ {2,}/', ' ', $text);

        return trim(is_string($text) ? $text : '');
    }

    private function inlineChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= self::textNode($child->data);
                continue;
            }
            if (!($child instanceof \DOMElement) || $this->skip($child)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (isset(self::BLOCK[$name])) {
                $out .= ' ' . $this->inlineChildren($child) . ' ';
            } else {
                $out .= $this->inlineElement($child, $name);
            }
        }

        return $out;
    }

    private function inlineElement(\DOMElement $el, string $name): string
    {
        switch ($name) {
            case 'br':
                return self::BREAK;
            case 'strong':
            case 'b':
                return $this->wrap($el, '**');
            case 'em':
            case 'i':
                return $this->wrap($el, '*');
            case 'code':
                return self::codeSpan($el->textContent);
            case 'a':
                return $this->link($el);
            case 'img':
                return $this->image($el);
        }

        return $this->inlineChildren($el);
    }

    private function wrap(\DOMElement $el, string $mark): string
    {
        $inner = $this->inlineChildren($el);
        $core = trim($inner, ' ');
        if ($core === '') {
            return $inner;
        }
        $lead = substr($inner, 0, 1) === ' ' ? ' ' : '';
        $trail = substr($inner, -1) === ' ' ? ' ' : '';

        return $lead . $mark . $core . $mark . $trail;
    }

    private function link(\DOMElement $el): string
    {
        $inner = $this->inlineChildren($el);
        $core = trim($inner, ' ');
        $href = UrlFilter::safe($el->getAttribute('href'));
        if ($href === null || $core === '') {
            return $inner;
        }
        $lead = substr($inner, 0, 1) === ' ' ? ' ' : '';
        $trail = substr($inner, -1) === ' ' ? ' ' : '';

        return $lead . '[' . $core . '](' . self::destination($href) . self::title($el->getAttribute('title')) . ')' . $trail;
    }

    private function image(\DOMElement $el): string
    {
        $alt = self::textNode($el->getAttribute('alt'));
        $src = UrlFilter::safe($el->getAttribute('src'));
        if ($src === null) {
            return $alt;
        }

        return '![' . trim($alt) . '](' . self::destination($src) . self::title($el->getAttribute('title')) . ')';
    }

    private static function destination(string $url): string
    {
        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '\\' => '%5C']);
    }

    private static function title(string $title): string
    {
        $title = preg_replace('/\s+/', ' ', trim($title));
        if (!is_string($title) || $title === '') {
            return '';
        }

        return ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
    }

    private static function codeSpan(string $text): string
    {
        $text = preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $text);
        $text = is_string($text) ? $text : '';
        if ($text === '') {
            return '';
        }
        $longest = 0;
        if (preg_match_all('/`+/', $text, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', $longest + 1);
        $pad = $longest > 0 ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    /** Texto de HTML: espacios colapsados y los caracteres de Markdown escapados. */
    private static function textNode(string $data): string
    {
        $text = preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $data);
        if (!is_string($text)) {
            $text = $data;
        }
        $text = preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text);
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/&(?=#?[A-Za-z0-9]+;)/', '\\\\&', $text);

        return is_string($text) ? $text : '';
    }

    /** Párrafo terminado: sin espacios de más, con los saltos duros y sin que una línea parezca otro bloque. */
    private static function finishInline(string $inline): string
    {
        $text = preg_replace('/ {2,}/', ' ', $inline);
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/ *' . self::BREAK . '[ ' . self::BREAK . ']*/', self::BREAK, $text);
        $text = trim(is_string($text) ? $text : '', ' ' . self::BREAK);
        if ($text === '') {
            return '';
        }

        $lines = explode(self::BREAK, $text);
        foreach ($lines as $n => $line) {
            $line = trim($line);
            if (preg_match('/^\d{1,9}[.)](?:\s|$)/', $line) === 1) {
                // Una barra delante de una cifra no escapa nada: se escapa el punto o el paréntesis.
                $line = (string) preg_replace('/^(\d{1,9})([.)])/', '$1\\\\$2', $line);
            } elseif (preg_match('/^(#{1,6}(?:\s|$)|[+-](?:\s|$)|=+$|-+$|`{3}|~{3})/', $line) === 1) {
                $line = '\\' . $line;
            }
            $lines[$n] = $line;
        }

        return implode("  \n", $lines);
    }
}
