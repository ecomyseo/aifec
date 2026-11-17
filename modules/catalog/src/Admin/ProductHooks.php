<?php
/**
 * AIFEC — ganchos del panel para los productos: el buscador de órdenes (Ctrl + K).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * Los registra routes.php de este módulo. Un gancho que lanza no tumba la petición (Hooks lo recoge y lo apunta).
 */
final class ProductHooks
{
    /**
     * Filtro «admin.palette»: productos por nombre o referencia, para quien puede ver el catálogo.
     *
     * @param array<int,array<string,string>> $items
     *
     * @return array<int,array<string,string>>
     */
    public static function palette(array $items, string $q, App $app): array
    {
        $q = trim($q);
        if ($q === '' || !$app->auth()->can('catalog.view')) {
            return $items;
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $sql = 'SELECT p.id, p.sku, x.name FROM {products} p LEFT JOIN {product_i18n} x ON x.product_id = p.id AND x.lang = ? '
            . "WHERE x.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' ORDER BY x.name, p.id LIMIT 8";
        foreach ($app->db()->select($sql, [$app->defaultLang(), $like, $like]) as $row) {
            $name = $row['name'] !== null && (string) $row['name'] !== '' ? (string) $row['name'] : '#' . $row['id'];
            $items[] = [
                'label' => $name . ($row['sku'] !== null && (string) $row['sku'] !== '' ? ' · ' . $row['sku'] : ''),
                'group' => t('Products'),
                'url' => Admin::resourceUrl($app, 'products', (int) $row['id']),
            ];
        }

        return $items;
    }
}
