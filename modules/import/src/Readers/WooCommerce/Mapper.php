<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Modules\Import\Neutral;

/**
 * Del formato de WooCommerce al formato neutro. Es lo que comparten los tres lectores: la API entrega estas formas tal cual
 * (wc/v3 y wp/v2) y el lector de la copia de la base de datos arma las mismas formas desde las tablas, así que para el mismo
 * producto, cliente o pedido sale exactamente el mismo registro.
 *
 * Reglas de este traductor:
 *  - los importes de WooCommerce son decimales en texto («106.61») y salen en céntimos enteros;
 *  - WooCommerce guarda las líneas de pedido sin impuestos: si el pedido llevaba los precios con impuestos, unit_price,
 *    line_total, items_total, discount_total, shipping_total y fee_total salen con impuestos (como en el origen), y
 *    line_net y total_net salen siempre sin ellos;
 *  - lo que no tiene campo va a «extra» y solo cuando tiene algo que decir (para que la API y la copia coincidan);
 *  - lo que no se puede convertir lanza InvalidArgumentException y el lector lo descarta con su motivo.
 */
final class Mapper
{
    /** Estados de pedido de WooCommerce y su significado (estado sin el prefijo «wc-»). */
    private const STATES = [
        'pending' => 'pending', 'on-hold' => 'on_hold', 'processing' => 'paid', 'completed' => 'delivered',
        'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'failed',
    ];

    /** Estados propios que se reconocen por su nombre (los de plugins de seguimiento, los traducidos). */
    private const NAMED_STATES = [
        'shipped' => 'shipped', 'sent' => 'shipped', 'dispatched' => 'shipped', 'enviado' => 'shipped', 'en-transito' => 'shipped',
        'delivered' => 'delivered', 'entregado' => 'delivered', 'completado' => 'delivered', 'paid' => 'paid', 'pagado' => 'paid',
        'awaiting-payment' => 'pending', 'pendiente' => 'pending', 'pending-payment' => 'pending', 'cancelado' => 'cancelled',
        'reembolsado' => 'refunded', 'fallido' => 'failed', 'en-espera' => 'on_hold', 'on-hold-payment' => 'on_hold',
    ];

    /** Significados que cuentan como pagado. */
    private const PAID = ['paid', 'processing', 'shipped', 'delivered', 'refunded'];

    /** Claves de dato extra donde los plugins de NIF guardan el identificador fiscal. */
    private const VAT_KEYS = ['billing_nif', '_billing_nif', 'billing_vat', '_billing_vat', 'billing_cif', '_billing_cif', 'billing_vat_number', 'vat_number', 'billing_dni'];

    /** Plugins que el contrato cubre (o que son la propia tienda): no se apuntan como «sin traer». */
    private const COVERED_PLUGINS = ['woocommerce', 'wordpress-seo', 'wordpress-seo-premium', 'seo-by-rank-math', 'seo-by-rank-math-pro', 'redirection'];

    /** @var string */
    private $lang;

    /** @var string */
    private $tz;

    /** @var string */
    private $weightUnit;

    /** @var string */
    private $dimUnit;

    /** @var bool */
    private $includesTax;

    /** @var array<string,string> */
    private $legalPages;

    /** @var string */
    private $siteUrl;

    /**
     * @param array<string,mixed> $context locale («es_ES»), timezone, weight_unit, dimension_unit, prices_include_tax (bool),
     *                                     legal_pages (id de página => terms|privacy|returns…), site_url
     */
    public function __construct(array $context = [])
    {
        $this->lang = self::lang((string) ($context['locale'] ?? ''));
        $zone = (string) ($context['timezone'] ?? '');
        $this->tz = $zone !== '' ? $zone : 'UTC';
        $this->weightUnit = (string) ($context['weight_unit'] ?? 'kg');
        $this->dimUnit = (string) ($context['dimension_unit'] ?? 'cm');
        $this->includesTax = !empty($context['prices_include_tax']);
        $this->legalPages = is_array($context['legal_pages'] ?? null) ? $context['legal_pages'] : [];
        $this->siteUrl = rtrim((string) ($context['site_url'] ?? ''), '/');
    }

    // ------------------------------------------------------------------ utilidades

    /** «es_ES» → «es»; «pt_BR» → «pt-BR»; vacío (WordPress en inglés) → «en». */
    public static function lang(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '' || preg_match('/^([A-Za-z]{2,3})(?:-([A-Za-z]{2}))?/', $locale, $m) !== 1) {
            return 'en';
        }
        $base = strtolower($m[1]);
        if (isset($m[2]) && in_array($base, ['pt', 'zh'], true)) {
            return $base . '-' . strtoupper($m[2]);
        }

