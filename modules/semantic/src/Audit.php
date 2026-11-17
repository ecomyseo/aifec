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

use Aifec\Kernel\App;

/**
 * La auditoría AIFEC: qué le falta a la tienda y a cada producto para que las máquinas la entiendan.
 *
 * La nota va de 0 a 100 y se calcula restando a 100 el peso de lo que no se cumple. Una comprobación que no se aplica (el peso
 * de un servicio, el mapa del sitio sin el módulo SEO, las traducciones con un solo idioma) ni aparece ni resta: así un
 * producto completo da siempre 100 y quitar una pieza baja la nota exactamente su peso.
 *
 * Cada comprobación sale como ['id', 'ok', 'label', 'fix_url', 'weight'], con la dirección del panel donde se arregla.
 */
final class Audit
{
    /** Comprobaciones del sitio: código => peso. Suman 100. */
    public const SITE = [
        'organization' => 10, 'returns' => 10, 'shipping' => 10, 'llms' => 8, 'sitemap' => 8, 'feeds' => 10,
        'robots' => 8, 'indexable' => 10, 'email' => 4, 'legal' => 6, 'search' => 6, 'gates' => 10,
    ];

    /** Comprobaciones de un producto: código => peso. Suman 100. */
    public const PRODUCT = [
        'identifier' => 12, 'brand' => 8, 'category' => 6, 'images' => 10, 'alt' => 6, 'description' => 12, 'summary' => 6,
        'facts' => 8, 'attributes' => 6, 'weight' => 6, 'delivery' => 6, 'faqs' => 6, 'price' => 4, 'translations' => 4,
    ];

    /** Largo mínimo de la descripción de un producto, en caracteres de texto. */
    public const MIN_DESCRIPTION = 300;

    /** Datos clave mínimos de un producto. */
    public const MIN_FACTS = 3;

    /** Productos que se puntúan en cada tanda del trabajo en segundo plano. */
    public const BATCH = 100;

    /** Segundos de vida de un feed antes de darlo por viejo. */
    private const FEED_MAX_AGE = 86400;

    /**
     * El nombre legible de cada comprobación (del sitio y de los productos).
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            'organization' => t('The company data is complete'),
            'returns' => t('The return policy is defined'),
            'shipping' => t('There is a carrier with a rate and a delivery time'),
            'llms' => t('The llms.txt summary for assistants is published'),
            'sitemap' => t('The sitemap is published'),
            'feeds' => t('The product feeds were built in the last 24 hours'),
            'robots' => t('AI assistants are not blocked in robots.txt'),
            'indexable' => t('The site can be indexed by search engines'),
            'email' => t('The shop email is set'),
            'legal' => t('The legal pages are published'),
            'search' => t('The own search is active'),
            'gates' => t('The API and the MCP server are active'),
            'identifier' => t('It has a barcode, or a brand and a part number'),
            'brand' => t('It has a brand'),
            'category' => t('It is in a category'),
            'images' => t('It has at least one image'),
            'alt' => t('All its images have alternative text'),
            'description' => t('Its description has 300 characters or more'),
            'summary' => t('It has a summary'),
            'facts' => t('It has three or more key facts'),
            'attributes' => t('The attributes of its product type are filled in'),
            'weight' => t('It has weight and measures'),
            'delivery' => t('It has a delivery time'),
            'faqs' => t('It has frequently asked questions'),
            'price' => t('Its price is greater than zero'),
            'translations' => t('It is translated into every language of the shop'),
        ];
    }

    public static function label(string $id): string
    {
        $labels = self::labels();

        return isset($labels[$id]) ? $labels[$id] : $id;
    }

    // ------------------------------------------------------------------ el sitio

    /**
     * La auditoría del sitio.
     *
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}
     */
    public static function site(Semantic $s): array
    {
        $app = $s->app();
        $settings = $app->settings();
        $url = $app->url();
        $labels = self::labels();
        $semanticSettings = $url->plain('/admin/settings/semantic');
        $seoSettings = $url->plain('/admin/settings/seo');
        $core = $url->plain('/admin/settings/core');
        $plugins = $url->plain('/admin/plugins');

        $results = [];

        // Datos de la organización: lo que leen los buscadores y los asistentes sobre la empresa.
        $results['organization'] = [
            trim($settings->str('store.legal_name')) !== ''
                && trim($settings->str('store.phone')) !== ''
                && trim($settings->str('store.address')) !== ''
                && $s->logoUrl() !== '',
            $core,
        ];
        $results['returns'] = [$s->returns() !== null, $semanticSettings . '#tab=smreturns'];
        $results['shipping'] = [self::hasCarrierWithDelay($s), $url->plain('/admin/r/carriers')];
        $results['llms'] = [$app->router()->has('semantic.llms'), $semanticSettings . '#tab=smtext'];
        if ($app->modules()->isLoaded('seo')) {
            $results['sitemap'] = [$app->router()->has('seo.sitemap'), $seoSettings];
        }
        $results['feeds'] = [self::feedsAreFresh($app), $semanticSettings . '#tab=smfeeds'];
        $results['robots'] = [!self::assistantsBlocked($app), $seoSettings];
        $results['indexable'] = [!$app->site()->isDemo() && !$settings->bool('seo.noindex_site', false), $seoSettings];
        $results['email'] = [filter_var(trim($settings->str('store.email')), FILTER_VALIDATE_EMAIL) !== false, $core];
        $results['legal'] = [self::legalPagesPublished($app), $url->plain('/admin/cms/legal')];
        $results['search'] = [$app->modules()->isLoaded('search'), $plugins];
        $results['gates'] = [$app->modules()->isLoaded('api') && $app->modules()->isLoaded('mcp'), $plugins];

        return self::assemble(self::SITE, $results, $labels);
    }

