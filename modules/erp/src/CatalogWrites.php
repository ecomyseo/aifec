<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\Amounts;

/**
 * Las dos escrituras de catálogo que comparten la API (POST /stock y /prices) y la importación programada: una línea de existencias y
 * una línea de precios, por SKU. Cada línea contesta con un estado: done, unknown_sku, invalid_value, insufficient_stock o not_tracked.
 * Nunca crea ni borra productos.
 */
final class CatalogWrites
{
    private const MAX_CENTS = 2000000000;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param mixed $line
     *
     * @return array<string,mixed>
     */
    public function stockLine($line, string $reference): array
    {
        $sku = is_array($line) && isset($line['sku']) && is_string($line['sku']) ? trim($line['sku']) : '';
        $out = ['sku' => $sku, 'status' => 'invalid_value'];
        if ($sku === '') {
            return $out + ['message' => 'The sku is required'];
        }
        $hasStock = array_key_exists('stock', $line);
        $hasDelta = array_key_exists('delta', $line);
        if ($hasStock === $hasDelta) {
            return $out + ['message' => 'Send either stock or delta'];
        }
        $value = $hasStock ? $line['stock'] : $line['delta'];
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || abs($value) > 999999999 || ($hasStock && $value < 0) || ($hasDelta && $value === 0)) {
            return $out + ['message' => $hasStock ? 'The stock must be a whole number of zero or more' : 'The delta must be a whole number other than zero'];
        }
        $target = $this->target($sku);
        if ($target === null) {
            return ['status' => 'unknown_sku'] + $out + ['message' => 'There is no product or variant with that SKU'];
        }
        if ($target['has_variants']) {
            return $out + ['message' => 'This SKU is a product with variants: send the SKU of a variant'];
        }
        if (!$target['manage_stock']) {
            return ['status' => 'not_tracked'] + $out + ['message' => 'This product does not control stock'];
        }
        $delta = $hasStock ? $value - $target['stock'] : $value;
        if ($delta !== 0 && !$this->app->service('catalog')->adjustStock($target['product_id'], $target['variant_id'], $delta, 'erp', $reference)) {
            return ['status' => 'insufficient_stock'] + $out + ['message' => 'The change would leave negative stock and the product does not accept orders without stock', 'stock' => $target['stock']];
        }

