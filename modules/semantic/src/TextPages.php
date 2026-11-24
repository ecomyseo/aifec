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

use Aifec\Text\HtmlToMarkdown;
use Aifec\Util\Str;

/**
 * La versión en texto (Markdown) de las páginas públicas. Se compone desde los datos que dan los servicios del catálogo y de los
 * contenidos —nunca desde el HTML ya pintado—, así lo que lee una máquina es lo mismo que ve una persona y no depende del tema.
 *
 * entity() carga lo que hace falta de una entidad y lo compone; compose() solo compone, con los datos que se le den.
 */
final class TextPages
{
    /** Los mismos segundos y las mismas etiquetas de caché que la página en HTML: se vacían a la vez. */
    private const TTL = ['product' => 600, 'category' => 300, 'brand' => 300, 'page' => 600, 'post' => 600, 'term' => 600, 'blog' => 600, 'home' => 300];

    /**
     * Carga una entidad (o la portada, con id 0) y devuelve su versión en texto, la dirección canónica de su página y cómo cachearla;
     * null si no existe, si todavía no se ve o si falta el módulo que la guarda.
     *
     * @return array{markdown:string,canonical:string,tags:string[],ttl:int}|null
     */
    public static function entity(Semantic $s, string $type, int $id): ?array
    {
        $app = $s->app();
        $catalog = $s->catalog();
        $cms = $s->cms();
        $page = max(1, $app->request()->int('page', 1));
        $data = null;
        $canonical = '';
        $tags = ['semantic'];

        if ($type === 'product' && $catalog !== null) {
            $data = $catalog->product($id);
            $canonical = is_array($data) && isset($data['url_abs']) ? (string) $data['url_abs'] : '';
            $tags = array_merge($tags, self::catalogTags('product', $id));
        } elseif (($type === 'category' || $type === 'brand') && $catalog !== null) {
            $entity = $type === 'category' ? $catalog->category($id) : $catalog->brand($id);
            if (is_array($entity)) {
                $canonical = $s->abs(isset($entity['url']) ? (string) $entity['url'] : '');
                $data = ['entity' => $entity, 'kind' => $type, 'canonical' => $canonical, 'listing' => $catalog->listing([$type . '_id' => $id, 'page' => $page])];
            }
            $tags = array_merge($tags, self::catalogTags($type, $id));
        } elseif ($type === 'page' && $cms !== null) {
            $data = $cms->page($id);
            $canonical = is_array($data) && isset($data['url_abs']) ? (string) $data['url_abs'] : '';
            $tags = array_merge($tags, ['page:' . $id, 'cms']);
        } elseif ($type === 'post' && $cms !== null) {
            $data = $cms->post($id);
            $canonical = is_array($data) && isset($data['url_abs']) ? (string) $data['url_abs'] : '';
            $tags = array_merge($tags, ['post:' . $id, 'cms']);
        } elseif ($type === 'term' && $cms !== null) {
            $term = $cms->term($id);
            if (is_array($term)) {
                $canonical = $s->abs(isset($term['url']) ? (string) $term['url'] : '');
                $data = [
                    'title' => (string) $term['name'],
                    'description' => isset($term['description']) ? (string) $term['description'] : '',
                    'canonical' => $canonical,
                    'posts' => $cms->posts(['term_id' => $id, 'page' => $page]),
                ];
            }
            $tags[] = 'cms';
        } elseif ($type === 'blog' && $cms !== null) {
            $canonical = $app->router()->has('blog') ? $s->abs($app->url()->route('blog')) : '';
            $data = ['title' => t('Blog'), 'description' => '', 'canonical' => $canonical, 'posts' => $cms->posts(['page' => $page])];
            $tags[] = 'cms';
        } elseif ($type === 'home') {
            $canonical = $app->url()->abs('/');
            $data = ['canonical' => $canonical, 'sections' => Llms::sections($s)];
            $tags = array_merge($tags, ['home', 'catalog', 'cms', 'settings']);
        }
        if (!is_array($data) || $canonical === '') {
            return null;
        }

        return [
            'markdown' => self::compose($s, $type, $data),
            'canonical' => $canonical,
            'tags' => array_values(array_unique($tags)),
            'ttl' => isset(self::TTL[$type]) ? self::TTL[$type] : 300,
        ];
    }

