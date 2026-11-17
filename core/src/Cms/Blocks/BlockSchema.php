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
use Aifec\Text\HtmlSanitizer;
use Aifec\Text\UrlFilter;

/**
 * Esquema del modelo de bloques: qué tipos hay, qué atributos lleva cada uno y cómo se
 * normalizan. `validate()` devuelve siempre bloques utilizables y seguros (todo el HTML pasa
 * por el saneador, las direcciones por el filtro de esquemas, los números se acotan) y nunca
 * lanza por contenido raro; `errors()` cuenta, en lenguaje legible, lo que está mal en la
 * entrada original.
 *
 * Tipos de valor de un atributo (columna `attrs` de `types()`): `inline`, `content` y `embed`
 * (HTML saneado con ese perfil), `text` (una línea sin etiquetas), `raw` (texto tal cual),
 * `url`, `align`, `style`, `color`, `anchor`, `lang`, `bool`, `form_id`, `ints`,
 * `int:<min>:<max>`, `num:<min>:<max>`, `enum:<a|b|c>` y los compuestos `list_items`,
 * `buttons_items`, `gallery_images` y `rows`.
 */
final class BlockSchema
{
    /** Profundidad máxima de bloques anidados; lo que pasa de ahí se descarta. */
    public const MAX_DEPTH = 20;

    /** Profundidad máxima de listas dentro de listas. */
    private const MAX_ITEM_DEPTH = 8;

    /**
     * La tabla de tipos como datos.
     *
     * `required`: atributos obligatorios. `any_of`: grupos de los que hace falta al menos uno.
     * `children`: `none` (no admite hijos), `any` (cualquier bloque) o `columns` (solo `column`).
     *
     * @return array<string,array{attrs:array<string,string>,required:string[],any_of:array<int,string[]>,children:string}>
     */
    public static function types(): array
    {
        $none = 'none';
        $any = 'any';

        return [
            'paragraph' => ['attrs' => ['html' => 'inline', 'align' => 'align', 'style' => 'style'], 'required' => ['html'], 'any_of' => [], 'children' => $none],
            'heading' => ['attrs' => ['level' => 'int:1:6', 'html' => 'inline', 'anchor' => 'anchor', 'align' => 'align'], 'required' => ['level', 'html'], 'any_of' => [], 'children' => $none],
            'image' => ['attrs' => ['src' => 'url', 'media_id' => 'int:1:2147483647', 'alt' => 'text', 'caption' => 'inline', 'href' => 'url', 'width' => 'int:1:10000', 'height' => 'int:1:10000', 'align' => 'align'], 'required' => [], 'any_of' => [['src', 'media_id']], 'children' => $none],
            'gallery' => ['attrs' => ['images' => 'gallery_images', 'columns' => 'int:1:6'], 'required' => ['images'], 'any_of' => [], 'children' => $none],
            'list' => ['attrs' => ['ordered' => 'bool', 'items' => 'list_items'], 'required' => ['items'], 'any_of' => [], 'children' => $none],
            'quote' => ['attrs' => ['html' => 'content', 'cite' => 'inline'], 'required' => ['html'], 'any_of' => [], 'children' => $none],
            'table' => ['attrs' => ['head' => 'rows', 'rows' => 'rows', 'caption' => 'content'], 'required' => ['rows'], 'any_of' => [], 'children' => $none],
            'columns' => ['attrs' => [], 'required' => [], 'any_of' => [], 'children' => 'columns'],
            'column' => ['attrs' => ['width' => 'num:0:100'], 'required' => [], 'any_of' => [], 'children' => $any],
            'group' => ['attrs' => ['style' => 'style', 'class_hint' => 'enum:wide|full', 'tag' => 'enum:div|section'], 'required' => [], 'any_of' => [], 'children' => $any],
            'cover' => ['attrs' => ['src' => 'url', 'media_id' => 'int:1:2147483647', 'overlay' => 'color', 'dim' => 'int:0:100', 'min_height' => 'int:0:3000', 'class_hint' => 'enum:wide|full'], 'required' => [], 'any_of' => [], 'children' => $any],
            'buttons' => ['attrs' => ['items' => 'buttons_items', 'align' => 'align'], 'required' => ['items'], 'any_of' => [], 'children' => $none],
            'separator' => ['attrs' => [], 'required' => [], 'any_of' => [], 'children' => $none],
            'spacer' => ['attrs' => ['height' => 'int:1:400'], 'required' => [], 'any_of' => [], 'children' => $none],
            'embed' => ['attrs' => ['provider' => 'enum:youtube|vimeo|other', 'url' => 'url', 'caption' => 'inline'], 'required' => ['url'], 'any_of' => [], 'children' => $none],
            'video' => ['attrs' => ['src' => 'url', 'poster' => 'url', 'caption' => 'inline'], 'required' => ['src'], 'any_of' => [], 'children' => $none],
            'details' => ['attrs' => ['summary' => 'inline'], 'required' => ['summary'], 'any_of' => [], 'children' => $any],
            'code' => ['attrs' => ['code' => 'raw', 'language' => 'lang'], 'required' => ['code'], 'any_of' => [], 'children' => $none],
            'html' => ['attrs' => ['html' => 'embed', 'review' => 'bool'], 'required' => ['html'], 'any_of' => [], 'children' => $none],
            'products' => ['attrs' => ['mode' => 'enum:manual|category|latest|featured|sale', 'ids' => 'ints', 'category_id' => 'int:1:2147483647', 'limit' => 'int:1:100', 'columns' => 'int:1:6'], 'required' => ['mode'], 'any_of' => [], 'children' => $none],
            'product' => ['attrs' => ['id' => 'int:1:2147483647'], 'required' => ['id'], 'any_of' => [], 'children' => $none],
            'posts' => ['attrs' => ['limit' => 'int:1:100', 'category_id' => 'int:1:2147483647'], 'required' => [], 'any_of' => [], 'children' => $none],
            'form' => ['attrs' => ['form_id' => 'form_id'], 'required' => ['form_id'], 'any_of' => [], 'children' => $none],
            'toc' => ['attrs' => ['max_level' => 'int:1:6'], 'required' => [], 'any_of' => [], 'children' => $none],
            // Los datos de la empresa (Ajustes → General), leídos al pintar: las páginas legales nunca se quedan con datos viejos.
            'company' => ['attrs' => ['contact' => 'bool'], 'required' => [], 'any_of' => [], 'children' => $none],
        ];
    }

