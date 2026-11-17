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

use Aifec\Kernel\App;

/**
 * El filtro «page.meta»: plantillas de título y de descripción, idiomas alternativos (hreflang) y reglas de robots.
 *
 * Los controladores de la tienda ya pusieron type, entity, title y description; aquí solo se completa lo que falta. Una
 * plantilla nunca pisa un meta título o una meta descripción que el comerciante escribió a mano.
 */
final class Meta
{
    /**
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    public static function apply(array $page, App $app): array
    {
        $page = self::templates($page, $app);
        $page = self::alternates($page, $app);
        $page = self::demo($page, $app);

        return self::robots($page, $app);
    }

    /**
     * Si la tienda está con la tienda oculta y «seo.index_products», las fichas de producto sí se indexan (nunca en un sitio
     * de demostración). Lo usan también la página (Page) y el robots.txt.
     *
     * @param array<string,mixed> $page
     */
    public static function openProduct(App $app, array $page): bool
    {
        return isset($page['type']) && $page['type'] === 'product' && !$app->site()->isDemo()
            && $app->settings()->bool('seo.index_products', false);
    }

    /**
     * «seo.demo_notice»: «demo» al final del título y la descripción empezando por que es una tienda de demostración, para
     * que ni las personas ni los buscadores la tomen por una tienda de verdad (17/11/2026).
     *
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    private static function demo(array $page, App $app): array
    {
        if (!$app->settings()->bool('seo.demo_notice', false)) {
            return $page;
        }
        $suffix = ' · ' . t('demo');
        $full = isset($page['full_title']) ? (string) $page['full_title'] : (isset($page['title']) ? (string) $page['title'] : '');
        if ($full !== '' && substr($full, -strlen($suffix)) !== $suffix) {
            $page['full_title'] = $full . $suffix;
        }
        $note = t('Demo shop with fictional products and brands.');
        $description = isset($page['description']) ? trim((string) $page['description']) : '';
        if (strpos($description, $note) !== 0) {
            $page['description'] = Templates::cut(trim($note . ' ' . $description), Templates::DESCRIPTION_MAX, '…');
        }

        return $page;
    }

    // ------------------------------------------------------------------ títulos y descripciones

    /**
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    private static function templates(array $page, App $app): array
    {
        $type = isset($page['type']) ? (string) $page['type'] : '';
        if (!in_array($type, Templates::TYPES, true)) {
            return $page;
        }
        $settings = $app->settings();
        $titleTemplate = trim($settings->str('seo.title.' . $type));
        $descriptionTemplate = trim($settings->str('seo.description.' . $type));
        if ($titleTemplate === '' && $descriptionTemplate === '') {
            return $page;
        }

        $own = self::ownMeta($app, $page);
        $wantsTitle = $titleTemplate !== '' && !$own['title'];
        $wantsDescription = $descriptionTemplate !== '' && !$own['description'];
        if (!$wantsTitle && !$wantsDescription) {
            return $page;
        }

        // Las variables se calculan una vez y con el título original: cambiar el título no cambia {name} de la descripción.
        $vars = self::vars($app, $page, ($wantsTitle ? $titleTemplate : '') . ' ' . ($wantsDescription ? $descriptionTemplate : ''));
        if ($wantsTitle) {
            $title = Templates::cut(Templates::render($titleTemplate, $vars), Templates::TITLE_MAX);
            if ($title !== '') {
                $page['title'] = $title;
                $page['full_title'] = $title;
                $page['title_full'] = true;
            }
        }
        if ($wantsDescription) {
            $description = Templates::cut(Templates::render($descriptionTemplate, $vars), Templates::DESCRIPTION_MAX, '…');
            if ($description !== '') {
                $page['description'] = $description;
            }
        }

        return $page;
    }

    /**
     * ¿La entidad trae su propio meta título y su propia meta descripción? Se mira en su tabla de textos, porque el título que
     * llega en $page ya viene con el nombre como respaldo y no se distingue de uno escrito a mano.
     *
     * @param array<string,mixed> $page
     *
     * @return array{title:bool,description:bool}
     */
    private static function ownMeta(App $app, array $page): array
    {
        $none = ['title' => false, 'description' => false];
        if (isset($page['type']) && $page['type'] === 'home') {
            // La portada ya lee su título y su descripción de estos dos ajustes (HomeController): si están, son los del comerciante.
            $settings = $app->settings();

            return ['title' => $settings->str('seo.home_title') !== '', 'description' => $settings->str('seo.home_description') !== ''];
        }
        $entity = isset($page['entity']) && is_array($page['entity']) ? $page['entity'] : null;
        if ($entity === null || empty($entity['id']) || !isset($entity['type'])) {
            return $none;
        }
        switch ((string) $entity['type']) {
            case 'product':
                $sql = 'SELECT meta_title, meta_description FROM {product_i18n} WHERE product_id = ? AND lang = ?';
                $module = 'catalog';
                break;
            case 'category':
                $sql = 'SELECT meta_title, meta_description FROM {category_i18n} WHERE category_id = ? AND lang = ?';
                $module = 'catalog';
                break;
            case 'page':
                $sql = 'SELECT meta_title, meta_description FROM {page_i18n} WHERE page_id = ? AND lang = ?';
                $module = 'cms';
                break;
            case 'post':
                $sql = 'SELECT meta_title, meta_description FROM {post_i18n} WHERE post_id = ? AND lang = ?';
                $module = 'cms';
                break;
            default:
                return $none;
        }
        if (!$app->has($module)) {
            return $none;
        }
        $db = $app->db();
        $lang = isset($page['lang']) ? (string) $page['lang'] : $app->lang();
        $row = $db->row($sql, [(int) $entity['id'], $lang]);
        if ($row === null && $lang !== $app->defaultLang()) {
            $row = $db->row($sql, [(int) $entity['id'], $app->defaultLang()]);
        }
        if ($row === null) {
            return $none;
        }

        return [
            'title' => trim((string) $row['meta_title']) !== '',
            'description' => trim((string) $row['meta_description']) !== '',
        ];
    }

