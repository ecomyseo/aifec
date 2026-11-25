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
use Aifec\Util\Str;

/**
 * Los feeds de producto. Se escriben a ficheros en la carpeta «feeds» de los datos del sitio (una tarea cada hora y un trabajo
 * cuando cambia un precio o unas existencias) y se sirven desde el fichero:
 *
 *   google.xml     RSS 2.0 con el espacio de nombres «g:» de Google Merchant: un «item» por variante que se puede comprar;
 *   products.json  la lista pública de productos, con «generated_at»;
 *   openai.json    el feed de producto para compra en asistentes.
 *
 * Los productos se leen por tandas de 200 y cada elemento se escribe en cuanto está listo: la memoria no depende del tamaño del
 * catálogo. Cada fichero se escribe aparte y se renombra al terminar, así nadie lee uno a medias. El filtro «feed.item»
 * (array $item, array $product, string $format) deja cambiar cada elemento; si devuelve algo que no es una lista, o vacío, el
 * elemento no sale.
 */
final class Feeds
{
    /** Productos que se piden de una vez al catálogo. */
    public const BATCH = 200;

    /** Hasta cuántos productos genera un feed en el momento de pedirlo por primera vez; con más lo hace un trabajo en segundo plano. */
    public const FIRST_REQUEST_MAX_PRODUCTS = 500;

    /** @var array<string,string> clave => nombre del fichero */
    public const FILES = ['google' => 'google.xml', 'products' => 'products.json', 'openai' => 'openai.json'];

    /** Los feeds que se trocean en páginas: clave => nombre del fichero sin extensión (openai.p1.json, products.p1.json…). */
    public const PAGED = ['openai' => 'openai', 'products' => 'products'];

    /** Una página es lo que cabe en un lector de páginas: el feed entero solo se manda de golpe si cabe en una. */
    public const SINGLE_MAX_BYTES = 80000;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** Propiedades de variante que Google conoce. */
    private const GOOGLE_VARIANT = ['color' => 'color', 'size' => 'size', 'material' => 'material', 'pattern' => 'pattern', 'gender' => 'gender', 'age_group' => 'age_group'];

    /** ¿Está encendido este feed en los ajustes? */
    public static function enabled(App $app, string $key): bool
    {
        return isset(self::FILES[$key]) && $app->settings()->bool('semantic.feeds.' . $key, true);
    }

    /** Dónde está (o estará) el fichero de un feed. */
    public static function path(App $app, string $key): string
    {
        return $app->site()->path('feeds', true) . '/' . self::FILES[$key];
    }

    /**
     * Rehace los feeds encendidos. Devuelve cuántos elementos salieron en cada uno (clave del feed => número).
     * Con otra generación en marcha (una petición, la tarea o el trabajo) no hace nada y devuelve una lista vacía.
     *
     * @return array<string,int>
     */
    public static function generate(Semantic $s): array
    {
        $counts = self::tryGenerate($s);

        return $counts !== null ? $counts : [];
    }