    /** True si el tipo admite bloques hijos (`columns`, `column`, `group`, `cover`, `details`). */
    public static function isContainer(string $type): bool
    {
        $types = self::types();

        return isset($types[$type]) && $types[$type]['children'] !== 'none';
    }

    /**
     * Color CSS sencillo (hexadecimal, nombre, rgb() o hsl()) o null si no vale.
     *
     * @param mixed $value
     */
    public static function color($value): ?string
    {
        $c = is_string($value) ? trim($value) : '';
        if (preg_match('/^(#[0-9A-Fa-f]{3,8}|[A-Za-z]{3,30}|(rgb|hsl)a?\([0-9 ,.%\/-]{3,40}\))$/', $c) === 1) {
            return $c;
        }

        return null;
    }

    /**
     * Bloques normalizados y saneados. Nunca lanza: lo que no se entiende se convierte en un
     * bloque `html` marcado para revisar, y lo que pasa de la profundidad máxima se descarta.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return array<int,array<string,mixed>>
     */
    public static function validate(array $blocks): array
    {
        return self::validateList($blocks, 0, '');
    }

    /**
     * Problemas de la entrada, legibles: obligatorios que faltan, hijos donde no puede haberlos,
     * tipos desconocidos.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return string[]
     */
    public static function errors(array $blocks): array
    {
        $out = [];
        self::collectErrors($blocks, 'blocks', 0, '', $out);

        return $out;
    }

