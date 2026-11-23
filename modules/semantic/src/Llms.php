<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

/**
 * El resumen de la tienda para modelos de lenguaje (/llms.txt y /llms-full.txt) y las secciones que comparte con la versión en
 * texto de la portada.
 *
 * Una sección es ['id', 'title', 'links' => [['title', 'url', 'note', 'ref' => ['type', 'id'] | null, 'indent' => bool]]]. Los módulos y los plugins
 * pueden añadir, quitar o reordenar secciones con el filtro «llms.sections».
 */
final class Llms
{
    /** Productos que salen en el llms.txt: los más vendidos. */
    public const PRODUCTS = 30;

    /** Entradas del blog que salen. */
    private const POSTS = 10;

    /** Categorías que salen (todas las del árbol hasta el segundo nivel, con este tope). */
    private const CATEGORIES = 60;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function sections(Semantic $s): array
    {
        $app = $s->app();
        $sections = [];

        // Las páginas principales: la portada en texto, y el mapa con las versiones en texto de todas las páginas.
        $main = [['title' => t('Home'), 'url' => Addresses::forPage($app, ['type' => 'home']), 'note' => '', 'ref' => ['type' => 'home', 'id' => 0]]];
        if ($app->router()->has('semantic.md_sitemap')) {
            $main[] = ['title' => t('Text versions of every page (sitemap)'), 'url' => $app->url()->abs('/sitemap-md.xml', [], ''), 'note' => '', 'ref' => null];
        }
        $sections[] = ['id' => 'main', 'title' => t('Main pages'), 'links' => $main];

        $catalog = $s->catalog();
        if ($catalog !== null) {
            $links = [];
            foreach (is_array($catalog->tree()) ? $catalog->tree() : [] as $category) {
                self::category($s, $category, 1, $links);
            }
            if ($links !== []) {
                $sections[] = ['id' => 'categories', 'title' => t('Categories'), 'links' => $links];
            }

            $links = [];
            $result = $catalog->listing(['sort' => 'best', 'per_page' => self::PRODUCTS]);
            foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $card) {
                if (!is_array($card) || !isset($card['id'], $card['name'], $card['url'])) {
                    continue;
                }
                $price = isset($card['price']) && is_array($card['price']) && isset($card['price']['amount']) && empty($card['price']['hidden']) ? $s->money((int) $card['price']['amount'], isset($card['price']['currency']) ? (string) $card['price']['currency'] : null) : '';
                $label = isset($card['availability']['label']) ? (string) $card['availability']['label'] : '';
                $links[] = [
                    'title' => (string) $card['name'],
                    'url' => $s->mdUrl($s->abs((string) $card['url'])),
                    'note' => \Aifec\Util\Str::trim($price . ($label !== '' ? ' · ' . $label : ''), ' ·'),
                    'ref' => ['type' => 'product', 'id' => (int) $card['id']],
                ];
            }
            if ($links !== []) {
                // Es una muestra: se dice cuántos hay y dónde está la lista entera (13/11/2026: 31 de 226 sin avisar).
                $total = isset($result['total']) ? (int) $result['total'] : count($links);
                $intro = '';
                if ($total > count($links)) {
                    $intro = t('The {shown} best sellers of {total} products. The full list: {full} and the API {api}.', [
                        'shown' => count($links), 'total' => $total,
                        'full' => $app->url()->abs('/llms-full.txt', [], ''), 'api' => $app->url()->abs('/api/v1/products', [], ''),
                    ]);
                }
                $sections[] = ['id' => 'products', 'title' => t('Products'), 'intro' => $intro, 'links' => $links];
            }
        }