    /**
     * Las etiquetas de caché de la página en HTML de una entidad del catálogo, de la MISMA lista que usa su controlador
     * (Catalog\Front\Support::pageTags): una etiqueta nueva en la ficha llega sola a su versión en texto. Si esa lista no
     * existiera (otro catálogo), las de siempre.
     *
     * @return string[]
     */
    private static function catalogTags(string $type, int $id): array
    {
        $support = 'Aifec\\Modules\\Catalog\\Front\\Support';
        if (class_exists($support) && method_exists($support, 'pageTags')) {
            $tags = $support::pageTags($type, $id);
            if (is_array($tags)) {
                return array_values(array_filter($tags, 'is_string'));
            }
        }

        return [$type . ':' . $id, 'catalog', 'tax'];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function compose(Semantic $s, string $type, array $data): string
    {
        switch ($type) {
            case 'product':
                $lines = self::product($s, $data);
                break;
            case 'category':
            case 'brand':
                $lines = self::listing($s, $data);
                break;
            case 'page':
                $lines = self::content($s, $data, false);
                break;
            case 'post':
                $lines = self::content($s, $data, true);
                break;
            case 'home':
                $lines = self::home($s, $data);
                break;
            case 'blog':
            case 'term':
                $lines = self::posts($s, $data);
                break;
            default:
                return '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /** Un texto como texto de Markdown, en una línea: lo que podría pasar por formato se escapa. */
    public static function esc(Semantic $s, string $text): string
    {
        return strtr($s->line($text), ['\\' => '\\\\', '*' => '\\*', '`' => '\\`', '[' => '\\[', ']' => '\\]', '<' => '\\<', '|' => '\\|']);
    }

    // ------------------------------------------------------------------ producto

    /**
     * @param array<string,mixed> $p ProductData
     *
     * @return string[]
     */
    private static function product(Semantic $s, array $p): array
    {
        $url = isset($p['url_abs']) ? (string) $p['url_abs'] : '';
        $price = isset($p['price']) && is_array($p['price']) ? $p['price'] : [];
        $availability = isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : [];
        $out = ['# ' . self::esc($s, (string) $p['name']), ''];

        $line = '**' . t('Availability') . ':** ' . self::esc($s, isset($availability['label']) ? (string) $availability['label'] : '');
        // Un producto cuyo precio no se enseña (show_price = false) tampoco lo dice aquí.
        $out[] = !empty($price['hidden']) ? $line : '**' . t('Price') . ':** ' . self::priceText($s, $price) . ' · ' . $line;
        $out[] = '';
        $out[] = '**' . t('Link') . ':** [' . $url . '](' . $url . ')';
        if (isset($p['rating']['count']) && (int) $p['rating']['count'] > 0) {
            $out[] = '';
            $out[] = '**' . t('Rating') . ':** ' . $s->app()->i18n()->number((float) $p['rating']['average'], 1) . ' / 5 (' . (int) $p['rating']['count'] . ')';
        }
        $out = array_merge($out, self::actions($s, 'text.product.actions', $p));

        $summary = trim(HtmlToMarkdown::convert(isset($p['summary']) ? (string) $p['summary'] : ''));
        if ($summary !== '') {
            array_push($out, '', $summary);
        }

        $rows = [];
        if (isset($p['brand']['name'])) {
            $rows[] = [t('Brand'), (string) $p['brand']['name']];
        }
        $category = $s->categoryPath($p);
        if ($category !== '') {
            $rows[] = [t('Category'), $category];
        }
        foreach (['sku' => 'SKU', 'gtin' => 'GTIN', 'mpn' => 'MPN'] as $key => $label) {
            if (isset($p[$key]) && $p[$key] !== null && trim((string) $p[$key]) !== '') {
                $rows[] = [$label, trim((string) $p[$key])];
            }
        }
        foreach (isset($p['facts']) && is_array($p['facts']) ? $p['facts'] : [] as $fact) {
            if (is_array($fact) && isset($fact['label'], $fact['value']) && trim((string) $fact['label']) !== '') {
                $rows[] = [(string) $fact['label'], (string) $fact['value']];
            }
        }
        foreach (isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['name'], $attribute['value']) && trim((string) $attribute['value']) !== '') {
                $rows[] = [(string) $attribute['name'], (string) $attribute['value']];
            }
        }
        if ($rows !== []) {
            array_push($out, '', '## ' . t('Key facts'), '');
            $out = array_merge($out, self::table($s, [t('Data'), t('Value')], $rows));
        }

        $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [];
        if ($variants !== []) {
            $rows = [];
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $name = isset($variant['label']) && trim((string) $variant['label']) !== '' ? (string) $variant['label'] : (isset($variant['sku']) && $variant['sku'] !== null && (string) $variant['sku'] !== '' ? (string) $variant['sku'] : '#' . (int) $variant['id']);
                $vPrice = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
                $vAvailability = isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : [];
                $rows[] = [$name, self::priceText($s, $vPrice), isset($vAvailability['label']) ? (string) $vAvailability['label'] : ''];
            }
            array_push($out, '', '## ' . t('Variants'), '');
            $out = array_merge($out, self::table($s, [t('Option'), t('Price'), t('Availability')], $rows));
        }

        $description = trim(HtmlToMarkdown::convert(isset($p['description']) ? (string) $p['description'] : ''));
        if ($description !== '') {
            array_push($out, '', '## ' . t('Description'), '', $description);
        }

        $faqs = [];
        foreach (isset($p['faqs']) && is_array($p['faqs']) ? $p['faqs'] : [] as $faq) {
            if (is_array($faq) && isset($faq['question']) && trim((string) $faq['question']) !== '') {
                $faqs[] = $faq;
            }
        }
        if ($faqs !== []) {
            array_push($out, '', '## ' . t('Frequently asked questions'));
            foreach ($faqs as $faq) {
                array_push($out, '', '### ' . self::esc($s, (string) $faq['question']), '', trim(HtmlToMarkdown::convert(isset($faq['answer']) ? (string) $faq['answer'] : '')));
            }
        }

        $policies = [];
        $ship = !empty($price['hidden']) ? null : $s->shippingFor($p, $s->gross($price));
        if ($ship !== null) {
            $text = $ship['price'] > 0 ? $s->money($ship['price'], isset($price['currency']) ? (string) $price['currency'] : null) : t('Free');
            if ($ship['min_days'] !== null && $ship['max_days'] !== null && ($ship['min_days'] > 0 || $ship['max_days'] > 0)) {
                $text .= ' (' . ($ship['min_days'] === $ship['max_days'] ? tn('{n} day', '{n} days', $ship['min_days']) : t('{min} to {max} days', ['min' => $ship['min_days'], 'max' => $ship['max_days']])) . ')';
            }
            $policies[] = '**' . t('Shipping') . ':** ' . t('from {price}', ['price' => $text]);
        }
        $returns = $s->returns();
        if ($returns !== null) {
            $method = $s->returnMethodFor($p, $returns);
            $link = $s->returnLink();
            if ($method === 'none') {
                // Descarga o servicio: no hay nada que mandar de vuelta; el plazo es el de desistimiento y manda la página legal.
                $policies[] = '**' . t('Returns') . ':** ' . t('{days} to withdraw; nothing has to be sent back.', ['days' => tn('{n} day', '{n} days', $returns['days'])])
                    . ($link !== '' ? ' ' . t('Conditions:') . ' ' . $link : '');
            } else {
                $methods = ['mail' => t('by mail'), 'store' => t('in a physical shop'), 'kiosk' => t('at a drop-off point')];
                $policies[] = '**' . t('Returns') . ':** ' . tn('{n} day', '{n} days', $returns['days']) . ', '
                    . ($returns['payer'] === 'merchant' ? t('free of charge') : t('the customer pays the return')) . ', ' . $methods[$method] . '.'
                    . ($link !== '' ? ' ' . t('Conditions:') . ' ' . $link : '');
            }
        }
        if ($policies !== []) {
            array_push($out, '', '## ' . t('Shipping and returns'));
            foreach ($policies as $policy) {
                array_push($out, '', $policy);
            }
        }

        return $out;
    }