    /**
     * Como generate(), pero distingue «no había nada que hacer» (lista vacía) de «ya hay una generación en marcha» (null).
     * Nunca corren dos a la vez: el cerrojo es un fichero bloqueado con flock, que el sistema suelta solo si el proceso muere.
     *
     * @return array<string,int>|null
     */
    public static function tryGenerate(Semantic $s): ?array
    {
        $handle = fopen($s->app()->site()->path('feeds', true) . '/.generating.lock', 'cb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the feeds lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        try {
            return self::build($s);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Lo que hace la primera petición a un feed encendido que todavía no existe (tienda recién instalada: la tarea periódica aún no ha
     * corrido). Hasta FIRST_REQUEST_MAX_PRODUCTS productos se genera en ese momento; con más, se deja el trabajo encolado y quien pide
     * recibe un 503 con Retry-After. Si otra generación ya está en marcha, tampoco se lanza una segunda.
     *
     * @return string ready (el fichero ya existe) | busy (otra generación en marcha) | queued (tienda grande: trabajo encolado) | none (no hay catálogo)
     */
    public static function ensure(Semantic $s, string $key, int $max = self::FIRST_REQUEST_MAX_PRODUCTS): string
    {
        $app = $s->app();
        if (is_file(self::path($app, $key))) {
            return 'ready';
        }
        $catalog = $s->catalog();
        if ($catalog === null) {
            return 'none';
        }
        $listing = $catalog->listing(['per_page' => 1]);
        $total = is_array($listing) && isset($listing['total']) ? (int) $listing['total'] : 0;
        if ($total > $max) {
            if ($app->has('jobs')) {
                $app->service('jobs')->push('semantic.feeds', [], ['unique' => 'semantic.feeds']);
            }

            return 'queued';
        }
        if (self::tryGenerate($s) === null) {
            return 'busy';
        }

        return is_file(self::path($app, $key)) ? 'ready' : 'none';
    }

    /**
     * @return array<string,int>
     */
    private static function build(Semantic $s): array
    {
        $app = $s->app();
        $catalog = $s->catalog();
        $keys = [];
        foreach (array_keys(self::FILES) as $key) {
            if (self::enabled($app, $key)) {
                $keys[] = $key;
            }
        }
        if ($catalog === null || $keys === []) {
            return [];
        }

        $writers = [];
        $counts = array_fill_keys($keys, 0);
        $siteUrl = $s->siteUrl();
        $generated = $s->iso($app->clock()->sql());
        $jsonHead = '"generated_at":' . json_encode($generated) . ',"shop":' . json_encode($s->shop(), self::JSON_FLAGS) . ',"url":' . json_encode($siteUrl, self::JSON_FLAGS)
            . ',"currency":' . json_encode($s->currency());
        // Los dos feeds JSON, además de enteros, se escriben troceados en páginas pequeñas (FeedParts) para quien los lee con un límite de tamaño.
        $parts = [];
        foreach ($keys as $key) {
            if (isset(self::PAGED[$key])) {
                $parts[$key] = new FeedParts($app->site()->path('feeds', true), self::PAGED[$key]);
            }
        }
        try {
            foreach ($keys as $key) {
                $final = self::path($app, $key);
                $handle = fopen($final . '.tmp', 'wb');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot write the feed ' . self::FILES[$key]);
                }
                $writers[$key] = ['handle' => $handle, 'tmp' => $final . '.tmp', 'final' => $final];
                if ($key === 'google') {
                    fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n"
                        . '<title>' . self::xml($s->shop()) . "</title>\n<link>" . self::xml($siteUrl) . "</link>\n<description>" . self::xml($s->summary()) . "</description>\n");
                } else {
                    fwrite($handle, '{' . $jsonHead . ',"products":[');
                }
            }

            foreach (self::batches($catalog) as $batch) {
                foreach ($batch as $product) {
                    foreach ($keys as $key) {
                        $counts[$key] += self::write($s, $key, $writers[$key]['handle'], $product, $counts[$key], isset($parts[$key]) ? $parts[$key] : null);
                    }
                }
            }

            foreach ($keys as $key) {
                fwrite($writers[$key]['handle'], $key === 'google' ? "</channel>\n</rss>\n" : '],"count":' . $counts[$key] . "}\n");
                fclose($writers[$key]['handle']);
                $writers[$key]['handle'] = null;
                if (!rename($writers[$key]['tmp'], $writers[$key]['final'])) {
                    throw new \RuntimeException('Cannot replace the feed ' . self::FILES[$key]);
                }
            }
            self::finishParts($s, $parts, $keys, $counts, $jsonHead, $generated);
        } catch (\Throwable $e) {
            foreach ($parts as $part) {
                $part->abort();
            }
            foreach ($writers as $writer) {
                if (is_resource($writer['handle'])) {
                    fclose($writer['handle']);
                }
                if (is_file($writer['tmp'])) {
                    unlink($writer['tmp']);
                }
            }

            throw $e;
        }

        return $counts;
    }

    /** La dirección pública de un feed, o de una de sus páginas. */
    public static function url(App $app, string $key, int $page = 0): string
    {
        return $app->url()->abs('/feeds/' . self::FILES[$key], $page > 0 ? ['page' => $page] : [], '');
    }

    /** La dirección del índice de feeds. */
    public static function indexUrl(App $app): string
    {
        return $app->url()->abs('/feeds/index.json', [], '');
    }