    /**
     * @param array<int|string,mixed> $blocks
     *
     * @return array<int,array<string,mixed>>
     */
    private static function validateList(array $blocks, int $depth, string $parent): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }
        $out = [];
        foreach ($blocks as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if ($parent === 'columns' && ($raw['type'] ?? null) !== 'column') {
                $raw = ['type' => 'column', 'attrs' => [], 'children' => [$raw]];
            }
            $out[] = self::validateBlock($raw, $depth, $parent);
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    private static function validateBlock(array $raw, int $depth, string $parent): array
    {
        $types = self::types();
        $attrs = isset($raw['attrs']) && is_array($raw['attrs']) ? $raw['attrs'] : [];
        $type = $raw['type'] ?? null;

        if (!is_string($type) || !isset($types[$type])) {
            $html = $attrs['html'] ?? '';
            $html = is_string($html) ? HtmlSanitizer::clean($html, 'embed') : '';

            return ['type' => 'html', 'attrs' => ['html' => $html, 'review' => true], 'children' => []];
        }
        // Una columna fuera de unas columnas es un grupo de bloques.
        if ($type === 'column' && $parent !== 'columns') {
            $type = 'group';
        }

        $spec = $types[$type];
        $clean = [];
        foreach ($spec['attrs'] as $name => $kind) {
            if (!array_key_exists($name, $attrs)) {
                continue;
            }
            $value = self::value($kind, $attrs[$name], 0);
            if ($value !== null) {
                $clean[$name] = $value;
            }
        }
        foreach ($spec['required'] as $name) {
            $default = array_key_exists($name, $clean) ? null : self::defaultFor($spec['attrs'][$name]);
            if ($default !== null) {
                $clean[$name] = $default;
            }
        }
        // Orden estable de los atributos: el del esquema.
        $ordered = [];
        foreach ($spec['attrs'] as $name => $kind) {
            if (array_key_exists($name, $clean)) {
                $ordered[$name] = $clean[$name];
            }
        }

        $children = [];
        if ($spec['children'] !== 'none' && isset($raw['children']) && is_array($raw['children'])) {
            $children = self::validateList($raw['children'], $depth + 1, $type);
        }

        return ['type' => $type, 'attrs' => $ordered, 'children' => $children];
    }

    /**
     * Valor con el que se rellena un atributo obligatorio que falta; null si no se puede inventar
     * (una dirección, un identificador) y entonces se deja sin poner.
     *
     * @return mixed
     */
    private static function defaultFor(string $kind)
    {
        if ($kind === 'int:1:6') {
            return 2;
        }
        if ($kind === 'inline' || $kind === 'content' || $kind === 'embed' || $kind === 'raw') {
            return '';
        }
        if ($kind === 'list_items' || $kind === 'buttons_items' || $kind === 'gallery_images' || $kind === 'rows') {
            return [];
        }
        if ($kind === 'enum:manual|category|latest|featured|sale') {
            return 'manual';
        }

        return null;
    }

    /**
     * Normaliza un valor según su tipo; null si no vale (el atributo se descarta).
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function value(string $kind, $value, int $itemDepth)
    {
        $parts = explode(':', $kind);
        switch ($parts[0]) {
            case 'inline':
            case 'content':
            case 'embed':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    return null;
                }

                return HtmlSanitizer::clean((string) $value, $parts[0]);
            case 'text':
                return is_string($value) || is_int($value) || is_float($value) ? self::plain((string) $value) : null;
            case 'raw':
                return is_string($value) ? HtmlDom::normalize($value) : null;
            case 'url':
                if (!is_string($value) || strlen($value) > 2048) {
                    return null;
                }

                return UrlFilter::safe($value);
            case 'align':
                $a = is_string($value) ? strtolower(trim($value)) : '';

                return in_array($a, ['left', 'center', 'right'], true) ? $a : null;
            case 'style':
                return is_string($value) ? self::style($value) : null;
            case 'color':
                return self::color($value);
            case 'anchor':
                $a = is_string($value) ? trim($value) : '';

                return preg_match('/^[A-Za-z][A-Za-z0-9_:.-]{0,99}$/', $a) === 1 ? $a : null;
            case 'lang':
                $l = is_string($value) ? strtolower(trim($value)) : '';

                return preg_match('/^[a-z0-9+#_.-]{1,30}$/', $l) === 1 ? $l : null;
            case 'bool':
                return self::toBool($value);
            case 'int':
                $n = self::toNumber($value);

                return $n === null ? null : max((int) $parts[1], min((int) $parts[2], (int) round($n)));
            case 'num':
                $n = self::toNumber($value);
                if ($n === null) {
                    return null;
                }
                $n = round(max((float) $parts[1], min((float) $parts[2], $n)), 2);

                return floor($n) === $n ? (int) $n : $n;
            case 'enum':
                $e = is_string($value) ? strtolower(trim($value)) : '';

                return in_array($e, explode('|', $parts[1]), true) ? $e : null;
            case 'ints':
                return self::toInts($value);
            case 'form_id':
                return self::toFormId($value);
            case 'list_items':
                return is_array($value) ? self::listItems($value, $itemDepth) : null;
            case 'buttons_items':
                return is_array($value) ? self::buttonItems($value) : null;
            case 'gallery_images':
                return is_array($value) ? self::galleryImages($value) : null;
            case 'rows':
                return is_array($value) ? self::rows($value) : null;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function toNumber($value): ?float
    {
        if (is_bool($value) || !(is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))))) {
            return null;
        }
        $n = (float) (is_string($value) ? trim($value) : $value);

        return is_finite($n) ? $n : null;
    }

    /**
     * @param mixed $value
     *
     * @return int[]|null
     */
    private static function toInts($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $v) {
            $n = self::toNumber($v);
            if ($n !== null && $n >= 1 && $n <= 2147483647) {
                $out[(int) $n] = (int) $n;
            }
            if (count($out) >= 100) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * @param mixed $value
     *
     * @return int|string|null
     */
    private static function toFormId($value)
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value)) {
            $v = trim($value);
            if (preg_match('/^[0-9]{1,10}$/', $v) === 1 && (int) $v >= 1) {
                return (int) $v;
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v) === 1) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param array<int|string,mixed> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private static function listItems(array $items, int $depth): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $item = ['html' => (string) $item];
            }
            if (!is_array($item)) {
                continue;
            }
            $clean = ['html' => self::stringOf($item['html'] ?? '', 'inline')];
            if (isset($item['items']) && is_array($item['items']) && $depth < self::MAX_ITEM_DEPTH) {
                $sub = self::listItems($item['items'], $depth + 1);
                if ($sub !== []) {
                    $clean['items'] = $sub;
                }
            }
            if (array_key_exists('ordered', $item)) {
                $ordered = self::toBool($item['ordered']);
                if ($ordered !== null) {
                    $clean['ordered'] = $ordered;
                }
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $items
     *
     * @return array<int,array<string,string>>
     */
    private static function buttonItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clean = [];
            if (isset($item['text'])) {
                $clean['text'] = self::stringOf($item['text'], 'inline');
            }
            $href = self::value('url', $item['href'] ?? null, 0);
            if (is_string($href)) {
                $clean['href'] = $href;
            }
            $variant = self::value('enum:fill|outline', $item['variant'] ?? null, 0);
            if (is_string($variant)) {
                $clean['variant'] = $variant;
            }
            $target = self::value('enum:_blank|_self', $item['target'] ?? null, 0);
            if (is_string($target)) {
                $clean['target'] = $target;
            }
            if (isset($clean['text']) || isset($clean['href'])) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $images
     *
     * @return array<int,array<string,mixed>>
     */
    private static function galleryImages(array $images): array
    {
        $out = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $clean = [];
            foreach (['src' => 'url', 'media_id' => 'int:1:2147483647', 'alt' => 'text', 'caption' => 'inline'] as $name => $kind) {
                if (!array_key_exists($name, $image)) {
                    continue;
                }
                $v = self::value($kind, $image[$name], 0);
                if ($v !== null) {
                    $clean[$name] = $v;
                }
            }
            if (isset($clean['src']) || isset($clean['media_id'])) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $rows
     *
     * @return array<int,array<int,string>>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = self::stringOf($cell, 'inline');
            }
            $out[] = $cells;
            if (count($out) >= 5000) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function stringOf($value, string $profile): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        return HtmlSanitizer::clean((string) $value, $profile);
    }

    /** Texto de una línea, sin etiquetas y estable al volver a normalizarlo. */
    private static function plain(string $text): string
    {
        for ($i = 0; $i < 4; ++$i) {
            $next = HtmlSanitizer::text($text);
            $next = trim((string) preg_replace('/\s+/u', ' ', $next));
            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        return str_replace(['<', '>'], '', $text);
    }

    /** Declaraciones CSS permitidas, con el mismo filtro que el saneador de HTML. */
    public static function style(string $css): string
    {
        if (strlen($css) > 2000) {
            return '';
        }
        $html = '<span style="' . htmlspecialchars($css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">x</span>';
        $clean = HtmlSanitizer::clean($html, 'inline');
        if (preg_match('/^<span style="([^"]*)">x<\/span>$/', $clean, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    /**
     * @param mixed             $blocks
     * @param array<int,string> $out
     */
    private static function collectErrors($blocks, string $path, int $depth, string $parent, array &$out): void
    {
        if (!is_array($blocks)) {
            $out[] = $path . ': is not a list of blocks';

            return;
        }
        if ($depth >= self::MAX_DEPTH) {
            $out[] = $path . ': nesting is deeper than ' . self::MAX_DEPTH . ' levels and will be dropped';

            return;
        }
        $types = self::types();
        foreach ($blocks as $i => $block) {
            $at = $path . '[' . $i . ']';
            if (!is_array($block)) {
                $out[] = $at . ': is not a block';
                continue;
            }
            $type = $block['type'] ?? null;
            if (!is_string($type) || $type === '') {
                $out[] = $at . ': missing block type';
                continue;
            }
            if (!isset($types[$type])) {
                $out[] = $at . ': unknown block type "' . $type . '"';
                continue;
            }
            $at .= ' (' . $type . ')';
            $attrs = $block['attrs'] ?? [];
            if (!is_array($attrs)) {
                $out[] = $at . ': attrs must be an object';
                $attrs = [];
            }
            $spec = $types[$type];
            if ($parent === 'columns' && $type !== 'column') {
                $out[] = $at . ': only column blocks can be inside columns';
            }
            if ($type === 'column' && $parent !== 'columns') {
                $out[] = $at . ': column must be inside columns';
            }
            foreach ($spec['required'] as $name) {
                if (!self::present($attrs, $name, $spec['attrs'][$name])) {
                    $out[] = $at . ': missing required attribute "' . $name . '"';
                }
            }
            foreach ($spec['any_of'] as $group) {
                $found = false;
                foreach ($group as $name) {
                    if (self::present($attrs, $name, $spec['attrs'][$name])) {
                        $found = true;
                    }
                }
                if (!$found) {
                    $out[] = $at . ': needs at least one of "' . implode('", "', $group) . '"';
                }
            }
            $children = $block['children'] ?? [];
            if (is_array($children) && $children !== []) {
                if ($spec['children'] === 'none') {
                    $out[] = $at . ': this block cannot have children';
                } else {
                    self::collectErrors($children, $at . '.children', $depth + 1, $type, $out);
                }
            }
        }
    }

    /**
     * @param array<int|string,mixed> $attrs
     */
    private static function present(array $attrs, string $name, string $kind): bool
    {
        if (!array_key_exists($name, $attrs) || $attrs[$name] === null) {
            return false;
        }
        $v = $attrs[$name];
        if ($kind === 'url' || strpos($kind, 'enum:') === 0 || $kind === 'form_id' || $kind === 'anchor') {
            return (is_string($v) && trim($v) !== '') || is_int($v);
        }
        if (strpos($kind, 'int:') === 0) {
            return self::toNumber($v) !== null;
        }
        if ($kind === 'list_items' || $kind === 'buttons_items' || $kind === 'gallery_images' || $kind === 'rows') {
            return is_array($v);
        }

        return is_string($v) || is_int($v) || is_float($v);
    }
}