    /**
     * Lo que un asistente puede HACER desde esta página, escrito como direcciones enteras: otros módulos lo añaden por el filtro
     * (la API pone la dirección que mete el producto en un carrito). Va arriba, junto al enlace, y cada dirección completa:
     * muchos lectores de los asistentes solo abren una dirección si la han leído escrita, no las montan ellos, y recortan las
     * páginas largas. Cada acción es ['label' => qué hace, 'url' => dirección absoluta, 'note' => aclaración opcional].
     *
     * @param array<string,mixed> $subject el producto (o lo que toque) tal como lo da el catálogo
     *
     * @return string[]
     */
    private static function actions(Semantic $s, string $filter, array $subject): array
    {
        $actions = $s->app()->hooks()->apply($filter, [], $subject);
        $out = [];
        foreach (is_array($actions) ? array_slice($actions, 0, 40) : [] as $action) {
            if (!is_array($action) || !isset($action['label'], $action['url']) || preg_match('#^https?://#', (string) $action['url']) !== 1) {
                continue;
            }
            $url = (string) $action['url'];
            $note = isset($action['note']) ? trim((string) $action['note']) : '';
            $out[] = '';
            $out[] = '**' . self::esc($s, (string) $action['label']) . ':** [' . $url . '](' . $url . ')' . ($note !== '' ? ' — ' . self::esc($s, $note) : '');
        }

        return $out;
    }

