<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Util\Str;

/**
 * Arma ProductData (la ficha completa) y ProductCard (la tarjeta de un listado) a partir de los paquetes que
 * lee ProductLoader. No toca la base de datos: todo lo que usa viene en el paquete o en la caché de atributos.
 */
final class ProductData
{
    /** Lo más que vive una entrada de la caché aunque nada cambie (segundos). */
    public const MAX_TTL = 3600;

    /** @var App */
    private $app;

    /** @var Pricing */
    private $pricing;

    /** @var Stock */
    private $stock;

    /** @var Attributes */
    private $attributes;

    public function __construct(App $app, Pricing $pricing, Stock $stock, Attributes $attributes)
    {
        $this->app = $app;
        $this->pricing = $pricing;
        $this->stock = $stock;
        $this->attributes = $attributes;
    }

    /**
     * Lo que se guarda en la caché de un producto: sus datos y lo que hace falta para decidir si se puede ver.
     *
     * @param array<string,mixed> $bundle paquete de ProductLoader
     *
     * @return array{status:string,published_at:?string,data:array<string,mixed>}
     */
    public function entry(array $bundle, string $lang, bool $full): array
    {
        $row = $bundle['row'];

        return [
            'status' => (string) $row['status'],
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null && $row['published_at'] !== '' ? (string) $row['published_at'] : null,
            'data' => $full ? $this->full($bundle, $lang) : $this->card($bundle, $lang),
        ];
    }

