<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot\Actions;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Finder;
use Aifec\Modules\Copilot\Fmt;
use Aifec\Modules\Copilot\Params;
use Aifec\Modules\Copilot\Phrases;
use Aifec\Modules\Copilot\ProductScope;
use Aifec\Modules\Copilot\Reader;
use Aifec\Util\Str;

/**
 * Las acciones del catálogo: el precio de un producto, los precios de un conjunto, las existencias, activar o desactivar y crear
 * un producto en borrador. Todas con el permiso «catalog.edit».
 *
 * Reglas: un precio nunca queda en cero ni en negativo; un cambio toca como mucho «copilot.max_rows» registros; nada se borra
 * (desactivar es pasar a borrador; deshacer una creación es archivar). Cada cambio se escribe con una sentencia condicionada al
 * valor que se vio, y deshacer solo vale mientras el dato siga como lo dejó la propuesta.
 */
final class CatalogActions
{
    /** Un precio no pasa de esto (la columna es un entero de 32 bits). */
    private const PRICE_MAX = 2000000000;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(App $app): array
    {
        return [
            [
                'id' => 'catalog.price', 'module' => 'catalog', 'permission' => 'catalog.edit',
                'label' => 'Change the price of a product',
                'description' => 'Sets the price of one product, or adds or takes off an amount or a percentage. The product is named by its title, its reference (SKU) or its barcode. Never touches a sale price.',
                'params' => [
                    ['name' => 'product', 'type' => 'string', 'required' => true, 'description' => 'Product title, reference (SKU) or barcode'],
                    ['name' => 'price', 'type' => 'money', 'description' => 'The new price'],
                    ['name' => 'delta', 'type' => 'money', 'description' => 'Amount to add (positive) or take off (negative)'],
                    ['name' => 'percent', 'type' => 'percent', 'description' => 'Percentage to add (positive) or take off (negative)'],
                ],
                'one_of' => [['price', 'delta', 'percent']],
                'preview' => [self::class, 'previewPrice'], 'apply' => [self::class, 'applyPrice'], 'undo' => [self::class, 'undoPrices'],
                'understand' => [self::class, 'readPrice'],
                'examples' => ['Set the price of the red chair to 59.90', 'Lower the price of SKU-001 by 10 %'],
            ],
            [
                'id' => 'catalog.bulk_price', 'module' => 'catalog', 'permission' => 'catalog.edit',
                'label' => 'Change the prices of a set of products',
                'description' => 'Adds or takes off a percentage to the prices of every product of a category (with its subcategories), of a brand or of a search. Variants with their own price change too. Never touches sale prices.',
                'params' => [
                    ['name' => 'category', 'type' => 'string', 'description' => 'Category name'],
                    ['name' => 'brand', 'type' => 'string', 'description' => 'Brand name'],
                    ['name' => 'query', 'type' => 'string', 'description' => 'Words that the product titles contain'],
                    ['name' => 'percent', 'type' => 'percent', 'required' => true, 'description' => 'Percentage to add (positive) or take off (negative)'],
                ],
                'one_of' => [['category', 'brand', 'query']],
                'preview' => [self::class, 'previewBulk'], 'apply' => [self::class, 'applyBulk'], 'undo' => [self::class, 'undoPrices'],
                'understand' => [self::class, 'readBulk'],
                'examples' => ['Raise the prices of the category chairs by 5 %'],
            ],
            [
                'id' => 'catalog.stock', 'module' => 'catalog', 'permission' => 'catalog.edit',
                'label' => 'Set or adjust the stock',
                'description' => 'Sets the units in stock of a product or variant, or adds or takes off units. The product is named by its title, its reference (SKU) or its barcode.',
                'params' => [
                    ['name' => 'product', 'type' => 'string', 'required' => true, 'description' => 'Product or variant: title, reference (SKU) or barcode'],
                    ['name' => 'quantity', 'type' => 'int', 'description' => 'The units in stock after the change'],
                    ['name' => 'delta', 'type' => 'int', 'description' => 'Units to add (positive) or take off (negative)'],
                ],
                'one_of' => [['quantity', 'delta']],
                'preview' => [self::class, 'previewStock'], 'apply' => [self::class, 'applyStock'], 'undo' => [self::class, 'undoStock'],
                'understand' => [self::class, 'readStock'],
                'examples' => ['Set the stock of the red chair to 20', 'Add 5 units to SKU-001'],
            ],
            [
                'id' => 'catalog.visibility', 'module' => 'catalog', 'permission' => 'catalog.edit',
                'label' => 'Activate or deactivate products',
                'description' => 'Publishes products or takes them back to draft (nothing is deleted). Name one product, a category, a brand or a search.',
                'params' => [
                    ['name' => 'product', 'type' => 'string', 'description' => 'Product title, reference (SKU) or barcode'],
                    ['name' => 'category', 'type' => 'string', 'description' => 'Category name'],
                    ['name' => 'brand', 'type' => 'string', 'description' => 'Brand name'],
                    ['name' => 'query', 'type' => 'string', 'description' => 'Words that the product titles contain'],
                    ['name' => 'active', 'type' => 'bool', 'required' => true, 'description' => 'Publish (yes) or take back to draft (no)'],
                ],
                'one_of' => [['product', 'category', 'brand', 'query']],
                'preview' => [self::class, 'previewVisibility'], 'apply' => [self::class, 'applyVisibility'], 'undo' => [self::class, 'undoVisibility'],
                'understand' => [self::class, 'readVisibility'],
                'examples' => ['Deactivate the red chair', 'Activate the products of the category chairs'],
            ],
            [
                'id' => 'catalog.create', 'module' => 'catalog', 'permission' => 'catalog.edit',
                'label' => 'Create a draft product',
                'description' => 'Creates a product in draft with a title and a price. It is not published: the merchant finishes it in the product form.',
                'params' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Product title'],
                    ['name' => 'price', 'type' => 'money', 'required' => true, 'description' => 'Price'],
                ],
                'preview' => [self::class, 'previewCreate'], 'apply' => [self::class, 'applyCreate'], 'undo' => [self::class, 'undoCreate'],
                'understand' => [self::class, 'readCreate'],
                'examples' => ['Create a draft product called Red chair at 59.90'],
            ],
        ];
    }

    // ------------------------------------------------------------------ entender

    /**
     * @return array<string,mixed>|null
     */
    public static function readPrice(App $app, string $raw, string $norm): ?array
    {
        $up = Reader::has($norm, Phrases::UP);
        $down = Reader::has($norm, Phrases::DOWN);
        $priceWord = Reader::has($norm, Phrases::PRICE);
        if (Reader::has($norm, Phrases::STOCK) || Reader::has($norm, Phrases::SHIPPING) || Phrases::isBulk($norm) || (!$priceWord && !($up || $down))) {
            return null;
        }
        $work = $norm;
        $args = [];
        list($basis, $work) = Reader::takePercent($work);
        $absolute = null;
        $amount = null;
        if ($basis === null) {
            list($absolute, $work) = Reader::takeAmountAfter($work);
            if ($absolute === null) {
                list($amount, $work) = Reader::takeMoney($work);
            }
        }
        if ($basis !== null) {
            $args['percent'] = $down && $basis > 0 ? -$basis : $basis;
        } elseif ($absolute !== null) {
            $args['price'] = $absolute;
        } elseif ($amount !== null) {
            if ($up || $down) {
                $args['delta'] = $down ? -abs($amount) : $amount;
            } else {
                $args['price'] = $amount;
            }
        } elseif (!$priceWord) {
            return null;
        }
        $name = Phrases::name($raw, $work);
        if ($name !== '') {
            $args['product'] = $name;
        }

        return ['args' => $args, 'score' => 60 + ($priceWord ? 10 : 0) + (count($args) > 1 ? 10 : 0) + (isset($args['product']) ? 5 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readBulk(App $app, string $raw, string $norm): ?array
    {
        $up = Reader::has($norm, Phrases::UP);
        $down = Reader::has($norm, Phrases::DOWN);
        if (!Phrases::isBulk($norm) || !($up || $down) || Reader::has($norm, Phrases::STOCK)) {
            return null;
        }
        list($basis, $work) = Reader::takePercent($norm);
        if ($basis === null && !Reader::has($norm, Phrases::PRICE)) {
            return null;
        }
        $args = [];
        if ($basis !== null) {
            $args['percent'] = $down && $basis > 0 ? -$basis : $basis;
        }
        $hasScope = false;
        $categories = Finder::categoriesIn($app, $work);
        if (count($categories) === 1) {
            $args['category'] = $categories[0]['name'];
            $hasScope = true;
        }
        $brands = Finder::brandsIn($app, $work);
        if (count($brands) === 1) {
            $args['brand'] = $brands[0]['name'];
            $hasScope = true;
        }
        if (!$hasScope) {
            $fold = mb_strtolower(Reader::fold($raw), 'UTF-8');
            if (preg_match('/(?:categoria|category)\s+(?:de\s+|of\s+)?(?:la\s+|el\s+|the\s+)?(.+)$/u', $fold) === 1) {
                $args['category'] = self::tail($raw,'/(?:categoria|category)\s+(?:de\s+|of\s+)?(?:la\s+|el\s+|the\s+)?(.+)$/u');
                $hasScope = true;
            } elseif (preg_match('/(?:marca|brand)\s+(?:de\s+|of\s+)?(.+)$/u', $fold) === 1) {
                $args['brand'] = self::tail($raw,'/(?:marca|brand)\s+(?:de\s+|of\s+)?(.+)$/u');
                $hasScope = true;
            }
        }
        if (!$hasScope) {
            $words = (string) preg_replace('/\b(?:todos|todas|los|las|productos|precios|que|contengan|busqueda|all|the|products|prices|containing)\b/', ' ', $work);
            $name = Phrases::name($raw, trim((string) preg_replace('/\s+/', ' ', $words)));
            if ($name !== '') {
                $args['query'] = $name;
            }
        }

        return ['args' => $args, 'score' => 78 + ($basis !== null ? 5 : 0) + ($hasScope ? 5 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readStock(App $app, string $raw, string $norm): ?array
    {
        if (!Reader::has($norm, Phrases::STOCK) || Phrases::isBulk($norm)) {
            return null;
        }
        $plus = Reader::has($norm, 'anade|anadir|suma|sumar|agrega|agregar|repone|reponer|add|increase|restock|receive|incrementa|mete|meter|llegan|han llegado');
        $minus = Reader::has($norm, 'resta|restar|quita|quitar|reduce|reducir|remove|decrease|subtract|descuenta|baja|bajar|lower|sell');
        $set = Reader::has($norm, 'pon|poner|ponme|fija|fijar|ajusta|ajustar|cambia|cambiar|establece|set|change|update|adjust|deja|dejar') || !($plus || $minus);
        $work = $norm;
        $args = [];
        if ($set && !$plus && !$minus) {
            if (preg_match('/(?<![a-z0-9])(?:a|en|to|=|at)\s*(\d+)(?![\d.,a-z%€])/', $work, $m, PREG_OFFSET_CAPTURE) === 1) {
                $args['quantity'] = (int) $m[1][0];
                $work = Reader::cut($work, (int) $m[0][1], strlen((string) $m[0][0]));
            }
        } else {
            list($number, $work) = Reader::takeInt($work);
            if ($number !== null) {
                $args['delta'] = $minus ? -abs($number) : abs($number);
            }
        }
        $name = Phrases::name($raw, $work);
        if ($name !== '') {
            $args['product'] = $name;
        }

        return ['args' => $args, 'score' => 75 + (isset($args['product']) ? 5 : 0) + (isset($args['quantity']) || isset($args['delta']) ? 5 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readVisibility(App $app, string $raw, string $norm): ?array
    {
        $off = Reader::has($norm, 'desactiva|desactivar|oculta|ocultar|despublica|despublicar|deshabilita|deshabilitar|deactivate|disable|hide|unpublish|retira|retirar');
        $on = !$off && Reader::has($norm, 'activa|activar|publica|publicar|habilita|habilitar|enable|activate|publish');
        if (!$off && !$on) {
            return null;
        }
        if (Reader::has($norm, 'pedido|pedidos|order|orders|cupon|coupon|envio gratis|free shipping|tarifa|rate|ajuste|setting')) {
            return null;
        }
        $args = ['active' => $on];
        $work = $norm;
        $hasScope = false;
        if (Phrases::isBulk($norm) || Reader::has($norm, 'categoria|category|marca|brand')) {
            $categories = Finder::categoriesIn($app, $work);
            if (count($categories) === 1) {
                $args['category'] = $categories[0]['name'];
                $hasScope = true;
            }
            $brands = Finder::brandsIn($app, $work);
            if (count($brands) === 1) {
                $args['brand'] = $brands[0]['name'];
                $hasScope = true;
            }
            if (!$hasScope) {
                $fold = mb_strtolower(Reader::fold($raw), 'UTF-8');
                if (preg_match('/(?:categoria|category)\s+(?:de\s+|of\s+)?(?:la\s+|el\s+|the\s+)?(.+)$/u', $fold) === 1) {
                    $args['category'] = self::tail($raw,'/(?:categoria|category)\s+(?:de\s+|of\s+)?(?:la\s+|el\s+|the\s+)?(.+)$/u');
                    $hasScope = true;
                } elseif (preg_match('/(?:marca|brand)\s+(?:de\s+|of\s+)?(.+)$/u', $fold) === 1) {
                    $args['brand'] = self::tail($raw,'/(?:marca|brand)\s+(?:de\s+|of\s+)?(.+)$/u');
                    $hasScope = true;
                }
            }
        }
        if (!$hasScope) {
            $name = Phrases::name($raw, $work);
            if ($name !== '') {
                $args['product'] = $name;
            }
        }

        return ['args' => $args, 'score' => 70 + (count($args) > 1 ? 10 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readCreate(App $app, string $raw, string $norm): ?array
    {
        if (!Reader::has($norm, 'crea|crear|creame|anade|anadir|nuevo|nueva|create|add|new|dar de alta|alta')
            || !Reader::has($norm, 'producto|productos|articulo|product|item')
            || Reader::has($norm, Phrases::STOCK)
            || Reader::has($norm, 'cupon|coupon|pedido|order|nota|note')) {
            return null;
        }
        $fold = Reader::fold($raw);
        $name = Reader::capture($raw, $fold, '/["“«]([^"“«”»]{2,120})["”»]/u');
        if ($name === null) {
            $name = Reader::capture($raw, $fold, '/(?:llamado|llamada|named|called|nombre|name|titulado|titled)\s*:?\s*([^,;:]+?)(?=\s+(?:a|at|por|for|precio|price|de|con|with)\s+[\d€]|\s+(?:a|at|por|for|precio|price)\s*$|\s*[,;]|\s+[\d€]|\s*$)/iu');
        }
        if ($name === null) {
            $name = Reader::capture($raw, $fold, '/(?:producto|product|articulo|item)\s*:?\s*(?:borrador|draft)?\s*:?\s*([^,;:]+?)(?=\s+(?:a|at|por|for|precio|price|de|con|with)\s+[\d€]|\s*[,;]|\s+[\d€]|\s*$)/iu');
        }
        $work = $norm;
        $args = [];
        $money = null;
        list($money, $work) = Reader::takeMoney($work);
        if ($money === null) {
            list($money, $work) = Reader::takeAmountAfter($work);
        }
        if ($money === null && preg_match('/(?:precio|price)\s*:?\s*(\d[\d.,]*)/', $norm, $m) === 1) {
            $parsed = \Aifec\Validation\Validator::toCents(trim($m[1], '.,'));
            $money = $parsed;
        }
        if ($money !== null) {
            $args['price'] = abs($money);
        }
        if ($name !== null) {
            $name = trim((string) preg_replace('/^(?:un|una|el|la|a|an|the)\s+/iu', '', trim($name)));
            $name = trim((string) preg_replace('/\s+(?:borrador|draft)$/iu', '', $name));
            if ($name !== '' && !Reader::has(Reader::norm($name), 'borrador|draft')) {
                $args['name'] = mb_substr($name, 0, 191, 'UTF-8');
            }
        }

        return ['args' => $args, 'score' => 80 + (isset($args['name']) ? 5 : 0) + (isset($args['price']) ? 5 : 0)];
    }

    /** Lo que hay detrás de una palabra clave, con las mayúsculas y tildes que escribió la persona. */
    private static function tail(string $raw, string $regex): string
    {
        $text = Reader::capture($raw, Reader::fold($raw), str_replace('/u', '/iu', $regex));

        // Se corta donde empieza lo que no es el nombre: un número, o «por / by / en / un» delante de uno.
        return $text === null ? '' : trim((string) preg_replace('/\s+(?:(?:por|by|en|un|una|a|to)\s+)?[+-]?\d.*$/iu', '', $text));
    }

    // ------------------------------------------------------------------ precio de un producto

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function computePrice(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'warnings' => [], 'items' => [], 'summary' => ''];
        $modes = (isset($args['price']) ? 1 : 0) + (isset($args['delta']) ? 1 : 0) + (isset($args['percent']) ? 1 : 0);
        if ($modes !== 1) {
            $out['errors'][] = t('Give only one of these: the new price, an amount to add or take off, or a percentage.');

            return $out;
        }
        $candidates = Finder::products($app, (string) $args['product'], 8);
        if ($candidates === []) {
            $out['errors'][] = t('No product matches «{text}».', ['text' => (string) $args['product']]);

            return $out;
        }
        if (count($candidates) > 1) {
            $out['errors'][] = t('Several products match «{text}». Choose one.', ['text' => (string) $args['product']]);
            foreach ($candidates as $candidate) {
                $out['choices'][] = ['param' => 'product', 'value' => ($candidate['variant_id'] > 0 ? '#v' . $candidate['variant_id'] : '#' . $candidate['id']), 'label' => $candidate['label']];
            }

            return $out;
        }
        $candidate = $candidates[0];
        $db = $app->db();
        $product = $db->row('SELECT id, price, sale_price FROM {products} WHERE id = ?', [$candidate['id']]);
        if ($product === null) {
            $out['errors'][] = t('No product matches «{text}».', ['text' => (string) $args['product']]);

            return $out;
        }
        $name = $candidate['name'];
        $items = [];
        if ($candidate['variant_id'] > 0) {
            $variant = $db->row('SELECT id, price, label FROM {variants} WHERE id = ?', [$candidate['variant_id']]);
            if ($variant !== null) {
                $old = $variant['price'] !== null ? (int) $variant['price'] : (int) $product['price'];
                $items[] = ['t' => 'v', 'id' => (int) $variant['id'], 'before' => $variant['price'] !== null ? (int) $variant['price'] : null, 'old' => $old, 'name' => $candidate['label']];
            }
        } else {
            $items[] = ['t' => 'p', 'id' => (int) $product['id'], 'before' => (int) $product['price'], 'old' => (int) $product['price'], 'name' => $name];
            if (!isset($args['price'])) {
                foreach ($db->select('SELECT id, price, label, sku FROM {variants} WHERE product_id = ? AND price IS NOT NULL ORDER BY position, id', [(int) $product['id']]) as $variant) {
                    $label = isset($variant['label']) && $variant['label'] !== null && $variant['label'] !== '' ? (string) $variant['label'] : '#' . $variant['id'];
                    $items[] = ['t' => 'v', 'id' => (int) $variant['id'], 'before' => (int) $variant['price'], 'old' => (int) $variant['price'], 'name' => $name . ' · ' . $label];
                }
            } elseif ((int) $db->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ? AND price IS NOT NULL', [(int) $product['id']]) > 0) {
                $out['warnings'][] = t('The variants of {name} with their own price keep it.', ['name' => $name]);
            }
        }
        if ($product['sale_price'] !== null) {
            $out['warnings'][] = t('{name} has a sale price ({price}) that does not change.', ['name' => $name, 'price' => Fmt::money($app, (int) $product['sale_price'])]);
        }
        $changed = [];
        foreach ($items as $item) {
            $new = self::newPrice($item['old'], $args);
            if ($new <= 0) {
                $out['errors'][] = t('The price of {name} would be {price}: it has to stay above zero.', ['name' => $item['name'], 'price' => Fmt::money($app, $new)]);
            } elseif ($new > self::PRICE_MAX) {
                $out['errors'][] = t('The price of {name} would be too high.', ['name' => $item['name']]);
            }
            if ($new !== $item['old']) {
                $item['after'] = $new;
                $changed[] = $item;
            }
        }
        if ($changed === [] && $out['errors'] === []) {
            $out['errors'][] = t('Nothing to change: the price is already that.');
        }
        $out['items'] = $changed;
        if (isset($args['price'])) {
            $out['summary'] = t('Set the price of {name} to {price}', ['name' => $name, 'price' => Fmt::money($app, (int) $args['price'])]);
        } elseif (isset($args['delta'])) {
            $out['summary'] = t('Change the price of {name} by {amount}', ['name' => $name, 'amount' => ((int) $args['delta'] > 0 ? '+' : '') . Fmt::money($app, (int) $args['delta'])]);
        } else {
            $out['summary'] = t('Change the price of {name} by {percent}', ['name' => $name, 'percent' => ((int) $args['percent'] > 0 ? '+' : '') . Params::toForm('percent', (int) $args['percent']) . ' %']);
        }

        return $out;
    }

    /**
     * El precio nuevo: el escrito, el de ahora más una cantidad o el de ahora más un porcentaje (redondeado al céntimo).
     *
     * @param array<string,mixed> $args
     */
    private static function newPrice(int $old, array $args): int
    {
        if (isset($args['price'])) {
            return (int) $args['price'];
        }
        if (isset($args['delta'])) {
            return $old + (int) $args['delta'];
        }

        return self::percentOf($old, (int) $args['percent']);
    }

    /** $old con un porcentaje en centésimas de punto, con aritmética entera y medio céntimo hacia arriba. */
    public static function percentOf(int $old, int $basis): int
    {
        return intdiv($old * (10000 + $basis) + 5000, 10000);
    }

    /**
     * @param array<string,mixed> $items
     *
     * @return array<int,array<string,string>>
     */
    private static function priceChanges(App $app, array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = Fmt::change($item['name'], Fmt::money($app, $item['old']), Fmt::money($app, $item['after']));
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewPrice(App $app, array $args): array
    {
        $plan = self::computePrice($app, $args);

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Change the price of a product'),
            'changes' => self::priceChanges($app, $plan['items']),
            'count' => count($plan['items']),
            'warnings' => $plan['warnings'], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyPrice(App $app, array $args): array
    {
        $plan = self::computePrice($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['items'] === []) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }

        return self::writePrices($app, $plan['items']);
    }

    /**
     * Escribe los precios con una sentencia condicionada al valor visto; si alguno ha cambiado, no se escribe ninguno.
     *
     * @param array<int,array<string,mixed>> $items t (p|v), id, before, after
     *
     * @return array<string,mixed>
     */
    private static function writePrices(App $app, array $items): array
    {
        $now = $app->clock()->sql();
        try {
            $app->db()->transaction(function (Db $db) use ($items, $now): void {
                foreach ($items as $item) {
                    $table = $item['t'] === 'p' ? 'products' : 'variants';
                    if ($db->update($table, ['price' => $item['after'], 'updated_at' => $now], ['id' => $item['id'], 'price' => $item['before']]) !== 1) {
                        throw new \RuntimeException('A price changed while applying');
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => t('A price changed while applying. Nothing was changed.'), 'undo' => null];
        }
        $products = self::touchedProducts($app, $items);
        self::refresh($app, $products);
        $undo = [];
        foreach ($items as $item) {
            $undo[] = ['t' => $item['t'], 'id' => $item['id'], 'before' => $item['before'], 'after' => $item['after']];
        }

        return ['ok' => true, 'message' => tn('{n} price changed.', '{n} prices changed.', count($items)), 'undo' => ['items' => $undo]];
    }

    /**
     * Devuelve los precios a como estaban, solo si todos siguen como los dejó la propuesta.
     *
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoPrices(App $app, array $undo): array
    {
        $items = isset($undo['items']) && is_array($undo['items']) ? $undo['items'] : [];
        $db = $app->db();
        foreach ($items as $item) {
            $table = $item['t'] === 'p' ? 'products' : 'variants';
            $row = $db->row('SELECT price FROM {' . $table . '} WHERE id = ?', [(int) $item['id']]);
            if ($row === null || $row['price'] === null || (int) $row['price'] !== (int) $item['after']) {
                return ['ok' => false, 'message' => t('A price has changed since. It is not undone so that nothing else is lost.')];
            }
        }
        $now = $app->clock()->sql();
        try {
            $db->transaction(function (Db $db) use ($items, $now): void {
                foreach ($items as $item) {
                    $table = $item['t'] === 'p' ? 'products' : 'variants';
                    if ($db->update($table, ['price' => $item['before'], 'updated_at' => $now], ['id' => (int) $item['id'], 'price' => (int) $item['after']]) !== 1) {
                        throw new \RuntimeException('A price changed while undoing');
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => t('A price has changed since. It is not undone so that nothing else is lost.')];
        }
        self::refresh($app, self::touchedProducts($app, $items));

        return ['ok' => true, 'message' => tn('{n} price restored.', '{n} prices restored.', count($items))];
    }

    // ------------------------------------------------------------------ precios de un conjunto

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function computeBulk(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'warnings' => [], 'items' => [], 'summary' => '', 'records' => 0];
        $basis = isset($args['percent']) ? (int) $args['percent'] : 0;
        if ($basis === 0) {
            $out['errors'][] = t('A change of 0 % changes nothing.');

            return $out;
        }
        if ($basis <= -10000) {
            $out['errors'][] = t('Taking off 100 % or more would leave the prices at zero.');

            return $out;
        }
        $max = $app->settings()->int('copilot.max_rows', 500);
        $scope = ProductScope::resolve($app, $args, $max + 1);
        $out['errors'] = $scope['errors'];
        $out['choices'] = $scope['choices'];
        if ($scope['errors'] !== []) {
            return $out;
        }
        $label = $scope['label'] !== '' ? $scope['label'] : t('the selection');
        $out['summary'] = t('Change the prices of {what} by {percent}', ['what' => $label, 'percent' => ($basis > 0 ? '+' : '') . Params::toForm('percent', $basis) . ' %']);
        if ($scope['ids'] === []) {
            $out['errors'][] = t('No product matches that selection.');

            return $out;
        }
        $db = $app->db();
        $names = Fmt::productNames($app, $scope['ids']);
        $items = [];
        $free = 0;
        $onSale = 0;
        foreach (array_chunk($scope['ids'], 200) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, price, sale_price FROM {products} WHERE id IN (' . $in . ') ORDER BY id', $params) as $row) {
                $name = isset($names[(int) $row['id']]) ? $names[(int) $row['id']] : '#' . $row['id'];
                $old = (int) $row['price'];
                if ($old <= 0) {
                    ++$free;
                } else {
                    $items[] = ['t' => 'p', 'id' => (int) $row['id'], 'before' => $old, 'old' => $old, 'name' => $name];
                }
                if ($row['sale_price'] !== null) {
                    ++$onSale;
                }
            }
            $params = [];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT v.id, v.product_id, v.price, v.label FROM {variants} v WHERE v.product_id IN (' . $in . ') AND v.price IS NOT NULL ORDER BY v.product_id, v.id', $params) as $row) {
                $name = isset($names[(int) $row['product_id']]) ? $names[(int) $row['product_id']] : '#' . $row['product_id'];
                $items[] = ['t' => 'v', 'id' => (int) $row['id'], 'before' => (int) $row['price'], 'old' => (int) $row['price'], 'name' => $name . ' · ' . ((string) $row['label'] !== '' ? (string) $row['label'] : '#' . $row['id'])];
            }
        }
        $changed = [];
        foreach ($items as $item) {
            $new = self::percentOf($item['old'], $basis);
            if ($new <= 0) {
                $out['errors'][] = t('The price of {name} would be {price}: it has to stay above zero.', ['name' => $item['name'], 'price' => Fmt::money($app, $new)]);
                continue;
            }
            if ($new > self::PRICE_MAX) {
                $out['errors'][] = t('The price of {name} would be too high.', ['name' => $item['name']]);
                continue;
            }
            if ($new !== $item['old']) {
                $item['after'] = $new;
                $changed[] = $item;
            }
        }
        $out['errors'] = array_slice($out['errors'], 0, 5);
        $out['records'] = count($changed);
        if ($changed === [] && $out['errors'] === []) {
            $out['errors'][] = t('Nothing to change: no price moves with that percentage.');
        }
        if ($free > 0) {
            $out['warnings'][] = tn('{n} product without a price was left as it is.', '{n} products without a price were left as they are.', $free);
        }
        if ($onSale > 0) {
            $out['warnings'][] = tn('{n} product has a sale price that does not change.', '{n} products have a sale price that does not change.', $onSale);
        }
        $out['items'] = $changed;

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewBulk(App $app, array $args): array
    {
        $plan = self::computeBulk($app, $args);

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Change the prices of a set of products'),
            'changes' => self::priceChanges($app, $plan['items']),
            'count' => count($plan['items']),
            'warnings' => $plan['warnings'], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyBulk(App $app, array $args): array
    {
        $plan = self::computeBulk($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['items'] === []) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }

        return self::writePrices($app, $plan['items']);
    }

    // ------------------------------------------------------------------ existencias

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function computeStock(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'warnings' => [], 'item' => null, 'summary' => ''];
        if ((isset($args['quantity']) ? 1 : 0) + (isset($args['delta']) ? 1 : 0) !== 1) {
            $out['errors'][] = t('Give only one of these: the units in stock or the units to add or take off.');

            return $out;
        }
        $candidates = Finder::products($app, (string) $args['product'], 8);
        if ($candidates === []) {
            $out['errors'][] = t('No product matches «{text}».', ['text' => (string) $args['product']]);

            return $out;
        }
        if (count($candidates) > 1) {
            $out['errors'][] = t('Several products match «{text}». Choose one.', ['text' => (string) $args['product']]);
            foreach ($candidates as $candidate) {
                $out['choices'][] = ['param' => 'product', 'value' => ($candidate['variant_id'] > 0 ? '#v' . $candidate['variant_id'] : '#' . $candidate['id']), 'label' => $candidate['label']];
            }

            return $out;
        }
        $candidate = $candidates[0];
        $db = $app->db();
        $product = $db->row('SELECT id, has_variants, manage_stock, backorders, stock FROM {products} WHERE id = ?', [$candidate['id']]);
        if ($product === null) {
            $out['errors'][] = t('No product matches «{text}».', ['text' => (string) $args['product']]);

            return $out;
        }
        if ((int) $product['has_variants'] === 1 && $candidate['variant_id'] === 0) {
            $out['errors'][] = t('{name} has variants. Choose the one to change.', ['name' => $candidate['name']]);
            foreach ($db->select('SELECT id, sku, label, stock FROM {variants} WHERE product_id = ? AND active = 1 ORDER BY position, id', [(int) $product['id']]) as $variant) {
                $label = $candidate['name'] . ((string) $variant['label'] !== '' ? ' · ' . $variant['label'] : '') . ((string) $variant['sku'] !== '' ? ' (' . $variant['sku'] . ')' : '');
                $out['choices'][] = ['param' => 'product', 'value' => '#v' . $variant['id'], 'label' => $label . ' — ' . tn('{n} unit', '{n} units', (int) $variant['stock'])];
            }

            return $out;
        }
        if ((int) $product['manage_stock'] !== 1) {
            $out['errors'][] = t('The stock of {name} is not managed: turn it on in the product first.', ['name' => $candidate['name']]);

            return $out;
        }
        $variantId = (int) $candidate['variant_id'];
        if ($variantId > 0) {
            $stock = $db->value('SELECT stock FROM {variants} WHERE id = ? AND product_id = ?', [$variantId, (int) $product['id']]);
            if ($stock === null) {
                $out['errors'][] = t('No product matches «{text}».', ['text' => (string) $args['product']]);

                return $out;
            }
        } else {
            $stock = $product['stock'];
        }
        $before = (int) $stock;
        $after = isset($args['quantity']) ? (int) $args['quantity'] : $before + (int) $args['delta'];
        if ($after < 0 && (string) $product['backorders'] !== 'yes') {
            $out['errors'][] = t('The stock of {name} would be {n}: it cannot be negative.', ['name' => $candidate['label'], 'n' => (string) $after]);
        }
        if ($after === $before && $out['errors'] === []) {
            $out['errors'][] = t('Nothing to change: the stock is already {n}.', ['n' => (string) $before]);
        }
        $out['item'] = ['product_id' => (int) $product['id'], 'variant_id' => $variantId, 'before' => $before, 'after' => $after, 'name' => $candidate['label']];
        $out['summary'] = t('Change the stock of {name} from {from} to {to}', ['name' => $candidate['label'], 'from' => (string) $before, 'to' => (string) $after]);

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewStock(App $app, array $args): array
    {
        $plan = self::computeStock($app, $args);
        $changes = [];
        if ($plan['item'] !== null) {
            $changes[] = Fmt::change($plan['item']['name'], (string) $plan['item']['before'], (string) $plan['item']['after']);
        }

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Set or adjust the stock'),
            'changes' => $changes, 'count' => $changes === [] ? 0 : 1,
            'warnings' => $plan['warnings'], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyStock(App $app, array $args): array
    {
        $plan = self::computeStock($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['item'] === null) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $item = $plan['item'];
        $delta = $item['after'] - $item['before'];
        if (!$app->service('catalog')->adjustStock($item['product_id'], $item['variant_id'], $delta, 'copilot')) {
            return ['ok' => false, 'message' => t('The stock could not be changed.'), 'undo' => null];
        }

        return [
            'ok' => true,
            'message' => t('The stock of {name} is now {n}.', ['name' => $item['name'], 'n' => (string) $item['after']]),
            'undo' => ['product_id' => $item['product_id'], 'variant_id' => $item['variant_id'], 'before' => $item['before'], 'after' => $item['after']],
        ];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoStock(App $app, array $undo): array
    {
        $db = $app->db();
        $productId = (int) $undo['product_id'];
        $variantId = (int) $undo['variant_id'];
        $current = $variantId > 0
            ? $db->value('SELECT stock FROM {variants} WHERE id = ? AND product_id = ?', [$variantId, $productId])
            : $db->value('SELECT stock FROM {products} WHERE id = ?', [$productId]);
        if ($current === null || (int) $current !== (int) $undo['after']) {
            return ['ok' => false, 'message' => t('The stock has changed since. It is not undone so that nothing else is lost.')];
        }
        $delta = (int) $undo['before'] - (int) $undo['after'];
        if ($delta !== 0 && !$app->service('catalog')->adjustStock($productId, $variantId, $delta, 'copilot undo')) {
            return ['ok' => false, 'message' => t('The stock could not be changed.')];
        }

        return ['ok' => true, 'message' => t('The stock is back to {n}.', ['n' => (string) $undo['before']])];
    }

    // ------------------------------------------------------------------ activar y desactivar

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function computeVisibility(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'warnings' => [], 'items' => [], 'summary' => ''];
        $active = !empty($args['active']);
        $max = $app->settings()->int('copilot.max_rows', 500);
        $scope = ProductScope::resolve($app, $args, $max + 1);
        $out['errors'] = $scope['errors'];
        $out['choices'] = $scope['choices'];
        if ($scope['errors'] !== []) {
            return $out;
        }
        $label = $scope['label'] !== '' ? $scope['label'] : t('the selection');
        $out['summary'] = $active ? t('Publish {what}', ['what' => $label]) : t('Take {what} back to draft', ['what' => $label]);
        if ($scope['ids'] === []) {
            $out['errors'][] = t('No product matches that selection.');

            return $out;
        }
        $db = $app->db();
        $names = Fmt::productNames($app, $scope['ids']);
        foreach (array_chunk($scope['ids'], 200) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, status, published_at FROM {products} WHERE id IN (' . $in . ') ORDER BY id', $params) as $row) {
                $status = (string) $row['status'];
                if (($active && $status === 'published') || (!$active && $status !== 'published')) {
                    continue;
                }
                $out['items'][] = [
                    'id' => (int) $row['id'], 'before' => $status, 'after' => $active ? 'published' : 'draft',
                    'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
                    'name' => isset($names[(int) $row['id']]) ? $names[(int) $row['id']] : '#' . $row['id'],
                ];
            }
        }
        if ($out['items'] === []) {
            $out['errors'][] = $active ? t('Nothing to change: they are all published already.') : t('Nothing to change: none is published.');
        }

        return $out;
    }

    private static function statusLabel(string $status): string
    {
        $labels = ['published' => t('Published'), 'draft' => t('Draft'), 'archived' => t('Archived')];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewVisibility(App $app, array $args): array
    {
        $plan = self::computeVisibility($app, $args);
        $changes = [];
        foreach ($plan['items'] as $item) {
            $changes[] = Fmt::change($item['name'], self::statusLabel($item['before']), self::statusLabel($item['after']));
        }

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Activate or deactivate products'),
            'changes' => $changes, 'count' => count($changes),
            'warnings' => $plan['warnings'], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyVisibility(App $app, array $args): array
    {
        $plan = self::computeVisibility($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['items'] === []) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $now = $app->clock()->sql();
        try {
            $app->db()->transaction(function (Db $db) use ($plan, $now): void {
                foreach ($plan['items'] as $item) {
                    $set = ['status' => $item['after'], 'updated_at' => $now];
                    if ($item['after'] === 'published' && $item['published_at'] === null) {
                        $set['published_at'] = $now;
                    }
                    if ($db->update('products', $set, ['id' => $item['id'], 'status' => $item['before']]) !== 1) {
                        throw new \RuntimeException('A product changed while applying');
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => t('A product changed while applying. Nothing was changed.'), 'undo' => null];
        }
        $ids = [];
        $undo = [];
        foreach ($plan['items'] as $item) {
            $ids[] = $item['id'];
            $undo[] = ['id' => $item['id'], 'before' => $item['before'], 'after' => $item['after'], 'published_at' => $item['published_at']];
        }
        self::refresh($app, $ids);

        return ['ok' => true, 'message' => tn('{n} product changed.', '{n} products changed.', count($ids)), 'undo' => ['items' => $undo]];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoVisibility(App $app, array $undo): array
    {
        $items = isset($undo['items']) && is_array($undo['items']) ? $undo['items'] : [];
        $db = $app->db();
        foreach ($items as $item) {
            $status = $db->value('SELECT status FROM {products} WHERE id = ?', [(int) $item['id']]);
            if ($status === null || (string) $status !== (string) $item['after']) {
                return ['ok' => false, 'message' => t('A product has changed since. It is not undone so that nothing else is lost.')];
            }
        }
        $now = $app->clock()->sql();
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) $item['id'];
            $db->update('products', ['status' => (string) $item['before'], 'published_at' => $item['published_at'], 'updated_at' => $now], ['id' => (int) $item['id'], 'status' => (string) $item['after']]);
        }
        self::refresh($app, $ids);

        return ['ok' => true, 'message' => tn('{n} product restored.', '{n} products restored.', count($ids))];
    }

    // ------------------------------------------------------------------ crear un producto en borrador

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function computeCreate(App $app, array $args): array
    {
        $out = ['errors' => [], 'warnings' => []];
        $name = trim((string) $args['name']);
        $price = (int) $args['price'];
        if ($name === '') {
            $out['errors'][] = t('Write the title of the product.');
        }
        if ($price <= 0) {
            $out['errors'][] = t('The price has to be above zero.');
        } elseif ($price > self::PRICE_MAX) {
            $out['errors'][] = t('The price is too high.');
        }
        if ($name !== '' && Finder::idsByName($app, [$name], 1) !== []) {
            $out['warnings'][] = t('There is already a product with a similar title.');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewCreate(App $app, array $args): array
    {
        $plan = self::computeCreate($app, $args);
        $name = trim((string) $args['name']);

        return [
            'summary' => t('Create the draft product {name}', ['name' => $name]),
            'changes' => [
                Fmt::change(t('Title'), '—', $name),
                Fmt::change(t('Price'), '—', Fmt::money($app, (int) $args['price'])),
                Fmt::change(t('Status'), '—', t('Draft')),
            ],
            'count' => 1, 'warnings' => $plan['warnings'], 'errors' => $plan['errors'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyCreate(App $app, array $args): array
    {
        $plan = self::computeCreate($app, $args);
        if ($plan['errors'] !== []) {
            return ['ok' => false, 'message' => (string) $plan['errors'][0], 'undo' => null];
        }
        $name = mb_substr(trim((string) $args['name']), 0, 191, 'UTF-8');
        $now = $app->clock()->sql();
        $lang = $app->defaultLang();
        $id = (int) $app->db()->transaction(function (Db $db) use ($args, $name, $now, $lang): int {
            $id = $db->insert('products', [
                'kind' => 'physical', 'price' => (int) $args['price'], 'status' => 'draft', 'visibility' => 'everywhere',
                'manage_stock' => 1, 'stock' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('product_i18n', ['product_id' => $id, 'lang' => $lang, 'name' => $name]);

            return $id;
        });
        $prefix = trim($app->settings()->str('urls.product', 'producto'), '/');
        $slug = Str::slug($name);
        $app->slugs()->set('product', $id, $lang, ($prefix !== '' ? $prefix . '/' : '') . ($slug !== '' ? $slug : 'producto-' . $id));
        self::refresh($app, [$id]);
        $created = $app->db()->value('SELECT updated_at FROM {products} WHERE id = ?', [$id]);

        return ['ok' => true, 'message' => t('Draft product created: {name}.', ['name' => $name]), 'undo' => ['id' => $id, 'updated_at' => (string) $created]];
    }

    /**
     * Deshacer una creación no borra nada: archiva el producto mientras siga en borrador y sin tocar.
     *
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoCreate(App $app, array $undo): array
    {
        $db = $app->db();
        $row = $db->row('SELECT status, updated_at FROM {products} WHERE id = ?', [(int) $undo['id']]);
        if ($row === null || (string) $row['status'] !== 'draft' || (string) $row['updated_at'] !== (string) $undo['updated_at']) {
            return ['ok' => false, 'message' => t('The product has changed since. It is not undone so that nothing else is lost.')];
        }
        $db->update('products', ['status' => 'archived', 'updated_at' => $app->clock()->sql()], ['id' => (int) $undo['id'], 'status' => 'draft']);
        self::refresh($app, [(int) $undo['id']]);

        return ['ok' => true, 'message' => t('The draft product was archived.')];
    }

    // ------------------------------------------------------------------ comunes

    /**
     * Los productos a los que pertenecen unas líneas de precio (de producto o de variante).
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return int[]
     */
    private static function touchedProducts(App $app, array $items): array
    {
        $ids = [];
        $variants = [];
        foreach ($items as $item) {
            if ($item['t'] === 'p') {
                $ids[(int) $item['id']] = true;
            } else {
                $variants[] = (int) $item['id'];
            }
        }
        foreach (array_chunk($variants, 200) as $chunk) {
            $params = [];
            $in = $app->db()->in($chunk, $params);
            foreach ($app->db()->select('SELECT DISTINCT product_id FROM {variants} WHERE id IN (' . $in . ')', $params) as $row) {
                $ids[(int) $row['product_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Lo que sigue a un cambio de producto: la caché del catálogo y el índice del buscador (por el mismo aviso que da el panel).
     *
     * @param int[] $ids
     */
    private static function refresh(App $app, array $ids): void
    {
        $tags = ['catalog'];
        foreach ($ids as $id) {
            $tags[] = 'product:' . $id;
        }
        $app->invalidate($tags);
        foreach ($ids as $id) {
            $app->hooks()->fire('admin.resource.saved', 'products', (int) $id, [], null);
        }
    }
}