    /**
     * Las variables de la plantilla. Primero las que da el controlador en $page['vars']; las que faltan se sacan del
     * catálogo o de los contenidos solo si la plantilla las usa. Una que no se puede sacar queda vacía.
     *
     * @param array<string,mixed> $page
     *
     * @return array<string,string>
     */
    private static function vars(App $app, array $page, string $template): array
    {
        $given = isset($page['vars']) && is_array($page['vars']) ? $page['vars'] : [];
        $vars = [];
        foreach ($given as $key => $value) {
            if (is_scalar($value)) {
                $vars[(string) $key] = (string) $value;
            }
        }
        if (!isset($vars['name'])) {
            $vars['name'] = isset($page['title']) ? (string) $page['title'] : '';
        }
        if (!isset($vars['shop'])) {
            $vars['shop'] = isset($page['shop']) ? (string) $page['shop'] : $app->settings()->str('store.name', 'AIFEC');
        }
        if (!isset($vars['page'])) {
            $number = $app->request()->int('page', 1);
            $vars['page'] = $number > 1 ? t('Page {n}', ['n' => $number]) : '';
        }

        $missing = [];
        foreach (['category', 'brand', 'price'] as $key) {
            if (!isset($vars[$key]) && strpos($template, '{' . $key . '}') !== false) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            $entity = isset($page['entity']) && is_array($page['entity']) ? $page['entity'] : [];
            $type = isset($entity['type']) ? (string) $entity['type'] : '';
            $id = isset($entity['id']) ? (int) $entity['id'] : 0;
            $derived = $id > 0 ? self::derive($app, $type, $id) : [];
            foreach ($missing as $key) {
                $vars[$key] = isset($derived[$key]) ? (string) $derived[$key] : '';
            }
        }

        return $vars;
    }

    /**
     * @return array<string,string>
     */
    private static function derive(App $app, string $type, int $id): array
    {
        if ($type === 'product' && $app->has('catalog')) {
            $product = $app->service('catalog')->product($id);
            if (!is_array($product)) {
                return [];
            }
            $out = [];
            if (isset($product['main_category']['name'])) {
                $out['category'] = (string) $product['main_category']['name'];
            }
            if (isset($product['brand']['name'])) {
                $out['brand'] = (string) $product['brand']['name'];
            }
            if (isset($product['price']['amount'])) {
                $currency = isset($product['price']['currency']) ? (string) $product['price']['currency'] : $app->settings()->str('shop.currency', 'EUR');
                $out['price'] = $app->i18n()->money((int) $product['price']['amount'], $currency);
            }

            return $out;
        }
        if ($type === 'post' && $app->has('cms')) {
            $post = $app->service('cms')->post($id);
            if (is_array($post) && isset($post['categories'][0]['name'])) {
                return ['category' => (string) $post['categories'][0]['name']];
            }
        }

        return [];
    }

    // ------------------------------------------------------------------ idiomas alternativos

    /**
     * Un idioma por cada dirección amigable que exista de la entidad, más «x-default» (el idioma principal). La portada, todos
     * los idiomas. Si la página ya trae sus alternativas, o solo hay una versión, no se toca.
     *
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    private static function alternates(array $page, App $app): array
    {
        if (!empty($page['alternates'])) {
            return $page;
        }
        $languages = $app->languages();
        if (count($languages) < 2) {
            return $page;
        }
        $alternates = [];
        $entity = isset($page['entity']) && is_array($page['entity']) ? $page['entity'] : null;
        if (isset($page['type']) && $page['type'] === 'home') {
            foreach ($languages as $lang) {
                $alternates[$lang] = $app->url()->abs('/', [], $lang);
            }
        } elseif ($entity !== null && !empty($entity['id']) && isset($entity['type'])) {
            $type = (string) $entity['type'];
            $id = (int) $entity['id'];
            foreach ($languages as $lang) {
                if ($app->slugs()->path($type, $id, $lang) !== null) {
                    $alternates[$lang] = $app->url()->entityAbs($type, $id, $lang);
                }
            }
        }
        if (count($alternates) < 2) {
            return $page;
        }
        $default = $app->defaultLang();
        $first = array_values($alternates);
        $alternates['x-default'] = isset($alternates[$default]) ? $alternates[$default] : $first[0];
        $page['alternates'] = $alternates;

        return $page;
    }

    // ------------------------------------------------------------------ robots

    /**
     * «noindex» en todo el sitio con el interruptor del panel, y en las páginas de un listado más allá de la última que se deja indexar.
     *
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    private static function robots(array $page, App $app): array
    {
        $robots = isset($page['robots']) ? (string) $page['robots'] : '';
        if (stripos($robots, 'noindex') !== false) {
            return $page;
        }
        $settings = $app->settings();
        if ($settings->bool('seo.noindex_site', false) && !self::openProduct($app, $page)) {
            $page['robots'] = 'noindex, follow';

            return $page;
        }
        $max = $settings->int('seo.max_indexed_page', 0);
        $type = isset($page['type']) ? (string) $page['type'] : '';
        if ($max > 0 && !in_array($type, ['home', 'product', 'page', 'post'], true) && $app->request()->int('page', 1) > $max) {
            $page['robots'] = 'noindex, follow';
        }

        return $page;
    }
}