        $cms = $s->cms();
        if ($cms !== null) {
            $links = [];
            $map = $app->settings()->arr('cms.legal');
            foreach (array_values($map) as $pageId) {
                $page = is_numeric($pageId) ? $cms->page((int) $pageId) : null;
                if (is_array($page) && isset($page['title'], $page['url_abs'])) {
                    $links[] = ['title' => (string) $page['title'], 'url' => $s->mdUrl((string) $page['url_abs']), 'note' => '', 'ref' => ['type' => 'page', 'id' => (int) $page['id']]];
                }
            }
            if ($links !== []) {
                $sections[] = ['id' => 'policies', 'title' => t('Policies and legal pages'), 'links' => $links];
            }

            $links = [];
            if ($app->router()->has('blog')) {
                $links[] = ['title' => t('Blog'), 'url' => $s->mdUrl($s->abs($app->url()->route('blog'))), 'note' => '', 'ref' => ['type' => 'blog', 'id' => 0]];
            }
            $posts = $cms->posts(['per_page' => self::POSTS, 'page' => 1]);
            foreach (isset($posts['items']) && is_array($posts['items']) ? $posts['items'] : [] as $post) {
                if (is_array($post) && isset($post['title'], $post['url_abs'])) {
                    $links[] = ['title' => (string) $post['title'], 'url' => $s->mdUrl((string) $post['url_abs']), 'note' => substr($s->iso(isset($post['published_at']) ? (string) $post['published_at'] : null), 0, 10), 'ref' => ['type' => 'post', 'id' => (int) $post['id']]];
                }
            }
            if ($links !== []) {
                $sections[] = ['id' => 'blog', 'title' => t('Blog'), 'links' => $links];
            }
        }

        $links = [];
        $gates = $s->gates();
        $labels = ['google' => t('Product feed for Google (XML)'), 'products' => t('Product feed (JSON)'), 'openai' => t('Product feed for shopping assistants (JSON)')];
        foreach (isset($gates['feeds']) && is_array($gates['feeds']) ? $gates['feeds'] : [] as $key => $url) {
            $links[] = ['title' => $labels[$key], 'url' => (string) $url, 'note' => '', 'ref' => null];
        }
        if (isset($gates['api'])) {
            $links[] = ['title' => t('Public API (OpenAPI)'), 'url' => (string) $gates['api'], 'note' => '', 'ref' => null];
        }
        if (isset($gates['mcp'])) {
            $links[] = ['title' => t('MCP server for agents'), 'url' => (string) $gates['mcp'], 'note' => t('a Model Context Protocol server (JSON-RPC by POST), not a page to read'), 'ref' => null];
        }
        if (isset($gates['sitemap'])) {
            $links[] = ['title' => t('Sitemap'), 'url' => (string) $gates['sitemap'], 'note' => '', 'ref' => null];
        }
        $links[] = ['title' => t('What this shop offers to machines'), 'url' => $app->url()->abs('/.well-known/aifec.json', [], ''), 'note' => '', 'ref' => null];
        $sections[] = ['id' => 'howto', 'title' => t('How to query this shop'), 'links' => $links];

        $filtered = $app->hooks()->apply('llms.sections', $sections);

