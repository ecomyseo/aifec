<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Cms\Blocks;

use Aifec\Text\HtmlDom;

/**
 * Convierte el código de bloques de WordPress (`<!-- wp:paragraph -->…`) y el contenido clásico
 * (HTML suelto) al modelo de bloques de AIFEC. Lo que no reconoce no se pierde: va a un bloque
 * `html` marcado con `review => true`. El resultado pasa siempre por `BlockSchema::validate()`.
 */
final class GutenbergParser
{
    private const TOKEN = '/<!--\s*(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(\{(?:(?!-->).)*?\}))?\s*(\/)?-->/s';

    /** Atributos dentro de una etiqueta de apertura, con valores entre comillas que pueden llevar «>». */
    private const ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    private const SHORTCODE = '/\[(\/?)([a-z][a-z0-9_-]{1,40})((?:\s[^\[\]]*)?)\]/i';

    private const MAX_NODE_DEPTH = 40;

    private const INLINE_TAGS = ['a', 'abbr', 'b', 'strong', 'i', 'em', 'u', 's', 'mark', 'code', 'kbd', 'sub', 'sup', 'br', 'span', 'small', 'del', 'ins', 'cite', 'q'];

    private const VOID_TAGS = ['br', 'hr', 'img', 'source', 'col', 'input', 'meta', 'link', 'area', 'base', 'wbr'];

    private function __construct()
    {
    }

    /**
     * @return array<int,array<string,mixed>> bloques del modelo, ya validados
     */
    public static function parse(string $content): array
    {
        $root = self::tree($content);

        return BlockSchema::validate(self::fromParts($root->parts, false));
    }