    /** Dónde está la página N de un feed troceado. */
    public static function partPath(App $app, string $key, int $page): string
    {
        return $app->site()->path('feeds', true) . '/' . FeedParts::file(self::PAGED[$key], $page);
    }

    /** Dónde está el índice (lo que mide cada feed y cada página), escrito en la última generación. */
    public static function indexPath(App $app): string
    {
        return $app->site()->path('feeds', true) . '/index.json';
    }

    /**
     * Compone las páginas de los feeds troceados y escribe el índice.
     *
     * @param array<string,FeedParts> $parts
     * @param string[]                $keys
     * @param array<string,int>       $counts
     */
    private static function finishParts(Semantic $s, array $parts, array $keys, array $counts, string $jsonHead, string $generated): void
    {
        $app = $s->app();
        $index = ['generated_at' => $generated, 'note' => 'Each JSON feed is also split into pages small enough for a reader with a size limit: open ?page=1, then follow pagination.next. The whole feed is the same address with ?all=1.', 'feeds' => []];
        foreach ($keys as $key) {
            $path = self::path($app, $key);
            $entry = ['file' => self::FILES[$key], 'url' => self::url($app, $key), 'items' => $counts[$key], 'bytes' => is_file($path) ? (int) filesize($path) : 0,
                'format' => $key === 'google' ? 'xml' : 'json', 'complete' => true];
            if (isset($parts[$key])) {
                $meta = $parts[$key]->finish($jsonHead, static function (int $page) use ($app, $key): string {
                    return self::url($app, $key, $page);
                }, self::url($app, $key) . '?all=1', self::indexUrl($app));
                $entry['pages'] = count($meta);
                $entry['paged_by_default'] = $entry['bytes'] > self::SINGLE_MAX_BYTES;
                $entry['all_url'] = self::url($app, $key) . '?all=1';
                $entry['parts'] = [];
                foreach ($meta as $part) {
                    $entry['parts'][] = ['page' => $part['page'], 'url' => self::url($app, $key, $part['page']), 'items' => $part['items'], 'bytes' => $part['bytes']];
                }
            } else {
                $entry['note'] = 'Complete file, for Google Merchant Center: it is not split.';
            }
            $index['feeds'][$key] = $entry;
        }
        $tmp = self::indexPath($app) . '.tmp';
        if (file_put_contents($tmp, (string) json_encode($index, self::JSON_FLAGS | JSON_PRETTY_PRINT)) === false || !rename($tmp, self::indexPath($app))) {
            throw new \RuntimeException('Cannot write the feeds index');
        }
    }

