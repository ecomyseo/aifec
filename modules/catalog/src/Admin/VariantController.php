<?php
/**
 * AIFEC — pantalla de variantes de un producto: generar combinaciones, y una tabla editable que se guarda de una vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Validation\Validator;

/**
 * Dos acciones sobre la misma dirección (campo «op»): «generate» crea las combinaciones que faltan y «save» guarda la tabla.
 * Las existencias que se cambian a mano se apuntan con catalog->adjustStock() (motivo «manual»), no con un UPDATE directo.
 * Una variante que aparece en pedidos no se borra: se desactiva.
 */
final class VariantController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.view');
        $product = $this->product($params);

        return $this->screen($product, VariantAdmin::rows($this->app, (int) $product['id']), [], 200);
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.edit');
        $product = $this->product($params);
        $productId = (int) $product['id'];
        $back = Response::redirect(VariantAdmin::url($this->app, $productId));

        if ($request->str('op') === 'generate') {
            return $this->generate($request, $productId, $back);
        }
        if ($request->str('op') === 'parse') {
            return $this->parseTyped($request, $product);
        }

        return $this->saveTable($request, $product, $back);
    }

    // ------------------------------------------------------------------ escribirlas

    /**
     * «Escríbelas»: entiende el texto (por reglas y, si no basta y la IA está encendida, con la IA) y enseña la tabla de
     * combinaciones que saldrían. No crea nada: para crearlas hay que confirmar, y eso lo hace la operación «generate».
     *
     * @param array<string,mixed> $product
     */
    private function parseTyped(Request $request, array $product): Response
    {
        $app = $this->app;
        $productId = (int) $product['id'];
        $text = trim(mb_substr($request->str('typed'), 0, 500, 'UTF-8'));
        $attributes = VariantAdmin::parserAttributes($app);
        $parsed = VariantParser::parse($text, $attributes);
        $viaAi = false;
        $problem = '';
        if ($text !== '' && ($parsed['chosen'] === [] || $parsed['unknown'] !== []) && VariantAi::available($app)) {
            try {
                $fromAi = VariantAi::interpret($app, $text, $attributes);
                if ($fromAi !== []) {
                    $parsed = ['chosen' => $fromAi, 'unknown' => []];
                    $viaAi = true;
                }
            } catch (\Throwable $e) {
                $app->log()->exception($e, 'catalog.variants_text');
                $problem = t('The AI could not help right now.');
            }
        }
        $typed = [
            'text' => $text,
            'chosen' => $parsed['chosen'],
            'unknown' => $parsed['unknown'],
            'preview' => $parsed['chosen'] !== [] ? VariantAdmin::preview($app, $productId, $parsed['chosen']) : null,
            'ai' => $viaAi,
            'aiProblem' => $problem,
            'aiOn' => VariantAi::available($app),
        ];

        return $this->screen($product, VariantAdmin::rows($app, $productId), [], 200, $typed);
    }

    // ------------------------------------------------------------------ generar

    private function generate(Request $request, int $productId, Response $back): Response
    {
        $attributes = VariantAdmin::attributes($this->app);
        $sent = $request->arr('gen');
        $chosen = [];
        foreach ($attributes as $attributeId => $attr) {
            $ids = [];
            foreach (isset($sent[$attributeId]) && is_array($sent[$attributeId]) ? $sent[$attributeId] : [] as $valueId) {
                if (is_scalar($valueId) && isset($attr['values'][(int) $valueId])) {
                    $ids[(int) $valueId] = (int) $valueId;
                }
            }
            if ($ids !== []) {
                $chosen[(int) $attributeId] = array_values($ids);
            }
        }
        if ($chosen === []) {
            Admin::flash($this->app, 'warn', t('Choose at least one value of one attribute.'));

            return $back;
        }
        $result = VariantAdmin::generate($this->app, $productId, $chosen);
        Admin::audit($this->app, 'generate_variants', 'products', (string) $productId, $this->name($productId), ['variants' => ['+' . $result['created']]]);
        if ($result['created'] > 0) {
            Admin::flash($this->app, 'ok', tn('{n} variant created.', '{n} variants created.', $result['created']));
        } else {
            Admin::flash($this->app, 'info', t('Every combination already exists.'));
        }
        if ($result['capped']) {
            Admin::flash($this->app, 'warn', t('A product can have {n} variants at most: the rest of the combinations were not created.', ['n' => VariantAdmin::MAX]));
        }

        return $back;
    }

    // ------------------------------------------------------------------ guardar la tabla

    /**
     * @param array<string,mixed> $product
     */
    private function saveTable(Request $request, array $product, Response $back): Response
    {
        $db = $this->app->db();
        $productId = (int) $product['id'];
        $sent = $request->arr('v');
        $rows = VariantAdmin::rows($this->app, $productId);
        $images = array_flip(array_map('intval', $db->column('SELECT media_id FROM {product_media} WHERE product_id = ?', [$productId])));

        $changes = [];
        $errors = [];
        $skus = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $in = isset($sent[$id]) && is_array($sent[$id]) ? $sent[$id] : null;
            if ($in === null) {
                continue;
            }
            $label = (string) $row['display'];
            if (!empty($in['delete'])) {
                $changes[$id] = ['delete' => true, 'in_orders' => (bool) $row['in_orders']];
                continue;
            }
            $clean = ['delete' => false];
            $problem = '';
            foreach (['price', 'compare_price'] as $field) {
                $text = isset($in[$field]) && is_scalar($in[$field]) ? trim((string) $in[$field]) : '';
                $cents = $text === '' ? null : Validator::toCents($text);
                if ($text !== '' && ($cents === null || $cents < 0)) {
                    $problem = $problem !== '' ? $problem : t('Enter an amount, for example 12,50');
                }
                $clean[$field] = $cents;
            }
            $stock = isset($in['stock']) && is_scalar($in['stock']) ? trim((string) $in['stock']) : '';
            if ($stock === '' || preg_match('/^\d{1,9}$/', $stock) !== 1) {
                $problem = $problem !== '' ? $problem : t('The stock has to be a whole number, zero or more.');
            }
            $clean['stock'] = $stock !== '' && preg_match('/^\d{1,9}$/', $stock) === 1 ? (int) $stock : (int) $row['stock'];
            $before = isset($in['stock0']) && is_scalar($in['stock0']) && preg_match('/^\d{1,9}$/', (string) $in['stock0']) === 1 ? (int) $in['stock0'] : (int) $row['stock'];
            $clean['stock_before'] = $before;
            $weight = isset($in['weight']) && is_scalar($in['weight']) ? trim((string) $in['weight']) : '';
            if ($weight !== '' && preg_match('/^\d{1,9}$/', $weight) !== 1) {
                $problem = $problem !== '' ? $problem : t('The weight has to be a whole number of grams.');
            }
            $clean['weight'] = $weight !== '' && preg_match('/^\d{1,9}$/', $weight) === 1 ? (int) $weight : null;
            foreach (['sku' => 64, 'gtin' => 32] as $field => $max) {
                $text = isset($in[$field]) && is_scalar($in[$field]) ? trim((string) $in[$field]) : '';
                if (mb_strlen($text, 'UTF-8') > $max) {
                    $problem = $problem !== '' ? $problem : t('Use {n} characters at most', ['n' => $max]);
                }
                $clean[$field] = $text !== '' ? $text : null;
            }
            $mediaId = isset($in['media_id']) && is_numeric($in['media_id']) ? (int) $in['media_id'] : 0;
            $clean['media_id'] = $mediaId > 0 && isset($images[$mediaId]) ? $mediaId : null;
            $clean['active'] = !empty($in['active']) ? 1 : 0;
            if ($clean['sku'] !== null) {
                $clash = isset($skus[$clean['sku']])
                    || (int) $db->value('SELECT COUNT(*) FROM {variants} WHERE sku = ? AND id <> ?', [$clean['sku'], $id]) > 0
                    || (int) $db->value('SELECT COUNT(*) FROM {products} WHERE sku = ?', [$clean['sku']]) > 0;
                if ($clash) {
                    $problem = $problem !== '' ? $problem : t('This reference is already in use.');
                }
                $skus[$clean['sku']] = true;
            }
            if ($problem !== '') {
                $errors[] = $label . ': ' . $problem;
            }
            $changes[$id] = $clean;
        }

        if ($errors !== []) {
            $shown = $rows;
            foreach ($shown as $i => $row) {
                if (isset($sent[(int) $row['id']]) && is_array($sent[(int) $row['id']])) {
                    $shown[$i] = array_merge($row, array_intersect_key($sent[(int) $row['id']], array_flip(['sku', 'gtin', 'price', 'compare_price', 'stock', 'stock0', 'weight', 'media_id', 'active'])));
                    $shown[$i]['posted'] = true;
                }
            }

            return $this->screen($product, $shown, $errors, 422);
        }

        $now = $this->app->clock()->sql();
        $deactivated = 0;
        $deleted = 0;
        $db->transaction(function () use ($db, $changes, $now, &$deactivated, &$deleted): void {
            foreach ($changes as $id => $change) {
                if ($change['delete']) {
                    if ($change['in_orders']) {
                        $db->update('variants', ['active' => 0, 'updated_at' => $now], ['id' => $id]);
                        $deactivated++;
                    } else {
                        $db->delete('variant_values', ['variant_id' => $id]);
                        $db->run('UPDATE {product_media} SET variant_id = NULL WHERE variant_id = ?', [$id]);
                        $db->delete('variants', ['id' => $id]);
                        $deleted++;
                    }
                    continue;
                }
                $db->update('variants', [
                    'sku' => $change['sku'], 'gtin' => $change['gtin'], 'price' => $change['price'], 'compare_price' => $change['compare_price'],
                    'weight' => $change['weight'], 'media_id' => $change['media_id'], 'active' => $change['active'], 'updated_at' => $now,
                ], ['id' => $id]);
            }
        });

        // Las existencias se apuntan como movimientos; para eso el producto tiene que contar con variantes en este momento.
        $any = (int) $db->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$productId]) > 0;
        if ($any) {
            $db->update('products', ['has_variants' => 1], ['id' => $productId]);
        }
        $catalog = $this->app->service('catalog');
        $failed = 0;
        foreach ($changes as $id => $change) {
            if ($change['delete'] || $change['stock'] === $change['stock_before']) {
                continue;
            }
            if (!$catalog->adjustStock($productId, (int) $id, $change['stock'] - $change['stock_before'], 'manual')) {
                $failed++;
            }
        }
        VariantAdmin::refresh($this->app, $productId);

        Admin::audit($this->app, 'update_variants', 'products', (string) $productId, $this->name($productId), ['variants' => [(string) count($rows), (string) (count($rows) - $deleted)]]);
        Admin::flash($this->app, 'ok', t('Variants saved'));
        if ($deactivated > 0) {
            Admin::flash($this->app, 'warn', tn('{n} variant is in orders: it was turned off instead of deleted.', '{n} variants are in orders: they were turned off instead of deleted.', $deactivated));
        }
        if ($failed > 0) {
            Admin::flash($this->app, 'warn', t('The stock of some variants could not be changed.'));
        }

        return $back;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function product(array $params): array
    {
        $row = $this->app->db()->find('products', ['id' => isset($params['id']) ? (int) $params['id'] : 0]);
        if ($row === null) {
            throw HttpError::notFound();
        }

        return $row;
    }

    private function name(int $productId): string
    {
        $name = $this->app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$productId, $this->app->defaultLang()]);

        return $name !== null && (string) $name !== '' ? (string) $name : '#' . $productId;
    }

    /**
     * @param array<string,mixed>            $product
     * @param array<int,array<string,mixed>> $rows
     * @param string[]                       $errors
     * @param array<string,mixed>|null       $typed  lo que salió de «Escríbelas» (texto, lo elegido, lo no entendido, la tabla de combinaciones)
     */
    private function screen(array $product, array $rows, array $errors, int $status, ?array $typed = null): Response
    {
        $app = $this->app;
        $id = (int) $product['id'];
        $images = [];
        foreach ($app->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position, media_id', [$id]) as $n => $mediaId) {
            $images[(int) $mediaId] = t('Image {n}', ['n' => $n + 1]);
        }
        $name = $this->name($id);

        return Admin::page($app, 'catalog::admin/product_variants', [
            'productId' => $id,
            'attributes' => VariantAdmin::attributes($app),
            'typed' => $typed,
            'aiOn' => $typed !== null ? (bool) $typed['aiOn'] : VariantAi::available($app),
            'rows' => $rows,
            'images' => $images,
            'errors' => $errors,
            'canEdit' => $app->auth()->can('catalog.edit'),
            'action' => VariantAdmin::url($app, $id),
            'attributesUrl' => Admin::resourceUrl($app, 'attributes'),
            'max' => VariantAdmin::MAX,
        ], [
            'title' => t('Variants: {name}', ['name' => $name]),
            'crumbs' => [
                [t('Products'), Admin::resourceUrl($app, 'products')],
                [$name, Admin::resourceUrl($app, 'products', $id)],
            ],
            'active' => '/admin/r/products',
            'help' => Admin::helpText("A variant is what the customer really buys: a colour, a size… Mark the attributes that make variants in Catalog, Attributes.\nWrite them in your own words, for example black and grey; sizes S to L: you see the combinations before anything is created.\nGenerate creates the combinations that are missing; the ones that already exist are not repeated.\nA price left empty is the price of the product. The stock of a product with variants is the stock of each variant.\nA variant that is in orders cannot be deleted: it is turned off."),
        ], $status);
    }
}
