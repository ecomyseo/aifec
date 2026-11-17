<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Kernel\App;

/**
 * Los dos buscadores de la pantalla «Pedido nuevo». Las dos consultas van acotadas (nunca se lee el listado entero) y con
 * parámetros; lo que se escribe se busca como texto (los comodines de LIKE se escapan).
 *
 * - Clientes: por nombre, apellidos, correo o teléfono; como mucho MAX_CUSTOMERS, solo cuentas activas.
 * - Productos: por nombre, referencia o código de barras, del producto o de una variante; como mucho MAX_PRODUCTS productos
 *   y MAX_OPTIONS opciones. Cada opción es lo que se compra de verdad (el producto, o una de sus variantes) con lo que dice el
 *   catálogo AHORA (Catalog::purchasable(): precio, existencias, si se puede comprar y por qué no).
 */
final class ManualOrderSearch
{
    public const MAX_CUSTOMERS = 10;
    public const MAX_PRODUCTS = 10;
    public const MAX_OPTIONS = 30;
    public const MAX_VARIANTS = 20;
    public const MIN_LENGTH = 2;

    /**
     * @return array<int,array{id:int,name:string,email:string,phone:string,guest:bool}>
     */
    public static function customers(App $app, string $q): array
    {
        $words = self::words($q);
        if ($words === []) {
            return [];
        }
        $where = ['c.active = 1'];
        $params = [];
        foreach ($words as $word) {
            $like = self::like($word);
            $digits = (string) preg_replace('/[^\d+]+/', '', $word);
            $parts = ["c.first_name LIKE ? ESCAPE '!'", "c.last_name LIKE ? ESCAPE '!'", "c.email LIKE ? ESCAPE '!'"];
            array_push($params, $like, $like, $like);
            if (strlen($digits) >= 3) {
                $parts[] = "c.phone LIKE ? ESCAPE '!'";
                $params[] = self::like($digits);
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $rows = $app->db()->select(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.guest FROM {customers} c WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.guest ASC, c.last_name, c.first_name, c.id LIMIT ' . self::MAX_CUSTOMERS,
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'email' => (string) $row['email'],
                'phone' => $row['phone'] !== null ? (string) $row['phone'] : '',
                'guest' => (int) $row['guest'] === 1,
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{product_id:int,variant_id:int,name:string,variant:string,sku:string,price:int,price_formatted:string,stock:int|null,available:bool,reason:string}>
     */
    public static function products(App $app, string $q): array
    {
        $words = self::words($q);
        if ($words === [] || !$app->has('catalog')) {
            return [];
        }
        $db = $app->db();
        $exact = trim($q);
        $params = [$app->lang()];
        $where = ["p.status = 'published'"];
        $byName = [];
        foreach ($words as $word) {
            $byName[] = "i.name LIKE ? ESCAPE '!'";
            $params[] = self::like($word);
        }
        $codes = '(p.sku = ? OR p.gtin = ? OR p.id IN (SELECT v.product_id FROM {variants} v WHERE v.active = 1 AND (v.sku = ? OR v.gtin = ?)))';
        array_push($params, $exact, $exact, $exact, $exact);
        $where[] = '((' . implode(' AND ', $byName) . ') OR ' . $codes . ')';
        $rows = $db->select(
            'SELECT p.id, p.has_variants FROM {products} p LEFT JOIN {product_i18n} i ON i.product_id = p.id AND i.lang = ? WHERE '
            . implode(' AND ', $where) . ' ORDER BY p.sales_count DESC, p.id DESC LIMIT ' . self::MAX_PRODUCTS,
            $params
        );
        $catalog = $app->service('catalog');
        $currency = $app->settings()->str('shop.currency', 'EUR');
        $out = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id'];
            $variants = [0];
            if ((int) $row['has_variants'] === 1) {
                $variants = [];
                $ids = $db->select('SELECT id FROM {variants} WHERE product_id = ? AND active = 1 ORDER BY position, id LIMIT ' . self::MAX_VARIANTS, [$productId]);
                foreach ($ids as $variant) {
                    $variants[] = (int) $variant['id'];
                }
            }
            foreach ($variants as $variantId) {
                if (count($out) >= self::MAX_OPTIONS) {
                    break 2;
                }
                $p = $catalog->purchasable($productId, $variantId);
                if ($p === null) {
                    continue;
                }
                $available = !empty($p['available']);
                $reason = '';
                if (!$available) {
                    $soldOut = $p['stock'] !== null && (int) $p['stock'] <= 0;
                    $reason = $soldOut ? t('Out of stock') : ((string) $p['reason'] !== '' ? (string) $p['reason'] : t('This product is not available'));
                }
                $out[] = [
                    'product_id' => $productId,
                    'variant_id' => (int) $p['variant_id'],
                    'name' => (string) $p['name'],
                    'variant' => (string) $p['variant_label'],
                    'sku' => (string) $p['sku'],
                    'price' => (int) $p['unit_price'],
                    'price_formatted' => money((int) $p['unit_price'], $currency),
                    'stock' => $p['stock'] !== null ? (int) $p['stock'] : null,
                    'available' => $available,
                    'reason' => $reason,
                ];
            }
        }

        return $out;
    }

    /**
     * Las palabras que se buscan: como mucho cuatro, y nada si lo escrito es más corto que MIN_LENGTH.
     *
     * @return string[]
     */
    public static function words(string $q): array
    {
        $q = trim(mb_substr($q, 0, 80, 'UTF-8'));
        if (mb_strlen($q, 'UTF-8') < self::MIN_LENGTH) {
            return [];
        }
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice(is_array($words) ? $words : [], 0, 4);
    }

    /** «%texto%» con los comodines de LIKE escapados con «!». */
    private static function like(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }
}
