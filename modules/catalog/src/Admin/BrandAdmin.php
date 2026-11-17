<?php
/**
 * AIFEC — marcas en el panel: listado, formulario, dirección amigable y borrado seguro.
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
 * Piezas del recurso «brands» de admin.php. La marca no tiene textos por idioma: su dirección («marca/<trozo>») se registra
 * solo en el idioma principal y la tienda la enseña en todos.
 */
final class BrandAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'from' => '{brands} b',
            'select' => 'b.*, (SELECT COUNT(*) FROM {products} p WHERE p.brand_id = b.id) AS product_total',
            'key' => 'id',
            'key_sql' => 'b.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 'b.name'],
                'product_total' => ['label' => 'Products', 'type' => 'int', 'hide_sm' => true],
                'website' => ['label' => 'Website', 'limit' => 40, 'hide_sm' => true],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => 'b.position', 'hide_sm' => true],
                'active' => ['label' => 'Active', 'type' => 'bool'],
            ],
            'search' => ['b.name'],
            'filters' => ['active' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive'], 'column' => 'b.active']],
            'sort' => ['b.position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $segment = '';
        if ($id !== null) {
            $path = $app->slugs()->path('brand', $id, $app->defaultLang());
            $segment = $path !== null ? TaxonomySlugs::segment($path) : '';
        }

        return [
            'fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
                'slug' => ['type' => 'slug', 'label' => 'Address', 'store' => false, 'rules' => 'max:90', 'from' => 'name', 'default' => $segment,
                    'hint' => 'Leave it empty to take it from the name.'],
                'description' => ['type' => 'richtext', 'label' => 'Description'],
                'website' => ['type' => 'url', 'label' => 'Website', 'rules' => 'max:191', 'placeholder' => 'https://'],
                'media_id' => ['type' => 'image', 'label' => 'Logo'],
                'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm'],
            ],
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }

        return '<a class="af-btn" target="_blank" rel="noopener" href="' . e($app->url()->entity('brand', (int) $row['id'])) . '">' . Admin::icon('external') . e(t('View')) . '</a>';
    }

    /**
     * Lo vacío se guarda como «sin valor».
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        foreach (['website', 'description'] as $field) {
            if (isset($values[$field]) && trim((string) $values[$field]) === '') {
                $values[$field] = null;
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $typed = isset($values['slug']) && is_string($values['slug']) ? trim($values['slug']) : '';
        $name = isset($values['name']) && is_string($values['name']) ? $values['name'] : '';
        $segment = TaxonomySlugs::make($typed !== '' ? $typed : $name, 'brand-' . $id);
        $app->slugs()->set('brand', $id, $app->defaultLang(), TaxonomySlugs::prefix($app, 'brand') . '/' . $segment);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return string|null el motivo por el que no se puede borrar, o null
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $products = (int) $app->db()->value('SELECT COUNT(*) FROM {products} WHERE brand_id = ?', [(int) $row['id']]);
        if ($products > 0) {
            return tn('This brand has {n} product. Move it to another brand first.', 'This brand has {n} products. Move them to another brand first.', $products);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $app->slugs()->remove('brand', (int) $row['id']);
    }
}