        return is_array($filtered) ? array_values($filtered) : $sections;
    }

    /**
     * Una categoría y sus hijas (hasta el segundo nivel), en orden.
     *
     * @param array<string,mixed>                $category
     * @param array<int,array<string,mixed>>     $links
     */
    private static function category(Semantic $s, array $category, int $depth, array &$links): void
    {
        if (count($links) >= self::CATEGORIES || !isset($category['id'], $category['name'], $category['url'])) {
            return;
        }
        $links[] = [
            'title' => (string) $category['name'],
            'url' => $s->mdUrl($s->abs((string) $category['url'])),
            'note' => '',
            'indent' => $depth > 1,
            'ref' => ['type' => 'category', 'id' => (int) $category['id']],
        ];
        if ($depth < 2) {
            foreach (isset($category['children']) && is_array($category['children']) ? $category['children'] : [] as $child) {
                if (is_array($child)) {
                    self::category($s, $child, $depth + 1, $links);
                }
            }
        }
    }

    /**
     * Las secciones como líneas de Markdown: «## Título» y una lista de enlaces a las versiones en texto.
     *
     * @param array<int,array<string,mixed>> $sections
     *
     * @return string[]
     */
    public static function renderSections(array $sections, Semantic $s): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !isset($section['title']) || empty($section['links']) || !is_array($section['links'])) {
                continue;
            }
            array_push($out, '', '## ' . TextPages::esc($s, (string) $section['title']), '');
            if (isset($section['intro']) && is_string($section['intro']) && $section['intro'] !== '') {
                array_push($out, TextPages::esc($s, $section['intro']), '');
            }
            foreach ($section['links'] as $link) {
                if (!is_array($link) || !isset($link['title'], $link['url'])) {
                    continue;
                }
                $title = (string) $link['title'];
                $prefix = !empty($link['indent']) ? '  ' : '';
                $note = isset($link['note']) ? $s->line((string) $link['note']) : '';
                $out[] = $prefix . '- [' . TextPages::esc($s, $title) . '](' . (string) $link['url'] . ')' . ($note !== '' ? ': ' . TextPages::esc($s, $note) : '');
            }
        }

        return $out;
    }

    /**
     * El contenido de /llms.txt: título, resumen en cita y las secciones con enlaces a las versiones en texto.
     */
    public static function build(Semantic $s): string
    {
        $lines = ['# ' . TextPages::esc($s, $s->shop()), '', '> ' . TextPages::esc($s, $s->summary())];
        // El servidor MCP, lo primero tras el resumen: estaba al final de la lista y las IA no lo veían (17/11/2026).
        $gates = $s->gates();
        if (isset($gates['mcp'])) {
            $lines[] = '';
            $lines[] = '**' . TextPages::esc($s, t('MCP server (no key needed)')) . ':** ' . (string) $gates['mcp'] . ' — '
                . TextPages::esc($s, t('an assistant that supports MCP connectors can add this address as a custom connector and search, fill a cart and prepare the order with tools. Its card:')) . ' '
                . $s->app()->url()->abs('/.well-known/mcp/server-card.json', [], '');
        }

        return implode("\n", array_merge($lines, self::renderSections(self::sections($s), $s))) . "\n";
    }

    /**
     * El contenido de /llms-full.txt: lo mismo que el llms.txt con el texto de cada página enlazada, sin pasar de $maxBytes. No se
     * corta una página por la mitad: cuando la siguiente ya no cabe, se termina.
     */
    public static function buildFull(Semantic $s, int $maxBytes): string
    {
        $text = self::build($s);
        $note = "\n" . t('(The list is cut here: llms-full.txt has a size limit.)') . "\n";
        $cut = false;
        $done = [];
        foreach (self::sections($s) as $section) {
            foreach (isset($section['links']) && is_array($section['links']) ? $section['links'] : [] as $link) {
                $ref = is_array($link) && isset($link['ref']) && is_array($link['ref']) ? $link['ref'] : null;
                if ($ref === null || isset($done[$ref['type'] . ':' . $ref['id']])) {
                    continue;
                }
                $done[$ref['type'] . ':' . $ref['id']] = true;
                $page = TextPages::entity($s, (string) $ref['type'], (int) $ref['id']);
                if ($page === null) {
                    continue;
                }
                // El título de la página ya está en su encabezado: dentro de este fichero cada página baja un nivel.
                $body = preg_replace('/^(#{1,5}) /m', '#$1 ', rtrim($page['markdown']));
                $chunk = "\n---\n\n" . $body . "\n";
                if (strlen($text) + strlen($chunk) + strlen($note) > $maxBytes) {
                    $cut = true;
                    break 2;
                }
                $text .= $chunk;
            }
        }
        if ($cut && strlen($text) + strlen($note) <= $maxBytes) {
            $text .= $note;
        }

        return strlen($text) > $maxBytes ? (string) mb_strcut($text, 0, $maxBytes, 'UTF-8') : $text;
    }
}