        return ['status' => 'done', 'sku' => $sku, 'stock' => $target['stock'] + $delta];
    }

    /**
     * @param mixed $line
     *
     * @return array<string,mixed>
     */
    public function priceLine($line, bool $includes): array
    {
        $sku = is_array($line) && isset($line['sku']) && is_string($line['sku']) ? trim($line['sku']) : '';
        $out = ['sku' => $sku, 'status' => 'invalid_value'];
        if ($sku === '') {
            return $out + ['message' => 'The sku is required'];
        }
        $db = $this->app->db();
        $product = $db->row('SELECT id, tax_class_id FROM {products} WHERE sku = ? ORDER BY id', [$sku]);
        $variant = $product === null ? $db->row('SELECT v.id, v.product_id, p.tax_class_id FROM {variants} v JOIN {products} p ON p.id = v.product_id WHERE v.sku = ? ORDER BY v.id', [$sku]) : null;
        if ($product === null && $variant === null) {
            return ['status' => 'unknown_sku'] + $out + ['message' => 'There is no product or variant with that SKU'];
        }
        $classId = ($product !== null ? $product['tax_class_id'] : $variant['tax_class_id']) !== null ? (int) ($product !== null ? $product['tax_class_id'] : $variant['tax_class_id']) : null;
        $lineIncludes = array_key_exists('prices_include_tax', $line) ? $this->truth($line['prices_include_tax']) : $includes;
        $set = [];
        try {
            foreach (['price', 'compare_price', 'sale_price'] as $field) {
                if (!array_key_exists($field, $line) && !array_key_exists($field . '_decimal', $line)) {
                    continue;
                }
                $cents = $this->money($line, $field);
                if ($cents === null && $field === 'price') {
                    throw ApiException::invalid('The price cannot be empty', 'price');
                }
                $set[$field] = $cents === null ? null : $this->toStore($cents, $lineIncludes, $classId);
            }
            foreach (['sale_from', 'sale_to'] as $field) {
                if (!array_key_exists($field, $line)) {
                    continue;
                }
                $when = $line[$field] === null || $line[$field] === '' ? null : (is_string($line[$field]) ? Present::toSql($line[$field]) : null);
                if ($when === null && $line[$field] !== null && $line[$field] !== '') {
                    throw ApiException::invalid('The date is not valid (use ISO 8601)', $field);
                }
                $set[$field] = $when;
            }
        } catch (ApiException $e) {
            return $out + ['message' => $e->getMessage(), 'field' => $e->field()];
        }
        if ($set === []) {
            return $out + ['message' => 'There is nothing to change'];
        }
        if (isset($set['sale_from'], $set['sale_to']) && $set['sale_from'] > $set['sale_to']) {
            return $out + ['message' => 'The offer cannot end before it starts'];
        }
        $now = $this->app->clock()->sql();
        if ($product !== null) {
            $db->update('products', $set + ['updated_at' => $now], ['id' => (int) $product['id']]);
            $this->app->invalidate(['product:' . (int) $product['id'], 'catalog']);
        } else {
            if (array_diff(array_keys($set), ['price', 'compare_price']) !== []) {
                return $out + ['message' => 'A variant only has price and compare_price'];
            }
            $db->update('variants', $set + ['updated_at' => $now], ['id' => (int) $variant['id']]);
            $this->app->invalidate(['product:' . (int) $variant['product_id'], 'catalog']);
        }

        return ['status' => 'done', 'sku' => $sku];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @return array{product_id:int,variant_id:int,stock:int,manage_stock:bool,has_variants:bool}|null
     */
    private function target(string $sku): ?array
    {
        $db = $this->app->db();
        $product = $db->row('SELECT id, has_variants, manage_stock, stock FROM {products} WHERE sku = ? ORDER BY id', [$sku]);
        if ($product !== null) {
            return ['product_id' => (int) $product['id'], 'variant_id' => 0, 'stock' => (int) $product['stock'], 'manage_stock' => (int) $product['manage_stock'] === 1, 'has_variants' => (int) $product['has_variants'] === 1];
        }
        $variant = $db->row('SELECT v.id, v.product_id, v.stock, p.manage_stock FROM {variants} v JOIN {products} p ON p.id = v.product_id WHERE v.sku = ? ORDER BY v.id', [$sku]);

        return $variant === null ? null : ['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['id'], 'stock' => (int) $variant['stock'], 'manage_stock' => (int) $variant['manage_stock'] === 1, 'has_variants' => false];
    }

    /**
     * Un importe del cuerpo: «price» en céntimos (entero) o «price_decimal» como texto («19.99»). null si no viene.
     *
     * @param array<string,mixed> $source
     */
    public function money(array $source, string $field): ?int
    {
        if (array_key_exists($field, $source) && $source[$field] !== null) {
            $v = $source[$field];
            if (is_string($v) && preg_match('/^\d{1,10}$/', $v) === 1) {
                $v = (int) $v;
            }
            if (!is_int($v) || $v < 0 || $v > self::MAX_CENTS) {
                throw ApiException::invalid('The field ' . $field . ' must be an amount in cents (a whole number of zero or more)', $field);
            }

            return $v;
        }
        $decimal = $field . '_decimal';
        if (array_key_exists($decimal, $source) && $source[$decimal] !== null) {
            if (!is_string($source[$decimal]) && !is_int($source[$decimal])) {
                throw ApiException::invalid('The field ' . $decimal . ' must be text like 19.99', $decimal);
            }
            try {
                return Amounts::parse((string) $source[$decimal]);
            } catch (\InvalidArgumentException $e) {
                throw ApiException::invalid('The field ' . $decimal . ' is not an amount', $decimal);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body
     */
    public function includesTax(array $body): bool
    {
        return array_key_exists('prices_include_tax', $body) ? $this->truth($body['prices_include_tax']) : $this->shopIncludesTax();
    }

    /**
     * @param mixed $value
     */
    private function truth($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    public function shopIncludesTax(): bool
    {
        return $this->app->has('tax') ? (bool) $this->app->service('tax')->pricesIncludeTax() : $this->app->settings()->bool('tax.prices_include_tax', true);
    }

    /** Pasa un importe a «como lo escribe la tienda» (con o sin impuestos dentro) si el ERP lo manda de la otra forma. */
    public function toStore(int $cents, bool $inputIncludesTax, ?int $classId): int
    {
        $shop = $this->shopIncludesTax();
        if ($inputIncludesTax === $shop || !$this->app->has('tax')) {
            return $cents;
        }
        $rate = (int) $this->app->service('tax')->rate($classId, [])['rate'];

        return $inputIncludesTax ? Money::split($cents, $rate)[0] : Money::add($cents, $rate)[1];
    }
}
