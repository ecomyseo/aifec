<?php
/**
 * AIFEC — productos en el panel: listado, validaciones, guardado, borrado, acciones en bloque y piezas del inicio.
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
 * Sus piezas las usa el recurso «products» de admin.php (list, form, before_save, after_save, before_delete, after_delete,
 * bulk, actions) y los ganchos del inicio del panel. El formulario es ProductForm y lo que se guarda aparte, ProductSaver.
 */
final class ProductAdmin
{
    // ------------------------------------------------------------------ listado

    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        $db = $app->db();
        $lang = self::quotedLang($app);
        $low = self::lowThreshold($app);
        $variantStock = '(SELECT COALESCE(SUM(v.stock), 0) FROM {variants} v WHERE v.product_id = t.id AND v.active = 1)';
        $variantCount = '(SELECT COUNT(*) FROM {variants} v2 WHERE v2.product_id = t.id AND v2.active = 1)';

        $categories = CategoryAdmin::parentOptions($app, null);
        $categorySql = [];
        foreach ($db->select('SELECT id, path FROM {categories}') as $category) {
            $id = (int) $category['id'];
            $path = (string) preg_replace('/[^0-9\/]/', '', (string) $category['path']);
            $inside = 'c.id = ' . $id . ($path !== '' ? " OR c.path = '" . $path . "' OR c.path LIKE '" . $path . "/%'" : '');
            $categorySql[(string) $id] = 't.id IN (SELECT pc.product_id FROM {product_categories} pc JOIN {categories} c ON c.id = pc.category_id WHERE ' . $inside . ')';
        }
        $brands = [];
        foreach ($db->select('SELECT id, name FROM {brands} ORDER BY name') as $brand) {
            $brands[(string) $brand['id']] = (string) $brand['name'];
        }

