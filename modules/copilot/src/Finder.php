<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * Busca en los datos reales lo que una orden nombra: el pedido por su número, el producto por referencia o nombre, la
 * categoría, la marca, el transportista y la zona de envío por su nombre.
 *
 * Una referencia puede ser lo que escribió la persona o un identificador exacto que dejó una elección anterior:
 * «#12» = producto 12, «#v45» = variante 45, y «#7» en una categoría, una marca, un transportista o una tarifa = esa fila.
 * Cuando hay varias lecturas devuelve las opciones; nunca elige por su cuenta entre dos que valen.
 */
final class Finder
{
    /** Hasta cuántos productos se recorren en la segunda pasada (sin tildes) antes de rendirse. */
    private const SCAN_MAX = 3000;

    /**
     * El pedido de un número («12», «2026-000012», «#12»).
     *
     * @return array{order:array<string,mixed>|null,choices:array<int,array<string,string>>,error:string}
     */
    public static function order(App $app, string $ref): array
    {
        $db = $app->db();
        $ref = trim(ltrim(trim($ref), '#'));
        if ($ref === '' || preg_match('/^[0-9A-Za-z\-]+$/', $ref) !== 1) {
            return ['order' => null, 'choices' => [], 'error' => t('Write the number of the order.')];
        }
        $columns = 'id, number, status, email, total, currency, tracking_code, carrier_id, carrier_title, fulfillment_status, payment_status, customer_id';
        $rows = $db->select(
            'SELECT ' . $columns . ' FROM {orders} WHERE number = ? OR number LIKE ?' . Like::ESC . ' ORDER BY id DESC LIMIT 40',
            [$ref, '%' . Like::escape($ref)]
        );
        $tail = ltrim($ref, '0');
        $matches = [];
        foreach ($rows as $row) {
            $number = (string) $row['number'];
            $last = preg_match('/(\d+)$/', $number, $m) === 1 ? ltrim($m[1], '0') : '';
            if (strcasecmp($number, $ref) === 0) {
                return ['order' => $row, 'choices' => [], 'error' => ''];
            }
            if ($tail !== '' && $last === $tail) {
                $matches[] = $row;
            }
        }
        if ($matches === [] && ctype_digit($ref)) {
            $byId = $db->row('SELECT ' . $columns . ' FROM {orders} WHERE id = ?', [(int) $ref]);
            if ($byId !== null) {
                $matches[] = $byId;
            }
        }
        if ($matches === []) {
            return ['order' => null, 'choices' => [], 'error' => t('There is no order {number}.', ['number' => $ref])];
        }
        if (count($matches) === 1) {
            return ['order' => $matches[0], 'choices' => [], 'error' => ''];
        }
        $choices = [];
        foreach (array_slice($matches, 0, 8) as $row) {
            $choices[] = ['param' => 'order', 'value' => (string) $row['number'], 'label' => (string) $row['number'] . ' · ' . (string) $row['email'] . ' · ' . Fmt::money($app, (int) $row['total'])];
        }

        return ['order' => null, 'choices' => $choices, 'error' => t('Several orders end in {number}. Choose one.', ['number' => $ref])];
    }

