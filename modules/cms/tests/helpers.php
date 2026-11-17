<?php
/**
 * AIFEC — ayudas de las pruebas del módulo de contenidos. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   cmsApp(array $modules, array $settings, bool $dosIdiomas): App   sitio de prueba con «cms» (español e inglés), arrancado
 *   cmsRole(App, código, permisos): void                              un papel nuevo con esos permisos
 *   cmsBlocks(string $html): string                                   JSON de un bloque de párrafo (lo que viaja en f[blocks][es])
 *   cmsSavePage(TestClient, array $f, ?int $id): Response             guarda una página por el panel, como el navegador
 *   cmsSavePost(TestClient, array $f, ?int $id): Response             lo mismo con una entrada
 *   cmsLastId(App, tabla): int                                        el último identificador de una tabla
 *   cmsInsertPage / cmsInsertPost(App, datos): int                    alta directa en la base, con su dirección (para montar muchas)
 *   cmsTerm(App, nombre, taxonomía): int                              una categoría o etiqueta con su dirección
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Util\Str;

require_once __DIR__ . '/../../catalog/tests/helpers.php';

if (!function_exists('cmsApp')) {
    /**
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     */
    function cmsApp(array $modules = ['cms'], array $settings = [], bool $twoLangs = true): App
    {
        $settings = ($twoLangs ? ['i18n.languages' => ['es', 'en']] : []) + $settings;
        $options = ['modules' => $modules];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /**
     * @param string[] $caps
     */
    function cmsRole(App $app, string $code, array $caps): void
    {
        $app->db()->insert('roles', ['code' => $code, 'name' => ucfirst($code), 'caps' => json_encode($caps), 'system' => 0]);
    }

    function cmsBlocks(string $html): string
    {
        return (string) json_encode([['type' => 'paragraph', 'attrs' => ['html' => $html]]], JSON_UNESCAPED_UNICODE);
    }

    function cmsLastId(App $app, string $table): int
    {
        return (int) $app->db()->value('SELECT MAX(id) FROM {' . $table . '}');
    }

    /**
     * El cuerpo de un envío del formulario de una página, con lo mínimo que pide (lo demás se pisa con $f).
     *
     * @param array<string,mixed> $f
     *
     * @return array<string,mixed>
     */
    function cmsPageFields(array $f): array
    {
        return $f + [
            'title' => ['es' => 'Quiénes somos', 'en' => ''],
            'blocks' => ['es' => cmsBlocks('Somos una tienda pequeña.'), 'en' => ''],
            'status' => 'draft', 'template' => 'default', 'position' => '0', 'is_home' => '0', 'noindex' => '0',
        ];
    }

    /**
     * @param array<string,mixed> $f
     */
    function cmsSavePage(TestClient $web, array $f, ?int $id = null): Response
    {
        $web->get('/admin/r/pages/' . ($id !== null ? (string) $id : 'new'));

        return $web->post('/admin/r/pages/' . ($id !== null ? $id . '/save' : 'save'), ['_token' => aifecToken($web), 'f' => cmsPageFields($f)]);
    }

    /**
     * @param array<string,mixed> $f
     *
     * @return array<string,mixed>
     */
    function cmsPostFields(array $f): array
    {
        return $f + [
            'title' => ['es' => 'Cómo cuidar la madera', 'en' => ''],
            'blocks' => ['es' => cmsBlocks('Un poco de aceite cada año.'), 'en' => ''],
            'status' => 'draft', 'noindex' => '0',
        ];
    }

    /**
     * @param array<string,mixed> $f
     */
    function cmsSavePost(TestClient $web, array $f, ?int $id = null): Response
    {
        $web->get('/admin/r/posts/' . ($id !== null ? (string) $id : 'new'));

        return $web->post('/admin/r/posts/' . ($id !== null ? $id . '/save' : 'save'), ['_token' => aifecToken($web), 'f' => cmsPostFields($f)]);
    }

    /**
     * Alta directa de una página (sin pasar por el panel): para montar muchas. $d: title (es), en (título en inglés), status,
     * published_at, blocks (lista), template, parent_id, is_home, slug.
     *
     * @param array<string,mixed> $d
     */
    function cmsInsertPage(App $app, array $d = []): int
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $id = $db->insert('pages', [
            'status' => isset($d['status']) ? $d['status'] : 'published', 'template' => isset($d['template']) ? $d['template'] : 'default',
            'parent_id' => isset($d['parent_id']) ? $d['parent_id'] : null, 'position' => 0, 'noindex' => 0, 'is_home' => isset($d['is_home']) ? (int) $d['is_home'] : 0,
            'published_at' => array_key_exists('published_at', $d) ? $d['published_at'] : $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $title = isset($d['title']) ? (string) $d['title'] : 'Página ' . $id;
        $blocks = isset($d['blocks']) ? $d['blocks'] : [['type' => 'paragraph', 'attrs' => ['html' => 'Texto de ' . $title]]];
        $db->insert('page_i18n', ['page_id' => $id, 'lang' => 'es', 'title' => $title, 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE)]);
        $slug = isset($d['slug']) ? (string) $d['slug'] : Str::slug($title, 90);
        $app->slugs()->set('page', $id, 'es', $slug);
        if (isset($d['en'])) {
            $db->insert('page_i18n', ['page_id' => $id, 'lang' => 'en', 'title' => (string) $d['en'], 'blocks' => '']);
            $app->slugs()->set('page', $id, 'en', Str::slug((string) $d['en'], 90));
        }
        $app->invalidate(['cms', 'page:' . $id]);

        return $id;
    }

    /**
     * Alta directa de una entrada. $d: title, status, published_at, blocks, terms (ids), media_id, author_id, en, slug.
     *
     * @param array<string,mixed> $d
     */
    function cmsInsertPost(App $app, array $d = []): int
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $id = $db->insert('posts', [
            'status' => isset($d['status']) ? $d['status'] : 'published', 'media_id' => isset($d['media_id']) ? $d['media_id'] : null,
            'author_id' => isset($d['author_id']) ? $d['author_id'] : null, 'noindex' => 0,
            'published_at' => array_key_exists('published_at', $d) ? $d['published_at'] : $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $title = isset($d['title']) ? (string) $d['title'] : 'Entrada ' . $id;
        $blocks = isset($d['blocks']) ? $d['blocks'] : [['type' => 'paragraph', 'attrs' => ['html' => 'Texto de ' . $title]]];
        $db->insert('post_i18n', ['post_id' => $id, 'lang' => 'es', 'title' => $title, 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'excerpt' => isset($d['excerpt']) ? $d['excerpt'] : '']);
        $slug = isset($d['slug']) ? (string) $d['slug'] : Str::slug($title, 90);
        $app->slugs()->set('post', $id, 'es', 'blog/' . $slug);
        if (isset($d['en'])) {
            $db->insert('post_i18n', ['post_id' => $id, 'lang' => 'en', 'title' => (string) $d['en'], 'blocks' => '']);
            $app->slugs()->set('post', $id, 'en', 'blog/' . Str::slug((string) $d['en'], 90));
        }
        foreach (isset($d['terms']) && is_array($d['terms']) ? $d['terms'] : [] as $termId) {
            $db->insert('post_terms', ['post_id' => $id, 'term_id' => (int) $termId]);
        }
        $app->invalidate(['cms', 'post:' . $id]);

        return $id;
    }

    /**
     * Una categoría o una etiqueta del blog con su nombre en español (y en inglés si se da) y su dirección.
     */
    function cmsTerm(App $app, string $name, string $taxonomy = 'category', ?string $en = null): int
    {
        $db = $app->db();
        $id = $db->insert('terms', ['taxonomy' => $taxonomy, 'position' => 0]);
        $db->insert('term_i18n', ['term_id' => $id, 'lang' => 'es', 'name' => $name]);
        $prefix = $taxonomy === 'tag' ? 'blog/etiqueta/' : 'blog/categoria/';
        $app->slugs()->set('term', $id, 'es', $prefix . Str::slug($name, 90));
        if ($en !== null) {
            $db->insert('term_i18n', ['term_id' => $id, 'lang' => 'en', 'name' => $en]);
            $app->slugs()->set('term', $id, 'en', $prefix . Str::slug($en, 90));
        }
        $app->invalidate(['cms']);

        return $id;
    }

    /** Dirección de un entorno de prueba de la tienda: una página de la tienda que solo dice «página N» (el módulo de la tienda aún no está). */
    function cmsFakeFront(App $app): void
    {
        foreach (['page', 'post', 'term'] as $type) {
            $app->entity($type, static function (\Aifec\Kernel\Request $request, App $app, array $params): Response {
                return Response::html('<p>' . $params['type'] . ' ' . $params['id'] . '</p>');
            });
        }
    }
}
