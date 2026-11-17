<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * La auditoría SEO del panel: una tabla de problemas con su número y enlaces para arreglarlos.
 *
 * Cada comprobación es una consulta que cuenta y otra que enseña unos pocos casos (nunca se carga una tabla entera en memoria),
 * y el resultado se guarda 10 minutos en la caché. Se mira el idioma principal de la tienda.
 *
 * Comprobaciones de productos, categorías, páginas y entradas publicados:
 *   - sin meta descripción propia;
 *   - con el título de más de 65 caracteres, o repetido en otro contenido del mismo tipo;
 *   - sin imagen (productos, categorías y entradas);
 *   - con poco texto (productos con menos de 300 caracteres de descripción).
 * Y además: imágenes de la biblioteca sin texto alternativo, redirecciones encadenadas y páginas «noindex» enlazadas en un menú.
 */
final class Audit
{
    /** Segundos que se guarda el resultado. */
    public const TTL = 600;

    /** Casos que se enseñan de cada comprobación. */
    public const SAMPLE = 8;

    /** Largo máximo de un título. */
    public const TITLE_MAX = 65;

    /** Descripción de un producto por debajo de la cual el texto se considera corto. */
    public const SHORT_TEXT = 300;

    /** Redirecciones que se revisan en busca de cadenas. */
    private const REDIRECT_SCAN = 2000;

    /**
     * El resultado, de la caché si está fresco.
     *
     * @return array{checks:array<int,array<string,mixed>>,total:int}
     */
    public static function run(App $app): array
    {
        $result = $app->cache()->remember('seo.audit.' . $app->defaultLang(), self::TTL, static function () use ($app): array {
            return self::compute($app);
        }, ['seo', 'catalog', 'cms']);

        return is_array($result) && isset($result['checks']) ? $result : self::compute($app);
    }

    /**
     * @return array{checks:array<int,array<string,mixed>>,total:int}
     */
    public static function compute(App $app): array
    {
        $checks = [];
        foreach (self::entities($app) as $entity) {
            foreach (self::entityChecks($app, $entity) as $check) {
                $checks[] = $check;
            }
        }
        foreach ([self::mediaAlt($app), self::redirectChains($app), self::noindexInMenus($app)] as $check) {
            if ($check !== null) {
                $checks[] = $check;
            }
        }
        $total = 0;
        foreach ($checks as $check) {
            $total += (int) $check['count'];
        }

        return ['checks' => $checks, 'total' => $total];
    }

    /**
     * El texto de una comprobación en el idioma de quien mira. El resultado guardado en la caché solo lleva el identificador.
     */
    public static function label(string $id): string
    {
        switch ($id) {
            case 'product.meta_description':
                return t('Products without their own meta description');
            case 'product.title_long':
                return t('Products with a title longer than 65 characters');
            case 'product.title_repeated':
                return t('Products with a title that is repeated');
            case 'product.no_image':
                return t('Products without an image');
            case 'product.short_text':
                return t('Products with a very short description');
            case 'category.meta_description':
                return t('Categories without their own meta description');
            case 'category.title_long':
                return t('Categories with a title longer than 65 characters');
            case 'category.title_repeated':
                return t('Categories with a title that is repeated');
            case 'category.no_image':
                return t('Categories without an image');
            case 'page.meta_description':
                return t('Pages without their own meta description');
            case 'page.title_long':
                return t('Pages with a title longer than 65 characters');
            case 'page.title_repeated':
                return t('Pages with a title that is repeated');
            case 'post.meta_description':
                return t('Posts without their own meta description');
            case 'post.title_long':
                return t('Posts with a title longer than 65 characters');
            case 'post.title_repeated':
                return t('Posts with a title that is repeated');
            case 'post.no_image':
                return t('Posts without an image');
            case 'media.alt':
                return t('Images without alternative text');
            case 'redirects.chains':
                return t('Chained redirections');
            case 'menus.noindex':
                return t('Pages hidden from search engines that are linked in a menu');
        }

        return $id;
    }

    // ------------------------------------------------------------------ productos, categorías, páginas y entradas

