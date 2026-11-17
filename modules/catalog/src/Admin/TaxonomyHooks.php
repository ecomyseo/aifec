<?php
/**
 * AIFEC — ganchos del panel del catálogo: buscador de órdenes y recálculo de direcciones al cambiar un prefijo.
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
final class TaxonomyHooks
{
    /**
     * Filtro «admin.palette»: categorías y marcas por nombre, para quien puede ver el catálogo.
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
        $db = $app->db();
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        foreach ($db->select("SELECT category_id, MIN(name) AS name FROM {category_i18n} WHERE name LIKE ? ESCAPE '!' GROUP BY category_id ORDER BY MIN(name) LIMIT 6", [$like]) as $row) {
            $items[] = [
                'label' => (string) $row['name'],
                'group' => t('Categories'),
                'url' => Admin::resourceUrl($app, 'categories', (int) $row['category_id']),
            ];
        }
        foreach ($db->select("SELECT id, name FROM {brands} WHERE name LIKE ? ESCAPE '!' ORDER BY name LIMIT 6", [$like]) as $row) {
            $items[] = [
                'label' => (string) $row['name'],
                'group' => t('Brands'),
                'url' => Admin::resourceUrl($app, 'brands', (int) $row['id']),
            ];
        }

        return $items;
    }

    /**
     * Gancho «settings.saved»: si ha cambiado la palabra de las direcciones de productos, categorías o marcas,
     * se recalculan las de esa clase de entidad (las antiguas quedan redirigiendo) y se vacía la caché del catálogo.
     *
     * @param string[] $keys claves de ajuste cambiadas
     */
    public static function settingsSaved(string $module, array $keys, App $app): void
    {
        if ($module !== 'catalog') {
            return;
        }
        $moved = 0;
        foreach (['product', 'category', 'brand'] as $entity) {
            if (in_array('urls.' . $entity, $keys, true)) {
                $moved += TaxonomySlugs::rebuild($app, $entity);
            }
        }
        if ($moved > 0) {
            $app->invalidate(['catalog']);
        }
    }
}
