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

use Aifec\Db\Db;
use Aifec\Kernel\App;

/**
 * Existencias: la disponibilidad que se enseña y el ajuste del stock con la sentencia condicionada del contrato (regla 6).
 *
 * Estados: in_stock, low_stock (quedan pocas), out_of_stock y backorder (sin existencias pero se vende igual).
 * Las variantes solo llevan su cantidad; controlar o no las existencias y admitir pedidos sin ellas lo decide el producto.
 */
final class Stock
{
    /** Los valores de schema.org (ItemAvailability) que un plugin puede pedir para los datos estructurados. */
    private const SCHEMA_VALUES = ['InStock', 'OutOfStock', 'PreOrder', 'PreSale', 'BackOrder', 'LimitedAvailability', 'SoldOut', 'Discontinued', 'OnlineOnly', 'InStoreOnly'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * El estado de una cantidad según el producto.
     *
     * @param array<string,mixed> $product fila de products
     */
    public function status(array $product, int $qty): string
    {
        if ((int) $product['manage_stock'] === 0) {
            return 'in_stock';
        }
        if ($qty <= 0) {
            return isset($product['backorders']) && (string) $product['backorders'] === 'yes' ? 'backorder' : 'out_of_stock';
        }

        return $qty <= $this->threshold($product) ? 'low_stock' : 'in_stock';
    }

    /**
     * Hasta cuántas unidades se avisa de que quedan pocas: el mayor entre el aviso del producto y catalog.low_stock (5).
     *
     * @param array<string,mixed> $product
     */
    public function threshold(array $product): int
    {
        $alert = isset($product['stock_alert']) ? (int) $product['stock_alert'] : 0;

        return max($alert, $this->app->settings()->int('catalog.low_stock', 5));
    }

    /**
     * La disponibilidad de un producto sin variantes.
     *
     * @param array<string,mixed> $product
     *
     * @return array<string,mixed>
     */
    public function forProduct(array $product, string $lang): array
    {
        $managed = (int) $product['manage_stock'] === 1;
        $qty = max(0, (int) $product['stock']);
        $status = $this->status($product, (int) $product['stock']);

        return $this->make($product, 0, $status, $managed ? $qty : null, $lang);
    }

    /**
     * La disponibilidad de una variante.
     *
     * @param array<string,mixed> $product
     * @param array<string,mixed> $variant
     *
     * @return array<string,mixed>
     */
    public function forVariant(array $product, array $variant, string $lang): array
    {
        $managed = (int) $product['manage_stock'] === 1;
        $qty = max(0, (int) $variant['stock']);
        $status = $this->status($product, (int) $variant['stock']);

        return $this->make($product, (int) $variant['id'], $status, $managed ? $qty : null, $lang);
    }

    /**
     * La disponibilidad del producto con variantes: resume las activas (la mejor de ellas; la cantidad es la suma).
     * Sin ninguna variante activa no se puede comprar.
     *
     * @param array<string,mixed>            $product
     * @param array<int,array<string,mixed>> $variants variantes activas
     *
     * @return array<string,mixed>
     */
    public function forVariants(array $product, array $variants, string $lang): array
    {
        $managed = (int) $product['manage_stock'] === 1;
        $rank = ['out_of_stock' => 0, 'backorder' => 1, 'low_stock' => 2, 'in_stock' => 3];
        $best = 'out_of_stock';
        $sum = 0;
        foreach ($variants as $variant) {
            $status = $this->status($product, (int) $variant['stock']);
            if ($rank[$status] > $rank[$best]) {
                $best = $status;
            }
            $sum += max(0, (int) $variant['stock']);
        }
        if ($variants === []) {
            return $this->make($product, 0, 'out_of_stock', $managed ? 0 : null, $lang);
        }

        return $this->make($product, 0, $best, $managed ? $sum : null, $lang);
    }

    /** El texto del estado, en el idioma pedido. */
    public function label(string $status, ?int $qty, string $lang): string
    {
        $i18n = $this->app->i18n();
        if ($status === 'low_stock') {
            return $i18n->tn('Only {n} left', 'Only {n} left', (int) $qty, [], $lang);
        }
        if ($status === 'out_of_stock') {
            return $i18n->t('Sold out', [], $lang);
        }
        if ($status === 'backorder') {
            return $i18n->t('On backorder', [], $lang);
        }

        return $i18n->t('In stock', [], $lang);
    }

    /**
     * Suma o resta existencias (delta negativo = sale mercancía). Devuelve false si no hay bastantes y no se admite
     * pedir sin existencias. Un producto sin control de existencias lo admite siempre y no cambia nada.
     *
     * Es una sola sentencia condicionada (no se lee y luego se escribe): dos descuentos a la vez no dejan existencias
     * negativas. Cada cambio se apunta en stock_moves, se invalida la caché del producto y se lanza la acción
     * stock.changed (int $productId, int $variantId, int $stockAfter).
     */
    public function adjust(int $productId, int $variantId, int $delta, string $reason, ?string $reference = null): bool
    {
        $result = $this->app->db()->transaction(function (Db $db) use ($productId, $variantId, $delta, $reason, $reference): array {
            $product = $db->row('SELECT id, has_variants, manage_stock FROM {products} WHERE id = ?', [$productId]);
            if ($product === null) {
                return ['ok' => false, 'after' => null];
            }
            // Un producto con variantes se ajusta por la variante; uno sin variantes, por sí mismo.
            if (((int) $product['has_variants'] === 1) !== ($variantId > 0)) {
                return ['ok' => false, 'after' => null];
            }
            if ((int) $product['manage_stock'] === 0 || $delta === 0) {
                return ['ok' => true, 'after' => null];
            }
            if ($variantId > 0) {
                $changed = $db->run(
                    'UPDATE {variants} SET stock = stock + ? WHERE id = ? AND product_id = ? AND (? >= 0 OR stock + ? >= 0'
                    . " OR EXISTS (SELECT 1 FROM {products} q WHERE q.id = ? AND q.backorders = 'yes'))",
                    [$delta, $variantId, $productId, $delta, $delta, $productId]
                );
                $after = $changed === 1 ? $db->value('SELECT stock FROM {variants} WHERE id = ?', [$variantId]) : null;
            } else {
                $changed = $db->run(
                    "UPDATE {products} SET stock = stock + ? WHERE id = ? AND (backorders = 'yes' OR ? >= 0 OR stock + ? >= 0)",
                    [$delta, $productId, $delta, $delta]
                );
                $after = $changed === 1 ? $db->value('SELECT stock FROM {products} WHERE id = ?', [$productId]) : null;
            }
            if ($changed !== 1 || $after === null) {
                return ['ok' => false, 'after' => null];
            }
            $db->insert('stock_moves', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'delta' => $delta,
                'stock_after' => (int) $after,
                'reason' => mb_substr($reason, 0, 191, 'UTF-8'),
                'reference' => $reference,
                'created_at' => $this->app->clock()->sql(),
            ]);

            return ['ok' => true, 'after' => (int) $after];
        });

        if ($result['after'] !== null) {
            $this->app->invalidate(['product:' . $productId]);
            $this->app->hooks()->fire('stock.changed', $productId, $variantId, (int) $result['after']);
        }

        return (bool) $result['ok'];
    }

