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

/**
 * Lo que se puede sacar de una lista de bloques sin pintarla: el texto plano (extractos e
 * índice de búsqueda), el número de palabras, los encabezados con su ancla y la primera imagen.
 */
final class BlockText
{
    /**
     * Texto visible de los bloques, un bloque por línea. Las imágenes aportan su pie y los
     * bloques dinámicos (productos, formularios) nada.
     *
     * @param array<int|string,mixed> $blocks
     */
    public static function plain(array $blocks): string
    {
        $lines = [];
        self::collect($blocks, 0, $lines);

        return implode("\n", $lines);
    }

    /**
     * Número de palabras del texto visible.
     *
     * @param array<int|string,mixed> $blocks
     */
    public static function words(array $blocks): int
    {
        $parts = preg_split('/\s+/u', trim(self::plain($blocks)), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * Encabezados en orden de aparición, con el ancla que lleva cada uno: la suya si la tiene y
     * si no la que sale de su texto. Las anclas no se repiten nunca, y las explícitas mandan
     * sobre las generadas.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return array<int,array{level:int,text:string,anchor:string}>
     */
    public static function headings(array $blocks): array
    {
        $found = [];
        self::collectHeadings($blocks, 0, $found);

        $used = [];
        foreach ($found as $i => $h) {
            if ($h['anchor'] === '') {
                continue;
            }
            $found[$i]['anchor'] = self::unique($h['anchor'], $used);
        }
        foreach ($found as $i => $h) {
            if ($h['anchor'] !== '') {
                continue;
            }
            $slug = self::slug($h['text']);
            $found[$i]['anchor'] = self::unique($slug !== '' ? $slug : 'section', $used);
        }

        return $found;
    }

    /**
     * Atributos de la primera imagen (`image`, la primera de una `gallery` o la de fondo de una
     * `cover`), o null si no hay ninguna.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return array<string,mixed>|null
     */
    public static function firstImage(array $blocks): ?array
    {
        return self::findImage($blocks, 0);
    }

    /**
     * Pasa un texto a una ancla: minúsculas, sin tildes, guiones entre palabras.
     */
    public static function slug(string $text): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
            'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ];
        $s = strtr(mb_strtolower($text, 'UTF-8'), $map);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > 60) {
            $s = rtrim(substr($s, 0, 60), '-');
        }

        return $s;
    }

    /**
     * @param array<string,bool> $used
     */
    private static function unique(string $base, array &$used): string
    {
        $candidate = $base;
        $n = 2;
        while (isset($used[$candidate])) {
            $candidate = $base . '-' . $n;
            ++$n;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * @param array<int|string,mixed> $blocks
     * @param string[]                $lines
     */
    private static function collect(array $blocks, int $depth, array &$lines): void
    {
        if ($depth >= BlockSchema::MAX_DEPTH) {
            return;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            switch ($type) {
                case 'paragraph':
                case 'heading':
                case 'quote':
                case 'html':
                    self::add($lines, self::text($attrs['html'] ?? ''));
                    if ($type === 'quote') {
                        self::add($lines, self::text($attrs['cite'] ?? ''));
                    }
                    break;
                case 'image':
                case 'embed':
                case 'video':
                    self::add($lines, self::text($attrs['caption'] ?? ''));
                    break;
                case 'gallery':
                    foreach (is_array($attrs['images'] ?? null) ? $attrs['images'] : [] as $image) {
                        if (is_array($image)) {
                            self::add($lines, self::text($image['caption'] ?? ''));
                        }
                    }
                    break;
                case 'list':
                    self::items(is_array($attrs['items'] ?? null) ? $attrs['items'] : [], 0, $lines);
                    break;
                case 'table':
                    foreach (['head', 'rows'] as $part) {
                        foreach (is_array($attrs[$part] ?? null) ? $attrs[$part] : [] as $row) {
                            $cells = [];
                            foreach (is_array($row) ? $row : [] as $cell) {
                                $t = self::text($cell);
                                if ($t !== '') {
                                    $cells[] = $t;
                                }
                            }
                            self::add($lines, implode(' ', $cells));
                        }
                    }
                    self::add($lines, self::text($attrs['caption'] ?? ''));
                    break;
                case 'buttons':
                    foreach (is_array($attrs['items'] ?? null) ? $attrs['items'] : [] as $button) {
                        if (is_array($button)) {
                            self::add($lines, self::text($button['text'] ?? ''));
                        }
                    }
                    break;
                case 'details':
                    self::add($lines, self::text($attrs['summary'] ?? ''));
                    break;
                case 'code':
                    self::add($lines, self::text(is_string($attrs['code'] ?? null) ? htmlspecialchars($attrs['code'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''));
                    break;
            }

            if (BlockSchema::isContainer($type) && isset($block['children']) && is_array($block['children'])) {
                self::collect($block['children'], $depth + 1, $lines);
            }
        }
    }

    /**
     * @param array<int|string,mixed> $items
     * @param string[]                $lines
     */
    private static function items(array $items, int $depth, array &$lines): void
    {
        if ($depth > 12) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            self::add($lines, self::text($item['html'] ?? ''));
            if (isset($item['items']) && is_array($item['items'])) {
                self::items($item['items'], $depth + 1, $lines);
            }
        }
    }

    /**
     * @param string[] $lines
     */
    private static function add(array &$lines, string $text): void
    {
        if ($text !== '') {
            $lines[] = $text;
        }
    }

    /**
     * @param mixed $html
     */
    private static function text($html): string
    {
        if (!is_string($html) || $html === '') {
            return '';
        }
        $text = HtmlSanitizer::text($html);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<int|string,mixed>                       $blocks
     * @param array<int,array{level:int,text:string,anchor:string}> $found
     */
    private static function collectHeadings(array $blocks, int $depth, array &$found): void
    {
        if ($depth >= BlockSchema::MAX_DEPTH) {
            return;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'heading') {
                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
                $level = isset($attrs['level']) && is_numeric($attrs['level']) ? max(1, min(6, (int) $attrs['level'])) : 2;
                $anchor = isset($attrs['anchor']) && is_string($attrs['anchor']) ? trim($attrs['anchor']) : '';
                $found[] = ['level' => $level, 'text' => self::text($attrs['html'] ?? ''), 'anchor' => $anchor];
            }
            if (is_string($block['type'] ?? null) && BlockSchema::isContainer($block['type']) && isset($block['children']) && is_array($block['children'])) {
                self::collectHeadings($block['children'], $depth + 1, $found);
            }
        }
    }

    /**
     * @param array<int|string,mixed> $blocks
     *
     * @return array<string,mixed>|null
     */
    private static function findImage(array $blocks, int $depth): ?array
    {
        if ($depth >= BlockSchema::MAX_DEPTH) {
            return null;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            if ($type === 'image' || $type === 'cover') {
                if ((isset($attrs['src']) && $attrs['src'] !== '') || isset($attrs['media_id'])) {
                    return $attrs;
                }
            }
            if ($type === 'gallery' && isset($attrs['images']) && is_array($attrs['images'])) {
                foreach ($attrs['images'] as $image) {
                    if (is_array($image) && ((isset($image['src']) && $image['src'] !== '') || isset($image['media_id']))) {
                        return $image;
                    }
                }
            }
            if (is_string($type) && BlockSchema::isContainer($type) && isset($block['children']) && is_array($block['children'])) {
                $inner = self::findImage($block['children'], $depth + 1);
                if ($inner !== null) {
                    return $inner;
                }
            }
        }

        return null;
    }
}