    /**
     * Los productos (o variantes) que encajan con una referencia.
     *
     * @return array<int,array{id:int,variant_id:int,name:string,sku:string,label:string}>
     */
    public static function products(App $app, string $ref, int $limit = 8): array
    {
        $db = $app->db();
        $ref = trim($ref);
        if ($ref === '') {
            return [];
        }
        if (preg_match('/^#v(\d+)$/i', $ref, $m) === 1) {
            $row = $db->row('SELECT id, product_id, sku, label FROM {variants} WHERE id = ?', [(int) $m[1]]);

            return $row === null ? [] : [self::variantCandidate($app, $row)];
        }
        if (preg_match('/^#(\d+)$/', $ref, $m) === 1) {
            $row = $db->row('SELECT id, sku FROM {products} WHERE id = ?', [(int) $m[1]]);

            return $row === null ? [] : [self::productCandidate($app, $row)];
        }

        // Una referencia o un código de barras exactos mandan sobre cualquier nombre.
        $found = [];
        foreach ($db->select('SELECT id, sku FROM {products} WHERE LOWER(sku) = LOWER(?) OR gtin = ? ORDER BY id LIMIT ?', [$ref, $ref, $limit]) as $row) {
            $found[] = self::productCandidate($app, $row);
        }
        foreach ($db->select('SELECT id, product_id, sku, label FROM {variants} WHERE LOWER(sku) = LOWER(?) OR gtin = ? ORDER BY id LIMIT ?', [$ref, $ref, $limit]) as $row) {
            $found[] = self::variantCandidate($app, $row);
        }
        if ($found !== []) {
            return array_slice($found, 0, $limit);
        }

        $words = self::words($ref);
        $ids = self::idsByName($app, $words, $limit * 4);
        if ($ids === [] && $words !== []) {
            $ids = self::idsByScan($app, $words, $limit * 4);
        }
        if ($ids === [] && strpos($ref, ' ') === false) {
            // Un trozo de referencia («SILLA-R»).
            foreach ($db->select('SELECT id FROM {products} WHERE sku LIKE ?' . Like::ESC . ' ORDER BY id LIMIT ?', [Like::contains($ref), $limit * 4]) as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        $out = [];
        $exact = [];
        $norm = Reader::norm($ref);
        foreach (array_slice(array_values(array_unique($ids)), 0, $limit * 4) as $id) {
            $row = $db->row('SELECT id, sku FROM {products} WHERE id = ?', [$id]);
            if ($row === null) {
                continue;
            }
            $candidate = self::productCandidate($app, $row);
            $out[] = $candidate;
            if (Reader::norm($candidate['name']) === $norm) {
                $exact[] = $candidate;
            }
        }
        if (count($exact) === 1) {
            return $exact;
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Los identificadores de producto cuyo nombre lleva todas las palabras (en cualquier idioma).
     *
     * @param string[] $words
     *
     * @return int[]
     */
    public static function idsByName(App $app, array $words, int $limit): array
    {
        if ($words === []) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($words as $word) {
            $where[] = 'name LIKE ?' . Like::ESC;
            $params[] = Like::contains($word);
        }
        $params[] = $limit;
        $ids = [];
        foreach ($app->db()->select('SELECT DISTINCT product_id FROM {product_i18n} WHERE ' . implode(' AND ', $where) . ' ORDER BY product_id LIMIT ?', $params) as $row) {
            $ids[] = (int) $row['product_id'];
        }

        return $ids;
    }

    /**
     * Lo mismo sin distinguir tildes: recorre los nombres (hasta SCAN_MAX) y compara el texto normalizado.
     *
     * @param string[] $words
     *
     * @return int[]
     */
    public static function idsByScan(App $app, array $words, int $limit): array
    {
        $db = $app->db();
        if ((int) $db->value('SELECT COUNT(*) FROM {product_i18n}') > self::SCAN_MAX) {
            return [];
        }
        $wanted = array_map([Reader::class, 'norm'], $words);
        $ids = [];
        foreach ($db->select('SELECT product_id, name FROM {product_i18n} ORDER BY product_id') as $row) {
            $name = Reader::norm((string) $row['name']);
            $all = true;
            foreach ($wanted as $word) {
                if ($word !== '' && strpos($name, $word) === false) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $ids[(int) $row['product_id']] = true;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Las palabras de una búsqueda (hasta seis, de una letra o más).
     *
     * @return string[]
     */
    public static function words(string $text): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice(is_array($parts) ? $parts : [], 0, 6);
    }

    /**
     * @param array<string,mixed> $row id, sku
     *
     * @return array{id:int,variant_id:int,name:string,sku:string,label:string}
     */
    private static function productCandidate(App $app, array $row): array
    {
        $name = Fmt::productName($app, (int) $row['id']);
        $sku = isset($row['sku']) && $row['sku'] !== null ? (string) $row['sku'] : '';

        return ['id' => (int) $row['id'], 'variant_id' => 0, 'name' => $name, 'sku' => $sku, 'label' => $name . ($sku !== '' ? ' (' . $sku . ')' : '')];
    }

    /**
     * @param array<string,mixed> $row id, product_id, sku, label
     *
     * @return array{id:int,variant_id:int,name:string,sku:string,label:string}
     */
    private static function variantCandidate(App $app, array $row): array
    {
        $name = Fmt::productName($app, (int) $row['product_id']);
        $sku = isset($row['sku']) && $row['sku'] !== null ? (string) $row['sku'] : '';
        $label = isset($row['label']) && $row['label'] !== null ? (string) $row['label'] : '';

        return [
            'id' => (int) $row['product_id'], 'variant_id' => (int) $row['id'], 'name' => $name, 'sku' => $sku,
            'label' => $name . ($label !== '' ? ' · ' . $label : '') . ($sku !== '' ? ' (' . $sku . ')' : ''),
        ];
    }

    // ------------------------------------------------------------------ categorías, marcas, transportistas, zonas

    /**
     * Las categorías (con todos sus nombres) que encajan con una referencia: el nombre entero primero; si no, las que lo contienen.
     *
     * @return array<int,array{id:int,name:string,path:string}>
     */
    public static function categories(App $app, string $ref): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT c.id, c.path, i.name, i.lang FROM {categories} c JOIN {category_i18n} i ON i.category_id = c.id ORDER BY c.id') as $row) {
            $id = (int) $row['id'];
            if (!isset($rows[$id])) {
                $rows[$id] = ['id' => $id, 'path' => (string) $row['path'], 'names' => [], 'name' => (string) $row['name']];
            }
            $rows[$id]['names'][] = (string) $row['name'];
            if ((string) $row['lang'] === $app->defaultLang()) {
                $rows[$id]['name'] = (string) $row['name'];
            }
        }

        return self::byName(array_values($rows), $ref, '#');
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    public static function brands(App $app, string $ref): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT id, name FROM {brands} ORDER BY id') as $row) {
            $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'names' => [(string) $row['name']]];
        }

        return self::byName($rows, $ref, '#');
    }

    /**
     * Los transportistas que encajan con una referencia (nombre, código o «#id»).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function carriers(App $app, string $ref): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT id, code, name, free_from, active FROM {carriers} ORDER BY position, id') as $row) {
            $row['names'] = [(string) $row['name'], (string) $row['code']];
            $rows[] = $row;
        }

        return self::byName($rows, $ref, '#');
    }

    /**
     * Los transportistas cuyo nombre o código aparece en un texto normalizado (el nombre más largo gana a uno que lo contiene).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function carriersIn(App $app, string $norm): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT id, code, name, free_from, active FROM {carriers} ORDER BY position, id') as $row) {
            $row['names'] = [(string) $row['name'], (string) $row['code']];
            $rows[] = $row;
        }

        return self::namesIn($rows, $norm);
    }

    /**
     * @return array<int,array{id:int,name:string,path:string}>
     */
    public static function categoriesIn(App $app, string $norm): array
    {
        $all = [];
        foreach ($app->db()->select('SELECT c.id, c.path, i.name FROM {categories} c JOIN {category_i18n} i ON i.category_id = c.id ORDER BY c.id') as $row) {
            $id = (int) $row['id'];
            if (!isset($all[$id])) {
                $all[$id] = ['id' => $id, 'path' => (string) $row['path'], 'name' => (string) $row['name'], 'names' => []];
            }
            $all[$id]['names'][] = (string) $row['name'];
        }

        return self::namesIn(array_values($all), $norm);
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    public static function brandsIn(App $app, string $norm): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT id, name FROM {brands} ORDER BY id') as $row) {
            $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'names' => [(string) $row['name']]];
        }

        return self::namesIn($rows, $norm);
    }