        return [
            'from' => '{products} t LEFT JOIN {product_i18n} x ON x.product_id = t.id AND x.lang = ' . $lang,
            'select' => 't.*, x.name AS name, ' . $variantStock . ' AS variant_stock, ' . $variantCount . ' AS variant_count',
            'key' => 'id',
            'key_sql' => 't.id',
            'columns' => [
                'main_media_id' => ['label' => 'Image', 'type' => 'image'],
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 'x.name'],
                'sku' => ['label' => 'Reference (SKU)', 'sort' => 't.sku', 'hide_sm' => true],
                'price' => ['label' => 'Price', 'type' => 'money', 'sort' => 't.price'],
                'stock' => ['label' => 'Stock', 'sort' => 't.stock', 'render' => static function (array $row, App $app) use ($low): string {
                    return self::stockCell($row, $low);
                }],
                'status' => ['label' => 'Status', 'type' => 'badge', 'sort' => 't.status',
                    'map' => ['draft' => ['Draft', 'warn'], 'published' => ['Published', 'ok'], 'archived' => ['Archived', '']]],
                'featured' => ['label' => 'Featured', 'type' => 'bool', 'hide_sm' => true],
            ],
            'search' => ['x.name', 't.sku', 't.gtin', 't.mpn'],
            'filters' => [
                'status' => ['label' => 'Status', 'options' => ProductForm::STATUSES, 'column' => 't.status'],
                'category' => ['label' => 'Category', 'options' => $categories, 'raw' => true, 'sql' => $categorySql, 'column' => 't.id'],
                'brand' => ['label' => 'Brand', 'options' => $brands, 'raw' => true, 'column' => 't.brand_id'],
                'stock' => ['label' => 'Stock', 'options' => ['low' => 'Low stock', 'out' => 'Sold out'], 'column' => 't.stock', 'sql' => [
                    'low' => self::lowSql($low),
                    'out' => self::outSql(),
                ]],
                'issues' => ['label' => 'Needs attention', 'options' => ['incomplete' => 'Published without image or price'], 'column' => 't.id',
                    'sql' => ['incomplete' => self::incompleteSql()]],
            ],
            'sort' => ['t.id', 'desc'],
            'per_page' => 25,
        ];
    }

    /**
     * Existencias de una fila del listado, con su insignia: roja si está agotado, amarilla si quedan pocas.
     *
     * @param array<string,mixed> $row
     */
    public static function stockCell(array $row, int $low): string
    {
        if ((int) $row['manage_stock'] === 0) {
            return '<span class="af-muted">—</span>';
        }
        $hasVariants = (int) $row['has_variants'] === 1 && (int) $row['variant_count'] > 0;
        $qty = $hasVariants ? (int) $row['variant_stock'] : (int) $row['stock'];
        $limit = max((int) $row['stock_alert'], $low);
        $html = '<span class="af-nowrap">' . e((string) $qty) . '</span>';
        if ($hasVariants) {
            $html .= ' <span class="af-muted af-small">' . e(tn('{n} variant', '{n} variants', (int) $row['variant_count'])) . '</span>';
        }
        if ($qty <= 0 && (string) $row['backorders'] !== 'yes') {
            $html .= ' ' . Admin::badge(t('Sold out'), 'bad');
        } elseif ($qty <= $limit) {
            $html .= ' ' . Admin::badge(t('Low stock'), 'warn');
        }

        return $html;
    }

    /**
     * El idioma principal entre comillas, para ponerlo en el SQL del listado (es un código ya validado, no una entrada).
     */
    private static function quotedLang(App $app): string
    {
        $lang = $app->defaultLang();

        return "'" . (preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})?$/', $lang) === 1 ? $lang : 'es') . "'";
    }

    public static function lowThreshold(App $app): int
    {
        return max(0, $app->settings()->int('catalog.low_stock', 5));
    }

    /**
     * SQL de «pocas existencias»: productos y variantes por debajo de su aviso (el mayor entre el suyo y el de los ajustes).
     *
     * @param string $a alias de la tabla de productos en la consulta
     */
    public static function lowSql(int $low, string $a = 't'): string
    {
        $limit = 'CASE WHEN ' . $a . '.stock_alert > ' . $low . ' THEN ' . $a . '.stock_alert ELSE ' . $low . ' END';

        return $a . '.manage_stock = 1 AND ((' . $a . '.has_variants = 0 AND ' . $a . '.stock <= ' . $limit . ') OR (' . $a . '.has_variants = 1 AND EXISTS ('
            . 'SELECT 1 FROM {variants} fv WHERE fv.product_id = ' . $a . '.id AND fv.active = 1 AND fv.stock <= ' . $limit . ')))';
    }

    private static function outSql(): string
    {
        return "t.manage_stock = 1 AND t.backorders <> 'yes' AND ((t.has_variants = 0 AND t.stock <= 0) OR (t.has_variants = 1 AND NOT EXISTS ("
            . 'SELECT 1 FROM {variants} fv WHERE fv.product_id = t.id AND fv.active = 1 AND fv.stock > 0)))';
    }

    /** SQL de «publicado sin imagen o sin precio»; el precio de una variante activa también cuenta. */
    private static function incompleteSql(): string
    {
        return "t.status = 'published' AND (t.main_media_id IS NULL OR (t.price <= 0 AND NOT EXISTS ("
            . 'SELECT 1 FROM {variants} pv WHERE pv.product_id = t.id AND pv.active = 1 AND pv.price > 0)))';
    }

    // ------------------------------------------------------------------ guardar

    /**
     * Normaliza lo vacío y comprueba las reglas que cruzan campos: la oferta menor que el precio, las fechas en orden,
     * los máximos, y la categoría principal entre las elegidas.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        // Un alta solo necesita nombre y precio: lo que no llega nace con su valor razonable (borrador, visible en todas partes,
        // físico, sin pedidos sin existencias, existencias sin controlar). Una ficha que ya existe conserva lo que tenía.
        foreach (['status' => 'draft', 'visibility' => 'everywhere', 'kind' => 'physical', 'backorders' => 'no'] as $field => $default) {
            if (!isset($values[$field]) || $values[$field] === '') {
                $values[$field] = $current !== null && isset($current[$field]) ? (string) $current[$field] : $default;
            }
        }
        // Un interruptor que no llega en el envío no se apaga: en una ficha que ya existe se queda como estaba.
        $sent = ProductForm::posted($app);
        foreach (['featured' => 0, 'manage_stock' => 0] as $field => $default) {
            if ($current !== null && isset($current[$field]) && !array_key_exists($field, $sent)) {
                $values[$field] = (int) $current[$field];
            } elseif (!isset($values[$field]) || $values[$field] === '') {
                $values[$field] = $default;
            }
        }
        foreach (['price' => 0, 'stock' => 0, 'stock_alert' => 0, 'min_qty' => 1, 'max_qty' => 0, 'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0] as $field => $default) {
            if (!isset($values[$field]) || $values[$field] === '') {
                $values[$field] = $default;
            }
        }
        $errors = [];
        $price = (int) $values['price'];
        if (isset($values['sale_price']) && $values['sale_price'] !== null && (int) $values['sale_price'] >= $price) {
            $errors['sale_price'] = t('The sale price has to be lower than the price.');
        }
        if (!empty($values['sale_from']) && !empty($values['sale_to']) && (string) $values['sale_to'] <= (string) $values['sale_from']) {
            $errors['sale_to'] = t('The sale has to end after it starts.');
        }
        if ((int) $values['max_qty'] > 0 && (int) $values['max_qty'] < (int) $values['min_qty']) {
            $errors['max_qty'] = t('The maximum cannot be lower than the minimum.');
        }
        if (isset($values['delivery_min_days'], $values['delivery_max_days']) && (int) $values['delivery_max_days'] < (int) $values['delivery_min_days']) {
            $errors['delivery_max_days'] = t('The maximum cannot be lower than the minimum.');
        }
        if ($errors !== []) {
            return ['_errors' => $errors];
        }

        $chosen = [];
        foreach (isset($values['categories']) && is_array($values['categories']) ? $values['categories'] : [] as $categoryId) {
            $chosen[(int) $categoryId] = (int) $categoryId;
        }
        $main = isset($values['main_category']) && is_numeric($values['main_category']) ? (int) $values['main_category'] : 0;
        $values['main_category'] = isset($chosen[$main]) ? $main : ($chosen !== [] ? (int) reset($chosen) : null);

        return $values;
    }

    /**
     * Categorías, etiquetas, imágenes, atributos, datos clave, preguntas, relacionados, ficheros y direcciones, todo o nada.
     * Si falla, un producto que ya existía conserva lo que tenía; uno nuevo se queda como borrador y se dice.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        try {
            $warnings = (new ProductSaver($app, $id, $values, $current))->run();
            foreach ($warnings as $warning) {
                Admin::flash($app, 'warn', $warning);
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'catalog.product_save');
            if ($current === null) {
                $app->db()->update('products', ['status' => 'draft'], ['id' => $id]);
                Admin::flash($app, 'bad', t('The product was created as a draft, but its categories, images and other data could not be saved. Open it and save again.'));
            } else {
                Admin::flash($app, 'bad', t('The categories, images and other data of the product could not be saved, so they stay as they were. Try again.'));
            }
        }
        CategoryAdmin::recount($app);
        $app->invalidate(['product:' . $id, 'catalog']);
    }

    // ------------------------------------------------------------------ borrar

    /**
     * Un producto que aparece en pedidos no se borra (los pedidos guardan su identificador): se archiva.
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $db = $app->db();
        if ($db->tableExists('order_items')) {
            $orders = (int) $db->value('SELECT COUNT(DISTINCT order_id) FROM {order_items} WHERE product_id = ?', [(int) $row['id']]);
            if ($orders > 0) {
                return tn('This product is in {n} order, so it cannot be deleted. Archive it instead.', 'This product is in {n} orders, so it cannot be deleted. Archive it instead.', $orders);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $id = (int) $row['id'];
        ProductSaver::erase($app, $id);
        CategoryAdmin::recount($app);
        $app->invalidate(['product:' . $id, 'catalog']);
    }

    // ------------------------------------------------------------------ acciones en bloque

    /**
     * @param int[] $ids
     */
    public static function bulkPublish(array $ids, App $app): string
    {
        $n = self::setStatus($app, $ids, 'published');

        return tn('{n} product published', '{n} products published', $n);
    }

    /**
     * @param int[] $ids
     */
    public static function bulkDraft(array $ids, App $app): string
    {
        $n = self::setStatus($app, $ids, 'draft');

        return tn('{n} product turned into a draft', '{n} products turned into drafts', $n);
    }

    /**
     * @param int[] $ids
     */
    public static function bulkArchive(array $ids, App $app): string
    {
        $n = self::setStatus($app, $ids, 'archived');

        return tn('{n} product archived', '{n} products archived', $n);
    }

    /**
     * @param int[] $ids
     */
    private static function setStatus(App $app, array $ids, string $status): int
    {
        $db = $app->db();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $now = $app->clock()->sql();
        $params = [$status, $now];
        $sql = 'UPDATE {products} SET status = ?, updated_at = ?';
        if ($status === 'published') {
            $sql .= ', published_at = COALESCE(published_at, ?)';
            $params[] = $now;
        }
        $n = $db->run($sql . ' WHERE id IN (' . $db->in($ids, $params) . ')', $params);
        $tags = ['catalog'];
        foreach ($ids as $id) {
            $tags[] = 'product:' . $id;
        }
        $app->invalidate($tags);
        CategoryAdmin::recount($app);

        return $n;
    }

    // ------------------------------------------------------------------ inicio del panel

    /**
     * Pieza «Low stock»: los 8 productos o variantes con menos existencias por debajo de su aviso. Sin ninguno, no sale.
     */
    public static function widget(App $app): string
    {
        $db = $app->db();
        $low = self::lowThreshold($app);
        $limit = 'CASE WHEN p.stock_alert > ' . $low . ' THEN p.stock_alert ELSE ' . $low . ' END';
        $lang = $app->defaultLang();
        $items = [];
        $sql = 'SELECT p.id, p.sku, p.stock, x.name FROM {products} p LEFT JOIN {product_i18n} x ON x.product_id = p.id AND x.lang = ? '
            . 'WHERE p.manage_stock = 1 AND p.has_variants = 0 AND p.status = ? AND p.stock <= ' . $limit . ' ORDER BY p.stock, p.id LIMIT 8';
        foreach ($db->select($sql, [$lang, 'published']) as $row) {
            $items[] = ['id' => (int) $row['id'], 'name' => (string) ($row['name'] !== null ? $row['name'] : '#' . $row['id']), 'detail' => (string) ($row['sku'] !== null ? $row['sku'] : ''), 'stock' => (int) $row['stock']];
        }
        $sql = 'SELECT p.id, v.id AS variant_id, v.sku, v.stock, v.label, x.name FROM {variants} v JOIN {products} p ON p.id = v.product_id '
            . 'LEFT JOIN {product_i18n} x ON x.product_id = p.id AND x.lang = ? '
            . 'WHERE p.manage_stock = 1 AND p.has_variants = 1 AND p.status = ? AND v.active = 1 AND v.stock <= ' . $limit . ' ORDER BY v.stock, v.id LIMIT 8';
        foreach ($db->select($sql, [$lang, 'published']) as $row) {
            $detail = $row['label'] !== null && (string) $row['label'] !== '' ? (string) $row['label'] : '';
            if ($detail === '') {
                foreach (VariantAdmin::rows($app, (int) $row['id']) as $variant) {
                    if ((int) $variant['id'] === (int) $row['variant_id']) {
                        $detail = (string) $variant['display'];
                    }
                }
            }
            $items[] = ['id' => (int) $row['id'], 'name' => (string) ($row['name'] !== null ? $row['name'] : '#' . $row['id']), 'detail' => $detail, 'stock' => (int) $row['stock'], 'variant' => true];
        }
        if ($items === []) {
            return '';
        }
        usort($items, static function (array $a, array $b): int {
            return $a['stock'] <=> $b['stock'];
        });
        $items = array_slice($items, 0, 8);

        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Low stock')) . '</h2></div><div class="af-table-wrap"><table class="af-table"><tbody>';
        foreach ($items as $item) {
            $url = isset($item['variant']) ? VariantAdmin::url($app, $item['id']) : Admin::resourceUrl($app, 'products', $item['id']);
            $html .= '<tr><td><a href="' . e($url) . '"><strong>' . e($item['name']) . '</strong></a>'
                . ($item['detail'] !== '' ? ' <span class="af-muted af-small">' . e($item['detail']) . '</span>' : '') . '</td>'
                . '<td class="af-num">' . Admin::badge((string) $item['stock'], $item['stock'] <= 0 ? 'bad' : 'warn') . '</td></tr>';
        }

        return $html . '</tbody></table></div><div class="af-card-body"><a class="af-btn af-btn-sm" href="'
            . e(Admin::resourceUrl($app, 'products', null, ['f' => ['stock' => 'low']])) . '">' . e(t('See all')) . '</a></div></div>';
    }

    /**
     * Filtro «admin.status»: avisa si hay productos publicados sin imagen o sin precio.
     *
     * @param array<int,array<string,string>> $status
     *
     * @return array<int,array<string,string>>
     */
    public static function status(array $status, App $app): array
    {
        if (!$app->auth()->can('catalog.view')) {
            return $status;
        }
        $n = (int) $app->db()->value('SELECT COUNT(*) FROM {products} t WHERE ' . self::incompleteSql());
        if ($n > 0) {
            $status[] = [
                'kind' => 'warn',
                'text' => tn('{n} published product has no image or no price.', '{n} published products have no image or no price.', $n),
                'url' => Admin::resourceUrl($app, 'products', null, ['f' => ['issues' => 'incomplete']]),
                'label' => t('Review'),
            ];
        }

        return $status;
    }
}