    /**
     * El precio como se enseña: «19,99 €», con «Desde» si hay variantes con precios distintos y el de antes si hay oferta.
     *
     * @param array<string,mixed> $price
     */
    private static function priceText(Semantic $s, array $price): string
    {
        if (!isset($price['amount']) || !empty($price['hidden'])) {
            return '';
        }
        $currency = isset($price['currency']) ? (string) $price['currency'] : null;
        $text = $s->money((int) $price['amount'], $currency);
        if (!empty($price['from'])) {
            $text = t('From {price}', ['price' => $text]);
        }
        if (!empty($price['compare']) && (int) $price['compare'] > (int) $price['amount']) {
            $text .= ' (' . t('was {price}', ['price' => $s->money((int) $price['compare'], $currency)]) . ')';
        }

        return $text;
    }

    /**
     * Una tabla de Markdown; las celdas se escapan aquí.
     *
     * @param string[]                      $headers
     * @param array<int,array<int,string>> $rows
     *
     * @return string[]
     */
    private static function table(Semantic $s, array $headers, array $rows): array
    {
        $out = ['| ' . implode(' | ', array_map(static function (string $h) use ($s): string {
            return self::esc($s, $h);
        }, $headers)) . ' |', '|' . str_repeat('---|', count($headers))];
        foreach ($rows as $row) {
            $out[] = '| ' . implode(' | ', array_map(static function (string $cell) use ($s): string {
                return self::esc($s, $cell);
            }, $row)) . ' |';
        }

        return $out;
    }

    // ------------------------------------------------------------------ listados

