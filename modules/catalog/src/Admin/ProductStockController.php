<?php
/**
 * AIFEC — edición rápida de precio y existencias: una tabla de productos y variantes que se guarda por páginas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Attributes;
use Aifec\Validation\Validator;

/**
 * GET /admin/catalog/stock enseña los productos de una página (con sus variantes debajo) con el precio y las existencias
 * en campos; POST guarda solo lo que la persona ha cambiado. Las existencias se apuntan con catalog->adjustStock() (motivo
 * «manual») sumando la diferencia con lo que se veía al abrir la página, así una venta hecha mientras tanto no se pierde.
 */
final class ProductStockController
{
    private const PER_PAGE = 30;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.view');
        $db = $this->app->db();
        $q = $request->str('q');
        $low = $request->str('low') === '1';

        $where = [];
        $args = [$this->app->defaultLang()];
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "(x.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!')";
            $args[] = $like;
            $args[] = $like;
        }
        if ($low) {
            $where[] = '(' . ProductAdmin::lowSql(ProductAdmin::lowThreshold($this->app), 'p') . ')';
        }
        $from = '{products} p LEFT JOIN {product_i18n} x ON x.product_id = p.id AND x.lang = ?' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $db->value('SELECT COUNT(*) FROM ' . $from, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->int('p', 1)));
        $products = $db->select(
            'SELECT p.id, p.sku, p.price, p.stock, p.stock_alert, p.manage_stock, p.has_variants, p.backorders, x.name FROM ' . $from
            . ' ORDER BY x.name, p.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );

        $variants = $this->variants(array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $products));
        $canEdit = $this->app->auth()->can('catalog.edit');
        $actions = '<a class="af-btn" href="' . e($this->app->url()->plain('/admin/r/products')) . '">' . Admin::icon('box') . e(t('Products')) . '</a>';

        return Admin::page($this->app, 'catalog::admin/product_stock', [
            'products' => $products,
            'variants' => $variants,
            'q' => $q,
            'low' => $low,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'canEdit' => $canEdit,
            'action' => $this->app->url()->plain('/admin/catalog/stock'),
            'here' => $this->app->url()->plain('/admin/catalog/stock', ['q' => $q, 'low' => $low ? '1' : null, 'p' => $page > 1 ? (string) $page : null]),
            'productUrl' => function (int $id): string {
                return Admin::resourceUrl($this->app, 'products', $id);
            },
        ], [
            'title' => t('Stock and prices'),
            'actions' => $actions,
            'active' => '/admin/catalog/stock',
            'help' => Admin::helpText("Change the price or the stock of many products at once and press Save: only what you changed is saved.\nA price left empty in a variant is the price of the product.\nThe stock you type is recorded as a manual movement."),
        ]);
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.edit');
        $db = $this->app->db();
        $catalog = $this->app->service('catalog');
        $back = Admin::back($this->app, $request, $this->app->url()->plain('/admin/catalog/stock'));

        $products = $request->arr('p');
        $variants = $request->arr('v');
        $changed = [];
        $problems = [];
        $saved = 0;

        foreach ($products as $rawId => $in) {
            $id = (int) $rawId;
            $row = is_array($in) ? $db->row('SELECT id, price, stock, manage_stock, has_variants FROM {products} WHERE id = ?', [$id]) : null;
            if ($row === null) {
                continue;
            }
            $name = $this->name($id);
            $price = $this->price($in, 'price', 'price0');
            if ($price === false) {
                $problems[] = $name . ': ' . t('Enter an amount, for example 12,50');
            } elseif ($price !== null && $price !== (int) $row['price']) {
                $db->update('products', ['price' => max(0, $price), 'updated_at' => $this->app->clock()->sql()], ['id' => $id]);
                $changed[$id]['price'] = [(string) $row['price'], (string) max(0, $price)];
                $saved++;
            }
            if ((int) $row['has_variants'] === 0 && array_key_exists('stock', $in)) {
                $delta = $this->stockDelta($in, (int) $row['stock']);
                if ($delta === false) {
                    $problems[] = $name . ': ' . t('The stock has to be a whole number, zero or more.');
                } elseif ($delta !== 0 && (int) $row['manage_stock'] === 1) {
                    if ($catalog->adjustStock($id, 0, $delta, 'manual')) {
                        $changed[$id]['stock'] = [(string) $row['stock'], (string) ((int) $row['stock'] + $delta)];
                        $saved++;
                    } else {
                        $problems[] = $name . ': ' . t('The stock could not be changed.');
                    }
                }
            }
        }

        foreach ($variants as $rawId => $in) {
            $variantId = (int) $rawId;
            $row = is_array($in) ? $db->row('SELECT v.id, v.product_id, v.price, v.stock, p.manage_stock FROM {variants} v JOIN {products} p ON p.id = v.product_id WHERE v.id = ?', [$variantId]) : null;
            if ($row === null) {
                continue;
            }
            $productId = (int) $row['product_id'];
            $name = $this->name($productId);
            $label = $name . ' · #' . $variantId;
            $text = isset($in['price']) && is_scalar($in['price']) ? trim((string) $in['price']) : '';
            $before = isset($in['price0']) && is_scalar($in['price0']) ? trim((string) $in['price0']) : $text;
            if ($text !== $before) {
                $cents = $text === '' ? null : Validator::toCents($text);
                if ($text !== '' && ($cents === null || $cents < 0)) {
                    $problems[] = $label . ': ' . t('Enter an amount, for example 12,50');
                } else {
                    $db->update('variants', ['price' => $cents, 'updated_at' => $this->app->clock()->sql()], ['id' => $variantId]);
                    $changed[$productId]['variant ' . $variantId . ' price'] = [(string) $row['price'], $cents === null ? '' : (string) $cents];
                    $saved++;
                }
            }
            if (array_key_exists('stock', $in) && (int) $row['manage_stock'] === 1) {
                $delta = $this->stockDelta($in, (int) $row['stock']);
                if ($delta === false) {
                    $problems[] = $label . ': ' . t('The stock has to be a whole number, zero or more.');
                } elseif ($delta !== 0) {
                    if ($catalog->adjustStock($productId, $variantId, $delta, 'manual')) {
                        $changed[$productId]['variant ' . $variantId . ' stock'] = [(string) $row['stock'], (string) ((int) $row['stock'] + $delta)];
                        $saved++;
                    } else {
                        $problems[] = $label . ': ' . t('The stock could not be changed.');
                    }
                }
            }
        }

        $tags = [];
        foreach ($changed as $productId => $diff) {
            $tags[] = 'product:' . $productId;
            Admin::audit($this->app, 'quick_edit', 'products', (string) $productId, $this->name($productId), $diff);
        }
        if ($tags !== []) {
            $tags[] = 'catalog';
            $this->app->invalidate($tags);
        }
        if ($saved > 0) {
            Admin::flash($this->app, 'ok', tn('{n} change saved.', '{n} changes saved.', $saved));
        } elseif ($problems === []) {
            Admin::flash($this->app, 'info', t('Nothing had changed.'));
        }
        if ($problems !== []) {
            Admin::flash($this->app, 'bad', implode(' · ', array_slice($problems, 0, 5)));
        }

        return $back;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El precio nuevo en céntimos; null si no ha cambiado; false si no es un importe.
     *
     * @param array<string,mixed> $in
     *
     * @return int|false|null
     */
    private function price(array $in, string $field, string $original)
    {
        $text = isset($in[$field]) && is_scalar($in[$field]) ? trim((string) $in[$field]) : '';
        $before = isset($in[$original]) && is_scalar($in[$original]) ? trim((string) $in[$original]) : $text;
        if ($text === $before) {
            return null;
        }
        $cents = Validator::toCents($text);

        return $cents === null || $cents < 0 ? false : $cents;
    }

    /**
     * Cuánto hay que sumar a las existencias: lo escrito menos lo que se veía al abrir la página. 0 si no ha cambiado;
     * false si no es un número entero de cero o más.
     *
     * @param array<string,mixed> $in
     *
     * @return int|false
     */
    private function stockDelta(array $in, int $current)
    {
        $text = isset($in['stock']) && is_scalar($in['stock']) ? trim((string) $in['stock']) : '';
        $before = isset($in['stock0']) && is_scalar($in['stock0']) && preg_match('/^-?\d{1,9}$/', trim((string) $in['stock0'])) === 1 ? (int) trim((string) $in['stock0']) : $current;
        if (preg_match('/^\d{1,9}$/', $text) !== 1) {
            return false;
        }

        return (int) $text - $before;
    }

    private function name(int $productId): string
    {
        $name = $this->app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$productId, $this->app->defaultLang()]);

        return $name !== null && (string) $name !== '' ? (string) $name : '#' . $productId;
    }

    /**
     * Las variantes de unos productos, con su etiqueta calculada, de una sola vez.
     *
     * @param int[] $productIds
     *
     * @return array<int,array<int,array<string,mixed>>> id del producto => variantes
     */
    private function variants(array $productIds): array
    {
        $db = $this->app->db();
        if ($productIds === []) {
            return [];
        }
        $params = [];
        $rows = $db->select('SELECT * FROM {variants} WHERE product_id IN (' . $db->in($productIds, $params) . ') ORDER BY position, id', $params);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $maps = [];
        $params = [];
        foreach ($db->select('SELECT variant_id, attribute_id, value_id FROM {variant_values} WHERE variant_id IN (' . $db->in($ids, $params) . ')', $params) as $value) {
            $maps[(int) $value['variant_id']][(int) $value['attribute_id']] = (int) $value['value_id'];
        }
        $attributes = new Attributes($this->app);
        $all = $attributes->all($this->app->defaultLang());
        $out = [];
        foreach ($rows as $row) {
            $row['display'] = $attributes->variantLabel($row, isset($maps[(int) $row['id']]) ? $maps[(int) $row['id']] : [], $all);
            $out[(int) $row['product_id']][] = $row;
        }

        return $out;
    }
}