    /** ¿Hay al menos un transportista con tarifa y plazo de entrega para un envío normal al país de la tienda? */
    private static function hasCarrierWithDelay(Semantic $s): bool
    {
        $app = $s->app();
        if (!$app->has('shipping')) {
            return false;
        }
        try {
            $options = $app->service('shipping')->options(['weight' => 1000, 'items_total' => 5000, 'qty' => 1, 'needs_shipping' => true], ['country' => $s->country()]);
        } catch (\Throwable $e) {
            return false;
        }
        foreach (is_array($options) ? $options : [] as $option) {
            $hasDays = isset($option['min_days']) || isset($option['max_days']);
            if (is_array($option) && isset($option['price']) && $hasDays) {
                return true;
            }
        }

        return false;
    }

    /** ¿Hay algún feed encendido y todos los encendidos se hicieron hace menos de 24 horas? */
    private static function feedsAreFresh(App $app): bool
    {
        $now = $app->clock()->now();
        $any = false;
        foreach (array_keys(Feeds::FILES) as $key) {
            if (!Feeds::enabled($app, $key)) {
                continue;
            }
            $any = true;
            $path = Feeds::path($app, $key);
            if (!is_file($path) || $now - (int) filemtime($path) > self::FEED_MAX_AGE) {
                return false;
            }
        }

        return $any;
    }

    /** ¿Está bloqueada en robots.txt la familia de robots de los asistentes? Sin el módulo SEO no hay nada que los bloquee. */
    public static function assistantsBlocked(App $app): bool
    {
        if (!$app->modules()->isLoaded('seo') || !class_exists('Aifec\\Modules\\Seo\\Robots')) {
            return false;
        }
        $blocked = \Aifec\Modules\Seo\Robots::blocked($app);

        return !empty($blocked['assistant']);
    }

    /** ¿Están publicadas todas las páginas legales que conoce el módulo de contenidos? */
    private static function legalPagesPublished(App $app): bool
    {
        if (!$app->modules()->isLoaded('cms') || !class_exists('Aifec\\Modules\\Cms\\Legal')) {
            return false;
        }
        $map = $app->settings()->arr('cms.legal');
        foreach (array_keys(\Aifec\Modules\Cms\Legal::pages()) as $code) {
            $id = isset($map[$code]) ? (int) $map[$code] : 0;
            if ($id <= 0 || (string) $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$id]) !== 'published') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ un producto

