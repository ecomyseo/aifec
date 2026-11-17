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

use Aifec\Text\HtmlSanitizer;
use Aifec\Text\UrlFilter;

/**
 * Pinta una lista de bloques a HTML semántico, con clases estables `af-b af-b-<tipo>`.
 *
 * Los bloques dinámicos (`products`, `product`, `posts`, `form`) los pinta quien los conoce:
 * se registran en el constructor como `['products' => callable(array $attrs): string, …]` y su
 * salida se inserta tal cual (es HTML del propio sistema); sin pintor, no pintan nada. Para
 * `image` el pintor recibe los atributos de la imagen con `media_id` y devuelve el HTML del
 * elemento `<img>` (o `<picture>`), que se envuelve en su figura, su enlace y su pie.
 *
 * Es defensivo aunque los bloques ya vengan saneados: todo dato se escapa, las direcciones
 * pasan por el filtro de esquemas y todo `html` se vuelve a limpiar antes de salir.
 */
final class BlockRenderer
{
    /** @var array<string,callable> */
    private $dynamic = [];

    /** @var array<int,array{level:int,text:string,anchor:string}> */
    private $headings = [];

    /** @var int */
    private $headingIndex = 0;

    /**
     * @param array<string,callable> $dynamic
     */
    public function __construct(array $dynamic = [])
    {
        foreach ($dynamic as $type => $painter) {
            if (is_string($type) && is_callable($painter)) {
                $this->dynamic[$type] = $painter;
            }
        }
    }

    /**
     * @param array<int|string,mixed> $blocks
     */
    public function render(array $blocks): string
    {
        $this->headings = BlockText::headings($blocks);
        $this->headingIndex = 0;

        return $this->renderList($blocks, 0);
    }

