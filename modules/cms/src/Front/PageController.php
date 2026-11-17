<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Cms\Cms;
use Aifec\Text\HtmlSanitizer;

/**
 * Lo que ve el público de los contenidos: páginas, portada del blog, entradas, categorías y etiquetas, y el canal de novedades.
 *
 * Todo sale de los métodos del servicio «cms» (page, post, posts, term): aquí solo se compone la página de la tienda. Un borrador
 * o un contenido programado para más tarde no existe para el público (404). Las respuestas se guardan en la caché de página con
 * la etiqueta de su contenido y «cms»; las vacía el panel al guardar.
 */
final class PageController
{
    /** Segundos que se guarda una página en la caché de página. */
    private const TTL = 600;

    /** Entradas del canal de novedades. */
    private const FEED_ITEMS = 20;

    /** Entradas relacionadas al final de una entrada. */
    private const RELATED = 3;

    /** Niveles de páginas madre que se cuentan en las migas (con tope por si hay un ciclo). */
    private const MAX_DEPTH = 6;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function cms(): Cms
    {
        /** @var Cms $cms */
        $cms = $this->app->service('cms');

        return $cms;
    }

    // ------------------------------------------------------------------ páginas

    /**
     * @param array<string,mixed> $params id
     */
    public function page(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $cms = $this->cms();
        // Primero la propia página: si lleva un formulario con errores guardados, los lee ella y no una madre.
        $content = $cms->page($id);
        if ($content === null) {
            throw HttpError::notFound();
        }
        $template = isset($content['template']) ? (string) $content['template'] : 'default';

        $trail = [];
        $seen = [$id => true];
        $parentId = isset($content['parent_id']) ? $content['parent_id'] : null;
        for ($depth = 0; $parentId !== null && $depth < self::MAX_DEPTH && !isset($seen[(int) $parentId]); ++$depth) {
            $seen[(int) $parentId] = true;
            $parent = $cms->page((int) $parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($trail, ['name' => (string) $parent['title'], 'url' => (string) $parent['url']]);
            $parentId = isset($parent['parent_id']) ? $parent['parent_id'] : null;
        }
        $crumbs = Support::crumbs($this->app, array_merge($trail, [['name' => (string) $content['title'], 'url' => '']]));

        $meta = [
            'type' => 'page',
            'entity' => ['type' => 'page', 'id' => $id],
            'title' => (string) $content['seo']['title'],
            'description' => (string) $content['seo']['description'],
            // La página que hace de portada se ve también en «/»: la buena es la portada, no su dirección propia (/inicio).
            'canonical' => !empty($content['is_home']) ? $this->app->url()->abs('/') : (string) $content['url_abs'],
            'robots' => !empty($content['seo']['noindex']) ? 'noindex, follow' : '',
            'breadcrumbs' => $crumbs,
            'body_class' => 'af-page-' . preg_replace('/[^a-z0-9_-]/', '', $template),
        ];
        $tags = array_merge(['page:' . $id, 'cms'], Support::dependencyTags(is_array($content['blocks']) ? $content['blocks'] : []));

        return $this->app->render('cms::front/page', [
            'page' => $meta,
            'content' => $content,
            'crumbs' => $crumbs,
            'template' => $template,
            'script' => Support::formScript($this->app, (string) $content['html']),
        ])->cacheable(self::TTL, $tags);
    }

    // ------------------------------------------------------------------ blog

    /**
     * @param array<string,mixed> $params
     */
    public function blog(Request $request, array $params): Response
    {
        $cms = $this->cms();
        $base = $this->app->url()->route('blog');
        $result = $this->listing($request, ['page' => $request->int('page', 1)]);
        $shop = $this->app->settings()->str('store.name', 'AIFEC');
        $title = t('Blog');
        $meta = [
            'type' => 'blog',
            'title' => $result['page'] > 1 ? $title . ' · ' . t('Page') . ' ' . $result['page'] : $title,
            'description' => t('News and articles from {shop}', ['shop' => $shop]),
            'canonical' => $this->app->url()->origin() . $this->pageUrl($base, $result['page']),
            'breadcrumbs' => Support::crumbs($this->app, [['name' => $title, 'url' => '']]),
        ];

        return $this->app->render('cms::front/blog', [
            'page' => $meta,
            'crumbs' => $meta['breadcrumbs'],
            'heading' => $title,
            'result' => $result,
            'base' => $base,
            'categories' => $cms->terms('category'),
            'feed' => $this->app->url()->route('blog.feed'),
        ])->cacheable(self::TTL, ['cms']);
    }

    /**
     * Las entradas de una categoría o de una etiqueta.
     *
     * @param array<string,mixed> $params id
     */
    public function term(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $cms = $this->cms();
        $term = $cms->term($id);
        if ($term === null) {
            throw HttpError::notFound();
        }
        $base = (string) $term['url'];
        $result = $this->listing($request, ['page' => $request->int('page', 1), 'term_id' => $id]);
        $name = (string) $term['name'];
        $description = trim((string) $term['description']);
        $blog = $this->app->url()->route('blog');
        $meta = [
            'type' => 'blog',
            'entity' => ['type' => 'term', 'id' => $id],
            'title' => $result['page'] > 1 ? $name . ' · ' . t('Page') . ' ' . $result['page'] : $name,
            'description' => $description !== '' ? $description : t('Posts filed under {name}', ['name' => $name]),
            'canonical' => $this->app->url()->origin() . $this->pageUrl($base, $result['page']),
            'breadcrumbs' => Support::crumbs($this->app, [['name' => t('Blog'), 'url' => $blog], ['name' => $name, 'url' => '']]),
        ];

        return $this->app->render('cms::front/term', [
            'page' => $meta,
            'crumbs' => $meta['breadcrumbs'],
            'heading' => $name,
            'intro' => $description,
            'result' => $result,
            'base' => $base,
        ])->cacheable(self::TTL, ['cms']);
    }

    // ------------------------------------------------------------------ entradas

    /**
     * @param array<string,mixed> $params id
     */
    public function post(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $cms = $this->cms();
        $post = $cms->post($id);
        if ($post === null) {
            throw HttpError::notFound();
        }
        $categories = is_array($post['categories']) ? $post['categories'] : [];
        $tail = [['name' => t('Blog'), 'url' => $this->app->url()->route('blog')]];
        if ($categories !== []) {
            $tail[] = ['name' => (string) $categories[0]['name'], 'url' => (string) $categories[0]['url']];
        }
        $tail[] = ['name' => (string) $post['title'], 'url' => ''];
        $crumbs = Support::crumbs($this->app, $tail);

        // La imagen que se ve al llegar no se difiere: la destacada si la hay y, si no, la primera del propio contenido.
        $html = (string) $post['html'];
        $featured = !empty($post['media_id']) && is_array($post['image']) ? $post['image'] : null;
        if ($featured === null) {
            $html = Support::eagerFirstImage($html);
        }

        $meta = [
            'type' => 'post',
            'entity' => ['type' => 'post', 'id' => $id],
            'title' => (string) $post['seo']['title'],
            'description' => (string) $post['seo']['description'],
            'canonical' => (string) $post['url_abs'],
            'robots' => !empty($post['seo']['noindex']) ? 'noindex, follow' : '',
            'breadcrumbs' => $crumbs,
            'image' => is_array($post['image']) && !empty($post['image']['url']) ? Support::absolute($this->app, (string) $post['image']['url']) : '',
        ];
        $tags = array_merge(['post:' . $id, 'cms'], Support::dependencyTags(is_array($post['blocks']) ? $post['blocks'] : []));

        return $this->app->render('cms::front/post', [
            'page' => $meta,
            'post' => $post,
            'html' => $html,
            'featured' => $featured,
            'crumbs' => $crumbs,
            'date' => Support::date($this->app, isset($post['published_at']) ? (string) $post['published_at'] : null),
            'previous' => $this->neighbour($post, true),
            'next' => $this->neighbour($post, false),
            'related' => $this->related($post),
            'script' => Support::formScript($this->app, $html),
        ])->cacheable(self::TTL, $tags);
    }

    // ------------------------------------------------------------------ canal de novedades

    /**
     * Canal Atom con las últimas entradas y su contenido completo saneado.
     *
     * @param array<string,mixed> $params
     */
    public function feed(Request $request, array $params): Response
    {
        $url = $this->app->url();
        $shop = $this->app->settings()->str('store.name', 'AIFEC');
        $result = $this->cms()->posts(['per_page' => self::FEED_ITEMS, 'page' => 1]);
        $self = $url->origin() . $url->route('blog.feed');
        $home = $url->origin() . $url->route('blog');

        $updated = 0;
        $entries = '';
        foreach ($result['items'] as $post) {
            $published = isset($post['published_at']) && $post['published_at'] !== null ? Clock::parse((string) $post['published_at']) : $this->app->clock()->now();
            $modified = isset($post['updated_at']) && (string) $post['updated_at'] !== '' ? Clock::parse((string) $post['updated_at']) : $published;
            $updated = max($updated, $modified, $published);
            $entries .= "  <entry>\n"
                . '    <title type="text">' . self::xml((string) $post['title']) . "</title>\n"
                . '    <id>' . self::xml((string) $post['url_abs']) . "</id>\n"
                . '    <link rel="alternate" type="text/html" href="' . self::xml((string) $post['url_abs']) . "\"/>\n"
                . '    <published>' . gmdate('Y-m-d\TH:i:s\Z', $published) . "</published>\n"
                . '    <updated>' . gmdate('Y-m-d\TH:i:s\Z', max($modified, $published)) . "</updated>\n"
                . '    <author><name>' . self::xml($post['author'] !== '' ? (string) $post['author'] : $shop) . "</name></author>\n";
            foreach (is_array($post['categories']) ? $post['categories'] : [] as $category) {
                $entries .= '    <category term="' . self::xml((string) $category['name']) . "\"/>\n";
            }
            if ((string) $post['excerpt'] !== '') {
                $entries .= '    <summary type="text">' . self::xml((string) $post['excerpt']) . "</summary>\n";
            }
            $entries .= '    <content type="html">' . self::xml($this->feedHtml((string) $post['html'])) . "</content>\n  </entry>\n";
        }
        if ($updated === 0) {
            $updated = $this->app->clock()->now();
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="' . self::xml($this->app->lang()) . "\">\n"
            . '  <title>' . self::xml($shop . ' · ' . t('Blog')) . "</title>\n"
            . '  <id>' . self::xml($home) . "</id>\n"
            . '  <link rel="self" type="application/atom+xml" href="' . self::xml($self) . "\"/>\n"
            . '  <link rel="alternate" type="text/html" href="' . self::xml($home) . "\"/>\n"
            . '  <updated>' . gmdate('Y-m-d\TH:i:s\Z', $updated) . "</updated>\n"
            . $entries . "</feed>\n";

        return (new Response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']))->cacheable(self::TTL, ['cms']);
    }

    // ------------------------------------------------------------------ interno

    /**
     * Las entradas de una página del listado. Una página que no existe (más allá de la última) es un 404, no una copia de la última.
     *
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    private function listing(Request $request, array $query): array
    {
        $query['page'] = max(1, (int) $query['page']);
        $result = $this->cms()->posts($query);
        if ($query['page'] > $result['pages']) {
            throw HttpError::notFound();
        }

        return $result;
    }

    /** La dirección de una página del listado: la primera va sin parámetro, para que tenga una sola dirección. */
    private function pageUrl(string $base, int $page): string
    {
        return $base . ($page > 1 ? (strpos($base, '?') === false ? '?' : '&') . 'page=' . $page : '');
    }

    /**
     * La entrada anterior (más antigua) o la siguiente (más reciente) a la dada, las dos entre lo que se ve ahora.
     *
     * @param array<string,mixed> $post
     *
     * @return array<string,mixed>|null
     */
    private function neighbour(array $post, bool $older): ?array
    {
        if (!isset($post['published_at']) || $post['published_at'] === null) {
            return null;
        }
        list($visible, $params) = $this->cms()->visibleSql('p');
        $when = (string) $post['published_at'];
        $id = (int) $post['id'];
        $params[] = $when;
        $params[] = $when;
        $params[] = $id;
        $sql = 'SELECT p.id FROM {posts} p WHERE ' . $visible . ' AND (p.published_at ' . ($older ? '<' : '>') . ' ? OR (p.published_at = ? AND p.id ' . ($older ? '<' : '>') . ' ?)) '
            . 'ORDER BY p.published_at ' . ($older ? 'DESC' : 'ASC') . ', p.id ' . ($older ? 'DESC' : 'ASC') . ' LIMIT 1';
        $found = $this->app->db()->value($sql, $params);

        return $found !== null ? $this->cms()->post((int) $found) : null;
    }

    /**
     * Entradas de la misma categoría que la dada (la primera que tenga), sin ella misma.
     *
     * @param array<string,mixed> $post
     *
     * @return array<int,array<string,mixed>>
     */
    private function related(array $post): array
    {
        $categories = is_array($post['categories']) ? $post['categories'] : [];
        if ($categories === []) {
            return [];
        }
        $result = $this->cms()->posts(['term_id' => (int) $categories[0]['id'], 'per_page' => self::RELATED + 1, 'page' => 1]);
        $out = [];
        foreach ($result['items'] as $item) {
            if ((int) $item['id'] !== (int) $post['id'] && count($out) < self::RELATED) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * El contenido de una entrada para un lector de canales: saneado una vez más y con las direcciones completas.
     */
    private function feedHtml(string $html): string
    {
        $clean = HtmlSanitizer::clean($html, 'content');
        $clean = (string) preg_replace('/\ssrcset="[^"]*"/i', '', $clean);
        $origin = $this->app->url()->origin();

        return (string) preg_replace_callback('#\b(href|src)="/(?!/)#i', static function (array $m) use ($origin): string {
            return $m[1] . '="' . $origin . '/';
        }, $clean);
    }

    /** Texto seguro para XML: escapado y sin los caracteres de control que XML no admite. */
    private static function xml(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