    /**
     * Cuántos segundos puede vivir en la caché lo calculado ahora: hasta el siguiente instante en que cambiaría
     * (empieza o termina una oferta, se publica, deja de ser «nuevo»), con un tope. null = no se guarda (cambia ya).
     *
     * @param array<string,mixed> $row fila de products
     */
    public function ttl(array $row): ?int
    {
        $now = $this->app->clock()->now();
        $limit = $now + self::MAX_TTL;
        $changes = [];
        if (!empty($row['published_at'])) {
            $changes[] = Clock::parse((string) $row['published_at']);
        }
        if (!empty($row['sale_from'])) {
            $changes[] = Clock::parse((string) $row['sale_from']);
        }
        if (!empty($row['sale_to'])) {
            $changes[] = Clock::parse((string) $row['sale_to']) + 1;
        }
        $start = !empty($row['published_at']) ? (string) $row['published_at'] : (isset($row['created_at']) ? (string) $row['created_at'] : '');
        if ($start !== '') {
            $changes[] = Clock::parse($start) + $this->newDays() * 86400;
        }
        foreach ($changes as $at) {
            if ($at > $now && $at < $limit) {
                $limit = $at;
            }
        }
        $ttl = $limit - 1 - $now;

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * La ficha completa (ProductData del contrato § 5).
     *
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed>
     */
    public function full(array $b, string $lang): array
    {
        $row = $b['row'];
        $id = (int) $row['id'];
        $now = $this->app->clock()->sql();
        $text = $b['text'];
        $hasVariants = (int) $row['has_variants'] === 1;
        $variants = $hasVariants ? $b['variants'] : [];
        $attrs = $this->attributes->all($lang);
        $url = $this->app->url();

        // Variantes y opciones (lo que se elige para comprar).
        $variantData = [];
        $options = [];
        // Qué valores tienen al menos una variante que se puede comprar: la talla agotada se dice en la ficha (14/11/2026: «la ficha
        // lista las tallas 39 a 46 sin decir cuáles están agotadas»).
        $inStock = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant['id'];
            $map = isset($b['values'][$variantId]) ? $b['values'][$variantId] : [];
            $values = [];
            foreach ($attrs as $attributeId => $attr) {
                if (isset($map[$attributeId], $attr['values'][$map[$attributeId]])) {
                    $values[$attr['code']] = $attr['values'][$map[$attributeId]]['code'];
                    $options[$attributeId][$map[$attributeId]] = true;
                }
            }
            $variantAvailability = $this->stock->forVariant($row, $variant, $lang);
            // Lo mismo que Front\Variants::soldOut(): agotada si no se puede comprar o si su estado es «agotado».
            $buyable = !((isset($variantAvailability['purchasable']) && empty($variantAvailability['purchasable']))
                || (isset($variantAvailability['status']) && $variantAvailability['status'] === 'out_of_stock'));
            foreach ($map as $attributeId => $valueId) {
                if ($buyable) {
                    $inStock[$attributeId][$valueId] = true;
                }
            }
            $variantPrice = $this->pricing->variantBlock($row, $variant, $now);
            $variantData[] = [
                'id' => $variantId,
                'sku' => $this->nullable($variant, 'sku'),
                'gtin' => $this->nullable($variant, 'gtin'),
                'label' => $this->attributes->variantLabel($variant, $map, $attrs),
                'values' => $values,
                'price' => $variantAvailability['show_price'] ? $variantPrice : Pricing::hide($variantPrice),
                'availability' => $variantAvailability,
                'image_id' => isset($variant['media_id']) && $variant['media_id'] !== null && (int) $variant['media_id'] > 0 ? (int) $variant['media_id'] : null,
            ];
        }
        $optionList = [];
        foreach ($attrs as $attributeId => $attr) {
            if (!isset($options[$attributeId])) {
                continue;
            }
            $values = [];
            foreach ($attr['values'] as $valueId => $value) {
                if (isset($options[$attributeId][$valueId])) {
                    $values[] = ['code' => $value['code'], 'label' => $value['label'], 'color' => $value['color'], 'available' => isset($inStock[$attributeId][$valueId])];
                }
            }
            $optionList[] = ['code' => $attr['code'], 'name' => $attr['name'], 'values' => $values];
        }

        // Atributos visibles en la ficha, con el valor ya legible.
        $rowsByAttribute = [];
        foreach ($b['attrRows'] as $attrRow) {
            $rowsByAttribute[(int) $attrRow['attribute_id']][] = $attrRow;
        }
        $attributeList = [];
        foreach ($attrs as $attributeId => $attr) {
            if (!$attr['visible'] || !isset($rowsByAttribute[$attributeId])) {
                continue;
            }
            $value = $this->attributes->readable($attr, $rowsByAttribute[$attributeId], $lang);
            if ($value === '') {
                continue;
            }
            // «value» es el texto ya legible (un número lleva su unidad: «130 kg»). Para las máquinas, el dato suelto:
            // «type», y en los numéricos «number» (el número) y «unit» (la unidad).
            $number = null;
            if ($attr['type'] === 'number') {
                foreach ($rowsByAttribute[$attributeId] as $attrRow) {
                    if (isset($attrRow['value_number']) && $attrRow['value_number'] !== null && $attrRow['value_number'] !== '') {
                        $number = (float) $attrRow['value_number'];
                        break;
                    }
                }
            }
            $attributeList[] = [
                'code' => $attr['code'], 'name' => $attr['name'], 'value' => $value, 'type' => (string) $attr['type'],
                'number' => $number, 'unit' => $attr['type'] === 'number' ? $attr['unit'] : '',
            ];
        }

        $categories = [];
        $main = null;
        foreach ($b['categories'] as $category) {
            $item = ['id' => $category['id'], 'name' => $category['name'], 'url' => $url->entity('category', $category['id'], $lang)];
            $categories[] = $item;
            if ($main === null && $category['is_main']) {
                $main = $item;
            }
        }
        if ($main === null && $categories !== []) {
            $main = $categories[0];
        }

        $images = $b['images'];
        $summaryText = Texts::plain($text['summary'] !== '' ? $text['summary'] : $text['description']);
        $rating = $this->rating($row);
        $extra = Texts::extra(isset($row['extra']) ? (string) $row['extra'] : null);
        $availability = $hasVariants ? $this->stock->forVariants($row, $variants, $lang) : $this->stock->forProduct($row, $lang);
        $price = $this->pricing->productBlock($row, $variants, $now);
        if (!$availability['show_price']) {
            $price = Pricing::hide($price);
        }

        $data = [
            'id' => $id,
            'kind' => (string) $row['kind'],
            'sku' => $this->nullable($row, 'sku'),
            'gtin' => $this->nullable($row, 'gtin'),
            'mpn' => $this->nullable($row, 'mpn'),
            'name' => $text['name'],
            'url' => $url->entity('product', $id, $lang),
            'url_abs' => $url->entityAbs('product', $id, $lang),
            'summary' => $text['summary'],
            'description' => $text['description'],
            'facts' => Texts::shownFacts(Texts::facts($text['facts'])),
            'faqs' => $b['faqs'],
            'brand' => $b['brand'] !== null
                ? ['id' => $b['brand']['id'], 'name' => $b['brand']['name'], 'url' => $url->entity('brand', $b['brand']['id'], $lang)]
                : null,
            'categories' => $categories,
            'main_category' => $main,
            'tags' => $b['tags'],
            'images' => $images,
            'image' => $images !== [] ? $images[0] : null,
            'price' => $price,
            'availability' => $availability,
            'badge_list' => [],
            'has_variants' => $hasVariants,
            'options' => $optionList,
            'variants' => $variantData,
            'attributes' => $attributeList,
            'rating' => $rating,
            'weight' => (int) $row['weight'],
            'dimensions' => ['length' => (int) $row['length'], 'width' => (int) $row['width'], 'height' => (int) $row['height']],
            'min_qty' => (int) $row['min_qty'],
            'max_qty' => (int) $row['max_qty'],
            'related' => $b['related'],
            'seo' => [
                'title' => $text['meta_title'] !== '' ? $text['meta_title'] : $text['name'],
                'description' => $text['meta_description'] !== '' ? $text['meta_description'] : Str::limit($summaryText, 154),
            ],
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null && $row['published_at'] !== '' ? (string) $row['published_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
            'extra' => [],
        ];

        // Las insignias de la ficha: las del catálogo y las que añada o quite un plugin (filtro product.badges).
        if (!empty($row['pack_units'])) {
            $data['pack_units'] = (int) $row['pack_units'];
            $data['pack_single_id'] = (int) $row['pack_single_id'];
        }
        $data['badge_list'] = $this->badges($row, $price, $availability, $data, 'page', $lang);

        // Datos que añaden los plugins, ya tipados. Se ven tal como los deja el filtro.
        $filteredExtra = $this->app->hooks()->apply('product.extra', $extra, $data);
        $data['extra'] = is_array($filteredExtra) ? $filteredExtra : $extra;

        $final = $this->app->hooks()->apply('product.data', $data);

        return is_array($final) ? $final : $data;
    }

    /**
     * La dirección de la ficha, o «» si el producto no tiene dirección en ese idioma ni en el principal: una tarjeta que llevaba a
     * la portada (13/11/2026, «Bailarinas brown…» en el buscador) es peor que una tarjeta sin enlace.
     */
    private function link(int $id, string $lang): string
    {
        $slugs = $this->app->slugs();
        if ($slugs->path('product', $id, $lang) === null && $slugs->path('product', $id, $this->app->defaultLang()) === null) {
            return '';
        }

        return $this->app->url()->entity('product', $id, $lang);
    }

    /**
     * La tarjeta de un listado (ProductCard del contrato § 5).
     *
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed>
     */
    public function card(array $b, string $lang): array
    {
        $row = $b['row'];
        $id = (int) $row['id'];
        $now = $this->app->clock()->sql();
        $hasVariants = (int) $row['has_variants'] === 1;
        $variants = $hasVariants ? $b['variants'] : [];
        $price = $this->pricing->productBlock($row, $variants, $now);
        $availability = $hasVariants ? $this->stock->forVariants($row, $variants, $lang) : $this->stock->forProduct($row, $lang);
        if (!$availability['show_price']) {
            $price = Pricing::hide($price);
        }

        $card = [
            'id' => $id,
            'name' => $b['text']['name'],
            'url' => $this->link($id, $lang),
            'image' => $b['images'] !== [] ? $b['images'][0] : null,
            // La segunda foto del producto, si la tiene: para enseñarlo con dos vistas (la tarjeta del asistente, un tema).
            'image2' => isset($b['images'][1]) ? $b['images'][1] : null,
            'price' => $price,
            'availability' => [
                'status' => $availability['status'], 'label' => $availability['label'],
                'purchasable' => $availability['purchasable'], 'show_price' => $availability['show_price'],
            ],
            'brand' => $b['brand'] !== null ? $b['brand']['name'] : '',
            'rating' => $this->rating($row),
            'badges' => [],
            'badge_list' => [],
            'has_variants' => $hasVariants,
            'buy' => null,
        ];
        if (!empty($availability['delivery_days'])) {
            $card['availability']['delivery_days'] = $availability['delivery_days'];
        }
        if (self::cardOptions($this->app)) {
            $card['buy'] = $this->buy($b, $row, $variants, $price, $availability, $lang, $now);
        }
        // Las insignias: «badge_list» lleva código, texto y tipo de cada una; «badges» solo los códigos (lo que miran las
        // plantillas antiguas: 'out' pone la tarjeta en gris).
        $card['badge_list'] = $this->badges($row, $price, $availability, $card, 'card', $lang);
        $card['badges'] = array_map(static function (array $badge): string {
            return $badge['code'];
        }, $card['badge_list']);

        return $card;
    }

    /** ¿Dejan las tarjetas de los listados elegir combinación y unidades? (ajuste «catalog.card_options», encendido de serie). */
    public static function cardOptions(App $app): bool
    {
        return $app->settings()->bool('catalog.card_options', true);
    }

    /** Cuántas variantes caben como mucho en una tarjeta; con más, la tarjeta enlaza a la ficha («Elegir opciones»). */
    public static function cardOptionsMax(App $app): int
    {
        return max(2, min(60, $app->settings()->int('catalog.card_options_max', 24)));
    }

    /**
     * Lo que una tarjeta necesita para comprar sin salir del listado («buy» del ProductCard): null si el producto no se puede
     * comprar desde ahí (agotado, no comprable, precio oculto) y, si se puede:
     *
     *   product_id, max_qty (null = sin tope conocido), too_many (más variantes que el tope: la tarjeta enlaza a la ficha),
     *   initial (la variante que sale elegida; 0 sin variantes),
     *   options  [{code, name, values: [{code, label, color}]}]            lo que se elige (talla, color…)
     *   variants [{id, label, values: {código de opción: código de valor}, price (bloque de precio), same_price (igual que el
     *              del producto: no hace falta repintarlo), purchasable, max_qty, stock_label, image (o null: la del producto)}]
     *
     * No lleva nada de quien mira: la tarjeta es la misma para todos y se sirve de la caché.
     *
     * @param array<string,mixed>            $b
     * @param array<string,mixed>            $row
     * @param array<int,array<string,mixed>> $variants
     * @param array<string,mixed>            $price
     * @param array<string,mixed>            $availability
     *
     * @return array<string,mixed>|null
     */
    private function buy(array $b, array $row, array $variants, array $price, array $availability, string $lang, string $now): ?array
    {
        if (empty($availability['purchasable']) || empty($availability['show_price']) || $availability['status'] === 'out_of_stock') {
            return null;
        }
        $buy = [
            'product_id' => (int) $row['id'],
            'max_qty' => isset($availability['qty']) && $availability['qty'] !== null && $availability['status'] !== 'backorder' ? max(1, (int) $availability['qty']) : null,
            'too_many' => false, 'initial' => 0, 'options' => [], 'variants' => [],
        ];
        if ($variants === []) {
            return (int) $row['has_variants'] === 1 ? null : $buy;
        }
        if (count($variants) > self::cardOptionsMax($this->app)) {
            $buy['too_many'] = true;

            return $buy;
        }
        $attrs = $this->attributes->all($lang);
        $used = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant['id'];
            $map = isset($b['values'][$variantId]) ? $b['values'][$variantId] : [];
            $values = [];
            foreach ($attrs as $attributeId => $attr) {
                if (isset($map[$attributeId], $attr['values'][$map[$attributeId]])) {
                    $values[$attr['code']] = $attr['values'][$map[$attributeId]]['code'];
                    $used[$attributeId][$map[$attributeId]] = true;
                }
            }
            $stock = $this->stock->forVariant($row, $variant, $lang);
            $block = $this->pricing->variantBlock($row, $variant, $now);
            $can = !empty($stock['purchasable']) && !empty($stock['show_price']) && $stock['status'] !== 'out_of_stock';
            $buy['variants'][] = [
                'id' => $variantId,
                'label' => $this->attributes->variantLabel($variant, $map, $attrs),
                'values' => $values,
                'price' => !empty($stock['show_price']) ? $block : Pricing::hide($block),
                'same_price' => (int) $block['amount'] === (int) $price['amount'] && $block['compare'] === $price['compare'] && empty($price['from']),
                'purchasable' => $can,
                'max_qty' => isset($stock['qty']) && $stock['qty'] !== null && $stock['status'] !== 'backorder' ? max(0, (int) $stock['qty']) : null,
                'stock_label' => in_array($stock['status'], ['low_stock', 'out_of_stock', 'backorder'], true) ? (string) $stock['label'] : '',
                'image' => isset($b['variant_images'][$variantId]) ? $b['variant_images'][$variantId] : null,
            ];
            if ($buy['initial'] === 0 && $can) {
                $buy['initial'] = $variantId;
            }
        }
        if ($buy['initial'] === 0) {
            return null;
        }
        foreach ($attrs as $attributeId => $attr) {
            if (!isset($used[$attributeId])) {
                continue;
            }
            $list = [];
            foreach ($attr['values'] as $valueId => $value) {
                if (isset($used[$attributeId][$valueId])) {
                    $list[] = ['code' => $value['code'], 'label' => $value['label'], 'color' => $value['color']];
                }
            }
            $buy['options'][] = ['code' => $attr['code'], 'name' => $attr['name'], 'values' => $list];
        }

        return $buy;
    }

