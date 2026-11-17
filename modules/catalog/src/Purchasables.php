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

/**
 * Lo que necesita el carrito de un producto o de una de sus variantes (purchasable() del contrato § 5).
 *
 * No pasa por la caché: las existencias tienen que ser las de ahora mismo. Si el producto no existe devuelve null;
 * si existe pero no se puede comprar, el array sale con available = false y el motivo ya traducido.
 */
final class Purchasables
{
    /** @var App */
    private $app;

    /** @var Pricing */
    private $pricing;

    /** @var Attributes */
    private $attributes;

    /** @var Stock */
    private $stock;

    public function __construct(App $app, Pricing $pricing, Attributes $attributes, Stock $stock)
    {
        $this->app = $app;
        $this->pricing = $pricing;
        $this->attributes = $attributes;
        $this->stock = $stock;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $productId, int $variantId, string $lang): ?array
    {
        $db = $this->app->db();
        $product = $db->row('SELECT * FROM {products} WHERE id = ?', [$productId]);
        if ($product === null) {
            return null;
        }
        $now = $this->app->clock()->sql();
        $i18n = $this->app->i18n();
        $default = $this->app->defaultLang();

        $byLang = [];
        foreach ($db->select('SELECT lang, name FROM {product_i18n} WHERE product_id = ?', [$productId]) as $row) {
            $byLang[(string) $row['lang']] = ['name' => (string) $row['name']];
        }
        $picked = Texts::pick($byLang, $lang, $default);
        $name = $picked !== null ? (string) $picked['name'] : '';

        $hasVariants = (int) $product['has_variants'] === 1;
        $variant = null;
        $valueMap = [];
        if ($variantId > 0) {
            $variant = $db->row('SELECT * FROM {variants} WHERE id = ? AND product_id = ?', [$variantId, $productId]);
            if ($variant !== null) {
                foreach ($db->select('SELECT attribute_id, value_id FROM {variant_values} WHERE variant_id = ?', [$variantId]) as $row) {
                    $valueMap[(int) $row['attribute_id']] = (int) $row['value_id'];
                }
            }
        }

        // Por qué no se puede comprar, en orden de importancia.
        $reason = '';
        if (!$this->published($product, $now)) {
            $reason = $i18n->t('This product is not available', [], $lang);
        } elseif ($hasVariants && $variantId <= 0) {
            $reason = $i18n->t('Choose an option first', [], $lang);
        } elseif ($variantId > 0 && ($variant === null || (int) $variant['active'] !== 1 || !$hasVariants)) {
            $reason = $i18n->t('The selected option is not available', [], $lang);
        }

        $eff = $this->pricing->effective($product, $variant, $now);
        $managed = (int) $product['manage_stock'] === 1;
        $stock = null;
        if ($managed) {
            $stock = $variant !== null && $hasVariants ? (int) $variant['stock'] : (int) $product['stock'];
        }
        $backorders = isset($product['backorders']) ? (string) $product['backorders'] : 'no';
        if ($reason === '' && $stock !== null && $stock <= 0 && $backorders !== 'yes') {
            $reason = $i18n->t('Sold out', [], $lang);
        }
        // Un plugin con la capacidad «pricing» puede decir, en la disponibilidad, que algo no se compra (próximamente, solo en
        // tienda…). Se comprueba aquí, que es lo que lee el carrito en cada paso (añadir, cambiar, ver, cobrar), no en la pantalla.
        if ($reason === '' && ($variant !== null || !$hasVariants)) {
            $gate = $this->stock->gate($product, $hasVariants ? $variant : null, $lang);
            if (empty($gate['purchasable'])) {
                $reason = (string) $gate['label'] !== '' ? (string) $gate['label'] : $i18n->t('This product is not available', [], $lang);
            }
        }

        $categoryIds = [];
        foreach ($db->select('SELECT category_id FROM {product_categories} WHERE product_id = ? ORDER BY is_main DESC, position, category_id', [$productId]) as $row) {
            $categoryIds[] = (int) $row['category_id'];
        }

        $kind = (string) $product['kind'];
        $usable = $variant !== null && $hasVariants;
        $sku = $usable && isset($variant['sku']) && $variant['sku'] !== null && $variant['sku'] !== ''
            ? (string) $variant['sku'] : (isset($product['sku']) && $product['sku'] !== null ? (string) $product['sku'] : '');
        $weight = $usable && isset($variant['weight']) && $variant['weight'] !== null ? (int) $variant['weight'] : (int) $product['weight'];

        $this->app->slugs()->preload('product', [$productId], $lang);
        if ($lang !== $default) {
            $this->app->slugs()->preload('product', [$productId], $default);
        }

        return [
            'product_id' => $productId,
            'variant_id' => $usable ? (int) $variant['id'] : 0,
            'name' => $name,
            'variant_label' => $usable ? $this->attributes->variantLabel($variant, $valueMap, $this->attributes->all($lang)) : '',
            'sku' => $sku,
            'unit_price' => $eff['price'],
            'compare_price' => $eff['compare'],
            'tax_class_id' => isset($product['tax_class_id']) && $product['tax_class_id'] !== null ? (int) $product['tax_class_id'] : null,
            'weight' => $weight,
            'kind' => $kind,
            'is_digital' => $kind === 'digital',
            'stock' => $stock,
            'backorders' => $backorders,
            'min_qty' => (int) $product['min_qty'],
            'max_qty' => (int) $product['max_qty'],
            'image' => $this->image($product, $variant, $name),
            'url' => $this->app->url()->entity('product', $productId, $lang),
            'category_ids' => $categoryIds,
            'on_sale' => $eff['compare'] !== null,
            'available' => $reason === '',
            'reason' => $reason,
            'sale_until' => $eff['compare'] !== null ? $eff['sale_until'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $product
     */
    private function published(array $product, string $now): bool
    {
        return (string) $product['status'] === 'published'
            && (empty($product['published_at']) || (string) $product['published_at'] <= $now);
    }

    /**
     * La imagen de la variante, o la principal del producto, o la primera de su galería.
     *
     * @param array<string,mixed>      $product
     * @param array<string,mixed>|null $variant
     *
     * @return array<string,mixed>|null
     */
    private function image(array $product, ?array $variant, string $name): ?array
    {
        if (!$this->app->has('media')) {
            return null;
        }
        $candidates = [];
        if ($variant !== null && isset($variant['media_id']) && $variant['media_id'] !== null) {
            $candidates[] = (int) $variant['media_id'];
        }
        if (isset($product['main_media_id']) && $product['main_media_id'] !== null) {
            $candidates[] = (int) $product['main_media_id'];
        }
        $first = $this->app->db()->value('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position, media_id', [(int) $product['id']]);
        if ($first !== null) {
            $candidates[] = (int) $first;
        }
        $media = $this->app->service('media');
        foreach ($candidates as $mediaId) {
            if ($mediaId <= 0 || !is_callable([$media, 'image'])) {
                continue;
            }
            $image = $media->image($mediaId);
            if (is_array($image)) {
                return Images::shape($image, $name);
            }
        }

        return null;
    }
}