    /**
     * Nombres de los códigos cortos (`[nombre …]`) que hay en el contenido, sin repetir y en
     * minúsculas. No mira dentro de comentarios, `<code>` ni `<pre>`.
     *
     * @return string[]
     */
    public static function shortcodes(string $content): array
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $content);
        $text = (string) preg_replace('/<(pre|code)\b.*?<\/\1\s*>/is', '', $text);
        $names = [];
        if (preg_match_all(self::SHORTCODE, $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $names[strtolower($hit[2])] = true;
            }
        }

        return array_keys($names);
    }

    // ------------------------------------------------------------------ lectura de comentarios

    private static function tree(string $content): WpNode
    {
        $root = new WpNode('', []);
        $found = preg_match_all(self::TOKEN, $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($found === false || $found === 0) {
            if ($content !== '') {
                $root->parts[] = $content;
            }

            return $root;
        }

        /** @var array<int,WpNode> $stack */
        $stack = [$root];
        $pos = 0;
        foreach ($all as $m) {
            $raw = $m[0][0];
            $start = $m[0][1];
            $top = $stack[count($stack) - 1];
            if ($start > $pos) {
                $top->parts[] = substr($content, $pos, $start - $pos);
            }
            $pos = $start + strlen($raw);

            $name = self::shortName($m[2][0]);
            if ($m[1][0] === '/') {
                for ($i = count($stack) - 1; $i >= 1; --$i) {
                    if ($stack[$i]->name === $name) {
                        $stack = array_slice($stack, 0, $i);
                        break;
                    }
                }
                continue;
            }
            if (count($stack) > self::MAX_NODE_DEPTH) {
                $top->parts[] = $raw;
                continue;
            }

            $attrs = [];
            if (isset($m[3]) && $m[3][1] >= 0) {
                $decoded = json_decode($m[3][0], true, 32);
                $attrs = is_array($decoded) ? $decoded : [];
            }
            $node = new WpNode($name, $attrs);
            $top->parts[] = $node;
            if (!(isset($m[4]) && $m[4][1] >= 0 && $m[4][0] === '/')) {
                $stack[] = $node;
            }
        }
        if ($pos < strlen($content)) {
            $stack[count($stack) - 1]->parts[] = substr($content, $pos);
        }

        return $root;
    }

    private static function shortName(string $name): string
    {
        return strpos($name, 'core/') === 0 ? substr($name, 5) : $name;
    }

    // ------------------------------------------------------------------ bloques

    /**
     * @param array<int,string|WpNode> $parts
     *
     * @return array<int,array<string,mixed>>
     */
    private static function fromParts(array $parts, bool $inContainer): array
    {
        $out = [];
        foreach ($parts as $part) {
            if ($part instanceof WpNode) {
                foreach (self::node($part) as $block) {
                    $out[] = $block;
                }
                continue;
            }
            $text = $inContainer ? self::stripWrappers($part) : $part;
            if ($inContainer && !self::hasText($text)) {
                continue;
            }
            foreach (self::classic($text) as $block) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function node(WpNode $n): array
    {
        switch ($n->name) {
            case 'paragraph':
                return self::paragraph($n);
            case 'heading':
                return self::heading($n);
            case 'image':
                return self::image($n);
            case 'gallery':
                return self::gallery($n);
            case 'list':
                $list = self::listFromNode($n);

                return [self::blk('list', ['ordered' => $list['ordered'], 'items' => $list['items']])];
            case 'list-item':
                $item = self::listItem($n);

                return [self::blk('list', ['ordered' => false, 'items' => [$item]])];
            case 'quote':
            case 'pullquote':
                return self::quote($n);
            case 'verse':
                return self::verse($n);
            case 'preformatted':
            case 'code':
                return self::code($n);
            case 'table':
                return self::table($n);
            case 'columns':
                return [self::columns($n)];
            case 'column':
                return [self::blk('group', [], self::fromParts($n->parts, true))];
            case 'group':
                return [self::group($n)];
            case 'cover':
                return [self::cover($n)];
            case 'buttons':
                return self::buttons($n);
            case 'button':
                $item = self::button($n);

                return $item === null ? [] : [self::blk('buttons', ['items' => [$item]])];
            case 'separator':
                return [self::blk('separator', [])];
            case 'spacer':
                return [self::spacer($n)];
            case 'video':
                return self::video($n);
            case 'details':
                return self::details($n);
            case 'html':
                $html = trim($n->inner());

                return $html === '' ? [] : [self::blk('html', ['html' => $html])];
            case 'shortcode':
                $text = trim($n->inner());

                return $text === '' ? [] : [self::blk('html', ['html' => $text, 'review' => true])];
            case 'media-text':
                return self::mediaText($n);
            case 'latest-posts':
                return [self::latestPosts($n)];
            case 'freeform':
            case 'classic':
                return self::classic($n->inner());
            case 'more':
            case 'nextpage':
                return [];
        }
        if (strpos($n->name, 'core-embed/') === 0 || $n->name === 'embed') {
            return self::embed($n);
        }

        return [self::unknown($n)];
    }

    /**
     * @param array<string,mixed> $attrs
     * @param array<int,array<string,mixed>> $children
     *
     * @return array<string,mixed>
     */
    private static function blk(string $type, array $attrs, array $children = []): array
    {
        $clean = [];
        foreach ($attrs as $key => $value) {
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return ['type' => $type, 'attrs' => $clean, 'children' => $children];
    }

    /**
     * @return array<string,mixed>
     */
    private static function unknown(WpNode $n): array
    {
        return self::blk('html', ['html' => trim($n->inner()), 'review' => true]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function unknownHtml(string $html): array
    {
        return self::blk('html', ['html' => trim($html), 'review' => true]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function paragraph(WpNode $n): array
    {
        $html = $n->inner();
        $w = self::wrapper($html, 'p');
        $inner = self::tidy(trim($w !== null ? $w['inner'] : $html));
        if (self::isShortcodeOnly($inner)) {
            return [self::shortcodeBlock($inner)];
        }
        $look = self::look($n->attrs, $w !== null ? $w['attrs'] : []);

        return [self::blk('paragraph', ['html' => $inner, 'align' => $look['align'], 'style' => $look['style']])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function heading(WpNode $n): array
    {
        $html = $n->inner();
        $w = self::wrapper($html, 'h[1-6]');
        $level = $w !== null ? (int) substr($w['tag'], 1) : (isset($n->attrs['level']) && is_numeric($n->attrs['level']) ? (int) $n->attrs['level'] : 2);
        $anchor = $w !== null && isset($w['attrs']['id']) && $w['attrs']['id'] !== '' ? $w['attrs']['id'] : self::str($n->attrs, 'anchor');
        $look = self::look($n->attrs, $w !== null ? $w['attrs'] : []);

        return [self::blk('heading', [
            'level' => $level,
            'html' => self::tidy(trim($w !== null ? $w['inner'] : $html)),
            'anchor' => $anchor !== '' ? $anchor : null,
            'align' => $look['align'],
        ])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function image(WpNode $n): array
    {
        $html = $n->own();
        $img = [];
        if (preg_match('/<img\b(' . self::ATTRS . ')>/i', $html, $m) === 1) {
            $img = self::parseAttrs($m[1]);
        }
        $href = '';
        if (preg_match('/<a\b(' . self::ATTRS . ')>\s*<img\b/is', $html, $m) === 1) {
            $a = self::parseAttrs($m[1]);
            $href = $a['href'] ?? '';
        }
        $caption = '';
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $m) === 1) {
            $caption = trim($m[1]);
        }
        $src = $img['src'] ?? self::str($n->attrs, 'url');
        if ($src === '') {
            return [self::unknown($n)];
        }
        $wrap = self::firstTag($html);
        $align = self::alignOf($n->attrs, $wrap !== null ? $wrap['attrs'] : []);

        return [self::imageBlock(
            $src,
            $img['alt'] ?? self::str($n->attrs, 'alt'),
            $href !== '' ? $href : (self::str($n->attrs, 'linkDestination') === 'custom' ? self::str($n->attrs, 'href') : ''),
            $caption,
            self::px($n->attrs['width'] ?? ($img['width'] ?? null)),
            self::px($n->attrs['height'] ?? ($img['height'] ?? null)),
            $align
        )];
    }

    /**
     * @return array<string,mixed>
     */
    private static function imageBlock(string $src, string $alt, string $href, string $caption, ?int $width, ?int $height, ?string $align): array
    {
        return self::blk('image', [
            'src' => $src,
            'alt' => $alt,
            'caption' => $caption !== '' ? self::tidy($caption) : null,
            'href' => $href !== '' ? $href : null,
            'width' => $width,
            'height' => $height,
            'align' => $align,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function gallery(WpNode $n): array
    {
        $images = [];
        foreach ($n->children() as $child) {
            if ($child->name !== 'image') {
                continue;
            }
            foreach (self::image($child) as $block) {
                if ($block['type'] === 'image') {
                    $images[] = $block['attrs'];
                }
            }
        }
        $own = $n->own();
        if ($images === []) {
            $chunks = preg_split('/(?=<img\b)/i', $own);
            foreach (is_array($chunks) ? $chunks : [] as $chunk) {
                if (preg_match('/^<img\b(' . self::ATTRS . ')>/i', $chunk, $m) !== 1) {
                    continue;
                }
                $a = self::parseAttrs($m[1]);
                if (($a['src'] ?? '') === '') {
                    continue;
                }
                $item = ['src' => $a['src'], 'alt' => $a['alt'] ?? ''];
                if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $chunk, $c) === 1 && trim($c[1]) !== '') {
                    $item['caption'] = self::tidy(trim($c[1]));
                }
                $images[] = $item;
            }
        }
        if ($images === []) {
            return [self::unknown($n)];
        }
        $columns = isset($n->attrs['columns']) && is_numeric($n->attrs['columns']) ? (int) $n->attrs['columns'] : null;
        if ($columns === null && preg_match('/\bcolumns-(\d)\b/', $own, $m) === 1) {
            $columns = (int) $m[1];
        }

        return [self::blk('gallery', ['images' => $images, 'columns' => $columns])];
    }

    // ------------------------------------------------------------------ listas

    /**
     * @return array{ordered:bool,items:array<int,array<string,mixed>>}
     */
    private static function listFromNode(WpNode $n): array
    {
        $ordered = null;
        if (isset($n->attrs['ordered'])) {
            $ordered = !empty($n->attrs['ordered']);
        }
        $first = self::firstTag($n->beforeChildren() !== '' ? $n->beforeChildren() : $n->own());
        if ($ordered === null && $first !== null) {
            $ordered = $first['tag'] === 'ol';
        }

        $items = [];
        $kids = $n->children();
        if ($kids !== []) {
            foreach ($kids as $kid) {
                if ($kid->name === 'list-item') {
                    $items[] = self::listItem($kid);
                }
            }
        } else {
            $body = HtmlDom::body($n->own());
            $list = $body !== null ? self::firstElement($body, ['ul', 'ol']) : null;
            if ($list !== null) {
                $parsed = self::domList($list, 0);
                $items = $parsed['items'];
                if ($ordered === null) {
                    $ordered = $parsed['ordered'];
                }
            }
        }

        return ['ordered' => (bool) $ordered, 'items' => $items];
    }

    /**
     * @return array<string,mixed>
     */
    private static function listItem(WpNode $li): array
    {
        $before = (string) preg_replace('/^\s*<li\b' . self::ATTRS . '>/i', '', $li->beforeChildren());
        $item = [];
        $nested = [];
        $nestedOrdered = null;
        foreach ($li->children() as $kid) {
            if ($kid->name !== 'list') {
                continue;
            }
            $sub = self::listFromNode($kid);
            foreach ($sub['items'] as $subItem) {
                $nested[] = $subItem;
            }
            $nestedOrdered = $sub['ordered'];
        }
        if ($nested === [] && preg_match('/<(ul|ol)\b/i', $before) === 1) {
            // Lista anidada escrita como HTML dentro del elemento, sin bloque propio.
            $body = HtmlDom::body('<ul><li>' . $before . '</li></ul>');
            $list = $body !== null ? self::firstElement($body, ['ul']) : null;
            $parsed = $list !== null ? self::domList($list, 0) : ['items' => []];
            if (isset($parsed['items'][0])) {
                return $parsed['items'][0];
            }
        }
        $item['html'] = self::tidy(trim((string) preg_replace('/<\/li\s*>\s*$/i', '', $before)));
        if ($nested !== []) {
            $item['items'] = $nested;
            $item['ordered'] = (bool) $nestedOrdered;
        }

        return $item;
    }

    /**
     * @return array{ordered:bool,items:array<int,array<string,mixed>>}
     */
    private static function domList(\DOMElement $list, int $depth): array
    {
        $items = [];
        foreach ($list->childNodes as $c) {
            if (!($c instanceof \DOMElement) || strtolower($c->nodeName) !== 'li') {
                continue;
            }
            $html = '';
            $sub = [];
            $subOrdered = false;
            foreach ($c->childNodes as $cc) {
                $isList = $cc instanceof \DOMElement && in_array(strtolower($cc->nodeName), ['ul', 'ol'], true);
                if ($isList && $depth < 8) {
                    /** @var \DOMElement $cc */
                    $parsed = self::domList($cc, $depth + 1);
                    foreach ($parsed['items'] as $subItem) {
                        $sub[] = $subItem;
                    }
                    $subOrdered = $parsed['ordered'];
                } else {
                    $html .= self::ser($cc);
                }
            }
            $item = ['html' => self::tidy(trim($html))];
            if ($sub !== []) {
                $item['items'] = $sub;
                $item['ordered'] = $subOrdered;
            }
            $items[] = $item;
        }

        return ['ordered' => strtolower($list->nodeName) === 'ol', 'items' => $items];
    }

    // ------------------------------------------------------------------ citas, versos, código

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function quote(WpNode $n): array
    {
        $cite = self::str($n->attrs, 'citation');
        if ($n->children() !== []) {
            $html = '';
            foreach ($n->parts as $part) {
                if ($part instanceof WpNode) {
                    $html .= $part->inner();
                } elseif (preg_match('/<cite\b[^>]*>(.*?)<\/cite>/is', $part, $m) === 1) {
                    $cite = $cite !== '' ? $cite : trim($m[1]);
                }
            }
        } else {
            $own = $n->own();
            $inner = $own;
            if (preg_match('/<blockquote\b' . self::ATTRS . '>(.*)<\/blockquote\s*>/is', $own, $m) === 1) {
                $inner = $m[1];
            }
            if (preg_match('/<cite\b[^>]*>(.*?)<\/cite>/is', $inner, $m) === 1) {
                $cite = $cite !== '' ? $cite : trim($m[1]);
                $inner = (string) preg_replace('/<cite\b[^>]*>.*?<\/cite>/is', '', $inner);
            }
            $html = $inner;
        }

        return [self::blk('quote', ['html' => self::tidy(trim($html)), 'cite' => $cite !== '' ? self::tidy($cite) : null])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function verse(WpNode $n): array
    {
        $w = self::wrapper($n->inner(), 'pre');
        $inner = trim($w !== null ? $w['inner'] : $n->inner());
        $inner = (string) preg_replace('/\r?\n/', '<br>', $inner);

        return [self::blk('paragraph', ['html' => self::tidy($inner)])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function code(WpNode $n): array
    {
        $html = $n->inner();
        $w = self::wrapper($html, 'pre');
        $inner = $w !== null ? $w['inner'] : $html;
        $language = self::str($n->attrs, 'language');
        if (preg_match('/<code\b(' . self::ATTRS . ')>/i', $inner, $m) === 1) {
            $a = self::parseAttrs($m[1]);
            if (preg_match('/\blang(?:uage)?-([A-Za-z0-9+#_.-]+)/', $a['class'] ?? '', $l) === 1) {
                $language = $language !== '' ? $language : $l[1];
            }
        }

        return [self::blk('code', ['code' => self::codeText($inner), 'language' => $language !== '' ? $language : null])];
    }

    private static function codeText(string $html): string
    {
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ------------------------------------------------------------------ tablas

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function table(WpNode $n): array
    {
        $body = HtmlDom::body($n->own());
        $table = $body !== null ? self::firstElement($body, ['table']) : null;
        if ($table === null) {
            return [self::unknown($n)];
        }
        $figure = null;
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $n->own(), $m) === 1) {
            $figure = trim($m[1]);
        }

        return [self::domTable($table, $figure)];
    }

    /**
     * @return array<string,mixed>
     */
    private static function domTable(\DOMElement $table, ?string $figcaption): array
    {
        $head = [];
        $rows = [];
        $rowEls = [];
        $caption = '';
        foreach ($table->childNodes as $c) {
            if (!($c instanceof \DOMElement)) {
                continue;
            }
            $name = strtolower($c->nodeName);
            if ($name === 'thead') {
                foreach (self::rowsOf($c) as $tr) {
                    $head[] = self::cellsOf($tr);
                }
            } elseif ($name === 'tbody' || $name === 'tfoot') {
                foreach (self::rowsOf($c) as $tr) {
                    $rows[] = self::cellsOf($tr);
                    $rowEls[] = $tr;
                }
            } elseif ($name === 'tr') {
                $rows[] = self::cellsOf($c);
                $rowEls[] = $c;
            } elseif ($name === 'caption') {
                $caption = trim(self::serChildren($c));
            }
        }
        // Sin <thead>, una primera fila hecha solo de <th> y seguida de más filas es la cabecera.
        if ($head === [] && count($rows) > 1 && self::isHeaderRow($rowEls[0])) {
            $head[] = array_shift($rows);
        }
        if ($figcaption !== null && $figcaption !== '') {
            $caption = $figcaption;
        }

        return self::blk('table', [
            'head' => $head !== [] ? $head : null,
            'rows' => $rows,
            'caption' => $caption !== '' ? self::tidy($caption) : null,
        ]);
    }

    private static function isHeaderRow(\DOMElement $tr): bool
    {
        $cells = 0;
        foreach ($tr->childNodes as $c) {
            if (!($c instanceof \DOMElement) || !in_array(strtolower($c->nodeName), ['td', 'th'], true)) {
                continue;
            }
            if (strtolower($c->nodeName) !== 'th') {
                return false;
            }
            ++$cells;
        }

        return $cells > 0;
    }

    /**
     * @return array<int,\DOMElement>
     */
    private static function rowsOf(\DOMElement $section): array
    {
        $out = [];
        foreach ($section->childNodes as $c) {
            if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'tr') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private static function cellsOf(\DOMElement $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof \DOMElement && in_array(strtolower($c->nodeName), ['td', 'th'], true)) {
                $cells[] = self::tidy(trim(self::serChildren($c)));
            }
        }

        return $cells;
    }

    // ------------------------------------------------------------------ contenedores

    /**
     * @return array<string,mixed>
     */
    private static function columns(WpNode $n): array
    {
        $columns = [];
        foreach ($n->children() as $kid) {
            if ($kid->name === 'column') {
                $width = null;
                if (isset($kid->attrs['width']) && is_string($kid->attrs['width']) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $kid->attrs['width'], $m) === 1) {
                    $width = (float) $m[1];
                }
                $columns[] = self::blk('column', ['width' => $width], self::fromParts($kid->parts, true));
            } else {
                $columns[] = self::blk('column', [], self::node($kid));
            }
        }

        return self::blk('columns', [], $columns);
    }

    /**
     * @return array<string,mixed>
     */
    private static function group(WpNode $n): array
    {
        $w = self::firstTag($n->beforeChildren() !== '' ? $n->beforeChildren() : $n->own());
        $wrap = $w !== null ? $w['attrs'] : [];
        $look = self::look($n->attrs, $wrap);
        $tag = self::str($n->attrs, 'tagName');
        if ($tag === '' && $w !== null) {
            $tag = $w['tag'];
        }

        return self::blk('group', [
            'style' => $look['style'],
            'class_hint' => self::hint($n->attrs, $wrap),
            'tag' => $tag === 'section' ? 'section' : null,
        ], self::fromParts($n->parts, true));
    }

    /**
     * @return array<string,mixed>
     */
    private static function cover(WpNode $n): array
    {
        $a = $n->attrs;
        $own = $n->own();
        $first = self::firstTag($own);
        $wrap = $first !== null ? $first['attrs'] : [];

        $src = self::str($a, 'url');
        if ($src === '' && preg_match('/<img\b(' . self::ATTRS . ')>/i', $own, $m) === 1) {
            $img = self::parseAttrs($m[1]);
            $src = $img['src'] ?? '';
        }
        if ($src === '' && preg_match('/background-image:\s*url\(\s*[\'"]?([^\'")]+)/i', $own, $m) === 1) {
            $src = trim($m[1]);
        }

        $overlay = self::str($a, 'customOverlayColor');
        if ($overlay === '' && preg_match('/wp-block-cover__background[^>]*\sstyle="[^"]*background-color:\s*([^;"]+)/i', $own, $m) === 1) {
            $overlay = trim($m[1]);
        }
        if ($overlay === '') {
            $overlay = self::presetColor(self::str($a, 'overlayColor'));
        }

        $dim = null;
        if (isset($a['dimRatio']) && is_numeric($a['dimRatio'])) {
            $dim = (int) $a['dimRatio'];
        } elseif (preg_match('/\bhas-background-dim-(\d+)\b/', $own, $m) === 1) {
            $dim = (int) $m[1];
        } elseif (preg_match('/\bhas-background-dim\b/', $own) === 1) {
            $dim = 50;
        }

        $minHeight = null;
        $unit = self::str($a, 'minHeightUnit');
        if (isset($a['minHeight']) && is_numeric($a['minHeight']) && ($unit === '' || $unit === 'px')) {
            $minHeight = (int) $a['minHeight'];
        } elseif (preg_match('/min-height:\s*(\d+)px/i', is_string($wrap['style'] ?? null) ? $wrap['style'] : '', $m) === 1) {
            $minHeight = (int) $m[1];
        }

        return self::blk('cover', [
            'src' => $src !== '' ? $src : null,
            'overlay' => $overlay !== '' ? $overlay : null,
            'dim' => $dim,
            'min_height' => $minHeight,
            'class_hint' => self::hint($a, $wrap),
        ], self::fromParts($n->parts, true));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function details(WpNode $n): array
    {
        $parts = $n->parts;
        $summary = self::str($n->attrs, 'summary');
        foreach ($parts as $i => $part) {
            if (is_string($part) && preg_match('/<summary\b[^>]*>(.*?)<\/summary\s*>/is', $part, $m) === 1) {
                if (trim($m[1]) !== '') {
                    $summary = trim($m[1]);
                }
                $parts[$i] = (string) preg_replace('/<summary\b[^>]*>.*?<\/summary\s*>/is', '', $part, 1);
                break;
            }
        }
        $children = self::fromParts(array_values($parts), true);

        return [self::blk('details', ['summary' => self::tidy($summary)], $children)];
    }

    // ------------------------------------------------------------------ botones, vídeo, embed, otros

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function buttons(WpNode $n): array
    {
        $items = [];
        $extra = [];
        foreach ($n->children() as $kid) {
            if ($kid->name === 'button') {
                $item = self::button($kid);
                if ($item !== null) {
                    $items[] = $item;
                }
            } else {
                foreach (self::node($kid) as $block) {
                    $extra[] = $block;
                }
            }
        }
        $first = self::firstTag($n->beforeChildren());
        $align = null;
        $layout = isset($n->attrs['layout']) && is_array($n->attrs['layout']) ? $n->attrs['layout'] : [];
        $justify = self::str($layout, 'justifyContent');
        if (in_array($justify, ['left', 'center', 'right'], true)) {
            $align = $justify;
        } else {
            $align = self::alignOf($n->attrs, $first !== null ? $first['attrs'] : []);
        }
        $out = [];
        if ($items !== []) {
            $out[] = self::blk('buttons', ['items' => $items, 'align' => $align]);
        }

        return array_merge($out, $extra);
    }

    /**
     * @return array<string,string>|null
     */
    private static function button(WpNode $n): ?array
    {
        $html = $n->inner();
        $href = self::str($n->attrs, 'url');
        $text = self::str($n->attrs, 'text');
        $target = self::str($n->attrs, 'linkTarget');
        $class = self::str($n->attrs, 'className');
        if (preg_match('/<a\b(' . self::ATTRS . ')>(.*?)<\/a\s*>/is', $html, $m) === 1) {
            $a = self::parseAttrs($m[1]);
            $href = ($a['href'] ?? '') !== '' ? $a['href'] : $href;
            $text = trim($m[2]);
            $target = ($a['target'] ?? '') !== '' ? $a['target'] : $target;
        } elseif (preg_match('/<button\b(' . self::ATTRS . ')>(.*?)<\/button\s*>/is', $html, $m) === 1) {
            $text = trim($m[2]);
        }
        $wrap = self::firstTag($html);
        $class .= ' ' . ($wrap !== null ? ($wrap['attrs']['class'] ?? '') : '');
        if ($text === '' && $href === '') {
            return null;
        }
        $item = ['text' => self::tidy($text), 'variant' => strpos($class, 'is-style-outline') !== false ? 'outline' : 'fill'];
        if ($href !== '') {
            $item['href'] = $href;
        }
        if ($target === '_blank') {
            $item['target'] = '_blank';
        }

        return $item;
    }

    /**
     * @return array<string,mixed>
     */
    private static function spacer(WpNode $n): array
    {
        $height = self::px($n->attrs['height'] ?? null);
        if ($height === null && preg_match('/height:\s*([0-9.]+(?:px|rem|em)?)/i', $n->own(), $m) === 1) {
            $height = self::px($m[1]);
        }

        return self::blk('spacer', ['height' => $height]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function embed(WpNode $n): array
    {
        $html = $n->own();
        $url = self::str($n->attrs, 'url');
        if ($url === '' && preg_match('/<a\b(' . self::ATTRS . ')>/i', $html, $m) === 1) {
            $a = self::parseAttrs($m[1]);
            $url = $a['href'] ?? '';
        }
        if ($url === '' && preg_match('/wp-block-embed__wrapper[^>]*>\s*([^<\s][^<]*)</i', $html, $m) === 1) {
            $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($url === '') {
            return [self::unknown($n)];
        }
        $provider = self::str($n->attrs, 'providerNameSlug');
        if ($provider === '' && strpos($n->name, 'core-embed/') === 0) {
            $provider = substr($n->name, 11);
        }
        if ($provider !== 'youtube' && $provider !== 'vimeo') {
            $host = parse_url($url, PHP_URL_HOST);
            $host = is_string($host) ? strtolower($host) : '';
            if (preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host) === 1) {
                $provider = 'youtube';
            } elseif (preg_match('/(^|\.)vimeo\.com$/', $host) === 1) {
                $provider = 'vimeo';
            } else {
                $provider = 'other';
            }
        }
        $caption = '';
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $m) === 1) {
            $caption = trim($m[1]);
        }

        return [self::blk('embed', ['provider' => $provider, 'url' => $url, 'caption' => $caption !== '' ? self::tidy($caption) : null])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function video(WpNode $n): array
    {
        $html = $n->own();
        $src = self::str($n->attrs, 'src');
        $poster = self::str($n->attrs, 'poster');
        if (preg_match('/<video\b(' . self::ATTRS . ')>/i', $html, $m) === 1) {
            $v = self::parseAttrs($m[1]);
            $src = ($v['src'] ?? '') !== '' ? $v['src'] : $src;
            $poster = ($v['poster'] ?? '') !== '' ? $v['poster'] : $poster;
        }
        if ($src === '' && preg_match('/<source\b(' . self::ATTRS . ')>/i', $html, $m) === 1) {
            $s = self::parseAttrs($m[1]);
            $src = $s['src'] ?? '';
        }
        if ($src === '') {
            return [self::unknown($n)];
        }
        $caption = '';
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $m) === 1) {
            $caption = trim($m[1]);
        }

        return [self::blk('video', ['src' => $src, 'poster' => $poster !== '' ? $poster : null, 'caption' => $caption !== '' ? self::tidy($caption) : null])];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function mediaText(WpNode $n): array
    {
        $own = $n->own();
        $width = isset($n->attrs['mediaWidth']) && is_numeric($n->attrs['mediaWidth']) ? max(10, min(90, (int) $n->attrs['mediaWidth'])) : 50;

        $media = [];
        $src = self::str($n->attrs, 'mediaUrl');
        $alt = self::str($n->attrs, 'mediaAlt');
        if (self::str($n->attrs, 'mediaType') === 'video') {
            if ($src === '' && preg_match('/<video\b(' . self::ATTRS . ')>/i', $own, $m) === 1) {
                $v = self::parseAttrs($m[1]);
                $src = $v['src'] ?? '';
            }
            if ($src !== '') {
                $media[] = self::blk('video', ['src' => $src]);
            }
        } else {
            if (preg_match('/<img\b(' . self::ATTRS . ')>/i', $own, $m) === 1) {
                $img = self::parseAttrs($m[1]);
                $src = ($img['src'] ?? '') !== '' ? $img['src'] : $src;
                $alt = ($img['alt'] ?? '') !== '' ? $img['alt'] : $alt;
            }
            if ($src !== '') {
                $media[] = self::imageBlock($src, $alt, '', '', null, null, null);
            }
        }
        $content = self::fromParts($n->parts, true);

        $left = self::blk('column', ['width' => $width], $media);
        $right = self::blk('column', ['width' => 100 - $width], $content);
        $order = self::str($n->attrs, 'mediaPosition') === 'right' ? [$right, $left] : [$left, $right];

        return [self::blk('columns', [], $order)];
    }

    /**
     * @return array<string,mixed>
     */
    private static function latestPosts(WpNode $n): array
    {
        $limit = isset($n->attrs['postsToShow']) && is_numeric($n->attrs['postsToShow']) ? (int) $n->attrs['postsToShow'] : null;
        $category = null;
        $cats = $n->attrs['categories'] ?? null;
        if (is_array($cats) && $cats !== []) {
            $first = reset($cats);
            $cats = $first;
        }
        if (is_array($cats) && isset($cats['id'])) {
            $cats = $cats['id'];
        }
        if (is_int($cats) || (is_string($cats) && ctype_digit($cats))) {
            $category = (int) $cats;
        }

        return self::blk('posts', ['limit' => $limit, 'category_id' => $category]);
    }

    // ------------------------------------------------------------------ contenido clásico

    /**
     * Contenido HTML sin bloques: se trocea por las etiquetas de primer nivel.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function classic(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $body = HtmlDom::body($html);
        if ($body === null) {
            return [];
        }
        $out = [];
        $run = '';
        foreach ($body->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $name = strtolower($c->nodeName);
                if (in_array($name, self::INLINE_TAGS, true)) {
                    $run .= self::ser($c);
                    continue;
                }
                foreach (self::runBlocks($run) as $block) {
                    $out[] = $block;
                }
                $run = '';
                foreach (self::classicElement($c, $name) as $block) {
                    $out[] = $block;
                }
            } elseif ($c instanceof \DOMText) {
                $run .= self::ser($c);
            }
        }
        foreach (self::runBlocks($run) as $block) {
            $out[] = $block;
        }

        return $out;
    }

    /**
     * Texto suelto y etiquetas en línea: un párrafo por cada bloque separado por líneas en blanco.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function runBlocks(string $run): array
    {
        $out = [];
        $pieces = preg_split('/\n[ \t\r]*\n/', $run);
        foreach (is_array($pieces) ? $pieces : [] as $piece) {
            $piece = trim($piece);
            if (!self::hasText($piece)) {
                continue;
            }
            if (preg_match(self::SHORTCODE, strip_tags($piece)) === 1) {
                $out[] = self::unknownHtml($piece);
                continue;
            }
            $out[] = self::blk('paragraph', ['html' => self::tidy($piece)]);
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function classicElement(\DOMElement $el, string $name): array
    {
        if ($name === 'p') {
            $sole = self::soleImage($el);
            if ($sole !== null) {
                return [self::imageFromDom($sole['img'], $sole['a'], '')];
            }
            $html = self::tidy(trim(self::serChildren($el)));
            if (!self::hasText($html)) {
                return [];
            }
            if (self::isShortcodeOnly($html)) {
                return [self::shortcodeBlock($html)];
            }
            $look = self::look([], self::domAttrs($el));

            return [self::blk('paragraph', ['html' => $html, 'align' => $look['align'], 'style' => $look['style']])];
        }
        if (preg_match('/^h([1-6])$/', $name, $m) === 1) {
            $look = self::look([], self::domAttrs($el));
            $id = $el->getAttribute('id');

            return [self::blk('heading', [
                'level' => (int) $m[1],
                'html' => self::tidy(trim(self::serChildren($el))),
                'anchor' => $id !== '' ? $id : null,
                'align' => $look['align'],
            ])];
        }
        switch ($name) {
            case 'ul':
            case 'ol':
                $parsed = self::domList($el, 0);

                return [self::blk('list', ['ordered' => $parsed['ordered'], 'items' => $parsed['items']])];
            case 'blockquote':
                $cite = '';
                $html = '';
                foreach ($el->childNodes as $c) {
                    if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'cite') {
                        $cite = trim(self::serChildren($c));
                    } else {
                        $html .= self::ser($c);
                    }
                }

                return [self::blk('quote', ['html' => self::tidy(trim($html)), 'cite' => $cite !== '' ? self::tidy($cite) : null])];
            case 'table':
                return [self::domTable($el, null)];
            case 'img':
                return [self::imageFromDom($el, null, '')];
            case 'figure':
                return self::classicFigure($el);
            case 'hr':
                return [self::blk('separator', [])];
            case 'pre':
                $language = null;
                foreach ($el->getElementsByTagName('code') as $code) {
                    if (preg_match('/\blang(?:uage)?-([A-Za-z0-9+#_.-]+)/', $code->getAttribute('class'), $l) === 1) {
                        $language = $l[1];
                    }
                    break;
                }

                return [self::blk('code', ['code' => self::codeText(self::serChildren($el)), 'language' => $language])];
            case 'details':
                $summary = '';
                $rest = '';
                foreach ($el->childNodes as $c) {
                    if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'summary') {
                        $summary = trim(self::serChildren($c));
                    } else {
                        $rest .= self::ser($c);
                    }
                }

                return [self::blk('details', ['summary' => self::tidy($summary)], self::classic($rest))];
        }

        return [self::unknownHtml(self::ser($el))];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function classicFigure(\DOMElement $fig): array
    {
        $caption = '';
        foreach ($fig->getElementsByTagName('figcaption') as $fc) {
            $caption = trim(self::serChildren($fc));
            break;
        }
        foreach ($fig->getElementsByTagName('table') as $table) {
            return [self::domTable($table, $caption !== '' ? $caption : null)];
        }
        foreach ($fig->getElementsByTagName('img') as $img) {
            $a = null;
            $parent = $img->parentNode;
            if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
                $a = $parent;
            }

            return [self::imageFromDom($img, $a, $caption)];
        }

        return [self::unknownHtml(self::ser($fig))];
    }

    /**
     * @return array{img:\DOMElement,a:?\DOMElement}|null
     */
    private static function soleImage(\DOMElement $p): ?array
    {
        $kids = [];
        foreach ($p->childNodes as $c) {
            if ($c instanceof \DOMText && trim($c->data) === '') {
                continue;
            }
            $kids[] = $c;
        }
        if (count($kids) !== 1 || !($kids[0] instanceof \DOMElement)) {
            return null;
        }
        $only = $kids[0];
        $name = strtolower($only->nodeName);
        if ($name === 'img') {
            return ['img' => $only, 'a' => null];
        }
        if ($name === 'a') {
            $inner = [];
            foreach ($only->childNodes as $c) {
                if ($c instanceof \DOMText && trim($c->data) === '') {
                    continue;
                }
                $inner[] = $c;
            }
            if (count($inner) === 1 && $inner[0] instanceof \DOMElement && strtolower($inner[0]->nodeName) === 'img') {
                return ['img' => $inner[0], 'a' => $only];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function imageFromDom(\DOMElement $img, ?\DOMElement $a, string $caption): array
    {
        $src = $img->getAttribute('src');
        if ($src === '') {
            return self::unknownHtml(self::ser($img));
        }
        $look = self::alignOf([], self::domAttrs($img));

        return self::imageBlock(
            $src,
            $img->getAttribute('alt'),
            $a !== null ? $a->getAttribute('href') : '',
            $caption,
            self::px($img->getAttribute('width')),
            self::px($img->getAttribute('height')),
            $look
        );
    }

    // ------------------------------------------------------------------ estilo y alineación

    /**
     * @param array<string,mixed>  $attrs atributos del comentario
     * @param array<string,string> $wrap  atributos de la etiqueta que envuelve el bloque
     *
     * @return array{align:?string,style:?string}
     */
    private static function look(array $attrs, array $wrap): array
    {
        $align = self::alignOf($attrs, $wrap);
        $decl = self::declarations($attrs);
        if (isset($wrap['style']) && $wrap['style'] !== '') {
            foreach (explode(';', $wrap['style']) as $d) {
                $pos = strpos($d, ':');
                if ($pos === false) {
                    continue;
                }
                $prop = strtolower(trim(substr($d, 0, $pos)));
                $value = trim(substr($d, $pos + 1));
                if ($prop === 'text-align' || $value === '' || strpos($value, 'var(') !== false) {
                    continue;
                }
                if (!isset($decl[$prop])) {
                    $decl[$prop] = $value;
                }
            }
        }
        $parts = [];
        foreach ($decl as $prop => $value) {
            $parts[] = $prop . ':' . $value;
        }

        return ['align' => $align, 'style' => $parts !== [] ? implode(';', $parts) : null];
    }

    /**
     * @param array<string,mixed>  $attrs
     * @param array<string,string> $wrap
     */
    private static function alignOf(array $attrs, array $wrap): ?string
    {
        foreach ([$attrs['textAlign'] ?? null, $attrs['align'] ?? null, $wrap['align'] ?? null] as $a) {
            if (is_string($a) && in_array($a, ['left', 'center', 'right'], true)) {
                return $a;
            }
        }
        if (preg_match('/(?:^|\s)(?:has-text-align-|align)(left|center|right)(?:\s|$)/', $wrap['class'] ?? '', $m) === 1) {
            return $m[1];
        }
        if (preg_match('/text-align:\s*(left|center|right)/i', $wrap['style'] ?? '', $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * @param array<string,mixed>  $attrs
     * @param array<string,string> $wrap
     */
    private static function hint(array $attrs, array $wrap): ?string
    {
        $a = self::str($attrs, 'align');
        if ($a === 'wide' || $a === 'full') {
            return $a;
        }
        if (preg_match('/(?:^|\s)align(wide|full)(?:\s|$)/', $wrap['class'] ?? '', $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Estilo de WordPress (`style.typography`, `style.color`, `style.spacing`) como declaraciones CSS.
     *
     * @param array<string,mixed> $attrs
     *
     * @return array<string,string>
     */
    private static function declarations(array $attrs): array
    {
        $d = [];
        $style = isset($attrs['style']) && is_array($attrs['style']) ? $attrs['style'] : [];
        $typo = isset($style['typography']) && is_array($style['typography']) ? $style['typography'] : [];
        $map = [
            'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'fontStyle' => 'font-style', 'lineHeight' => 'line-height',
            'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'textDecoration' => 'text-decoration',
        ];
        foreach ($map as $key => $prop) {
            $v = self::cssValue($typo[$key] ?? null);
            if ($v !== null) {
                $d[$prop] = $v;
            }
        }
        $color = isset($style['color']) && is_array($style['color']) ? $style['color'] : [];
        foreach (['text' => 'color', 'background' => 'background-color'] as $key => $prop) {
            $v = self::cssValue($color[$key] ?? null);
            if ($v !== null) {
                $d[$prop] = $v;
            }
        }
        $spacing = isset($style['spacing']) && is_array($style['spacing']) ? $style['spacing'] : [];
        foreach (['padding', 'margin'] as $kind) {
            $v = $spacing[$kind] ?? null;
            if (is_array($v)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $sv = self::cssValue($v[$side] ?? null);
                    if ($sv !== null) {
                        $d[$kind . '-' . $side] = $sv;
                    }
                }
            } else {
                $sv = self::cssValue($v);
                if ($sv !== null) {
                    $d[$kind] = $sv;
                }
            }
        }
        // Atributos de versiones antiguas y colores de la paleta que tienen valor propio.
        if (!isset($d['color'])) {
            $c = self::str($attrs, 'customTextColor');
            $c = $c !== '' ? $c : self::presetColor(self::str($attrs, 'textColor'));
            if ($c !== '') {
                $d['color'] = $c;
            }
        }
        if (!isset($d['background-color'])) {
            $c = self::str($attrs, 'customBackgroundColor');
            $c = $c !== '' ? $c : self::presetColor(self::str($attrs, 'backgroundColor'));
            if ($c !== '') {
                $d['background-color'] = $c;
            }
        }
        if (!isset($d['font-size']) && isset($attrs['customFontSize']) && is_numeric($attrs['customFontSize'])) {
            $d['font-size'] = (int) $attrs['customFontSize'] . 'px';
        }

        return $d;
    }

    /**
     * @param mixed $v
     */
    private static function cssValue($v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || strpos($v, 'var:') === 0 || strpos($v, 'var(') !== false) {
            return null;
        }

        return $v;
    }

    /** Solo los colores de la paleta de WordPress que coinciden con un color CSS básico. */
    private static function presetColor(string $slug): string
    {
        return in_array($slug, ['black', 'white'], true) ? $slug : '';
    }

    /**
     * @param mixed $v
     */
    private static function px($v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? (int) round($v) : null;
        }
        if (is_string($v) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px|rem|em)?\s*$/i', $v, $m) === 1) {
            $n = (float) $m[1];
            $unit = strtolower($m[2] ?? '');
            if ($unit === 'rem' || $unit === 'em') {
                $n *= 16;
            }

            return $n > 0 ? (int) round($n) : null;
        }

        return null;
    }

    // ------------------------------------------------------------------ utilidades de texto

    /**
     * @param array<string,mixed> $a
     */
    private static function str(array $a, string $key): string
    {
        return isset($a[$key]) && is_string($a[$key]) ? $a[$key] : '';
    }

    /**
     * La etiqueta única que envuelve todo el HTML, con sus atributos y su contenido.
     *
     * @return array{tag:string,attrs:array<string,string>,inner:string}|null
     */
    private static function wrapper(string $html, string $tags): ?array
    {
        $re = '/^\s*<(' . $tags . ')\b(' . self::ATTRS . ')>(.*)<\/\1\s*>\s*$/is';
        if (preg_match($re, $html, $m) !== 1) {
            return null;
        }

        return ['tag' => strtolower($m[1]), 'attrs' => self::parseAttrs($m[2]), 'inner' => $m[3]];
    }

    /**
     * La primera etiqueta de apertura del HTML.
     *
     * @return array{tag:string,attrs:array<string,string>}|null
     */
    private static function firstTag(string $html): ?array
    {
        if (preg_match('/^\s*<([a-z][a-z0-9]*)\b(' . self::ATTRS . ')>/i', $html, $m) !== 1) {
            return null;
        }

        return ['tag' => strtolower($m[1]), 'attrs' => self::parseAttrs($m[2])];
    }

    /**
     * @return array<string,string>
     */
    private static function parseAttrs(string $s): array
    {
        $out = [];
        if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $s, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $name = strtolower($hit[1]);
                $value = $hit[2] ?? '';
                if ($value === '' && isset($hit[3])) {
                    $value = $hit[3];
                }
                if ($value === '' && isset($hit[4])) {
                    $value = $hit[4];
                }
                if (!isset($out[$name])) {
                    $out[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return $out;
    }

    /** Quita de un HTML las clases que son solo de WordPress (`wp-…`, `has-…`) y dejan el marcado sucio. */
    private static function tidy(string $html): string
    {
        if (strpos($html, 'class=') === false) {
            return $html;
        }
        $tidy = preg_replace_callback('/\sclass="([^"]*)"/i', static function (array $m): string {
            $keep = [];
            foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (strpos($token, 'wp-') !== 0 && strpos($token, 'has-') !== 0) {
                    $keep[] = $token;
                }
            }

            return $keep === [] ? '' : ' class="' . implode(' ', $keep) . '"';
        }, $html);

        return is_string($tidy) ? $tidy : $html;
    }

    /** Quita las etiquetas que solo envuelven los bloques hijos de un contenedor. */
    private static function stripWrappers(string $s): string
    {
        $s = (string) preg_replace('/<figure\b[^>]*wp-block-media-text__media[^>]*>.*?<\/figure\s*>/is', '', $s);
        $s = (string) preg_replace('/<(?:img|video)\b[^>]*wp-block-cover__[^>]*>(?:\s*<\/video\s*>)?/i', '', $s);

        return (string) preg_replace('/<\/?(?:div|section|header|footer|main|article|aside|span|details)\b' . self::ATTRS . '>/i', '', $s);
    }

    private static function hasText(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('/[^\s\x{00A0}]/u', $text) === 1 || preg_match('/<(img|hr|iframe|video|table)\b/i', $html) === 1;
    }

    private static function isShortcodeOnly(string $html): bool
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' && preg_match('/^(?:\[\/?[a-z][a-z0-9_-]+(?:\s[^\[\]]*)?\]\s*)+$/i', $text) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private static function shortcodeBlock(string $html): array
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return self::blk('html', ['html' => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'review' => true]);
    }

    // ------------------------------------------------------------------ DOM

    /**
     * @param array<int,string> $names
     */
    private static function firstElement(\DOMElement $root, array $names): ?\DOMElement
    {
        foreach ($root->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            if (in_array(strtolower($child->nodeName), $names, true)) {
                return $child;
            }
            $inner = self::firstElement($child, $names);
            if ($inner !== null) {
                return $inner;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function domAttrs(\DOMElement $el): array
    {
        $out = [];
        foreach (['class', 'style', 'align'] as $name) {
            if ($el->hasAttribute($name)) {
                $out[$name] = $el->getAttribute($name);
            }
        }

        return $out;
    }

    /** Serializa un nodo a HTML sin depender de cómo lo escriba libxml. */
    private static function ser(\DOMNode $n): string
    {
        if ($n instanceof \DOMText) {
            return htmlspecialchars($n->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (!($n instanceof \DOMElement)) {
            return '';
        }
        $tag = strtolower($n->nodeName);
        $attrs = '';
        foreach ($n->attributes as $a) {
            $attrs .= ' ' . $a->nodeName . '="' . htmlspecialchars($a->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if (in_array($tag, self::VOID_TAGS, true)) {
            return '<' . $tag . $attrs . '>';
        }

        return '<' . $tag . $attrs . '>' . self::serChildren($n) . '</' . $tag . '>';
    }

    private static function serChildren(\DOMNode $n): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            $out .= self::ser($c);
        }

        return $out;
    }
}