    /**
     * Los productos publicados y visibles, por tandas de BATCH, sin repetir ninguno.
     *
     * @param object $catalog el servicio «catalog»
     *
     * @return \Generator<int,array<int,array<string,mixed>>>
     */
    private static function batches($catalog): \Generator
    {
        $seen = [];
        $ids = [];
        $page = 1;
        do {
            $result = $catalog->listing(['sort' => 'newest', 'page' => $page, 'per_page' => 96]);
            foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $card) {
                if (is_array($card) && isset($card['id']) && !isset($seen[(int) $card['id']])) {
                    $seen[(int) $card['id']] = true;
                    $ids[] = (int) $card['id'];
                }
            }
            while (count($ids) >= self::BATCH) {
                yield array_values($catalog->products(array_splice($ids, 0, self::BATCH)));
            }
            $more = $page < (isset($result['pages']) ? (int) $result['pages'] : 1);
            ++$page;
        } while ($more);
        if ($ids !== []) {
            yield array_values($catalog->products($ids));
        }
    }

    /**
     * Escribe lo que un producto aporta a un feed. Devuelve cuántos elementos escribió.
     *
     * @param resource            $handle
     * @param array<string,mixed> $product
     */
    private static function write(Semantic $s, string $key, $handle, array $product, int $written, ?FeedParts $parts = null): int
    {
        // Un producto cuyo precio no se enseña (disponibilidad con show_price = false) no entra en el feed de OpenAI, que exige precio;
        // el de Google ya lo deja fuera por no tener importe, y el público lo lleva sin precio.
        if ($key === 'openai' && !empty($product['price']['hidden'])) {
            return 0;
        }
        // La misma regla en los cinco feeds (FeedEligibility): sin imagen, gratuito (salvo ajuste) o sin poder comprarse, no entra.
        // Verificación del 24/11/2026: products.json llevaba 149 productos y los otros cuatro, 142 (los siete de más eran gratuitos).
        // El feed público (products.json) conserva el producto cuyo precio no se enseña (sale sin precio): es lo único que no cuenta.
        if (($key === 'openai' || $key === 'products') && $s->app()->has('catalog')) {
            $reason = FeedEligibility::reason($s->app(), $s, $s->app()->service('catalog'), $product);
            if ($reason !== '' && !($key === 'products' && $reason === 'price_hidden')) {
                return 0;
            }
        }
        $items = $key === 'google' ? self::googleItems($s, $product) : [$key === 'openai' ? self::openaiItem($s, $product) : self::publicItem($s, $product)];
        $count = 0;
        foreach ($items as $item) {
            $filtered = $s->app()->hooks()->apply('feed.item', $item, $product, $key);
            if (!is_array($filtered) || $filtered === []) {
                continue;
            }
            if ($key === 'google') {
                fwrite($handle, "<item>\n" . self::xmlFields($filtered) . "</item>\n");
            } else {
                $json = json_encode($filtered, self::JSON_FLAGS);
                if ($json === false) {
                    continue;
                }
                fwrite($handle, ($written + $count > 0 ? ',' : '') . "\n" . $json);
                if ($parts !== null) {
                    $parts->add($json);
                }
            }
            ++$count;
        }

        return $count;
    }

    // ------------------------------------------------------------------ google.xml

    /**
     * Un elemento por variante que se puede comprar (o uno solo si no hay variantes). Sin precio o sin imagen, ninguno.
     *
     * @param array<string,mixed> $p
     *
     * @return array<int,array<string,mixed>>
     */
    private static function googleItems(Semantic $s, array $p): array
    {
        $images = $s->images($p, 11);
        $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [];
        $units = [];
        if (!empty($p['has_variants']) && $variants !== []) {
            foreach ($variants as $variant) {
                if (is_array($variant)) {
                    $units[] = $variant;
                }
            }
        } else {
            $units[] = null;
        }
        $url = isset($p['url_abs']) ? (string) $p['url_abs'] : '';
        $name = $s->line((string) $p['name']);
        $description = $s->plain(isset($p['description']) ? (string) $p['description'] : '', 5000);
        if ($description === '') {
            $description = $s->plain(isset($p['summary']) ? (string) $p['summary'] : '', 5000);
        }
        $group = (string) (int) $p['id'];
        $options = self::optionLabels($p);

        $items = [];
        foreach ($units as $variant) {
            $price = $variant !== null && isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : (isset($p['price']) && is_array($p['price']) ? $p['price'] : []);
            $availability = $variant !== null && isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : (isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : []);
            $gross = $s->gross($price);
            $mine = $variant !== null ? self::variantImage($s, $p, $variant) : '';
            $gallery = $images;
            if ($mine !== '') {
                $gallery = array_merge([$mine], array_values(array_diff($images, [$mine])));
            }
            if (!FeedEligibility::priceAllowed($s->app(), $gross) || $gallery === []) {
                continue;
            }
            $currency = isset($price['currency']) && $price['currency'] !== '' ? (string) $price['currency'] : $s->currency();
            $compare = $s->grossCompare($price);
            $gtin = $variant !== null && isset($variant['gtin']) && trim((string) $variant['gtin']) !== '' ? trim((string) $variant['gtin']) : (isset($p['gtin']) ? trim((string) $p['gtin']) : '');
            $mpn = $s->mpn($p, $variant);
            $brand = $s->brand($p);
            $label = $variant !== null && isset($variant['label']) ? trim((string) $variant['label']) : '';

            $item = [
                'id' => $variant !== null ? (int) $p['id'] . '-' . (int) $variant['id'] : $group,
                'title' => Str::limit($label !== '' ? $name . ' - ' . $label : $name, 150, ''),
                'description' => $description !== '' ? $description : $name,
                'link' => $url,
                'image_link' => $gallery[0],
                'additional_image_link' => array_slice($gallery, 1, 10),
                'availability' => self::availability(isset($availability['status']) ? (string) $availability['status'] : 'in_stock'),
                'price' => $s->decimal($compare !== null ? $compare : $gross) . ' ' . $currency,
                'sale_price' => $compare !== null ? $s->decimal($gross) . ' ' . $currency : '',
                'brand' => $brand,
                'gtin' => $gtin,
                'mpn' => $mpn,
                'identifier_exists' => $gtin === '' && $mpn === '' ? 'no' : '',
                'condition' => 'new',
                'google_product_category' => self::googleCategory($s, $p),
                'product_type' => $s->categoryPath($p),
                'item_group_id' => $variant !== null ? $group : '',
            ];
            foreach ($variant !== null && isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [] as $attribute => $valueCode) {
                $attribute = (string) $attribute;
                if (isset(self::GOOGLE_VARIANT[$attribute])) {
                    $item[self::GOOGLE_VARIANT[$attribute]] = isset($options[$attribute][(string) $valueCode]) ? $options[$attribute][(string) $valueCode] : (string) $valueCode;
                }
            }
            if (isset($p['weight']) && (int) $p['weight'] > 0) {
                $item['shipping_weight'] = (int) $p['weight'] . ' g';
            }
            $ship = $s->shippingFor($p, $gross);
            if ($ship !== null) {
                $item['shipping'] = ['country' => $s->country(), 'service' => $ship['name'], 'price' => $s->decimal($ship['price']) . ' ' . $currency];
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function availability(string $status): string
    {
        $map = ['out_of_stock' => 'out_of_stock', 'backorder' => 'backorder'];

        return isset($map[$status]) ? $map[$status] : 'in_stock';
    }

    /**
     * La categoría de producto de Google: la que ponga un plugin en los datos extra del producto o, si no, la de los ajustes.
     *
     * @param array<string,mixed> $p
     */
    private static function googleCategory(Semantic $s, array $p): string
    {
        if (isset($p['extra']['google_product_category']['value']) && trim((string) $p['extra']['google_product_category']['value']) !== '') {
            return trim((string) $p['extra']['google_product_category']['value']);
        }

        return trim($s->app()->settings()->str('semantic.feeds.google_category'));
    }

    /**
     * La imagen propia de una variante, si la tiene.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $variant
     */
    private static function variantImage(Semantic $s, array $p, array $variant): string
    {
        if (!isset($variant['image_id']) || (int) $variant['image_id'] <= 0) {
            return '';
        }
        foreach (isset($p['images']) && is_array($p['images']) ? $p['images'] : [] as $image) {
            if (is_array($image) && isset($image['id'], $image['url']) && (int) $image['id'] === (int) $variant['image_id']) {
                return $s->abs((string) $image['url']);
            }
        }

        return '';
    }

    /**
     * Las etiquetas de cada valor de cada opción: [código de la opción => [código del valor => etiqueta]].
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,array<string,string>>
     */
    private static function optionLabels(array $p): array
    {
        $out = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option) || !isset($option['code'])) {
                continue;
            }
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['code'])) {
                    $out[(string) $option['code']][(string) $value['code']] = isset($value['label']) ? (string) $value['label'] : (string) $value['code'];
                }
            }
        }

        return $out;
    }

    /**
     * Los campos de un elemento como XML: «title», «link» y «description» son de RSS; el resto lleva el prefijo «g:». Una lista repite
     * la etiqueta y una lista con nombres abre una etiqueta con sus hijas.
     *
     * @param array<string,mixed> $fields
     */
    private static function xmlFields(array $fields, string $indent = ''): string
    {
        $out = '';
        foreach ($fields as $name => $value) {
            $name = (string) $name;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $tag = ($indent === '' && in_array($name, ['title', 'link', 'description'], true) ? '' : 'g:') . preg_replace('/[^a-z0-9_]/', '', strtolower($name));
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $one) {
                    $out .= is_array($one) ? '<' . $tag . '>' . self::xmlFields($one, $indent . ' ') . '</' . $tag . '>' . "\n" : '<' . $tag . '>' . self::xml((string) $one) . '</' . $tag . ">\n";
                }
            } elseif (is_array($value)) {
                $out .= '<' . $tag . '>' . self::xmlFields($value, $indent . ' ') . '</' . $tag . ">\n";
            } else {
                $out .= '<' . $tag . '>' . self::xml(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) . '</' . $tag . ">\n";
            }
        }

        return $out;
    }

    /** Texto para dentro de una etiqueta XML: sin caracteres que XML no admite y con «&», «<», «>» y comillas escapados. */
    public static function xml(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ------------------------------------------------------------------ products.json

    /**
     * Un producto reducido a lo público: lo que está en la ficha y nada de lo interno.
     *
     * @param array<string,mixed> $p ProductData
     *
     * @return array<string,mixed>
     */
    private static function publicItem(Semantic $s, array $p): array
    {
        $price = isset($p['price']) && is_array($p['price']) ? $p['price'] : [];
        $variants = [];
        foreach (isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [] as $variant) {
            if (is_array($variant)) {
                $vPrice = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
                $variants[] = [
                    'id' => (int) $variant['id'],
                    'sku' => $s->sku($p, $variant)[0],
                    'sku_source' => $s->sku($p, $variant)[1],
                    'gtin' => isset($variant['gtin']) ? (string) $variant['gtin'] : '',
                    'label' => isset($variant['label']) ? (string) $variant['label'] : '',
                    'values' => isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : new \stdClass(),
                    'price' => self::priceBlock($s, $vPrice),
                    'availability' => self::availabilityBlock(isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : []),
                ];
            }
        }
        $attributes = [];
        foreach (isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['name'])) {
                $attributes[] = ['code' => isset($attribute['code']) ? (string) $attribute['code'] : '', 'name' => (string) $attribute['name'], 'value' => isset($attribute['value']) ? (string) $attribute['value'] : ''];
            }
        }
        $extra = [];
        foreach (isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [] as $code => $field) {
            if (is_array($field) && !empty($field['public']) && isset($field['value'])) {
                $extra[(string) $code] = ['label' => isset($field['label']) ? (string) $field['label'] : (string) $code, 'value' => $field['value']];
            }
        }
        $categories = [];
        foreach (isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : [] as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = (string) $category['name'];
            }
        }

        return [
            'id' => (int) $p['id'],
            'kind' => isset($p['kind']) ? (string) $p['kind'] : 'physical',
            'sku' => $s->sku($p)[0],
            'sku_source' => $s->sku($p)[1],
            'gtin' => isset($p['gtin']) && $p['gtin'] !== null ? (string) $p['gtin'] : '',
            'mpn' => isset($p['mpn']) && $p['mpn'] !== null ? (string) $p['mpn'] : '',
            'name' => $s->line((string) $p['name']),
            'url' => isset($p['url_abs']) ? (string) $p['url_abs'] : '',
            'summary' => $s->plain(isset($p['summary']) ? (string) $p['summary'] : ''),
            'description' => $s->plain(isset($p['description']) ? (string) $p['description'] : ''),
            'brand' => $s->brand($p),
            'categories' => $categories,
            'category_path' => $s->categoryPath($p),
            'images' => $s->images($p),
            'price' => self::priceBlock($s, $price),
            'availability' => self::availabilityBlock(isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : []),
            'options' => self::publicOptions($p),
            'variants' => $variants,
            'attributes' => $attributes,
            'facts' => isset($p['facts']) && is_array($p['facts']) ? array_values($p['facts']) : [],
            'faqs' => self::publicFaqs($s, $p),
            'rating' => isset($p['rating']['count']) && (int) $p['rating']['count'] > 0 ? ['average' => round((float) $p['rating']['average'], 2), 'count' => (int) $p['rating']['count']] : null,
            'weight' => isset($p['weight']) ? (int) $p['weight'] : 0,
            'dimensions' => isset($p['dimensions']) && is_array($p['dimensions']) ? $p['dimensions'] : new \stdClass(),
            'updated_at' => $s->iso(isset($p['updated_at']) ? (string) $p['updated_at'] : null),
            'extra' => $extra === [] ? new \stdClass() : $extra,
        ];
    }

    /**
     * @param array<string,mixed> $price
     *
     * @return array<string,mixed>
     */
    private static function priceBlock(Semantic $s, array $price): array
    {
        if (!empty($price['hidden'])) {
            return ['hidden' => true];
        }
        $gross = $s->gross($price);
        $compare = $s->grossCompare($price);

        return [
            'amount' => $gross,
            'decimal' => $s->decimal($gross),
            'currency' => isset($price['currency']) && $price['currency'] !== '' ? (string) $price['currency'] : $s->currency(),
            'net' => isset($price['net']) ? (int) $price['net'] : null,
            'tax' => isset($price['tax']) ? (int) $price['tax'] : null,
            'tax_rate' => isset($price['tax_rate']) ? (int) $price['tax_rate'] : null,
            // La base sin impuestos y el total con ellos, con la divisa, el tipo en % y el país del tipo.
            'detail' => \Aifec\Modules\Catalog\PriceDetail::of($s->app(), $price),
            'compare' => $compare,
            'on_sale' => $compare !== null,
            'sale_until' => $compare !== null ? $s->iso(isset($price['sale_until']) ? (string) $price['sale_until'] : null) : '',
            'from' => !empty($price['from']),
        ];
    }

    /**
     * @param array<string,mixed> $availability
     *
     * @return array<string,mixed>
     */
    private static function availabilityBlock(array $availability): array
    {
        return [
            'status' => isset($availability['status']) ? (string) $availability['status'] : 'in_stock',
            'label' => isset($availability['label']) ? (string) $availability['label'] : '',
            'qty' => isset($availability['qty']) ? (int) $availability['qty'] : null,
            'delivery_days' => isset($availability['delivery_days']) && is_array($availability['delivery_days']) ? array_values($availability['delivery_days']) : null,
        ];
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<int,array<string,mixed>>
     */
    private static function publicOptions(array $p): array
    {
        $out = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option) || !isset($option['code'])) {
                continue;
            }
            $values = [];
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['code'])) {
                    $values[] = ['code' => (string) $value['code'], 'label' => isset($value['label']) ? (string) $value['label'] : (string) $value['code']];
                }
            }
            $out[] = ['code' => (string) $option['code'], 'name' => isset($option['name']) ? (string) $option['name'] : (string) $option['code'], 'values' => $values];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<int,array<string,string>>
     */
    private static function publicFaqs(Semantic $s, array $p): array
    {
        $out = [];
        foreach (isset($p['faqs']) && is_array($p['faqs']) ? $p['faqs'] : [] as $faq) {
            if (is_array($faq) && isset($faq['question']) && trim((string) $faq['question']) !== '') {
                $out[] = ['question' => $s->line((string) $faq['question']), 'answer' => $s->plain(isset($faq['answer']) ? (string) $faq['answer'] : '')];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ openai.json

    /**
     * La política de devoluciones de un producto en openai.json: sin método ni quién paga si es una descarga o un servicio.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $returns
     *
     * @return array<string,mixed>
     */
    private static function returnPolicy(Semantic $s, array $p, array $returns): array
    {
        $method = $s->returnMethodFor($p, $returns);
        $out = ['days' => $returns['days'], 'country' => $returns['country']];
        if ($method !== 'none') {
            $out['fees_paid_by'] = $returns['payer'];
            $out['method'] = $method;
        }
        $link = $s->returnLink();
        if ($link !== '') {
            $out['url'] = $link;
        }

        return $out;
    }

    /**
     * El producto en el formato de compra en asistentes: identificador, título, descripción, enlace, precio, disponibilidad,
     * inventario, imágenes, marca, códigos, categoría, variantes, devoluciones, valoraciones y los dos indicadores de los ajustes.
     *
     * @param array<string,mixed> $p ProductData
     *
     * @return array<string,mixed>
     */
    private static function openaiItem(Semantic $s, array $p): array
    {
        $settings = $s->app()->settings();
        $price = isset($p['price']) && is_array($p['price']) ? $p['price'] : [];
        $availability = isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : [];
        $currency = isset($price['currency']) && $price['currency'] !== '' ? (string) $price['currency'] : $s->currency();
        $gross = $s->gross($price);
        $compare = $s->grossCompare($price);
        $images = $s->images($p, 11);
        $name = $s->line((string) $p['name']);
        $description = $s->plain(isset($p['description']) ? (string) $p['description'] : '', 5000);
        if ($description === '') {
            $description = $s->plain(isset($p['summary']) ? (string) $p['summary'] : '', 5000);
        }

        $variants = [];
        $options = self::optionLabels($p);
        foreach (isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $vPrice = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
            $vAvailability = isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : [];
            $vCompare = $s->grossCompare($vPrice);
            $choices = [];
            foreach (isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [] as $attribute => $valueCode) {
                $choices[(string) $attribute] = isset($options[(string) $attribute][(string) $valueCode]) ? $options[(string) $attribute][(string) $valueCode] : (string) $valueCode;
            }
            $image = self::variantImage($s, $p, $variant);
            $variants[] = JsonLd::clean([
                'id' => (int) $p['id'] . '-' . (int) $variant['id'],
                'title' => isset($variant['label']) && trim((string) $variant['label']) !== '' ? $name . ' - ' . trim((string) $variant['label']) : $name,
                'sku' => $s->sku($p, $variant)[0],
                'sku_source' => $s->sku($p, $variant)[1],
                'gtin' => isset($variant['gtin']) && $variant['gtin'] !== null ? (string) $variant['gtin'] : '',
                'price' => $s->decimal($vCompare !== null ? $vCompare : $s->gross($vPrice)) . ' ' . $currency,
                'sale_price' => $vCompare !== null ? $s->decimal($s->gross($vPrice)) . ' ' . $currency : '',
                'availability' => self::availability(isset($vAvailability['status']) ? (string) $vAvailability['status'] : 'in_stock'),
                'inventory_quantity' => isset($vAvailability['qty']) ? (int) $vAvailability['qty'] : null,
                'image_link' => $image,
                'options' => $choices === [] ? new \stdClass() : $choices,
            ]);
        }

        $returns = $s->returns();
        $checkoutPolicies = $s->app()->hooks()->apply('semantic.openai.policies', []);
        $item = [
            'id' => (string) (int) $p['id'],
            'title' => Str::limit($name, 150, ''),
            'description' => $description !== '' ? $description : $name,
            'link' => isset($p['url_abs']) ? (string) $p['url_abs'] : '',
            'price' => $s->decimal($compare !== null ? $compare : $gross) . ' ' . $currency,
            'sale_price' => $compare !== null ? $s->decimal($gross) . ' ' . $currency : '',
            'availability' => self::availability(isset($availability['status']) ? (string) $availability['status'] : 'in_stock'),
            'inventory_quantity' => isset($availability['qty']) ? (int) $availability['qty'] : null,
            'image_link' => $images !== [] ? $images[0] : '',
            'additional_image_link' => array_slice($images, 1, 10),
            'brand' => $s->brand($p),
            'gtin' => isset($p['gtin']) && $p['gtin'] !== null ? trim((string) $p['gtin']) : '',
            'mpn' => $s->mpn($p),
            'product_category' => $s->categoryPath($p),
            'item_group_id' => $variants !== [] ? (string) (int) $p['id'] : '',
            'variants' => $variants,
            'return_policy' => $returns !== null ? self::returnPolicy($s, $p, $returns) : null,
            'review_count' => isset($p['rating']['count']) && (int) $p['rating']['count'] > 0 ? (int) $p['rating']['count'] : null,
            'star_rating' => isset($p['rating']['count']) && (int) $p['rating']['count'] > 0 ? round((float) $p['rating']['average'], 2) : null,
            'enable_search' => $settings->bool('semantic.openai.enable_search', true),
            'enable_checkout' => $checkoutPolicies !== [] && !in_array((string) ($availability['status'] ?? ''), ['out_of_stock', 'unavailable'], true),
        ] + $checkoutPolicies;

        // Lo vacío no se escribe, pero los dos indicadores y las listas de variantes se conservan tal cual.
        return array_filter($item, static function ($value, $key): bool {
            return in_array($key, ['enable_search', 'enable_checkout'], true) || !($value === null || $value === '' || $value === []);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