        return $base;
    }

    /** Un importe en céntimos a texto decimal («9559» → «95.59»). */
    public static function decimal(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Esquema de un resumen de contraseña de WordPress. */
    public static function passwordScheme(string $hash): string
    {
        if (strncmp($hash, '$P$', 3) === 0 || strncmp($hash, '$H$', 3) === 0 || strncmp($hash, '$wp$', 4) === 0) {
            return 'wordpress';
        }
        if (preg_match('/^\$2[abxy]\$/', $hash) === 1) {
            return 'bcrypt';
        }
        if (strncmp($hash, '$argon2', 7) === 0) {
            return 'argon2';
        }

        return 'unknown';
    }

    /** Identificador estable a partir de un texto («Tamaño» → «tamano»). */
    public static function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');

        return $slug !== '' ? substr($slug, 0, 50) : 'x';
    }

    /**
     * La zona horaria del sitio: el nombre de zona si lo hay y, si no, el desfase respecto a GMT («+01:00»).
     *
     * @param mixed $gmtOffset horas respecto a GMT (1, -3.5)
     */
    public static function timezone(string $name, $gmtOffset): string
    {
        $name = trim($name);
        if ($name !== '') {
            return $name;
        }
        $offset = (float) $gmtOffset;

        return sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', (int) abs($offset), (int) round((abs($offset) - (int) abs($offset)) * 60));
    }

    public function language(): string
    {
        return $this->lang;
    }

    /**
     * @param mixed $text
     *
     * @return array<string,string>
     */
    public function t($text): array
    {
        return [$this->lang => (string) $text];
    }

    /** Texto plano: sin etiquetas y con las entidades resueltas («Muebles &amp; Hogar» → «Muebles & Hogar»). */
    public function plain($html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * La ruta de un enlace permanente («https://tienda.test/producto/mesa/» → «/producto/mesa/»). Vacío si no hay ruta que
     * conservar (la portada, un enlace con ?p=12).
     *
     * @param mixed $url
     *
     * @return string[]
     */
    public function paths($url): array
    {
        $url = trim((string) $url);
        if ($url === '' || strpos($url, '?') !== false) {
            return [];
        }
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && strlen($path) > 1 && $path[0] === '/' ? [$path] : [];
    }

    /**
     * Fecha del origen al formato neutro: la de GMT si viene; si no, la local con la zona del sitio.
     *
     * @param mixed $gmt
     * @param mixed $local
     */
    public function date($gmt, $local = null): ?string
    {
        $g = trim((string) $gmt);
        if ($g !== '' && strncmp($g, '0000-00-00', 10) !== 0) {
            return Neutral::utc($g, 'UTC');
        }

        return Neutral::utc(trim((string) $local), $this->tz);
    }

    /**
     * @param mixed $value
     *
     * @return string[]
     */
    private function ids($value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $out[] = (string) (int) $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $meta lista de «meta_data» de la API: [{key, value}]
     *
     * @return array<string,mixed>
     */
    private function metaMap($meta): array
    {
        $map = [];
        foreach (is_array($meta) ? $meta : [] as $entry) {
            if (is_array($entry) && isset($entry['key']) && is_string($entry['key'])) {
                $map[$entry['key']] = $entry['value'] ?? '';
            }
        }

        return $map;
    }

    /**
     * El control de existencias de un producto con variaciones. En WooCommerce se controla en el padre, en cada variación o en las dos
     * cosas; aquí el control es del producto («manage_stock») y la cifra, de cada variante.
     *
     * - Control solo en las variaciones: el producto controla existencias y su cifra es la suma de las de sus variantes (no 0: con el
     *   control apagado aquí las variantes serían infinitas). Una variación sin control propio no puede ser infinita al lado de otras con él: queda
     *   con 0 y se apunta en extra.variants_without_stock_control.
     * - Control en el padre y no en las variaciones: las existencias son compartidas y aquí cada variante tiene las suyas. Cada variación
     *   sin control propio recibe la cifra entera del padre (se puede comprar hasta esa cantidad en cualquiera) y queda apuntado en
     *   extra.shared_stock, que usa el escritor para avisar y para no contarlas varias veces en la comprobación de cifras.
     * - En ninguno: sin control, como siempre.
     *
     * @param array<string,mixed>            $p
     * @param array<int,array<string,mixed>> $variations las variaciones del origen
     * @param array<int,array<string,mixed>> $variants   las variantes neutras (se cambian)
     * @param array<string,mixed>            $extra
     */
    private function variableStock(array $p, array $variations, array &$variants, bool &$manage, int &$stock, array &$extra): void
    {
        if ($variants === []) {
            return;
        }
        $parent = ($p['manage_stock'] ?? false) === true;
        $own = [];
        foreach ($variations as $i => $v) {
            if (!is_array($v)) {
                continue;
            }
            $id = (string) ($v['id'] ?? $i);
            foreach ($variants as $k => $variant) {
                if ((string) $variant['id'] === $id) {
                    $own[$k] = ($v['manage_stock'] ?? false) === true;
                    break;
                }
            }
        }
        if (!$parent && count(array_filter($own)) === 0) {
            return;
        }
        $manage = true;
        $without = [];
        foreach ($variants as $k => $variant) {
            if (empty($own[$k])) {
                $without[] = $k;
            }
        }
        if ($parent && $without !== []) {
            foreach ($without as $k) {
                $variants[$k]['stock'] = $stock;
            }
            $extra['shared_stock'] = ['quantity' => $stock, 'variants' => count($without)];

            return;
        }
        if (!$parent) {
            $stock = 0;
            foreach ($variants as $variant) {
                $stock += (int) $variant['stock'];
            }
            if ($without !== []) {
                $extra['variants_without_stock_control'] = count($without);
            }
        }
    }

    /**
     * Títulos y descripciones de posicionamiento (Yoast, Rank Math). Una plantilla con variables («%title% %sep%») no se
     * puede evaluar aquí: va a $extra.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $extra
     *
     * @return array<string,array<string,string>>
     */
    private function seo(array $item, array &$extra): array
    {
        $meta = $this->metaMap($item['meta_data'] ?? []);
        $yoast = is_array($item['yoast_head_json'] ?? null) ? $item['yoast_head_json'] : [];
        $candidates = [
            'meta_title' => [$yoast['title'] ?? '', $meta['_yoast_wpseo_title'] ?? '', $meta['rank_math_title'] ?? ''],
            'meta_description' => [$yoast['description'] ?? '', $meta['_yoast_wpseo_metadesc'] ?? '', $meta['rank_math_description'] ?? ''],
        ];
        $out = [];
        foreach ($candidates as $field => $values) {
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                if (strpos($value, '%%') !== false || preg_match('/%[a-z_]+%/i', $value) === 1) {
                    $extra['seo_templates'][$field] = $value;
                    continue;
                }
                $out[$field] = $this->t($this->plain($value));
                break;
            }
        }

        return $out;
    }

    private function lookupValueId(int $attributeId, string $text, array $terms): string
    {
        foreach (isset($terms[$attributeId]) && is_array($terms[$attributeId]) ? $terms[$attributeId] : [] as $term) {
            if (is_array($term) && (strcasecmp((string) ($term['slug'] ?? ''), $text) === 0 || strcasecmp((string) ($term['name'] ?? ''), $text) === 0)) {
                return (string) $term['id'];
            }
        }

        return self::slug($text);
    }

    /** Identificador neutro de un atributo: el número del global; «l-nombre» el que solo existe en un producto. */
    private function attributeRef(array $a): string
    {
        $id = (int) ($a['id'] ?? 0);

        return $id > 0 ? (string) $id : 'l-' . self::slug((string) ($a['name'] ?? ''));
    }

    /**
     * @param array<int,array<string,mixed>> $images
     * @param string[]                       $keys
     * @param mixed                          $image
     */
    private function addImage(array &$images, array &$keys, $image): ?int
    {
        if (!is_array($image)) {
            return null;
        }
        $src = trim((string) ($image['src'] ?? ''));
        $path = trim((string) ($image['path'] ?? ''));
        if ($src === '' && $path === '') {
            return null;
        }
        $id = (int) ($image['id'] ?? 0);
        if ($id === 0 && strpos($src, 'woocommerce-placeholder') !== false) {
            return null; // la imagen de relleno que pone WooCommerce a lo que no tiene foto
        }
        $key = $id > 0 ? 'id:' . $id : 'src:' . ($path !== '' ? $path : $src);
        $at = array_search($key, $keys, true);
        if ($at !== false) {
            return (int) $at;
        }
        $entry = $path !== '' ? ['path' => $path] : ['url' => $src];
        $alt = trim((string) ($image['alt'] ?? ''));
        if ($alt !== '') {
            $entry['alt'] = $this->t($alt);
        }
        $entry['position'] = count($images);
        $entry['cover'] = $images === [];
        $images[] = $entry;
        $keys[] = $key;

        return count($images) - 1;
    }

    /**
     * @param array<string,mixed> $x
     *
     * @return array{0:?int,1:?int,2:?int,3:?int} precio normal y rebajado en céntimos (null = no tiene) y, en 2 y 3, los mismos en
     *                                            millonésimas cuando el texto del origen tiene más de dos decimales (null = no hace falta)
     */
    private function prices(array $x): array
    {
        $regular = trim((string) ($x['regular_price'] ?? ''));
        $sale = trim((string) ($x['sale_price'] ?? ''));
        $price = trim((string) ($x['price'] ?? ''));
        if ($regular === '' && $sale === '' && $price !== '') {
            $regular = $price;
        }

        return [
            $regular === '' ? null : Neutral::cents($regular), $sale === '' ? null : Neutral::cents($sale),
            self::preciseMicros($regular), self::preciseMicros($sale),
        ];
    }

    /**
     * Las millonésimas de un importe escrito con más de dos decimales («82.6446»); null si tiene dos o menos (el céntimo ya es exacto) o
     * no es un número.
     */
    private static function preciseMicros(string $text): ?int
    {
        $text = str_replace(',', '.', trim($text));

        return preg_match('/^[+-]?\d*\.\d{3,}$/', $text) === 1 ? Neutral::micros($text) : null;
    }

    // ------------------------------------------------------------------ productos

    /**
     * Un producto de wc/v3 (con sus variaciones) al formato neutro.
     *
     * @param array<string,mixed>                  $p          producto (la copia de la base de datos arma la misma forma)
     * @param array<int,array<string,mixed>>       $variations sus variaciones
     * @param array<int,array<int,array<string,mixed>>> $terms  términos de cada atributo global: [id de atributo => [{id, name, slug}]]
     *
     * @return array<string,mixed>
     */
    public function product(array $p, array $variations = [], array $terms = []): array
    {
        $status = (string) ($p['status'] ?? 'publish');
        if ($status === 'trash' || $status === 'auto-draft') {
            throw new \InvalidArgumentException('product in the trash or an automatic draft');
        }
        $name = $this->plain($p['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('product without a name');
        }
        $type = (string) ($p['type'] ?? 'simple');
        $extra = [];
        $kind = !empty($p['downloadable']) ? 'digital' : (!empty($p['virtual']) ? 'service' : 'physical');
        $hasVariants = $type === 'variable' && $variations !== [];

        $images = [];
        $imageKeys = [];
        foreach (is_array($p['images'] ?? null) ? $p['images'] : [] as $image) {
            $this->addImage($images, $imageKeys, $image);
        }

        list($regular, $sale, $regularMicros, $saleMicros) = $this->prices($p);
        if ($regular === null) {
            $min = null;
            $minMicros = null;
            foreach ($variations as $variation) {
                list($vr, $vs, $vrMicros, $vsMicros) = $this->prices($variation);
                $effective = $vs !== null ? $vs : $vr;
                if ($effective !== null && ($min === null || $effective < $min)) {
                    $min = $effective;
                    $minMicros = $vs !== null ? $vsMicros : $vrMicros;
                }
            }
            $regular = $min !== null ? $min : 0;
            $regularMicros = $minMicros;
        }

        $manage = ($p['manage_stock'] ?? false) === true;
        $stock = $manage && isset($p['stock_quantity']) && is_numeric($p['stock_quantity']) ? (int) $p['stock_quantity'] : 0;
        $backorders = in_array($p['backorders'] ?? 'no', ['no', 'notify', 'yes'], true) ? (string) ($p['backorders'] ?? 'no') : 'no';
        $weight = Neutral::grams($p['weight'] ?? '', $this->weightUnit);
        $dims = is_array($p['dimensions'] ?? null) ? $p['dimensions'] : [];

        // Atributos: los de variaciones van a las variantes; los demás, al producto. Los que solo existen en este producto
        // (id 0) no tienen registro propio: se apuntan en extra.local_attributes para quien escriba.
        $attributes = [];
        foreach (is_array($p['attributes'] ?? null) ? $p['attributes'] : [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $id = (int) ($a['id'] ?? 0);
            $ref = $this->attributeRef($a);
            $options = array_map('strval', is_array($a['options'] ?? null) ? array_values($a['options']) : []);
            $isVariation = !empty($a['variation']);
            if ($id === 0) {
                $extra['local_attributes'][] = ['id' => $ref, 'name' => (string) ($a['name'] ?? ''), 'options' => $options, 'variation' => $isVariation];
            }
            if ($isVariation && $hasVariants) {
                continue;
            }
            $attributes[] = [
                'attribute_id' => $ref,
                'value_ids' => array_map(function (string $option) use ($id, $terms): string {
                    return $this->lookupValueId($id, $option, $terms);
                }, $options),
            ];
        }

        $variants = [];
        $dropped = [];
        foreach ($variations as $i => $v) {
            $values = [];
            foreach (is_array($v['attributes'] ?? null) ? $v['attributes'] : [] as $a) {
                if (!is_array($a) || trim((string) ($a['option'] ?? '')) === '') {
                    continue;
                }
                $id = (int) ($a['id'] ?? 0);
                $values[] = ['attribute_id' => $this->attributeRef($a), 'value_id' => $this->lookupValueId($id, (string) $a['option'], $terms)];
            }
            if ($values === []) {
                $dropped[] = (string) ($v['id'] ?? $i);
                continue;
            }
            list($vr, $vs, $vrMicros, $vsMicros) = $this->prices($v);
            if ($vr === null) {
                $vrMicros = $regularMicros;
            }
            $vr = $vr !== null ? $vr : $regular;
            $variant = [
                'id' => (string) ($v['id'] ?? $i), 'sku' => (string) ($v['sku'] ?? ''), 'gtin' => (string) ($v['global_unique_id'] ?? ''),
                'price' => $vs !== null ? $vs : $vr, 'compare_price' => $vs !== null ? $vr : null, 'cost' => null,
                'stock' => ($v['manage_stock'] ?? false) === true && is_numeric($v['stock_quantity'] ?? null) ? (int) $v['stock_quantity'] : 0,
                'weight' => trim((string) ($v['weight'] ?? '')) !== '' ? Neutral::grams($v['weight'], $this->weightUnit) : $weight,
                'image_index' => $this->addImage($images, $imageKeys, $v['image'] ?? null),
                'active' => (string) ($v['status'] ?? 'publish') === 'publish', 'position' => count($variants), 'values' => $values,
            ];
            // Con más precisión que el céntimo: el escritor convierte impuestos desde las millonésimas (solo los campos que las tienen).
            $variantMicros = $vs !== null ? $vsMicros : $vrMicros;
            if ($variantMicros !== null) {
                $variant['price_micros'] = $variantMicros;
            }
            if ($vs !== null && $vrMicros !== null) {
                $variant['compare_price_micros'] = $vrMicros;
            }
            $variants[] = $variant;
        }
        if ($dropped !== []) {
            $extra['dropped_variations'] = $dropped;
        }
        $this->variableStock($p, $variations, $variants, $manage, $stock, $extra);

        $tags = [];
        foreach (is_array($p['tags'] ?? null) ? $p['tags'] : [] as $tag) {
            if (is_array($tag) && trim((string) ($tag['name'] ?? '')) !== '') {
                $tags[] = $this->plain($tag['name']);
            }
        }
        $categoryIds = [];
        $categoryPrimary = null;
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $category) {
            if (is_array($category) && (int) ($category['id'] ?? 0) > 0) {
                $categoryIds[] = (int) $category['id'];
            }
        }
        sort($categoryIds);
        $categoryIds = array_map('strval', $categoryIds);
        $primary = $this->metaMap($p['meta_data'] ?? [])['_yoast_wpseo_primary_product_cat'] ?? '';
        $categoryPrimary = in_array((string) $primary, $categoryIds, true) ? (string) $primary : ($categoryIds !== [] ? $categoryIds[0] : null);
        $brand = null;
        foreach (is_array($p['brands'] ?? null) ? $p['brands'] : [] as $b) {
            if (is_array($b) && (int) ($b['id'] ?? 0) > 0) {
                $brand = (string) (int) $b['id'];
                break;
            }
        }

        $files = [];
        foreach (is_array($p['downloads'] ?? null) ? $p['downloads'] : [] as $download) {
            if (is_array($download) && trim((string) ($download['file'] ?? '')) !== '') {
                $files[] = ['name' => trim((string) ($download['name'] ?? '')) !== '' ? (string) $download['name'] : basename((string) $download['file']), 'url' => (string) $download['file']];
            }
        }

        // Lo que no tiene campo: solo cuando dice algo (igual en la API y en la copia).
        $taxStatus = (string) ($p['tax_status'] ?? 'taxable');
        if ($taxStatus !== 'taxable' && $taxStatus !== '') {
            $extra['tax_status'] = $taxStatus;
        }
        $stockStatus = (string) ($p['stock_status'] ?? 'instock');
        if ($stockStatus !== 'instock' && $stockStatus !== '') {
            $extra['stock_status'] = $stockStatus;
        }
        foreach (['purchase_note', 'shipping_class'] as $key) {
            if (trim((string) ($p[$key] ?? '')) !== '') {
                $extra[$key] = (string) $p[$key];
            }
        }
        if ((int) ($p['menu_order'] ?? 0) !== 0) {
            $extra['menu_order'] = (int) $p['menu_order'];
        }
        if (isset($p['download_limit']) && (int) $p['download_limit'] > 0) {
            $extra['download_limit'] = (int) $p['download_limit'];
        }
        if (isset($p['download_expiry']) && (int) $p['download_expiry'] > 0) {
            $extra['download_expiry'] = (int) $p['download_expiry'];
        }
        if ($type === 'grouped') {
            $extra['import_note'] = 'grouped product brought as a simple product';
            $extra['grouped_products'] = $this->ids($p['grouped_products'] ?? []);
        } elseif ($type === 'external') {
            $extra['import_note'] = 'external product brought as a simple product';
            $extra['external_url'] = (string) ($p['external_url'] ?? '');
            $extra['button_text'] = (string) ($p['button_text'] ?? '');
        } elseif ($type !== 'simple' && $type !== 'variable') {
            $extra['original_type'] = $type;
        }
        if (!in_array($status, ['publish', 'draft', 'private'], true)) {
            $extra['original_status'] = $status;
        }
        $seo = $this->seo($p, $extra);

        $taxClass = (string) ($p['tax_class'] ?? '');
        $record = [
            'id' => (string) ($p['id'] ?? ''), 'updated_at' => $this->date($p['date_modified_gmt'] ?? '', $p['date_modified'] ?? ''),
            'created_at' => $this->date($p['date_created_gmt'] ?? '', $p['date_created'] ?? ''),
            'published_at' => $status === 'publish' ? $this->date($p['date_created_gmt'] ?? '', $p['date_created'] ?? '') : null,
            'kind' => $kind, 'sku' => (string) ($p['sku'] ?? ''), 'gtin' => (string) ($p['global_unique_id'] ?? ''), 'mpn' => '',
            'brand_id' => $brand, 'category_ids' => $categoryIds, 'main_category_id' => $categoryPrimary,
            'tax_class_id' => $taxStatus === 'none' ? null : ($taxClass === '' ? 'standard' : $taxClass),
            'name' => $this->t($name), 'summary' => $this->t($p['short_description'] ?? ''), 'description' => $this->t($p['description'] ?? ''),
            'slug' => $this->t($p['slug'] ?? ''),
            'price' => $regular, 'price_includes_tax' => $this->includesTax, 'compare_price' => null, 'sale_price' => $sale,
            'sale_from' => $sale !== null ? $this->date($p['date_on_sale_from_gmt'] ?? '', $p['date_on_sale_from'] ?? '') : null,
            'sale_to' => $sale !== null ? $this->date($p['date_on_sale_to_gmt'] ?? '', $p['date_on_sale_to'] ?? '') : null,
            'cost' => null, 'manage_stock' => $manage, 'stock' => $stock, 'backorders' => $backorders, 'min_qty' => 1,
            'max_qty' => !empty($p['sold_individually']) ? 1 : null,
            'weight' => $weight, 'length' => Neutral::millimetres($dims['length'] ?? '', $this->dimUnit),
            'width' => Neutral::millimetres($dims['width'] ?? '', $this->dimUnit), 'height' => Neutral::millimetres($dims['height'] ?? '', $this->dimUnit),
            'status' => $status === 'publish' ? 'published' : ($status === 'private' ? 'private' : 'draft'),
            'visibility' => in_array($p['catalog_visibility'] ?? 'visible', ['visible', 'catalog', 'search', 'hidden'], true) ? (string) ($p['catalog_visibility'] ?? 'visible') : 'visible',
            'featured' => !empty($p['featured']), 'reviews_allowed' => !empty($p['reviews_allowed']),
            'images' => $images, 'attributes' => $attributes, 'tags' => $tags === [] ? [] : [$this->lang => $tags], 'files' => $files, 'variants' => $variants,
            'related_ids' => $this->ids($p['related_ids'] ?? []), 'upsell_ids' => $this->ids($p['upsell_ids'] ?? []),
            'cross_sell_ids' => $this->ids($p['cross_sell_ids'] ?? []),
        ] + $seo;
        if ($regularMicros !== null) {
            $record['price_micros'] = $regularMicros;
        }
        if ($sale !== null && $saleMicros !== null) {
            $record['sale_price_micros'] = $saleMicros;
        }
        $paths = $this->paths($p['permalink'] ?? '');
        if ($paths !== []) {
            $record['old_paths'] = $paths;
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /**
     * Un producto de la API pública de la tienda (wc/store/v1) al formato neutro. Esa API da los precios en unidades menores
     * como texto y no da existencias exactas, costes, fechas, tipo digital ni borradores: lo que no da se queda sin rellenar.
     *
     * @param array<string,mixed>            $p
     * @param array<int,array<string,mixed>> $variations variaciones (los productos «variation» de la misma API)
     *
     * @return array<string,mixed>
     */
    public function storeProduct(array $p, array $variations = []): array
    {
        $name = $this->plain($p['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('product without a name');
        }
        $type = (string) ($p['type'] ?? 'simple');
        $extra = [];
        $prices = is_array($p['prices'] ?? null) ? $p['prices'] : [];
        $unit = isset($prices['currency_minor_unit']) ? (int) $prices['currency_minor_unit'] : 2;
        $regular = self::minorToCents($prices['regular_price'] ?? '', $unit);
        $sale = !empty($p['on_sale']) ? self::minorToCents($prices['sale_price'] ?? '', $unit) : null;
        if ($regular === null) {
            $range = is_array($prices['price_range'] ?? null) ? $prices['price_range'] : [];
            $regular = self::minorToCents($range['min_amount'] ?? ($prices['price'] ?? ''), $unit);
        }
        if ($sale !== null && $sale === $regular) {
            $sale = null;
        }

        $images = [];
        $keys = [];
        foreach (is_array($p['images'] ?? null) ? $p['images'] : [] as $image) {
            $this->addImage($images, $keys, $image);
        }

        // Atributos de la tienda: con términos (id y slug). El valor de una variación llega como slug del término.
        $byName = [];
        $attributes = [];
        $hasVariants = $variations !== [];
        foreach (is_array($p['attributes'] ?? null) ? $p['attributes'] : [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $id = (int) ($a['id'] ?? 0);
            $ref = $this->attributeRef($a);
            $termsList = is_array($a['terms'] ?? null) ? $a['terms'] : [];
            $byName[strtolower((string) ($a['name'] ?? ''))] = ['ref' => $ref, 'id' => $id, 'terms' => $termsList];
            if ($id === 0) {
                $extra['local_attributes'][] = ['id' => $ref, 'name' => (string) ($a['name'] ?? ''), 'options' => array_map(static function ($t): string {
                    return is_array($t) ? (string) ($t['name'] ?? '') : '';
                }, $termsList), 'variation' => !empty($a['has_variations'])];
            }
            if (!empty($a['has_variations']) && $hasVariants) {
                continue;
            }
            $values = [];
            foreach ($termsList as $term) {
                if (is_array($term)) {
                    $values[] = $id > 0 && (int) ($term['id'] ?? 0) > 0 ? (string) (int) $term['id'] : self::slug((string) ($term['name'] ?? ''));
                }
            }
            $attributes[] = ['attribute_id' => $ref, 'value_ids' => $values];
        }

        $variants = [];
        foreach ($variations as $v) {
            $values = [];
            foreach (is_array($v['attributes'] ?? null) ? $v['attributes'] : [] as $a) {
                $key = strtolower((string) (is_array($a) ? ($a['name'] ?? '') : ''));
                if (!isset($byName[$key]) || trim((string) $a['value']) === '') {
                    continue;
                }
                $valueId = self::slug((string) $a['value']);
                foreach ($byName[$key]['terms'] as $term) {
                    if (is_array($term) && strcasecmp((string) ($term['slug'] ?? ''), (string) $a['value']) === 0 && (int) ($term['id'] ?? 0) > 0 && $byName[$key]['id'] > 0) {
                        $valueId = (string) (int) $term['id'];
                    }
                }
                $values[] = ['attribute_id' => $byName[$key]['ref'], 'value_id' => $valueId];
            }
            if ($values === []) {
                continue;
            }
            $vp = is_array($v['prices'] ?? null) ? $v['prices'] : [];
            $vu = isset($vp['currency_minor_unit']) ? (int) $vp['currency_minor_unit'] : $unit;
            $vr = self::minorToCents($vp['regular_price'] ?? '', $vu);
            $vs = !empty($v['on_sale']) ? self::minorToCents($vp['sale_price'] ?? '', $vu) : null;
            $vr = $vr !== null ? $vr : ($regular !== null ? $regular : 0);
            $vimg = is_array($v['images'] ?? null) && isset($v['images'][0]) ? $v['images'][0] : null;
            $variants[] = [
                'id' => (string) ($v['id'] ?? count($variants)), 'sku' => (string) ($v['sku'] ?? ''), 'price' => $vs !== null && $vs !== $vr ? $vs : $vr,
                'compare_price' => $vs !== null && $vs !== $vr ? $vr : null, 'stock' => 0, 'weight' => 0,
                'image_index' => $this->addImage($images, $keys, $vimg), 'active' => true, 'position' => count($variants), 'values' => $values,
            ];
        }

        $categoryIds = [];
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $category) {
            if (is_array($category) && (int) ($category['id'] ?? 0) > 0) {
                $categoryIds[] = (int) $category['id'];
            }
        }
        sort($categoryIds);
        $categoryIds = array_map('strval', $categoryIds);
        $tags = [];
        foreach (is_array($p['tags'] ?? null) ? $p['tags'] : [] as $tag) {
            if (is_array($tag) && trim((string) ($tag['name'] ?? '')) !== '') {
                $tags[] = $this->plain($tag['name']);
            }
        }
        $brand = null;
        foreach (is_array($p['brands'] ?? null) ? $p['brands'] : [] as $b) {
            if (is_array($b) && (int) ($b['id'] ?? 0) > 0) {
                $brand = (string) (int) $b['id'];
                break;
            }
        }
        if (isset($p['is_in_stock']) && $p['is_in_stock'] === false) {
            $extra['stock_status'] = 'outofstock';
        } elseif (!empty($p['is_on_backorder'])) {
            $extra['stock_status'] = 'onbackorder';
        }
        if ($type === 'grouped') {
            $extra['import_note'] = 'grouped product brought as a simple product';
            $extra['grouped_products'] = $this->ids($p['grouped_products'] ?? []);
        } elseif ($type === 'external') {
            $extra['import_note'] = 'external product brought as a simple product';
        }
        $dims = is_array($p['dimensions'] ?? null) ? $p['dimensions'] : [];

        $record = [
            'id' => (string) ($p['id'] ?? ''), 'kind' => 'physical', 'sku' => (string) ($p['sku'] ?? ''), 'brand_id' => $brand,
            'category_ids' => $categoryIds, 'main_category_id' => $categoryIds !== [] ? $categoryIds[0] : null,
            'name' => $this->t($name), 'summary' => $this->t($p['short_description'] ?? ''), 'description' => $this->t($p['description'] ?? ''),
            'slug' => $this->t($p['slug'] ?? ''),
            'price' => $regular !== null ? $regular : 0, 'price_includes_tax' => $this->includesTax, 'sale_price' => $sale,
            'manage_stock' => false, 'stock' => 0, 'backorders' => 'no', 'min_qty' => 1, 'max_qty' => !empty($p['sold_individually']) ? 1 : null,
            'weight' => Neutral::grams($p['weight'] ?? '', $this->weightUnit), 'length' => Neutral::millimetres($dims['length'] ?? '', $this->dimUnit),
            'width' => Neutral::millimetres($dims['width'] ?? '', $this->dimUnit), 'height' => Neutral::millimetres($dims['height'] ?? '', $this->dimUnit),
            'status' => 'published', 'visibility' => 'visible', 'images' => $images, 'attributes' => $attributes,
            'tags' => $tags === [] ? [] : [$this->lang => $tags], 'variants' => $variants,
        ];
        $paths = $this->paths($p['permalink'] ?? '');
        if ($paths !== []) {
            $record['old_paths'] = $paths;
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /** «10661» con 2 decimales menores → 10661 céntimos; sin decimales → ×100. Vacío = null. @param mixed $value */
    public static function minorToCents($value, int $unit): ?int
    {
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^-?\d+$/', $text) !== 1) {
            return null;
        }
        $n = (int) $text;
        if ($unit === 2) {
            return $n;
        }
        if ($unit < 2) {
            return $n * (int) (10 ** (2 - $unit));
        }

        return (int) round($n / (10 ** ($unit - 2)));
    }

    // ------------------------------------------------------------------ catálogo auxiliar

    /**
     * @param array<string,mixed> $c    categoría de wc/v3 (o de la API pública)
     * @param string[]            $paths rutas antiguas ya calculadas
     *
     * @return array<string,mixed>
     */
    public function category(array $c, array $paths = []): array
    {
        $name = $this->plain($c['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('category without a name');
        }
        $image = is_array($c['image'] ?? null) && trim((string) ($c['image']['src'] ?? '')) !== '' ? $this->imageEntry($c['image']) : null;
        $record = [
            'id' => (string) ($c['id'] ?? ''), 'parent_id' => (int) ($c['parent'] ?? 0) > 0 ? (string) (int) $c['parent'] : null,
            'name' => $this->t($name), 'description' => $this->t($c['description'] ?? ''), 'slug' => $this->t($c['slug'] ?? ''),
            'position' => max(0, (int) ($c['menu_order'] ?? 0)), 'active' => true, 'in_menu' => true, 'image' => $image,
        ];
        if ($paths !== []) {
            $record['old_paths'] = $paths;
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed>
     */
    public function brand(array $b): array
    {
        $name = $this->plain($b['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('brand without a name');
        }
        $image = is_array($b['image'] ?? null) && trim((string) ($b['image']['src'] ?? '')) !== '' ? $this->imageEntry($b['image']) : null;

        return [
            'id' => (string) ($b['id'] ?? ''), 'name' => $name, 'description' => $this->t($b['description'] ?? ''), 'logo' => $image,
            'active' => true, 'position' => max(0, (int) ($b['menu_order'] ?? 0)), 'slug' => $this->t($b['slug'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed>            $a     atributo global de wc/v3: {id, name, slug, type, order_by}
     * @param array<int,array<string,mixed>> $terms sus términos: {id, name, slug, menu_order}
     *
     * @return array<string,mixed>
     */
    public function attribute(array $a, array $terms): array
    {
        $name = $this->plain($a['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('attribute without a name');
        }
        $values = [];
        foreach ($terms as $i => $term) {
            if (is_array($term) && isset($term['id'])) {
                $values[] = ['id' => (string) $term['id'], 'label' => $this->t($this->plain($term['name'] ?? '')), 'position' => count($values)];
            }
        }
        $code = (string) ($a['slug'] ?? '');
        if (strncmp($code, 'pa_', 3) === 0) {
            $code = substr($code, 3);
        }

        return [
            'id' => (string) ($a['id'] ?? ''), 'code' => $code, 'name' => $this->t($name), 'type' => (string) ($a['type'] ?? 'select') === 'color' ? 'color' : 'select',
            'unit' => '', 'variant' => true, 'filterable' => true, 'visible' => true, 'position' => max(0, (int) ($a['position'] ?? 0)), 'values' => $values,
        ];
    }

    /**
     * @param array<string,mixed> $image {src, path, alt}
     *
     * @return array<string,mixed>
     */
    private function imageEntry(array $image): array
    {
        $path = trim((string) ($image['path'] ?? ''));
        $entry = $path !== '' ? ['path' => $path] : ['url' => trim((string) ($image['src'] ?? ''))];
        if (trim((string) ($image['alt'] ?? '')) !== '') {
            $entry['alt'] = $this->t($image['alt']);
        }

        return $entry;
    }

    // ------------------------------------------------------------------ impuestos y envíos

    /** @return array<string,mixed> */
    public function taxClass(string $slug, string $name): array
    {
        $slug = $slug === '' ? 'standard' : $slug;

        return ['id' => $slug, 'code' => $slug, 'name' => $name !== '' ? $name : ucfirst(str_replace('-', ' ', $slug)), 'is_default' => $slug === 'standard'];
    }

    /**
     * Códigos postales de WooCommerce («35*», «51000...51999») al formato neutro («35», «51000-51999»).
     *
     * @param mixed $postcodes lista o texto separado por «;»
     */
    public function postcodes($postcodes): string
    {
        $list = is_array($postcodes) ? $postcodes : explode(';', (string) $postcodes);
        $out = [];
        foreach ($list as $code) {
            $code = str_replace(['...', '*', ' '], ['-', '', ''], trim((string) $code));
            $code = (string) preg_replace('/[^0-9A-Za-z\-]/', '', $code);
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return implode(',', $out);
    }

    /**
     * @param array<string,mixed> $t tipo de impuesto de wc/v3 (la copia arma la misma forma)
     *
     * @return array<string,mixed>
     */
    public function taxRate(array $t): array
    {
        $countries = array_values(array_filter(array_map('trim', explode(';', strtoupper((string) ($t['country'] ?? ''))))));
        if ($countries === [] || preg_match('/^[A-Z]{2}$/', $countries[0]) !== 1) {
            throw new \InvalidArgumentException('tax rate without a country (it applies to every country)');
        }
        $states = array_values(array_filter(array_map('trim', explode(';', strtoupper((string) ($t['state'] ?? ''))))));
        $postcodes = isset($t['postcodes']) && is_array($t['postcodes']) && $t['postcodes'] !== [] ? $t['postcodes'] : (string) ($t['postcode'] ?? '');
        $extra = [];
        if (count($countries) > 1) {
            $extra['more_countries'] = array_slice($countries, 1);
        }
        if (count($states) > 1) {
            $extra['more_states'] = array_slice($states, 1);
        }
        $cities = isset($t['cities']) && is_array($t['cities']) ? array_values(array_filter(array_map('strval', $t['cities']))) : [];
        if ($cities !== []) {
            $extra['cities'] = $cities;
        }
        $class = (string) ($t['class'] ?? '');
        $record = [
            'id' => (string) ($t['id'] ?? ''), 'class_id' => $class === '' ? 'standard' : $class, 'country' => $countries[0],
            'region' => $states !== [] ? $states[0] : '', 'postcodes' => $this->postcodes($postcodes), 'rate' => Neutral::rate($t['rate'] ?? '0'),
            'name' => (string) ($t['name'] ?? ''), 'priority' => (int) ($t['priority'] ?? 1), 'active' => true, 'compound' => !empty($t['compound']),
            'applies_to_shipping' => !empty($t['shipping']),
        ];
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /**
     * @param array<string,mixed>            $z         zona: {id, name, order}
     * @param array<int,array<string,mixed>> $locations {code, type: country|state|postcode|continent}
     *
     * @return array<string,mixed>
     */
    public function zone(array $z, array $locations): array
    {
        $countries = [];
        $states = [];
        $postcodes = [];
        $continents = [];
        foreach ($locations as $l) {
            $code = (string) (is_array($l) ? ($l['code'] ?? '') : '');
            $type = (string) (is_array($l) ? ($l['type'] ?? '') : '');
            if ($type === 'country') {
                $countries[] = $code;
            } elseif ($type === 'state') {
                $parts = explode(':', $code, 2);
                $states[] = [$parts[0], isset($parts[1]) ? $parts[1] : ''];
            } elseif ($type === 'postcode') {
                $postcodes[] = $code;
            } elseif ($type === 'continent') {
                $continents[] = $code;
            }
        }
        $pc = $this->postcodes($postcodes);
        $list = [];
        foreach ($countries as $country) {
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $list[] = ['country' => $country, 'region' => '', 'postcodes' => $pc];
            }
        }
        foreach ($states as $state) {
            if (preg_match('/^[A-Z]{2}$/', $state[0]) === 1) {
                $list[] = ['country' => $state[0], 'region' => $state[1], 'postcodes' => $pc];
            }
        }
        $extra = [];
        if ($list === [] && $pc !== '') {
            $extra['postcodes_without_country'] = $pc;
        }
        if ($continents !== []) {
            $extra['continents'] = $continents;
        }
        $name = trim((string) ($z['name'] ?? ''));
        $record = ['id' => (string) ($z['id'] ?? ''), 'name' => $name !== '' ? $name : 'Zone ' . (string) ($z['id'] ?? ''), 'locations' => $list, 'position' => max(0, (int) ($z['order'] ?? 0)), 'active' => true];
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /**
     * Un método de envío de una zona → un transportista.
     *
     * @param array<string,mixed> $zone   {id, name}
     * @param array<string,mixed> $method {instance_id, method_id, title, enabled, order, settings (API: {clave:{value}}; copia: {clave: valor})}
     *
     * @return array<string,mixed>
     */
    public function carrier(array $zone, array $method): array
    {
        $methodId = (string) ($method['method_id'] ?? '');
        $settings = [];
        foreach (is_array($method['settings'] ?? null) ? $method['settings'] : [] as $key => $value) {
            $settings[(string) $key] = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
        }
        $zoneId = (string) ($zone['id'] ?? '');
        $title = trim((string) ($method['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($settings['title'] ?? ''));
        }
        if ($title === '') {
            $title = trim((string) ($method['method_title'] ?? $methodId));
        }
        $extra = [];
        $rates = [];
        $freeFrom = null;
        $taxClass = null;
        switch ($methodId) {
            case 'flat_rate':
            case 'local_pickup':
            case 'pickup_location':
            case 'legacy_local_pickup':
            case 'legacy_flat_rate':
                $kind = $methodId === 'flat_rate' || $methodId === 'legacy_flat_rate' ? 'rates' : 'pickup';
                $cost = trim((string) ($settings['cost'] ?? ''));
                $price = 0;
                $priceMicros = null;
                if ($cost !== '') {
                    if (preg_match('/^\d+(?:[.,]\d+)?$/', $cost) === 1) {
                        $price = Neutral::cents($cost);
                        $priceMicros = self::preciseMicros($cost);
                    } else {
                        $extra['cost_formula'] = $cost;
                    }
                }
                $rate = ['zone_id' => $zoneId, 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => $price];
                if ($priceMicros !== null) {
                    $rate['price_micros'] = $priceMicros;
                }
                $rates[] = $rate;
                foreach ($settings as $key => $value) {
                    if ((strncmp($key, 'class_cost_', 11) === 0 || $key === 'no_class_cost') && trim((string) $value) !== '') {
                        $extra['class_costs'][$key] = (string) $value;
                    }
                }
                if (trim((string) ($settings['type'] ?? '')) !== '' && $settings['type'] !== 'class') {
                    $extra['calculation_type'] = (string) $settings['type'];
                }
                $taxClass = (string) ($settings['tax_status'] ?? 'taxable') === 'taxable' ? 'standard' : null;
                break;
            case 'free_shipping':
                $kind = 'free';
                $requires = (string) ($settings['requires'] ?? '');
                if (in_array($requires, ['min_amount', 'either', 'both'], true) && trim((string) ($settings['min_amount'] ?? '')) !== '') {
                    $freeFrom = Neutral::cents($settings['min_amount']);
                }
                if ($requires === 'coupon' || $requires === 'either' || $requires === 'both') {
                    $extra['requires'] = $requires;
                }
                break;
            default:
                throw new \InvalidArgumentException('unsupported shipping method ' . $methodId);
        }
        $record = [
            'id' => (string) ($method['instance_id'] ?? ''), 'name' => $title, 'kind' => $kind, 'tax_class_id' => $taxClass, 'price_includes_tax' => false,
            'free_from' => $freeFrom, 'active' => ($method['enabled'] ?? true) === true || (string) ($method['enabled'] ?? '') === '1',
            'position' => max(0, (int) ($method['order'] ?? 0)), 'handling_fee' => 0, 'rates' => $rates, 'zone_ids' => [$zoneId],
        ];
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $g pasarela: {id, title, enabled, order, settings (API: {clave:{value}}; copia: {clave: valor})}
     *
     * @return array<string,mixed>
     */
    public function paymentMethod(array $g): array
    {
        $code = (string) ($g['id'] ?? '');
        if ($code === '') {
            throw new \InvalidArgumentException('payment gateway without an id');
        }
        $settings = [];
        foreach (is_array($g['settings'] ?? null) ? $g['settings'] : [] as $key => $value) {
            $settings[(string) $key] = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
        }
        $title = trim((string) ($g['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($settings['title'] ?? $code));
        }
        $sandbox = null;
        foreach (['testmode', 'sandbox', 'test_mode', 'environment'] as $key) {
            if (isset($settings[$key]) && $settings[$key] !== '') {
                $sandbox = in_array((string) $settings[$key], ['yes', '1', 'test', 'sandbox', 'true'], true);
                break;
            }
        }
        $enabled = $g['enabled'] ?? false;

        return [
            'id' => $code, 'code' => $code, 'title' => $this->t($title), 'enabled' => $enabled === true || (string) $enabled === 'yes' || (string) $enabled === '1',
            'sandbox' => $sandbox, 'settings' => $settings, 'min_amount' => null, 'max_amount' => null, 'countries' => [], 'position' => max(0, (int) ($g['order'] ?? 0)),
        ];
    }

    // ------------------------------------------------------------------ configuración

    /**
     * La configuración de la tienda.
     *
     * @param array<string,mixed> $o    opciones de WooCommerce y de WordPress, nombre => valor («woocommerce_currency» => «EUR»)
     * @param array<string,mixed> $site title, tagline, timezone_string, gmt_offset, admin_email, logo (imagen neutra), icon, next_order_number
     *
     * @return array<string,mixed>
     */
    public function settings(array $o, array $site = []): array
    {
        $country = explode(':', (string) ($o['woocommerce_default_country'] ?? ''), 2);
        $address = [
            'line1' => (string) ($o['woocommerce_store_address'] ?? ''), 'line2' => (string) ($o['woocommerce_store_address_2'] ?? ''),
            'postcode' => (string) ($o['woocommerce_store_postcode'] ?? ''), 'city' => (string) ($o['woocommerce_store_city'] ?? ''),
        ];
        if (preg_match('/^[A-Z]{2}$/', $country[0]) === 1) {
            $address['country'] = $country[0];
        }
        if (isset($country[1]) && $country[1] !== '') {
            $address['region'] = $country[1];
        }
        $zone = self::timezone((string) ($site['timezone_string'] ?? ''), $site['gmt_offset'] ?? 0);
        $weight = (string) ($o['woocommerce_weight_unit'] ?? 'kg');
        $dimension = (string) ($o['woocommerce_dimension_unit'] ?? 'cm');
        $based = (string) ($o['woocommerce_tax_based_on'] ?? 'shipping');
        $mail = [];
        $from = trim((string) ($o['woocommerce_email_from_address'] ?? ''));
        if ($from !== '') {
            $mail['from_email'] = $from;
        }
        if (trim((string) ($o['woocommerce_email_from_name'] ?? '')) !== '') {
            $mail['from_name'] = (string) $o['woocommerce_email_from_name'];
        }
        $legal = [];
        foreach (['terms' => 'woocommerce_terms_page_id', 'privacy' => 'wp_page_for_privacy_policy', 'returns' => 'woocommerce_refund_returns_page_id'] as $kind => $option) {
            if ((int) ($o[$option] ?? 0) > 0) {
                $legal[$kind] = (string) (int) $o[$option];
            }
        }
        $title = (string) ($site['title'] ?? '');
        $extra = [
            'calc_taxes' => ($o['woocommerce_calc_taxes'] ?? 'no') === 'yes', 'currency_position' => (string) ($o['woocommerce_currency_pos'] ?? ''),
            'price_decimals' => (int) ($o['woocommerce_price_num_decimals'] ?? 2),
        ];
        $record = [
            'id' => 'main', 'shop_name' => $title, 'email' => $from !== '' ? $from : (string) ($site['admin_email'] ?? ''), 'address' => $address,
            'currency' => (string) ($o['woocommerce_currency'] ?? 'EUR'), 'languages' => [$this->lang], 'default_language' => $this->lang, 'timezone' => $zone,
            'weight_unit' => in_array($weight, ['kg', 'g', 'lb', 'oz'], true) ? $weight : 'kg',
            'dimension_unit' => in_array($dimension, ['m', 'cm', 'mm', 'in'], true) ? $dimension : 'cm',
            'prices_include_tax' => ($o['woocommerce_prices_include_tax'] ?? 'no') === 'yes',
            'display_prices_with_tax' => ($o['woocommerce_tax_display_shop'] ?? 'excl') === 'incl',
            'tax_based_on' => $based === 'base' ? 'shop' : (in_array($based, ['shipping', 'billing'], true) ? $based : 'shipping'),
            'guest_checkout' => ($o['woocommerce_enable_guest_checkout'] ?? 'yes') === 'yes',
            'stock_management' => ($o['woocommerce_manage_stock'] ?? 'yes') === 'yes',
            'logo' => isset($site['logo']) && is_array($site['logo']) ? $site['logo'] : null,
            'icon' => isset($site['icon']) && is_array($site['icon']) ? $site['icon'] : null,
            'order_number_prefix' => '', 'mail' => $mail, 'legal_pages' => $legal,
            'home_title' => $title !== '' ? $this->t($title) : [], 'home_description' => trim((string) ($site['tagline'] ?? '')) !== '' ? $this->t($site['tagline']) : [],
            'extra' => $extra,
        ];
        if (isset($site['next_order_number']) && (int) $site['next_order_number'] > 0) {
            $record['order_number_next'] = (int) $site['next_order_number'];
        }

        return $record;
    }

    // ------------------------------------------------------------------ clientes, cupones, opiniones

    /**
     * @param array<string,mixed> $a dirección de wc/v3 (address_1, address_2, city, state, postcode, country…)
     *
     * @return array<string,mixed>
     */
    private function address(array $a): array
    {
        $out = [
            'first_name' => trim((string) ($a['first_name'] ?? '')), 'last_name' => trim((string) ($a['last_name'] ?? '')),
            'company' => trim((string) ($a['company'] ?? '')), 'phone' => trim((string) ($a['phone'] ?? '')),
            'line1' => trim((string) ($a['address_1'] ?? '')), 'line2' => trim((string) ($a['address_2'] ?? '')),
            'postcode' => trim((string) ($a['postcode'] ?? '')), 'city' => trim((string) ($a['city'] ?? '')), 'region' => trim((string) ($a['state'] ?? '')),
        ];
        $country = strtoupper(trim((string) ($a['country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $out['country'] = $country;
        }
        $vat = trim((string) ($a['vat_id'] ?? ''));
        if ($vat !== '') {
            $out['vat_id'] = $vat;
        }

        return $out;
    }

    private function hasAddress(array $a): bool
    {
        return trim((string) ($a['address_1'] ?? '') . (string) ($a['city'] ?? '') . (string) ($a['postcode'] ?? '')) !== '';
    }

    /**
     * @param array<string,mixed> $c    cliente de wc/v3
     * @param ?string             $hash resumen de la contraseña (solo la copia de la base de datos lo tiene)
     *
     * @return array<string,mixed>
     */
    public function customer(array $c, ?string $hash = null): array
    {
        $email = trim((string) ($c['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('customer without an email');
        }
        $billing = is_array($c['billing'] ?? null) ? $c['billing'] : [];
        $shipping = is_array($c['shipping'] ?? null) ? $c['shipping'] : [];
        $meta = $this->metaMap($c['meta_data'] ?? []);
        $vat = '';
        foreach (self::VAT_KEYS as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key]) && trim((string) $meta[$key]) !== '') {
                $vat = trim((string) $meta[$key]);
                break;
            }
        }
        $addresses = [];
        if ($this->hasAddress($billing)) {
            // WooCommerce no pone nombre a las direcciones: llevan estos dos, y quien las escribe (CustomersWriter) los pone en el
            // idioma de la tienda. El lector no traduce: no tiene tienda delante.
            $addresses[] = ['id' => 'billing', 'label' => 'Billing', 'default_billing' => true, 'default_shipping' => false] + $this->address($billing + ($vat !== '' ? ['vat_id' => $vat] : []));
        }
        if ($this->hasAddress($shipping)) {
            $addresses[] = ['id' => 'shipping', 'label' => 'Shipping', 'default_billing' => false, 'default_shipping' => true] + $this->address($shipping);
        }
        $first = trim((string) ($c['first_name'] ?? ''));
        $last = trim((string) ($c['last_name'] ?? ''));
        $record = [
            'id' => (string) ($c['id'] ?? ''), 'updated_at' => $this->date($c['date_modified_gmt'] ?? '', $c['date_modified'] ?? ''),
            'created_at' => $this->date($c['date_created_gmt'] ?? '', $c['date_created'] ?? ''), 'email' => $email,
            'first_name' => $first !== '' ? $first : trim((string) ($billing['first_name'] ?? '')),
            'last_name' => $last !== '' ? $last : trim((string) ($billing['last_name'] ?? '')),
            'company' => trim((string) ($billing['company'] ?? '')), 'vat_id' => $vat, 'phone' => trim((string) ($billing['phone'] ?? '')),
            'group_id' => null, 'active' => true, 'guest' => false, 'marketing' => false, 'marketing_at' => null,
            'password_hash' => $hash !== null && $hash !== '' ? $hash : null, 'notes' => '', 'last_login_at' => null,
            'addresses' => $addresses, 'consents' => [],
        ];
        if ($hash !== null && $hash !== '') {
            $record['password_scheme'] = self::passwordScheme($hash);
        }
        if (trim((string) ($c['locale'] ?? '')) !== '') {
            $record['locale'] = self::lang((string) $c['locale']);
        }
        if (trim((string) ($c['username'] ?? '')) !== '') {
            $record['extra'] = ['username' => (string) $c['username']];
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $c cupón de wc/v3
     *
     * @return array<string,mixed>
     */
    public function coupon(array $c): array
    {
        $code = trim((string) ($c['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('coupon without a code');
        }
        $type = (string) ($c['discount_type'] ?? 'fixed_cart');
        $amount = Neutral::cents($c['amount'] ?? '0');
        $unsupported = [];
        $kind = $type === 'percent' ? 'percent' : 'amount';
        if (!empty($c['free_shipping'])) {
            if ($amount === 0) {
                $kind = 'free_shipping';
            } else {
                $unsupported[] = 'free shipping together with a discount';
            }
        }
        if ($type === 'fixed_product') {
            $unsupported[] = 'fixed discount per product (applied here to the whole cart)';
        }
        if ($this->ids($c['excluded_product_ids'] ?? []) !== []) {
            $unsupported[] = 'excluded products: ' . implode(',', $this->ids($c['excluded_product_ids']));
        }
        if ($this->ids($c['excluded_product_categories'] ?? []) !== []) {
            $unsupported[] = 'excluded categories: ' . implode(',', $this->ids($c['excluded_product_categories']));
        }
        $emails = array_values(array_filter(array_map('strval', is_array($c['email_restrictions'] ?? null) ? $c['email_restrictions'] : [])));
        if ($emails !== []) {
            $unsupported[] = 'restricted to emails: ' . implode(',', $emails);
        }
        if ((int) ($c['limit_usage_to_x_items'] ?? 0) > 0) {
            $unsupported[] = 'limit of ' . (int) $c['limit_usage_to_x_items'] . ' items';
        }
        if (trim((string) ($c['maximum_amount'] ?? '')) !== '' && Neutral::cents($c['maximum_amount']) > 0) {
            $unsupported[] = 'maximum spend ' . trim((string) $c['maximum_amount']);
        }
        $min = trim((string) ($c['minimum_amount'] ?? ''));
        $limit = (int) ($c['usage_limit'] ?? 0);
        $perUser = (int) ($c['usage_limit_per_user'] ?? 0);

        return [
            'id' => (string) ($c['id'] ?? ''), 'updated_at' => $this->date($c['date_modified_gmt'] ?? '', $c['date_modified'] ?? ''), 'code' => $code,
            'kind' => $kind, 'percent' => $kind === 'percent' ? Neutral::rate($c['amount'] ?? '0') : 0, 'amount' => $kind === 'amount' ? $amount : 0,
            'amount_includes_tax' => false, 'min_subtotal' => $min !== '' && Neutral::cents($min) > 0 ? Neutral::cents($min) : null,
            'starts_at' => null, 'ends_at' => $this->date($c['date_expires_gmt'] ?? '', $c['date_expires'] ?? ''),
            'max_uses' => $limit > 0 ? $limit : null, 'max_uses_per_customer' => $perUser > 0 ? $perUser : null, 'uses' => max(0, (int) ($c['usage_count'] ?? 0)),
            'first_order_only' => false, 'exclude_sale' => !empty($c['exclude_sale_items']), 'combinable' => empty($c['individual_use']), 'customer_id' => null,
            'product_ids' => $this->ids($c['product_ids'] ?? []), 'category_ids' => $this->ids($c['product_categories'] ?? []),
            'active' => (string) ($c['status'] ?? 'publish') === 'publish', 'note' => $this->plain($c['description'] ?? ''), 'unsupported' => $unsupported,
        ];
    }

    /**
     * @param array<string,mixed> $r opinión de wc/v3: {id, product_id, status, reviewer, review, rating, verified, date_created_gmt}
     *
     * @return array<string,mixed>
     */
    public function review(array $r): array
    {
        $status = (string) ($r['status'] ?? 'approved');
        if ($status === 'trash') {
            throw new \InvalidArgumentException('review in the trash');
        }
        $rating = (int) ($r['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('review without a rating from 1 to 5');
        }
        if ((int) ($r['product_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('review without a product');
        }

        return [
            'id' => (string) ($r['id'] ?? ''), 'product_id' => (string) (int) $r['product_id'], 'author' => $this->plain($r['reviewer'] ?? ''), 'rating' => $rating,
            'title' => '', 'body' => $this->plain($r['review'] ?? ''), 'status' => $status === 'approved' ? 'approved' : ($status === 'spam' ? 'rejected' : 'pending'),
            'verified' => !empty($r['verified']), 'at' => $this->date($r['date_created_gmt'] ?? '', $r['date_created'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------ pedidos

    /**
     * Estado de WooCommerce → [estado tal cual (sin «wc-»), significado, ¿hay que avisar de que se desconoce?].
     *
     * @return array{0:string,1:string,2:bool}
     */
    public function orderState(string $status): array
    {
        $key = strtolower((string) preg_replace('/^wc-/', '', trim($status)));
        if (isset(self::STATES[$key])) {
            return [$key, self::STATES[$key], false];
        }
        if (isset(self::NAMED_STATES[$key])) {
            return [$key, self::NAMED_STATES[$key], false];
        }

        return [$key, 'processing', true];
    }

    /**
     * Un pedido de wc/v3 con sus devoluciones y sus notas.
     *
     * @param array<string,mixed>            $o       pedido (la copia de la base de datos arma la misma forma)
     * @param array<int,array<string,mixed>> $refunds devoluciones con sus líneas (orders/{id}/refunds)
     * @param array<int,array<string,mixed>> $notes   notas (orders/{id}/notes)
     *
     * @return array<string,mixed>
     */
    public function order(array $o, array $refunds = [], array $notes = []): array
    {
        $raw = strtolower((string) preg_replace('/^wc-/', '', trim((string) ($o['status'] ?? ''))));
        if (in_array($raw, ['trash', 'auto-draft', 'checkout-draft', 'draft', ''], true)) {
            throw new \InvalidArgumentException('order in the trash or an unfinished checkout');
        }
        $billing = is_array($o['billing'] ?? null) ? $o['billing'] : [];
        $shippingAddress = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
        $email = trim((string) ($billing['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('order without an email');
        }
        list($state, $meaning, $unknown) = $this->orderState($raw);
        $incl = !empty($o['prices_include_tax']);
        $placed = $this->date($o['date_created_gmt'] ?? '', $o['date_created'] ?? '');
        if ($placed === null) {
            throw new \InvalidArgumentException('order without a date');
        }

        $taxRates = [];
        foreach (is_array($o['tax_lines'] ?? null) ? $o['tax_lines'] : [] as $line) {
            if (is_array($line)) {
                $taxRates[(int) ($line['rate_id'] ?? 0)] = ['rate' => Neutral::rate($line['rate_percent'] ?? '0'), 'label' => (string) ($line['label'] ?? '')];
            }
        }

        $items = [];
        $byItemId = [];
        $baseByRate = [];
        $itemsTotal = 0;
        $discountTotal = 0;
        $shippingTotal = 0;
        $feeTotal = 0;
        $totalNet = 0;

        $addLine = function (array $l, string $kind) use (&$items, &$byItemId, &$baseByRate, &$totalNet, $incl, $taxRates): array {
            $qty = $kind === 'product' ? max(1, (int) ($l['quantity'] ?? 1)) : 1;
            $sub = Neutral::cents($kind === 'product' ? ($l['subtotal'] ?? '0') : ($l['total'] ?? '0'));
            $subTax = Neutral::cents($kind === 'product' ? ($l['subtotal_tax'] ?? '0') : ($l['total_tax'] ?? '0'));
            $net = Neutral::cents($l['total'] ?? '0');
            $tax = Neutral::cents($l['total_tax'] ?? '0');
            $rateId = 0;
            foreach (is_array($l['taxes'] ?? null) ? $l['taxes'] : [] as $t) {
                if (is_array($t) && Neutral::cents($t['total'] ?? '0') !== 0) {
                    $rateId = (int) ($t['id'] ?? 0);
                    $baseByRate[$rateId] = ($baseByRate[$rateId] ?? 0) + $net;
                    break;
                }
            }
            $gross = $sub + $subTax;
            $item = [
                'kind' => $kind, 'name' => $this->plain($kind === 'product' ? ($l['name'] ?? '') : ($l['method_title'] ?? $l['name'] ?? '')),
                'qty' => $qty, 'unit_price' => (int) round(($incl ? $gross : $sub) / $qty), 'discount' => $kind === 'product' ? ($incl ? $gross - ($net + $tax) : $sub - $net) : 0,
                'line_total' => $incl ? $net + $tax : $net, 'line_net' => $net, 'line_tax' => $tax,
            ];
            if ($item['name'] === '') {
                $item['name'] = $kind;
            }
            if ($rateId > 0 && isset($taxRates[$rateId])) {
                $item['tax_rate'] = $taxRates[$rateId]['rate'];
                if ($taxRates[$rateId]['label'] !== '') {
                    $item['tax_name'] = $taxRates[$rateId]['label'];
                }
            }
            if ($kind === 'product') {
                if ((int) ($l['product_id'] ?? 0) > 0) {
                    $item['product_id'] = (string) (int) $l['product_id'];
                }
                if ((int) ($l['variation_id'] ?? 0) > 0) {
                    $item['variant_id'] = (string) (int) $l['variation_id'];
                }
                $item['sku'] = (string) ($l['sku'] ?? '');
                $item['variant_label'] = $this->variantLabel($l['meta_data'] ?? []);
                $item['refunded_qty'] = 0;
                $item['refunded_total'] = 0;
            } elseif ($kind === 'shipping') {
                $item['sku'] = '';
            }
            $totalNet += $net;
            $items[] = $item;
            $byItemId[$kind . ':' . (int) ($l['id'] ?? 0)] = count($items) - 1;

            return [$incl ? $gross : $sub, $incl ? $net + $tax : $net];
        };
        foreach (is_array($o['line_items'] ?? null) ? $o['line_items'] : [] as $l) {
            if (is_array($l)) {
                $r = $addLine($l, 'product');
                $itemsTotal += $r[0];
                $discountTotal += $r[0] - $r[1];
            }
        }
        foreach (is_array($o['shipping_lines'] ?? null) ? $o['shipping_lines'] : [] as $l) {
            if (is_array($l)) {
                $shippingTotal += $addLine($l, 'shipping')[1];
            }
        }
        foreach (is_array($o['fee_lines'] ?? null) ? $o['fee_lines'] : [] as $l) {
            if (is_array($l)) {
                $feeTotal += $addLine($l, 'fee')[1];
            }
        }

        $taxes = [];
        foreach (is_array($o['tax_lines'] ?? null) ? $o['tax_lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $rateId = (int) ($line['rate_id'] ?? 0);
            $taxes[] = [
                'name' => (string) ($line['label'] ?? ''), 'rate' => $taxRates[$rateId]['rate'] ?? 0, 'base' => $baseByRate[$rateId] ?? 0,
                'amount' => Neutral::cents($line['tax_total'] ?? '0') + Neutral::cents($line['shipping_tax_total'] ?? '0'),
            ];
        }

        $coupons = [];
        foreach (is_array($o['coupon_lines'] ?? null) ? $o['coupon_lines'] : [] as $line) {
            if (is_array($line) && trim((string) ($line['code'] ?? '')) !== '') {
                $coupons[] = ['code' => (string) $line['code'], 'amount' => Neutral::cents($line['discount'] ?? '0')];
            }
        }

        $refundList = [];
        $refundedTotal = 0;
        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $amount = abs(Neutral::cents($refund['amount'] ?? ($refund['total'] ?? '0')));
            $tax = 0;
            $lines = [];
            foreach (['line_items' => 'product', 'shipping_lines' => 'shipping', 'fee_lines' => 'fee'] as $key => $kind) {
                foreach (is_array($refund[$key] ?? null) ? $refund[$key] : [] as $l) {
                    if (!is_array($l)) {
                        continue;
                    }
                    $meta = $this->metaMap($l['meta_data'] ?? []);
                    $original = $kind . ':' . (int) ($l['refunded_item_id'] ?? ($meta['_refunded_item_id'] ?? 0));
                    $net = abs(Neutral::cents($l['total'] ?? '0'));
                    $lineTax = abs(Neutral::cents($l['total_tax'] ?? '0'));
                    $tax += $lineTax;
                    if (!isset($byItemId[$original])) {
                        continue;
                    }
                    $at = $byItemId[$original];
                    $lineAmount = $incl ? $net + $lineTax : $net;
                    $qty = $kind === 'product' ? abs((int) ($l['quantity'] ?? 0)) : 0;
                    $lines[] = ['index' => $at, 'qty' => $qty, 'amount' => $lineAmount];
                    if ($kind === 'product') {
                        $items[$at]['refunded_qty'] += $qty;
                        $items[$at]['refunded_total'] += $lineAmount;
                    }
                }
            }
            $entry = ['amount' => $amount, 'tax' => $tax, 'reason' => $this->plain($refund['reason'] ?? ''), 'restock' => false, 'lines' => $lines];
            $at = $this->date($refund['date_created_gmt'] ?? '', $refund['date_created'] ?? '');
            if ($at !== null) {
                $entry['at'] = $at;
            }
            $refundList[] = $entry;
            $refundedTotal += $amount;
        }

        $history = [];
        foreach ($notes as $note) {
            if (!is_array($note)) {
                continue;
            }
            $at = $this->date($note['date_created_gmt'] ?? '', $note['date_created'] ?? '');
            if ($at === null) {
                continue;
            }
            $history[] = [
                'at' => $at, 'kind' => 'note', 'note' => $this->plain($note['note'] ?? ''), 'public' => !empty($note['customer_note']),
                'actor' => (string) ($note['author'] ?? ''),
            ];
        }

        $total = Neutral::cents($o['total'] ?? '0');
        $totalTax = Neutral::cents($o['total_tax'] ?? '0');
        $paidAt = $this->date($o['date_paid_gmt'] ?? '', $o['date_paid'] ?? '');
        $paid = $paidAt !== null || in_array($meaning, self::PAID, true);
        $method = (string) ($o['payment_method'] ?? '');
        $record = [
            'id' => (string) ($o['id'] ?? ''), 'updated_at' => $this->date($o['date_modified_gmt'] ?? '', $o['date_modified'] ?? ''),
            'number' => (string) (trim((string) ($o['number'] ?? '')) !== '' ? $o['number'] : ($o['id'] ?? '')), 'email' => $email,
            'currency' => (string) ($o['currency'] ?? 'EUR') !== '' ? (string) $o['currency'] : 'EUR', 'state' => $state, 'state_meaning' => $meaning,
            'payment_method' => $method, 'payment_title' => (string) ($o['payment_method_title'] ?? ''), 'prices_include_tax' => $incl,
            'items_total' => $itemsTotal, 'discount_total' => $discountTotal, 'shipping_total' => $shippingTotal, 'fee_total' => $feeTotal,
            'total_net' => $totalNet, 'total_tax' => $totalTax, 'total' => $total, 'paid_total' => $paid ? $total : 0, 'refunded_total' => $refundedTotal,
            'taxes' => $taxes, 'billing' => $this->address($billing + ($this->orderVat($o) !== '' ? ['vat_id' => $this->orderVat($o)] : [])), 'shipping' => $this->address($shippingAddress),
            'customer_note' => trim((string) ($o['customer_note'] ?? '')), 'placed_at' => $placed, 'paid_at' => $paidAt,
            'shipped_at' => null, 'completed_at' => $this->date($o['date_completed_gmt'] ?? '', $o['date_completed'] ?? ''),
            'cancelled_at' => $meaning === 'cancelled' ? $this->date($o['date_modified_gmt'] ?? '', $o['date_modified'] ?? '') : null,
            'items' => $items,
            'payments' => $paid && $method !== '' ? [['method' => $method, 'kind' => 'charge', 'reference' => (string) ($o['transaction_id'] ?? ''), 'amount' => $total, 'status' => 'ok', 'at' => $paidAt]] : [],
            'refunds' => $refundList, 'history' => $history, 'invoices' => [], 'coupons' => $coupons,
        ];
        if ((int) ($o['customer_id'] ?? 0) > 0) {
            $record['customer_id'] = (string) (int) $o['customer_id'];
        }
        foreach ($items as $item) {
            if ($item['kind'] === 'shipping') {
                $instance = null;
                foreach (is_array($o['shipping_lines'] ?? null) ? $o['shipping_lines'] : [] as $l) {
                    if (is_array($l) && (int) ($l['instance_id'] ?? 0) > 0) {
                        $instance = (string) (int) $l['instance_id'];
                        break;
                    }
                }
                if ($instance !== null) {
                    $record['carrier_id'] = $instance;
                }
                $record['carrier_title'] = $item['name'];
                break;
            }
        }
        $extra = [];
        foreach (['order_key', 'created_via', 'customer_ip_address', 'transaction_id', 'cart_hash'] as $key) {
            if (trim((string) ($o[$key] ?? '')) !== '') {
                $extra[$key] = (string) $o[$key];
            }
        }
        if ((int) ($o['parent_id'] ?? 0) > 0) {
            $extra['parent_id'] = (int) $o['parent_id'];
        }
        if ($unknown) {
            $extra['state_warning'] = 'unknown order status «' . $state . '»: read as processing';
        }
        if ($total !== $totalNet + $totalTax) {
            $extra['total_difference'] = $total - $totalNet - $totalTax;
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /** @param array<string,mixed> $o */
    private function orderVat(array $o): string
    {
        $meta = $this->metaMap($o['meta_data'] ?? []);
        foreach (self::VAT_KEYS as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key]) && trim((string) $meta[$key]) !== '') {
                return trim((string) $meta[$key]);
            }
        }

        return '';
    }

    /**
     * «Tamaño: Pequeña» a partir de los datos de la línea (los que no empiezan por «_»).
     *
     * @param mixed $meta
     */
    private function variantLabel($meta): string
    {
        $parts = [];
        foreach (is_array($meta) ? $meta : [] as $entry) {
            if (!is_array($entry) || !isset($entry['key']) || strncmp((string) $entry['key'], '_', 1) === 0) {
                continue;
            }
            $label = $this->plain($entry['display_key'] ?? $entry['key']);
            $value = $this->plain($entry['display_value'] ?? ($entry['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------ contenidos y equipo

    /**
     * @param array<string,mixed> $m adjunto de wp/v2/media (la copia añade «path» si el fichero existe)
     *
     * @return array<string,mixed>
     */
    public function media(array $m): array
    {
        $url = trim((string) ($m['source_url'] ?? ''));
        $path = trim((string) ($m['path'] ?? ''));
        if ($url === '' && $path === '') {
            throw new \InvalidArgumentException('media without a url or a file');
        }
        $title = is_array($m['title'] ?? null) ? ($m['title']['rendered'] ?? '') : ($m['title'] ?? '');
        $alt = trim((string) ($m['alt_text'] ?? ''));
        $record = ['id' => (string) ($m['id'] ?? ''), 'alt' => $alt !== '' ? $this->t($alt) : [], 'title' => $this->plain($title), 'mime' => (string) ($m['mime_type'] ?? '')];
        if ($url !== '') {
            $record['url'] = $url;
        }
        if ($path !== '') {
            $record['path'] = $path;
        }
        $paths = $this->paths($url);
        if ($paths !== []) {
            $record['old_paths'] = $paths;
        }

        return $record;
    }

    /**
     * @param mixed $field «title», «content», «excerpt» de wp/v2: {rendered, raw} o un texto
     */
    private function rendered($field, bool $preferRaw = false): string
    {
        if (is_array($field)) {
            if ($preferRaw && isset($field['raw']) && is_string($field['raw'])) {
                return $field['raw'];
            }

            return (string) ($field['rendered'] ?? ($field['raw'] ?? ''));
        }

        return (string) $field;
    }

    /**
     * @param array<string,mixed> $p página o entrada de wp/v2 (con context=edit trae el contenido en bruto)
     *
     * @return array<string,mixed>
     */
    private function content(array $p): array
    {
        $status = (string) ($p['status'] ?? 'publish');
        if ($status === 'trash' || $status === 'auto-draft' || $status === 'inherit') {
            throw new \InvalidArgumentException('content in the trash or an automatic draft');
        }
        $title = $this->plain($this->rendered($p['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('content without a title');
        }
        $content = $this->rendered($p['content'] ?? '', true);
        $extra = [];
        $seo = $this->seo($p, $extra);
        $published = $this->date($p['date_gmt'] ?? '', $p['date'] ?? '');

        return [
            'id' => (string) ($p['id'] ?? ''), 'updated_at' => $this->date($p['modified_gmt'] ?? '', $p['modified'] ?? ''),
            'title' => $this->t($title), 'slug' => $this->t($p['slug'] ?? ''), 'content' => $this->t($content),
            'content_format' => strpos($content, '<!-- wp:') !== false ? 'gutenberg' : 'html',
            'status' => $status === 'publish' ? 'published' : ($status === 'private' ? 'private' : 'draft'),
            'published_at' => $status === 'publish' ? $published : null,
            '_seo' => $seo, '_extra' => $extra,
        ];
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    public function page(array $p): array
    {
        $c = $this->content($p);
        $seo = $c['_seo'];
        $extra = $c['_extra'];
        unset($c['_seo'], $c['_extra']);
        $c['parent_id'] = (int) ($p['parent'] ?? 0) > 0 ? (string) (int) $p['parent'] : null;
        if (isset($this->legalPages[$c['id']])) {
            $c['legal'] = (string) $this->legalPages[$c['id']];
        }
        $c += $seo;
        $paths = $this->paths($p['link'] ?? '');
        if ($paths !== []) {
            $c['old_paths'] = $paths;
        }
        if ($extra !== []) {
            $c['extra'] = $extra;
        }

        return $c;
    }

    /**
     * @param array<string,mixed> $p entrada de wp/v2. La copia da «tag_names», «featured_image» y «author_name»; la API los trae en _embedded.
     *
     * @return array<string,mixed>
     */
    public function post(array $p): array
    {
        $c = $this->content($p);
        $seo = $c['_seo'];
        $extra = $c['_extra'];
        unset($c['_seo'], $c['_extra']);
        $embedded = is_array($p['_embedded'] ?? null) ? $p['_embedded'] : [];
        $tags = [];
        if (isset($p['tag_names']) && is_array($p['tag_names'])) {
            $tags = array_map('strval', $p['tag_names']);
        } else {
            foreach (is_array($embedded['wp:term'] ?? null) ? $embedded['wp:term'] : [] as $group) {
                foreach (is_array($group) ? $group : [] as $term) {
                    if (is_array($term) && ($term['taxonomy'] ?? '') === 'post_tag') {
                        $tags[] = $this->plain($term['name'] ?? '');
                    }
                }
            }
        }
        $image = null;
        if (isset($p['featured_image']) && is_array($p['featured_image'])) {
            $image = $p['featured_image'];
        } elseif (isset($embedded['wp:featuredmedia'][0]['source_url']) && is_string($embedded['wp:featuredmedia'][0]['source_url'])) {
            $image = ['url' => $embedded['wp:featuredmedia'][0]['source_url']];
        }
        $author = isset($p['author_name']) ? (string) $p['author_name'] : (string) ($embedded['author'][0]['name'] ?? '');
        $c['excerpt'] = $this->t($this->rendered($p['excerpt'] ?? ''));
        $c['category_ids'] = $this->ids($p['categories'] ?? []);
        $c['tags'] = $tags === [] ? [] : [$this->lang => $tags];
        $c['image'] = $image;
        $c['author'] = $this->plain($author);
        $c += $seo;
        $paths = $this->paths($p['link'] ?? '');
        if ($paths !== []) {
            $c['old_paths'] = $paths;
        }
        if ($extra !== []) {
            $c['extra'] = $extra;
        }

        return $c;
    }

    /**
     * @param array<string,mixed> $c categoría de wp/v2: {id, name, slug, parent, description, link}
     *
     * @return array<string,mixed>
     */
    public function postCategory(array $c): array
    {
        $name = $this->plain($c['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('category without a name');
        }
        $record = [
            'id' => (string) ($c['id'] ?? ''), 'name' => $this->t($name), 'slug' => $this->t($c['slug'] ?? ''),
            'parent_id' => (int) ($c['parent'] ?? 0) > 0 ? (string) (int) $c['parent'] : null, 'description' => $this->t($c['description'] ?? ''),
        ];
        $paths = $this->paths($c['link'] ?? '');
        if ($paths !== []) {
            $record['old_paths'] = $paths;
        }

        return $record;
    }

    /**
     * Un menú con sus elementos (wp/v2/menu-items, en lista plana) como árbol.
     *
     * @param array<string,mixed>            $menu  {id, name, slug, locations}
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<string,mixed>
     */
    public function menu(array $menu, array $items): array
    {
        $locations = array_values(array_filter(array_map('strval', is_array($menu['locations'] ?? null) ? $menu['locations'] : [])));
        $location = $locations !== [] ? $locations[0] : (string) ($menu['slug'] ?? $menu['id'] ?? '');
        if ($location === '') {
            throw new \InvalidArgumentException('menu without a location or a slug');
        }
        $children = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $children[(int) ($item['parent'] ?? 0)][] = $item;
            }
        }
        foreach ($children as $parent => $list) {
            usort($list, static function (array $a, array $b): int {
                return [(int) ($a['menu_order'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['menu_order'] ?? 0), (int) ($b['id'] ?? 0)];
            });
            $children[$parent] = $list;
        }

        return ['id' => (string) ($menu['id'] ?? $location), 'location' => $location, 'name' => $this->plain($menu['name'] ?? ''), 'items' => $this->menuItems($children, 0, 0)];
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $children
     *
     * @return array<int,array<string,mixed>>
     */
    private function menuItems(array $children, int $parent, int $depth): array
    {
        $out = [];
        if ($depth > 8) {
            return $out;
        }
        foreach ($children[$parent] ?? [] as $item) {
            $object = (string) ($item['object'] ?? 'custom');
            $url = trim((string) ($item['url'] ?? ''));
            $entry = ['label' => $this->t($this->plain($this->rendered($item['title'] ?? '')))];
            $map = ['page' => 'page', 'post' => 'post', 'category' => 'post_category', 'product_cat' => 'category', 'product' => 'product', 'product_brand' => 'brand'];
            if (isset($map[$object]) && (int) ($item['object_id'] ?? 0) > 0) {
                $entry['type'] = $map[$object];
                $entry['ref'] = (string) (int) $item['object_id'];
            } else {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $host = (string) parse_url($url, PHP_URL_HOST);
                $siteHost = (string) parse_url($this->siteUrl, PHP_URL_HOST);
                $isOwn = $host === '' || ($siteHost !== '' && strcasecmp($host, $siteHost) === 0);
                if ($isOwn && ($path === '' || $path === '/') && strpos($url, '?') === false && strpos($url, '#') !== 0) {
                    $entry['type'] = 'home';
                } else {
                    $entry['type'] = 'url';
                    $entry['url'] = $isOwn && $path !== '' ? $path . (strpos($url, '?') !== false ? '?' . (string) parse_url($url, PHP_URL_QUERY) : '') : $url;
                }
            }
            $entry['children'] = $this->menuItems($children, (int) ($item['id'] ?? 0), $depth + 1);
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $u usuario de wp/v2 con context=edit: {id, email, name, username, roles, locale}
     *
     * @return array<string,mixed>
     */
    public function teamMember(array $u): array
    {
        $email = trim((string) ($u['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('team member without an email');
        }
        $roles = array_values(array_filter(array_map('strval', is_array($u['roles'] ?? null) ? $u['roles'] : [])));
        $role = $roles !== [] ? $roles[0] : '';
        $name = $this->plain($u['name'] ?? '');
        if ($name === '') {
            $name = trim((string) ($u['username'] ?? ''));
        }
        $meaning = 'other';
        if (in_array('administrator', $roles, true)) {
            $meaning = 'admin';
        } elseif (in_array('shop_manager', $roles, true)) {
            $meaning = 'manager';
        } elseif (array_intersect($roles, ['editor', 'author', 'contributor']) !== []) {
            $meaning = 'editor';
        }
        $record = ['id' => (string) ($u['id'] ?? ''), 'email' => $email, 'name' => $name !== '' ? $name : $email, 'role' => $role, 'role_meaning' => $meaning, 'active' => true];
        if (trim((string) ($u['locale'] ?? '')) !== '') {
            $record['locale'] = self::lang((string) $u['locale']);
        }

        return $record;
    }

    // ------------------------------------------------------------------ plugins sin traer

    /**
     * Los plugins activos que no se traen, con su nombre.
     *
     * @param array<int,array{slug:string,name:string}> $plugins
     *
     * @return array<int,array{kind:string,name:string,count:int,note:string}>
     */
    public static function unmappedPlugins(array $plugins): array
    {
        $out = [];
        foreach ($plugins as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            $name = trim((string) ($plugin['name'] ?? '')) !== '' ? (string) $plugin['name'] : $slug;
            if ($slug === '' || in_array($slug, self::COVERED_PLUGINS, true)) {
                continue;
            }
            $note = 'Plugin with no equivalent here: its data and behaviour are not brought';
            if (in_array($slug, ['sitepress-multilingual-cms', 'polylang', 'polylang-pro', 'translatepress-multilingual'], true)) {
                $note = 'Multilingual plugin: only the main language is brought and the other languages are not';
            } elseif (in_array($slug, ['woocommerce-subscriptions', 'woocommerce-bookings', 'woocommerce-composite-products', 'woocommerce-product-bundles'], true)) {
                $note = 'Subscriptions, bookings and bundles are brought as simple products; their rules are not';
            } elseif (in_array($slug, ['mailpoet', 'newsletter', 'mailchimp-for-woocommerce', 'mailchimp-for-wp'], true)) {
                $note = 'Newsletter plugin: its subscribers are not brought';
            } elseif (in_array($slug, ['redsyspur', 'woocommerce-paypal-payments', 'woocommerce-gateway-stripe'], true)) {
                $note = 'Payment gateway plugin: its settings are read but the plugin is not brought; use the equivalent payment plugin';
            }
            $out[] = ['kind' => 'plugin', 'name' => $name, 'count' => 1, 'note' => $note];
        }

        return $out;
    }
}