    /**
     * Las insignias de un producto: «Oferta» (con su porcentaje), «Nuevo» y «Agotado», y lo que cambie el filtro product.badges
     * (array $badges, array $producto, string $donde = 'card'|'page'). Cada insignia es ['code', 'label', 'kind'] con el tipo
     * en info, ok, warn o sale; lo que el filtro devuelva se limpia aquí (texto corto, tipo conocido, sin repetir el código).
     *
     * @param array<string,mixed> $row          fila de products
     * @param array<string,mixed> $price        el bloque de precio que se enseña
     * @param array<string,mixed> $availability
     * @param array<string,mixed> $product      lo que ya se sabe del producto (la tarjeta o la ficha a medio armar)
     *
     * @return array<int,array{code:string,label:string,kind:string}>
     */
    private function badges(array $row, array $price, array $availability, array $product, string $where, string $lang): array
    {
        $i18n = $this->app->i18n();
        $badges = [];
        if (empty($price['hidden']) && isset($price['compare']) && $price['compare'] !== null) {
            $percent = isset($price['discount_percent']) ? (int) $price['discount_percent'] : 0;
            $badges[] = ['code' => 'sale', 'label' => $percent > 0 ? '−' . $percent . "\u{a0}%" : $i18n->t('Sale', [], $lang), 'kind' => 'sale'];
        }
        if ($this->isNew($row)) {
            $badges[] = ['code' => 'new', 'label' => $i18n->t('New', [], $lang), 'kind' => 'ok'];
        }
        if ($availability['status'] === 'out_of_stock') {
            $badges[] = ['code' => 'out', 'label' => $i18n->t('Sold out', [], $lang), 'kind' => 'warn'];
        }
        $filtered = $this->app->hooks()->apply('product.badges', $badges, $product, $where);

        return self::cleanBadges(is_array($filtered) ? $filtered : $badges);
    }