    /**
     * Las zonas de envío cuyo nombre (en inglés o traducido) aparece en el texto normalizado.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function zonesIn(App $app, string $norm): array
    {
        $rows = [];
        foreach ($app->db()->select('SELECT id, name FROM {shipping_zones} ORDER BY position, id') as $row) {
            $row['names'] = [(string) $row['name'], t((string) $row['name'])];
            $rows[] = $row;
        }

        return self::namesIn($rows, $norm);
    }

    /**
     * @param array<int,array<string,mixed>> $rows cada una con «names»
     *
     * @return array<int,array<string,mixed>>
     */
    public static function namesIn(array $rows, string $norm): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $best = '';
            foreach ($row['names'] as $name) {
                $n = Reader::norm((string) $name);
                if (strlen($n) >= 2 && Reader::has($norm, preg_quote($n, '/')) && strlen($n) > strlen($best)) {
                    $best = $n;
                }
            }
            if ($best !== '') {
                $row['_hit'] = $best;
                $hits[] = $row;
            }
        }
        // Un nombre dentro de otro más largo que también ha encajado («Sillas» y «Sillas de oficina»): solo el largo.
        $out = [];
        foreach ($hits as $hit) {
            $inside = false;
            foreach ($hits as $other) {
                if ($other !== $hit && strlen($other['_hit']) > strlen($hit['_hit']) && strpos($other['_hit'], $hit['_hit']) !== false) {
                    $inside = true;
                }
            }
            if (!$inside) {
                unset($hit['_hit']);
                $out[] = $hit;
            }
        }

        return $out;
    }

    /**
     * Las filas que encajan con una referencia: «#id» exacto; si no, el nombre entero (sin tildes ni mayúsculas); si no, las
     * que lo contienen.
     *
     * @param array<int,array<string,mixed>> $rows cada una con «id» y «names»
     *
     * @return array<int,array<string,mixed>>
     */
    private static function byName(array $rows, string $ref, string $idMark): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return $rows;
        }
        if ($idMark !== '' && preg_match('/^' . preg_quote($idMark, '/') . '(\d+)$/', $ref, $m) === 1) {
            return array_values(array_filter($rows, static function (array $row) use ($m): bool {
                return (int) $row['id'] === (int) $m[1];
            }));
        }
        $norm = Reader::norm($ref);
        $exact = [];
        $partial = [];
        foreach ($rows as $row) {
            foreach ($row['names'] as $name) {
                $n = Reader::norm((string) $name);
                if ($n === $norm) {
                    $exact[] = $row;
                    continue 2;
                }
                if ($n !== '' && strpos($n, $norm) !== false) {
                    $partial[] = $row;
                    continue 2;
                }
            }
        }

        return $exact !== [] ? $exact : $partial;
    }
}