    /**
     * Cómo mirar cada tipo de contenido. En «from» y «where», %P% es la tabla principal y %X% la de textos.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function entities(App $app): array
    {
        $lang = $app->defaultLang();
        $list = [];
        if ($app->has('catalog')) {
            $list[] = [
                'id' => 'product', 'resource' => 'products',
                'from' => '{products} %P% JOIN {product_i18n} %X% ON %X%.product_id = %P%.id AND %X%.lang = ?',
                'where' => "%P%.status = 'published'", 'params' => [$lang], 'where_params' => [],
                'title_sql' => 'CASE WHEN %X%.meta_title IS NULL OR %X%.meta_title = \'\' THEN %X%.name ELSE %X%.meta_title END',
                'name' => '%X%.name', 'image' => '%P%.main_media_id IS NULL AND NOT EXISTS (SELECT 1 FROM {product_media} pm WHERE pm.product_id = %P%.id)',
                'text_sql' => '%X%.description',
            ];
            $list[] = [
                'id' => 'category', 'resource' => 'categories',
                'from' => '{categories} %P% JOIN {category_i18n} %X% ON %X%.category_id = %P%.id AND %X%.lang = ?',
                'where' => '%P%.active = 1', 'params' => [$lang], 'where_params' => [],
                'title_sql' => 'CASE WHEN %X%.meta_title IS NULL OR %X%.meta_title = \'\' THEN %X%.name ELSE %X%.meta_title END',
                'name' => '%X%.name', 'image' => '%P%.media_id IS NULL', 'text_sql' => '',
            ];
        }
        if ($app->has('cms')) {
            list($visible, $visibleParams) = $app->service('cms')->visibleSql('p');
            $visible = str_replace('p.', '%P%.', $visible);
            $list[] = [
                'id' => 'page', 'resource' => 'pages',
                'from' => '{pages} %P% JOIN {page_i18n} %X% ON %X%.page_id = %P%.id AND %X%.lang = ?',
                'where' => $visible, 'params' => [$lang], 'where_params' => $visibleParams,
                'title_sql' => 'CASE WHEN %X%.meta_title IS NULL OR %X%.meta_title = \'\' THEN %X%.title ELSE %X%.meta_title END',
                'name' => '%X%.title', 'image' => '', 'text_sql' => '',
            ];
            $list[] = [
                'id' => 'post', 'resource' => 'posts',
                'from' => '{posts} %P% JOIN {post_i18n} %X% ON %X%.post_id = %P%.id AND %X%.lang = ?',
                'where' => $visible, 'params' => [$lang], 'where_params' => $visibleParams,
                'title_sql' => 'CASE WHEN %X%.meta_title IS NULL OR %X%.meta_title = \'\' THEN %X%.title ELSE %X%.meta_title END',
                'name' => '%X%.title', 'image' => '%P%.media_id IS NULL', 'text_sql' => '',
            ];
        }

        return $list;
    }

    /**
     * @param array<string,mixed> $e
     *
     * @return array<int,array<string,mixed>>
     */
    private static function entityChecks(App $app, array $e): array
    {
        $length = $app->db()->driver() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $id = (string) $e['id'];
        $titleSql = self::alias((string) $e['title_sql'], 'p', 'x');
        $checks = [];

        $checks[] = self::check($app, $e, $id . '.meta_description', "(x.meta_description IS NULL OR x.meta_description = '')");
        $checks[] = self::check($app, $e, $id . '.title_long', $length . '(' . $titleSql . ') > ' . self::TITLE_MAX);

        // Título repetido: el mismo título efectivo en otro contenido publicado del mismo tipo.
        $inner = self::alias((string) $e['from'], 'q', 'y') . ' WHERE ' . self::alias((string) $e['where'], 'q', 'y');
        $innerTitle = self::alias((string) $e['title_sql'], 'q', 'y');
        $checks[] = self::check($app, $e, $id . '.title_repeated',
            $titleSql . ' IN (SELECT ' . $innerTitle . ' FROM ' . $inner . ' GROUP BY ' . $innerTitle . ' HAVING COUNT(*) > 1)',
            array_merge((array) $e['params'], (array) $e['where_params']));

        if ((string) $e['image'] !== '') {
            $checks[] = self::check($app, $e, $id . '.no_image', self::alias((string) $e['image'], 'p', 'x'));
        }
        if ((string) $e['text_sql'] !== '') {
            $text = self::alias((string) $e['text_sql'], 'p', 'x');
            $checks[] = self::check($app, $e, $id . '.short_text',
                '(' . $text . ' IS NULL OR ' . $length . '(' . $text . ') < ' . self::SHORT_TEXT . ')');
        }

        return $checks;
    }