    /**
     * Deja las insignias como las pinta una plantilla: solo las bien formadas, con el texto sin etiquetas y de hasta 40
     * caracteres, un tipo conocido (si no, «info»), sin repetir el código y como mucho ocho.
     *
     * @param array<mixed,mixed> $badges
     *
     * @return array<int,array{code:string,label:string,kind:string}>
     */
    public static function cleanBadges(array $badges): array
    {
        $out = [];
        $seen = [];
        foreach ($badges as $badge) {
            if (!is_array($badge) || !isset($badge['label']) || !is_scalar($badge['label'])) {
                continue;
            }
            // Se recogen los espacios y los saltos corrientes; el espacio duro de «−17 %» se respeta.
            $label = trim((string) preg_replace('/[ \t\r\n\f\v]+/', ' ', (string) $badge['label']));
            if ($label === '') {
                continue;
            }
            $code = isset($badge['code']) && is_scalar($badge['code']) ? strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $badge['code'])) : '';
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $kind = isset($badge['kind']) && is_string($badge['kind']) && in_array($badge['kind'], ['info', 'ok', 'warn', 'sale'], true) ? $badge['kind'] : 'info';
            $seen[$code] = true;
            $out[] = ['code' => $code, 'label' => mb_substr($label, 0, 40, 'UTF-8'), 'kind' => $kind];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * ¿Se publicó hace menos de catalog.new_days días? Sin fecha de publicación cuenta la de alta.
     *
     * @param array<string,mixed> $row
     */
    private function isNew(array $row): bool
    {
        $start = !empty($row['published_at']) ? (string) $row['published_at'] : (isset($row['created_at']) ? (string) $row['created_at'] : '');
        if ($start === '') {
            return false;
        }
        $since = Clock::parse($start);

        return $since > 0 && $this->app->clock()->now() - $since < $this->newDays() * 86400;
    }

    private function newDays(): int
    {
        return max(0, $this->app->settings()->int('catalog.new_days', 30));
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{average:float,count:int}|null
     */
    private function rating(array $row): ?array
    {
        $count = (int) $row['rating_count'];
        if ($count <= 0) {
            return null;
        }

        return ['average' => round((int) $row['rating_sum'] / $count, 1), 'count' => $count];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function nullable(array $row, string $key): ?string
    {
        return isset($row[$key]) && $row[$key] !== null && $row[$key] !== '' ? (string) $row[$key] : null;
    }
}