    /**
     * @param array<int|string,mixed> $blocks
     */
    private function renderList(array $blocks, int $depth): string
    {
        if ($depth >= BlockSchema::MAX_DEPTH) {
            return '';
        }
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html = $this->renderBlock($block, $depth);
            if ($html !== '') {
                $out[] = $html;
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param array<int|string,mixed> $block
     */
    private function renderBlock(array $block, int $depth): string
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $children = BlockSchema::isContainer($type) && isset($block['children']) && is_array($block['children']) ? $block['children'] : [];

        switch ($type) {
            case 'paragraph':
                return '<p class="af-b af-b-paragraph"' . $this->styleAttr([$this->alignCss($attrs), $this->css($attrs['style'] ?? '')]) . '>'
                    . $this->inline($attrs['html'] ?? '') . '</p>';
            case 'heading':
                return $this->heading($attrs);
            case 'image':
                return $this->image($attrs);
            case 'gallery':
                return $this->gallery($attrs);
            case 'list':
                $items = is_array($attrs['items'] ?? null) ? $attrs['items'] : [];

                return $this->items($items, !empty($attrs['ordered']), 0, ' class="af-b af-b-list"');
            case 'quote':
                return $this->quote($attrs);
            case 'table':
                return $this->table($attrs);
            case 'columns':
                return $this->columns($children, $depth);
            case 'column':
                return '<div class="af-b-column">' . $this->renderList($children, $depth + 1) . '</div>';
            case 'group':
                $tag = ($attrs['tag'] ?? '') === 'section' ? 'section' : 'div';

                return '<' . $tag . ' class="af-b af-b-group' . $this->hintClass($attrs) . '"' . $this->styleAttr([$this->css($attrs['style'] ?? '')]) . '>'
                    . $this->renderList($children, $depth + 1) . '</' . $tag . '>';
            case 'cover':
                return $this->cover($attrs, $children, $depth);
            case 'buttons':
                return $this->buttons($attrs);
            case 'separator':
                return '<hr class="af-b af-b-separator">';
            case 'spacer':
                $h = isset($attrs['height']) && is_numeric($attrs['height']) ? max(1, min(400, (int) $attrs['height'])) : 32;

                return '<div class="af-b af-b-spacer" style="height:' . $h . 'px" aria-hidden="true"></div>';
            case 'embed':
                return $this->embed($attrs);
            case 'video':
                return $this->video($attrs);
            case 'details':
                return '<details class="af-b af-b-details"><summary>' . $this->inline($attrs['summary'] ?? '') . '</summary>'
                    . $this->renderList($children, $depth + 1) . '</details>';
            case 'code':
                $lang = is_string($attrs['language'] ?? null) && preg_match('/^[A-Za-z0-9+#_.-]{1,30}$/', $attrs['language']) === 1 ? $attrs['language'] : '';

                return '<pre class="af-b af-b-code"><code' . ($lang !== '' ? ' class="language-' . e($lang) . '"' : '') . '>'
                    . e(is_string($attrs['code'] ?? null) ? $attrs['code'] : '') . '</code></pre>';
            case 'html':
                $html = is_string($attrs['html'] ?? null) ? HtmlSanitizer::clean($attrs['html'], 'embed') : '';

                return $html === '' ? '' : '<div class="af-b af-b-html">' . $html . '</div>';
            case 'products':
            case 'product':
            case 'posts':
            case 'form':
            case 'company':
                return $this->dynamicBlock($type, $attrs);
            case 'toc':
                return $this->toc($attrs);
        }

        return '';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function heading(array $attrs): string
    {
        $level = isset($attrs['level']) && is_numeric($attrs['level']) ? max(1, min(6, (int) $attrs['level'])) : 2;
        $anchor = $this->headings[$this->headingIndex]['anchor'] ?? '';
        ++$this->headingIndex;

        return '<h' . $level . ' class="af-b af-b-heading"' . ($anchor !== '' ? ' id="' . e($anchor) . '"' : '')
            . $this->styleAttr([$this->alignCss($attrs)]) . '>' . $this->inline($attrs['html'] ?? '') . '</h' . $level . '>';
    }

    /**
     * El elemento de imagen: el del pintor si hay `media_id` y pintor, y si no un `<img>` con la
     * dirección. Cadena vacía si no hay nada que pintar.
     *
     * @param array<int|string,mixed> $attrs
     */
    private function imageTag(array $attrs, string $class = ''): string
    {
        $media = isset($attrs['media_id']) && is_numeric($attrs['media_id']) ? (int) $attrs['media_id'] : 0;
        if ($media > 0 && isset($this->dynamic['image'])) {
            $painted = ($this->dynamic['image'])($attrs);
            $painted = is_string($painted) ? HtmlSanitizer::clean($painted, 'content') : '';
            if ($painted !== '') {
                return $painted;
            }
        }
        $src = $this->url($attrs['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $alt = is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '';
        $size = '';
        foreach (['width', 'height'] as $dimension) {
            if (isset($attrs[$dimension]) && is_numeric($attrs[$dimension]) && (int) $attrs[$dimension] > 0) {
                $size .= ' ' . $dimension . '="' . min(10000, (int) $attrs[$dimension]) . '"';
            }
        }

        return '<img' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' src="' . e($src) . '" alt="' . e($alt) . '"' . $size . ' loading="lazy" decoding="async">';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function image(array $attrs): string
    {
        $img = $this->imageTag($attrs);
        if ($img === '') {
            return '';
        }
        $href = $this->url($attrs['href'] ?? '');
        if ($href !== '') {
            $img = '<a href="' . e($href) . '">' . $img . '</a>';
        }
        $align = $this->align($attrs);
        $caption = $this->inline($attrs['caption'] ?? '');

        return '<figure class="af-b af-b-image' . ($align !== '' ? ' af-align-' . $align : '') . '">' . $img
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function gallery(array $attrs): string
    {
        $columns = isset($attrs['columns']) && is_numeric($attrs['columns']) ? max(1, min(6, (int) $attrs['columns'])) : 3;
        $items = '';
        foreach (is_array($attrs['images'] ?? null) ? $attrs['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $img = $this->imageTag($image);
            if ($img === '') {
                continue;
            }
            $caption = $this->inline($image['caption'] ?? '');
            $items .= '<figure class="af-b-gallery-item">' . $img . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
        }

        return $items === '' ? '' : '<div class="af-b af-b-gallery af-cols-' . $columns . '">' . $items . '</div>';
    }

    /**
     * @param array<int|string,mixed> $items
     */
    private function items(array $items, bool $ordered, int $depth, string $attributes): string
    {
        if ($depth > 12) {
            return '';
        }
        $tag = $ordered ? 'ol' : 'ul';
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $html .= '<li>' . $this->inline($item['html'] ?? '');
            if (isset($item['items']) && is_array($item['items']) && $item['items'] !== []) {
                $html .= $this->items($item['items'], array_key_exists('ordered', $item) ? !empty($item['ordered']) : $ordered, $depth + 1, '');
            }
            $html .= '</li>';
        }

        return '<' . $tag . $attributes . '>' . $html . '</' . $tag . '>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function quote(array $attrs): string
    {
        $html = is_string($attrs['html'] ?? null) ? HtmlSanitizer::clean($attrs['html'], 'content') : '';
        if ($html !== '' && stripos($html, '<p') !== 0) {
            $html = '<p>' . $html . '</p>';
        }
        $cite = $this->inline($attrs['cite'] ?? '');

        return '<blockquote class="af-b af-b-quote">' . $html . ($cite !== '' ? '<cite>' . $cite . '</cite>' : '') . '</blockquote>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function table(array $attrs): string
    {
        $head = $this->rows(is_array($attrs['head'] ?? null) ? $attrs['head'] : [], 'th', ' scope="col"');
        $body = $this->rows(is_array($attrs['rows'] ?? null) ? $attrs['rows'] : [], 'td', '');
        if ($head === '' && $body === '') {
            return '';
        }
        $caption = is_string($attrs['caption'] ?? null) ? HtmlSanitizer::clean($attrs['caption'], 'content') : '';

        return '<figure class="af-b af-b-table"><table>' . ($head !== '' ? '<thead>' . $head . '</thead>' : '')
            . ($body !== '' ? '<tbody>' . $body . '</tbody>' : '') . '</table>'
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
    }

    /**
     * @param array<int|string,mixed> $rows
     */
    private function rows(array $rows, string $cell, string $attributes): string
    {
        $html = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<' . $cell . $attributes . '>' . $this->inline($value) . '</' . $cell . '>';
            }
            $html .= '</tr>';
        }

        return $html;
    }

    /**
     * @param array<int|string,mixed> $children
     */
    private function columns(array $children, int $depth): string
    {
        $html = '';
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $attrs = isset($child['attrs']) && is_array($child['attrs']) ? $child['attrs'] : [];
            $isColumn = ($child['type'] ?? null) === 'column';
            $inner = $isColumn
                ? $this->renderList(isset($child['children']) && is_array($child['children']) ? $child['children'] : [], $depth + 2)
                : $this->renderBlock($child, $depth + 1);
            $style = '';
            if ($isColumn && isset($attrs['width']) && is_numeric($attrs['width']) && (float) $attrs['width'] > 0) {
                $width = rtrim(rtrim(number_format(min(100.0, (float) $attrs['width']), 2, '.', ''), '0'), '.');
                $style = ' style="flex-basis:' . $width . '%"';
            }
            $html .= '<div class="af-b-column"' . $style . '>' . $inner . '</div>';
        }

        return '<div class="af-b af-b-columns">' . $html . '</div>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     * @param array<int|string,mixed> $children
     */
    private function cover(array $attrs, array $children, int $depth): string
    {
        $img = $this->imageTag(['src' => $attrs['src'] ?? '', 'media_id' => $attrs['media_id'] ?? null, 'alt' => ''], 'af-b-cover-img');
        $overlay = BlockSchema::color($attrs['overlay'] ?? '');
        $dim = isset($attrs['dim']) && is_numeric($attrs['dim']) ? max(0, min(100, (int) $attrs['dim'])) : ($overlay !== null ? 50 : 0);
        $minHeight = isset($attrs['min_height']) && is_numeric($attrs['min_height']) && (int) $attrs['min_height'] > 0 ? min(3000, (int) $attrs['min_height']) : 0;

        $html = '<div class="af-b af-b-cover' . $this->hintClass($attrs) . '"' . ($minHeight > 0 ? ' style="min-height:' . $minHeight . 'px"' : '') . '>' . $img;
        if ($dim > 0) {
            $html .= '<span class="af-b-cover-overlay" style="background-color:' . e($overlay !== null ? $overlay : '#000') . ';opacity:' . rtrim(rtrim(number_format($dim / 100, 2, '.', ''), '0'), '.') . '"></span>';
        }

        return $html . '<div class="af-b-cover-inner">' . $this->renderList($children, $depth + 1) . '</div></div>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function buttons(array $attrs): string
    {
        $html = '';
        foreach (is_array($attrs['items'] ?? null) ? $attrs['items'] : [] as $button) {
            if (!is_array($button)) {
                continue;
            }
            $text = $this->inline($button['text'] ?? '');
            $href = $this->url($button['href'] ?? '');
            $class = 'af-btn af-btn-' . (($button['variant'] ?? '') === 'outline' ? 'outline' : 'fill');
            if ($href === '') {
                if ($text !== '') {
                    $html .= '<span class="' . $class . '">' . $text . '</span>';
                }
                continue;
            }
            $blank = ($button['target'] ?? '') === '_blank';
            $html .= '<a class="' . $class . '" href="' . e($href) . '"' . ($blank ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $text . '</a>';
        }

        return $html === '' ? '' : '<div class="af-b af-b-buttons"' . $this->styleAttr([$this->alignCss($attrs)]) . '>' . $html . '</div>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function embed(array $attrs): string
    {
        $url = $this->url($attrs['url'] ?? '');
        if ($url === '') {
            return '';
        }
        $caption = $this->inline($attrs['caption'] ?? '');
        $figcaption = $caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '';

        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $provider = is_string($attrs['provider'] ?? null) ? $attrs['provider'] : 'other';
        if ($provider === 'other' || $provider === '') {
            if (preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host) === 1) {
                $provider = 'youtube';
            } elseif (preg_match('/(^|\.)vimeo\.com$/', $host) === 1) {
                $provider = 'vimeo';
            }
        }

        $src = null;
        if ($provider === 'youtube') {
            $src = self::youtubeSource(is_array($parts) ? $parts : []);
        } elseif ($provider === 'vimeo') {
            $src = self::vimeoSource(is_array($parts) ? $parts : []);
        }
        if ($src !== null) {
            $title = $caption !== '' ? ' title="' . e(HtmlSanitizer::text($caption)) . '"' : '';

            return '<figure class="af-b af-b-embed af-embed-' . $provider . '"><div class="af-embed-frame"><iframe src="' . e($src) . '"' . $title
                . ' loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>' . $figcaption . '</figure>';
        }

        return '<figure class="af-b af-b-embed af-embed-link"><a href="' . e($url) . '" rel="noopener">' . e($url) . '</a>' . $figcaption . '</figure>';
    }

    /**
     * @param array<string,mixed> $parts
     */
    private static function youtubeSource(array $parts): ?string
    {
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $id = '';
        if ($host === 'youtu.be' || substr($host, -9) === '.youtu.be') {
            $id = trim($path, '/');
        } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m) === 1) {
            $id = $m[2];
        } elseif (isset($parts['query'])) {
            parse_str((string) $parts['query'], $q);
            $id = isset($q['v']) && is_string($q['v']) ? $q['v'] : '';
        }

        return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1 ? 'https://www.youtube-nocookie.com/embed/' . $id : null;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private static function vimeoSource(array $parts): ?string
    {
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if (preg_match('#^/(?:video/)?([0-9]{4,12})(?:/([A-Za-z0-9]{6,20}))?/?$#', $path, $m) !== 1) {
            return null;
        }
        $query = [];
        if (isset($m[2]) && $m[2] !== '') {
            $query[] = 'h=' . $m[2];
        }
        $query[] = 'dnt=1';

        return 'https://player.vimeo.com/video/' . $m[1] . '?' . implode('&', $query);
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function video(array $attrs): string
    {
        $src = $this->url($attrs['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $poster = $this->url($attrs['poster'] ?? '');
        $caption = $this->inline($attrs['caption'] ?? '');

        return '<figure class="af-b af-b-video"><video controls preload="metadata" src="' . e($src) . '"' . ($poster !== '' ? ' poster="' . e($poster) . '"' : '') . '></video>'
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function dynamicBlock(string $type, array $attrs): string
    {
        if (!isset($this->dynamic[$type])) {
            return '';
        }
        $out = ($this->dynamic[$type])($attrs);

        return is_string($out) && $out !== '' ? '<div class="af-b af-b-' . $type . '">' . $out . '</div>' : '';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function toc(array $attrs): string
    {
        $max = isset($attrs['max_level']) && is_numeric($attrs['max_level']) ? max(1, min(6, (int) $attrs['max_level'])) : 3;
        $html = '';
        foreach ($this->headings as $h) {
            if ($h['level'] > $max || $h['text'] === '' || $h['anchor'] === '') {
                continue;
            }
            $html .= '<li class="af-toc-l' . $h['level'] . '"><a href="#' . e($h['anchor']) . '">' . e($h['text']) . '</a></li>';
        }

        return $html === '' ? '' : '<nav class="af-b af-b-toc"><ul>' . $html . '</ul></nav>';
    }

    /**
     * @param mixed $html
     */
    private function inline($html): string
    {
        return is_string($html) && $html !== '' ? HtmlSanitizer::clean($html, 'inline') : '';
    }

    /**
     * @param mixed $url
     */
    private function url($url): string
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $safe = UrlFilter::safe($url);

        return $safe === null ? '' : $safe;
    }

    /**
     * @param mixed $css
     */
    private function css($css): string
    {
        return is_string($css) && $css !== '' ? BlockSchema::style($css) : '';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function align(array $attrs): string
    {
        $a = is_string($attrs['align'] ?? null) ? $attrs['align'] : '';

        return in_array($a, ['left', 'center', 'right'], true) ? $a : '';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function alignCss(array $attrs): string
    {
        $a = $this->align($attrs);

        return $a !== '' ? 'text-align:' . $a : '';
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private function hintClass(array $attrs): string
    {
        $h = is_string($attrs['class_hint'] ?? null) ? $attrs['class_hint'] : '';

        return $h === 'wide' || $h === 'full' ? ' af-' . $h : '';
    }

    /**
     * @param string[] $parts
     */
    private function styleAttr(array $parts): string
    {
        $parts = array_values(array_filter($parts, static function (string $p): bool {
            return $p !== '';
        }));

        return $parts === [] ? '' : ' style="' . e(implode(';', $parts)) . '"';
    }
}