    /**
     * La auditoría de un producto (de cualquier estado). Un producto que no existe da nota 0 y ninguna comprobación.
     *
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}
     */
    public static function product(Semantic $s, int $id): array
    {
        $catalog = $s->catalog();
        $data = $catalog !== null ? $catalog->product($id, null, true) : null;
        if (!is_array($data)) {
            return ['score' => 0, 'checks' => []];
        }
        $app = $s->app();
        $db = $app->db();
        $edit = $app->url()->plain('/admin/r/products/' . $id);
        $kind = isset($data['kind']) ? (string) $data['kind'] : 'physical';
        $results = [];

        $gtin = isset($data['gtin']) ? trim((string) $data['gtin']) : '';
        $mpn = isset($data['mpn']) ? trim((string) $data['mpn']) : '';
        $hasBrand = isset($data['brand']) && is_array($data['brand']);
        $results['identifier'] = [$gtin !== '' || ($hasBrand && $mpn !== ''), $edit];
        $results['brand'] = [$hasBrand, $edit];
        $results['category'] = [isset($data['main_category']) && is_array($data['main_category']), $edit];

        $images = isset($data['images']) && is_array($data['images']) ? $data['images'] : [];
        $results['images'] = [$images !== [], $edit];
        $results['alt'] = [self::allImagesDescribed($app, $images), $app->url()->plain('/admin/media')];

        $results['description'] = [mb_strlen($s->plain(isset($data['description']) ? (string) $data['description'] : ''), 'UTF-8') >= self::MIN_DESCRIPTION, $edit];
        $results['summary'] = [trim($s->plain(isset($data['summary']) ? (string) $data['summary'] : '')) !== '', $edit];

        $facts = 0;
        foreach (isset($data['facts']) && is_array($data['facts']) ? $data['facts'] : [] as $fact) {
            if (is_array($fact) && trim((string) (isset($fact['label']) ? $fact['label'] : '')) !== '' && trim((string) (isset($fact['value']) ? $fact['value'] : '')) !== '') {
                ++$facts;
            }
        }
        $results['facts'] = [$facts >= self::MIN_FACTS, $edit];
        $results['attributes'] = [self::familyAttributesFilled($app, $id, !empty($data['has_variants'])), $edit];

        if ($kind === 'physical') {
            $dimensions = isset($data['dimensions']) && is_array($data['dimensions']) ? $data['dimensions'] : [];
            $measured = (int) (isset($data['weight']) ? $data['weight'] : 0) > 0;
            foreach (['length', 'width', 'height'] as $side) {
                $measured = $measured && (int) (isset($dimensions[$side]) ? $dimensions[$side] : 0) > 0;
            }
            $results['weight'] = [$measured, $edit];
            $days = isset($data['availability']['delivery_days']) ? $data['availability']['delivery_days'] : null;
            $results['delivery'] = [is_array($days), $edit];
        }

        $results['faqs'] = [isset($data['faqs']) && is_array($data['faqs']) && $data['faqs'] !== [], $edit];
        $results['price'] = [isset($data['price']) && is_array($data['price']) && $s->gross($data['price']) > 0, $edit];

        $languages = $app->languages();
        if (count($languages) > 1) {
            $translated = [];
            foreach ($db->select('SELECT lang, name, description FROM {product_i18n} WHERE product_id = ?', [$id]) as $row) {
                if (trim((string) $row['name']) !== '' && trim($s->plain((string) $row['description'])) !== '') {
                    $translated[(string) $row['lang']] = true;
                }
            }
            $all = true;
            foreach ($languages as $lang) {
                $all = $all && isset($translated[$lang]);
            }
            $results['translations'] = [$all, $edit];
        }

        return self::assemble(self::PRODUCT, $results, self::labels());
    }

    /**
     * ¿Hay imágenes y todas llevan texto alternativo escrito en la biblioteca? (La ficha del producto pone el nombre cuando falta,
     * así que se mira la biblioteca y no la ficha.)
     *
     * @param array<int,mixed> $images
     */
    private static function allImagesDescribed(App $app, array $images): bool
    {
        $ids = [];
        foreach ($images as $image) {
            if (is_array($image) && isset($image['id']) && (int) $image['id'] > 0) {
                $ids[(int) $image['id']] = true;
            }
        }
        if ($ids === [] || !$app->db()->tableExists('media')) {
            return false;
        }
        $params = [];
        $in = $app->db()->in(array_keys($ids), $params);
        $found = [];
        foreach ($app->db()->select('SELECT id, alt FROM {media} WHERE id IN (' . $in . ')', $params) as $row) {
            if (trim((string) $row['alt']) !== '') {
                $found[(int) $row['id']] = true;
            }
        }

        return count($found) === count($ids);
    }