    /**
     * Categoría o marca: nombre, descripción, subcategorías y la lista de sus productos (nombre enlazado, precio, disponibilidad).
     *
     * @param array<string,mixed> $d entity, kind, canonical, listing
     *
     * @return string[]
     */
    private static function listing(Semantic $s, array $d): array
    {
        $entity = isset($d['entity']) && is_array($d['entity']) ? $d['entity'] : [];
        $canonical = isset($d['canonical']) ? (string) $d['canonical'] : '';
        $out = ['# ' . self::esc($s, isset($entity['name']) ? (string) $entity['name'] : ''), ''];
        $out[] = '**' . t('Link') . ':** [' . $canonical . '](' . $canonical . ')';
        $description = trim(HtmlToMarkdown::convert(isset($entity['description']) ? (string) $entity['description'] : ''));
        if ($description !== '') {
            array_push($out, '', $description);
        }
        $children = isset($entity['children']) && is_array($entity['children']) ? $entity['children'] : [];
        if ($children !== []) {
            array_push($out, '', '## ' . t('Subcategories'), '');
            foreach ($children as $child) {
                if (is_array($child) && isset($child['name'], $child['url'])) {
                    $out[] = '- [' . self::esc($s, (string) $child['name']) . '](' . $s->mdUrl($s->abs((string) $child['url'])) . ')';
                }
            }
        }

        $listing = isset($d['listing']) && is_array($d['listing']) ? $d['listing'] : [];
        $items = isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [];
        array_push($out, '', '## ' . t('Products') . (isset($listing['total']) ? ' (' . (int) $listing['total'] . ')' : ''), '');
        foreach ($items as $card) {
            if (!is_array($card) || !isset($card['name'], $card['url'])) {
                continue;
            }
            $price = isset($card['price']) && is_array($card['price']) ? $card['price'] : [];
            $label = isset($card['availability']['label']) ? (string) $card['availability']['label'] : '';
            $note = Str::trim(self::priceText($s, $price) . ($label !== '' ? ' · ' . self::esc($s, $label) : ''), ' ·');
            // La dirección que lo mete en un carrito, si un módulo la da (la API): así el asistente compra desde el listado.
            $buy = $s->app()->hooks()->apply('text.listing.action', null, $card);
            if (is_array($buy) && isset($buy['label'], $buy['url']) && preg_match('#^https?://#', (string) $buy['url']) === 1) {
                $note .= ($note !== '' ? ' · ' : '') . '[' . self::esc($s, (string) $buy['label']) . '](' . (string) $buy['url'] . ')';
            }
            $out[] = '- [' . self::esc($s, (string) $card['name']) . '](' . $s->mdUrl($s->abs((string) $card['url'])) . ')' . ($note !== '' ? ': ' . $note : '');
        }

        return array_merge($out, self::paging($s, $canonical, $listing));
    }