    /**
     * Una comprobación: cuántos casos cumplen la condición y unos pocos de ejemplo con su enlace.
     *
     * @param array<string,mixed> $e
     * @param array<int,mixed>    $extraParams parámetros de la propia condición
     *
     * @return array<string,mixed>
     */
    private static function check(App $app, array $e, string $id, string $condition, array $extraParams = []): array
    {
        $db = $app->db();
        $from = self::alias((string) $e['from'], 'p', 'x');
        $where = self::alias((string) $e['where'], 'p', 'x') . ' AND (' . $condition . ')';
        $params = array_merge((array) $e['params'], (array) $e['where_params'], $extraParams);
        $count = (int) $db->value('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params);

        $items = [];
        if ($count > 0) {
            $nameSql = self::alias((string) $e['name'], 'p', 'x');
            foreach ($db->select('SELECT p.id AS id, ' . $nameSql . ' AS label FROM ' . $from . ' WHERE ' . $where . ' ORDER BY p.id LIMIT ' . self::SAMPLE, $params) as $row) {
                $items[] = [
                    'label' => (string) ($row['label'] !== null && (string) $row['label'] !== '' ? $row['label'] : '#' . $row['id']),
                    'url' => Admin::resourceUrl($app, (string) $e['resource'], (int) $row['id']),
                ];
            }
        }

        return ['id' => $id, 'count' => $count, 'items' => $items, 'url' => Admin::resourceUrl($app, (string) $e['resource'])];
    }

    /** Pone los alias de verdad en una pieza de SQL: %P% (tabla principal) y %X% (tabla de textos). */
    private static function alias(string $sql, string $main, string $texts): string
    {
        return str_replace(['%P%', '%X%'], [$main, $texts], $sql);
    }

    // ------------------------------------------------------------------ las demás

    /**
     * @return array<string,mixed>|null null si no hay biblioteca de medios
     */
    private static function mediaAlt(App $app): ?array
    {
        if (!$app->has('media')) {
            return null;
        }
        $db = $app->db();
        $where = "kind = 'image' AND (alt IS NULL OR alt = '')";
        $count = (int) $db->value('SELECT COUNT(*) FROM {media} WHERE ' . $where);
        $items = [];
        if ($count > 0) {
            foreach ($db->select('SELECT id, filename FROM {media} WHERE ' . $where . ' ORDER BY id LIMIT ' . self::SAMPLE) as $row) {
                $items[] = ['label' => (string) $row['filename'], 'url' => $app->url()->plain('/admin/media')];
            }
        }

        return ['id' => 'media.alt', 'count' => $count, 'items' => $items, 'url' => $app->url()->plain('/admin/media')];
    }

    /**
     * Redirecciones cuyo destino es el origen de otra redirección activa: el visitante da dos saltos (o más). Se revisan por
     * tandas las primeras REDIRECT_SCAN, de una en una: no se carga la tabla.
     *
     * @return array<string,mixed>
     */
    private static function redirectChains(App $app): array
    {
        $db = $app->db();
        $count = 0;
        $items = [];
        $lastId = 0;
        $seen = 0;
        while ($seen < self::REDIRECT_SCAN) {
            $rows = $db->select('SELECT id, source, target FROM {redirects} WHERE active = 1 AND id > ? ORDER BY id LIMIT 200', [$lastId]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                ++$seen;
                $path = parse_url((string) $row['target'], PHP_URL_PATH);
                $next = trim(is_string($path) ? $path : '', '/');
                if ($next === '' || $next === trim((string) $row['source'], '/')) {
                    continue;
                }
                if ((int) $db->value('SELECT COUNT(*) FROM {redirects} WHERE active = 1 AND source = ?', [$next]) > 0) {
                    ++$count;
                    if (count($items) < self::SAMPLE) {
                        $shown = '/' . $row['source'] . ' → ' . $row['target'];
                        $items[] = ['label' => $shown, 'url' => Admin::resourceUrl($app, 'redirects', $lastId)];
                    }
                }
            }
        }

        return ['id' => 'redirects.chains', 'count' => $count, 'items' => $items, 'url' => Admin::resourceUrl($app, 'redirects')];
    }

    /**
     * Páginas y entradas marcadas «no indexar» que tienen un enlace en un menú: se pide a los buscadores que no las muestren
     * y a la vez se las enseña a todo el mundo.
     *
     * @return array<string,mixed>|null null sin contenidos
     */
    private static function noindexInMenus(App $app): ?array
    {
        if (!$app->has('cms')) {
            return null;
        }
        $db = $app->db();
        $lang = $app->defaultLang();
        $count = 0;
        $items = [];
        $queries = [
            ['pages', 'page', 'SELECT COUNT(DISTINCT p.id) FROM {pages} p JOIN {menu_items} m ON m.type = ? AND m.ref = p.id WHERE p.noindex = 1',
                'SELECT DISTINCT p.id AS id, x.title AS label FROM {pages} p JOIN {menu_items} m ON m.type = ? AND m.ref = p.id LEFT JOIN {page_i18n} x ON x.page_id = p.id AND x.lang = ? WHERE p.noindex = 1 ORDER BY p.id LIMIT ' . self::SAMPLE],
            ['posts', 'post', 'SELECT COUNT(DISTINCT p.id) FROM {posts} p JOIN {menu_items} m ON m.type = ? AND m.ref = p.id WHERE p.noindex = 1',
                'SELECT DISTINCT p.id AS id, x.title AS label FROM {posts} p JOIN {menu_items} m ON m.type = ? AND m.ref = p.id LEFT JOIN {post_i18n} x ON x.post_id = p.id AND x.lang = ? WHERE p.noindex = 1 ORDER BY p.id LIMIT ' . self::SAMPLE],
        ];
        foreach ($queries as $query) {
            $n = (int) $db->value($query[2], [$query[1]]);
            $count += $n;
            if ($n > 0) {
                foreach ($db->select($query[3], [$query[1], $lang]) as $row) {
                    if (count($items) < self::SAMPLE) {
                        $items[] = ['label' => (string) ($row['label'] !== null && (string) $row['label'] !== '' ? $row['label'] : '#' . $row['id']), 'url' => Admin::resourceUrl($app, $query[0], (int) $row['id'])];
                    }
                }
            }
        }

        return ['id' => 'menus.noindex', 'count' => $count, 'items' => $items, 'url' => $app->url()->plain('/admin/cms/menus')];
    }
}