    /** ¿Tiene valor cada atributo que pide el tipo de producto (la familia)? Sin tipo de producto no se pide ninguno. */
    private static function familyAttributesFilled(App $app, int $productId, bool $hasVariants): bool
    {
        $db = $app->db();
        $family = (int) $db->value('SELECT family_id FROM {products} WHERE id = ?', [$productId]);
        if ($family <= 0) {
            return true;
        }
        $required = array_map('intval', $db->column('SELECT attribute_id FROM {family_attributes} WHERE family_id = ?', [$family]));
        if ($required === []) {
            return true;
        }
        $filled = [];
        $rows = $db->select(
            'SELECT attribute_id, value_id, value_text, value_number FROM {product_attributes} WHERE product_id = ?',
            [$productId]
        );
        foreach ($rows as $row) {
            if ($row['value_id'] !== null || trim((string) $row['value_text']) !== '' || ($row['value_number'] !== null && $row['value_number'] !== '')) {
                $filled[(int) $row['attribute_id']] = true;
            }
        }
        if ($hasVariants) {
            $variantAttributes = $db->column(
                'SELECT DISTINCT vv.attribute_id FROM {variant_values} vv JOIN {variants} v ON v.id = vv.variant_id WHERE v.product_id = ?',
                [$productId]
            );
            foreach ($variantAttributes as $attributeId) {
                $filled[(int) $attributeId] = true;
            }
        }
        foreach ($required as $attributeId) {
            if (!isset($filled[$attributeId])) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ puntuar y guardar

    /**
     * Puntúa un producto y guarda su nota. Si ya no existe, se borra su nota. Devuelve el resultado o null si no existe.
     *
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}|null
     */
    public static function scoreProduct(Semantic $s, int $id): ?array
    {
        $result = self::product($s, $id);
        if ($result['checks'] === []) {
            AuditStore::forget($s->app(), AuditStore::PRODUCT, $id);

            return null;
        }
        AuditStore::save($s->app(), AuditStore::PRODUCT, $id, $result);

        return $result;
    }

    /**
     * Una tanda del cálculo de todos los productos publicados: los $size siguientes al identificador $after.
     * Cuando la tanda sale incompleta (se acabaron) borra las notas de lo que ya no está publicado.
     *
     * @return array{done:int,last:int,more:bool}
     */
    public static function batch(App $app, int $after, int $size = self::BATCH): array
    {
        $size = max(1, $size);
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $ids = array_map('intval', $app->db()->column(
            "SELECT id FROM {products} WHERE status = 'published' AND id > ? ORDER BY id LIMIT " . $size,
            [max(0, $after)]
        ));
        $last = $after;
        foreach ($ids as $id) {
            self::scoreProduct($semantic, $id);
            $last = $id;
        }
        $more = count($ids) === $size;
        if (!$more) {
            AuditStore::prune($app);
        }

        return ['done' => count($ids), 'last' => $last, 'more' => $more];
    }

    /**
     * @param array<string,int>                $weights
     * @param array<string,array{0:bool,1:string}> $results código => [se cumple, dirección donde se arregla]
     * @param array<string,string>             $labels
     *
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}
     */
    private static function assemble(array $weights, array $results, array $labels): array
    {
        $checks = [];
        $score = 100;
        foreach ($weights as $id => $weight) {
            if (!isset($results[$id])) {
                continue;
            }
            $ok = (bool) $results[$id][0];
            if (!$ok) {
                $score -= $weight;
            }
            $checks[] = ['id' => $id, 'ok' => $ok, 'label' => isset($labels[$id]) ? $labels[$id] : $id, 'fix_url' => (string) $results[$id][1], 'weight' => $weight];
        }

        return ['score' => max(0, min(100, $score)), 'checks' => $checks];
    }
}