    /**
     * La línea de paginación con enlaces a la versión en texto de las páginas vecinas.
     *
     * @param array<string,mixed> $result page, pages
     *
     * @return string[]
     */
    private static function paging(Semantic $s, string $canonical, array $result): array
    {
        $page = isset($result['page']) ? (int) $result['page'] : 1;
        $pages = isset($result['pages']) ? (int) $result['pages'] : 1;
        if ($pages <= 1) {
            return [];
        }
        $base = $s->mdUrl($canonical);
        $links = [t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])];
        if ($page > 1) {
            $links[] = '[' . t('Previous') . '](' . ($page - 1 > 1 ? $base . '?page=' . ($page - 1) : $base) . ')';
        }
        if ($page < $pages) {
            $links[] = '[' . t('Next') . '](' . $base . '?page=' . ($page + 1) . ')';
        }

        return ['', implode(' · ', $links)];
    }

    // ------------------------------------------------------------------ contenidos

    /**
     * Página o entrada: su título y su contenido.
     *
     * @param array<string,mixed> $d cms->page() o cms->post()
     *
     * @return string[]
     */
    private static function content(Semantic $s, array $d, bool $isPost): array
    {
        $url = isset($d['url_abs']) ? (string) $d['url_abs'] : '';
        $out = ['# ' . self::esc($s, isset($d['title']) ? (string) $d['title'] : ''), ''];
        if ($isPost) {
            $meta = [];
            $date = substr($s->iso(isset($d['published_at']) ? (string) $d['published_at'] : null), 0, 10);
            if ($date !== '') {
                $meta[] = '**' . t('Published') . ':** ' . $date;
            }
            if (isset($d['author']) && trim((string) $d['author']) !== '') {
                $meta[] = '**' . t('Author') . ':** ' . self::esc($s, (string) $d['author']);
            }
            $names = [];
            foreach (isset($d['categories']) && is_array($d['categories']) ? $d['categories'] : [] as $category) {
                if (is_array($category) && isset($category['name'])) {
                    $names[] = self::esc($s, (string) $category['name']);
                }
            }
            if ($names !== []) {
                $meta[] = '**' . t('Category') . ':** ' . implode(', ', $names);
            }
            if ($meta !== []) {
                array_push($out, implode(' · ', $meta), '');
            }
        }
        $out[] = '**' . t('Link') . ':** [' . $url . '](' . $url . ')';
        $body = trim(HtmlToMarkdown::convert(isset($d['html']) ? (string) $d['html'] : ''));
        if ($body !== '') {
            array_push($out, '', $body);
        }

        return $out;
    }

    /**
     * El índice del blog o una de sus categorías: las entradas con su fecha y su extracto.
     *
     * @param array<string,mixed> $d title, description, canonical, posts
     *
     * @return string[]
     */
    private static function posts(Semantic $s, array $d): array
    {
        $canonical = isset($d['canonical']) ? (string) $d['canonical'] : '';
        $out = ['# ' . self::esc($s, isset($d['title']) ? (string) $d['title'] : ''), '', '**' . t('Link') . ':** [' . $canonical . '](' . $canonical . ')'];
        $intro = $s->line(isset($d['description']) ? (string) $d['description'] : '');
        if ($intro !== '') {
            array_push($out, '', self::esc($s, $intro));
        }
        $posts = isset($d['posts']) && is_array($d['posts']) ? $d['posts'] : [];
        array_push($out, '', '## ' . t('Posts'), '');
        foreach (isset($posts['items']) && is_array($posts['items']) ? $posts['items'] : [] as $post) {
            if (!is_array($post) || !isset($post['title'], $post['url_abs'])) {
                continue;
            }
            $date = substr($s->iso(isset($post['published_at']) ? (string) $post['published_at'] : null), 0, 10);
            $excerpt = $s->line(isset($post['excerpt']) ? (string) $post['excerpt'] : '');
            $note = Str::trim($date . ($excerpt !== '' ? ' — ' . self::esc($s, $excerpt) : ''), ' —');
            $out[] = '- [' . self::esc($s, (string) $post['title']) . '](' . $s->mdUrl((string) $post['url_abs']) . ')' . ($note !== '' ? ': ' . $note : '');
        }

        return array_merge($out, self::paging($s, $canonical, $posts));
    }

    // ------------------------------------------------------------------ portada

    /**
     * La portada: qué es la tienda, sus categorías y sus enlaces principales (las mismas secciones que el llms.txt).
     *
     * @param array<string,mixed> $d canonical, sections
     *
     * @return string[]
     */
    private static function home(Semantic $s, array $d): array
    {
        $canonical = isset($d['canonical']) ? (string) $d['canonical'] : '';
        $out = ['# ' . self::esc($s, $s->shop()), '', '> ' . self::esc($s, $s->summary()), '', '**' . t('Link') . ':** [' . $canonical . '](' . $canonical . ')'];

        return array_merge($out, Llms::renderSections(isset($d['sections']) && is_array($d['sections']) ? $d['sections'] : [], $s));
    }
}