    /**
     * La disponibilidad de lo que se va a comprar (un producto sin variantes o una variante), ya pasada por el filtro sellado
     * product.availability. La usan el carrito y la caja (Purchasables) para respetar «purchasable» en el servidor, no solo en la
     * pantalla. No lee la base de datos: todo viene de las filas que ya tiene quien llama.
     *
     * @param array<string,mixed>      $product fila de products
     * @param array<string,mixed>|null $variant fila de variants (null = producto sin variantes)
     *
     * @return array<string,mixed>
     */
    public function gate(array $product, ?array $variant, string $lang): array
    {
        $variantId = $variant !== null ? (int) $variant['id'] : 0;
        $managed = (int) $product['manage_stock'] === 1;
        $raw = $variant !== null ? (int) $variant['stock'] : (int) $product['stock'];

        return $this->make($product, $variantId, $this->status($product, $raw), $managed ? max(0, $raw) : null, $lang);
    }

    /**
     * Pone lo que un plugin pudo dejar sin decir: el filtro devuelve la disponibilidad entera, pero un oyente antiguo puede no
     * conocer las claves nuevas. «purchasable» y «show_price» valen true salvo que digan false; «schema» es un valor de schema.org
     * conocido (PreOrder, OutOfStock…) o vacío.
     *
     * @param array<string,mixed> $availability
     *
     * @return array<string,mixed>
     */
    public static function normalise(array $availability): array
    {
        $availability['purchasable'] = !array_key_exists('purchasable', $availability) || (bool) $availability['purchasable'];
        $availability['show_price'] = !array_key_exists('show_price', $availability) || (bool) $availability['show_price'];
        $schema = isset($availability['schema']) && is_string($availability['schema']) ? trim($availability['schema']) : '';
        $availability['schema'] = in_array($schema, self::SCHEMA_VALUES, true) ? $schema : '';
        if (!isset($availability['status']) || !is_string($availability['status'])) {
            $availability['status'] = 'in_stock';
        }
        if (!isset($availability['label']) || !is_string($availability['label'])) {
            $availability['label'] = '';
        }

        return $availability;
    }

    /**
     * Arma la disponibilidad y la pasa por el filtro sellado product.availability (array $availability, array $product).
     *
     * El resultado lleva siempre dos claves más: «purchasable» (false = no se puede comprar: ni botón, ni carrito, ni caja) y
     * «show_price» (false = no se enseña el precio de ese producto en ningún sitio), más «schema» opcional: la disponibilidad de
     * schema.org que se da a las máquinas (PreOrder…).
     *
     * @param array<string,mixed> $product
     *
     * @return array<string,mixed>
     */
    private function make(array $product, int $variantId, string $status, ?int $qty, string $lang): array
    {
        $min = isset($product['delivery_min_days']) && $product['delivery_min_days'] !== null ? (int) $product['delivery_min_days'] : null;
        $max = isset($product['delivery_max_days']) && $product['delivery_max_days'] !== null ? (int) $product['delivery_max_days'] : null;
        $days = null;
        if ($min !== null || $max !== null) {
            $low = $min !== null ? $min : (int) $max;
            $high = $max !== null ? $max : (int) $min;
            $days = [$low, max($low, $high)];
        }
        $availability = [
            'status' => $status,
            'qty' => $qty,
            'label' => $this->label($status, $qty, $lang),
            'delivery_days' => $days,
            'purchasable' => true,
            'show_price' => true,
            'schema' => '',
        ];
        $context = [
            'id' => (int) $product['id'],
            'variant_id' => $variantId,
            'sku' => isset($product['sku']) ? (string) $product['sku'] : '',
            'kind' => isset($product['kind']) ? (string) $product['kind'] : 'physical',
            'manage_stock' => (int) $product['manage_stock'] === 1,
            'backorders' => isset($product['backorders']) ? (string) $product['backorders'] : 'no',
        ];
        $filtered = $this->app->hooks()->apply('product.availability', $availability, $context);

        return self::normalise(is_array($filtered) ? $filtered : $availability);
    }
}
