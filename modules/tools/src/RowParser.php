<?php
/**
 * AIFEC — lectura y validación de una fila del CSV de productos: de las celdas de texto a los datos ya tipados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * No toca la base de datos: dice si la fila está bien escrita, no si lo que nombra existe (eso lo mira ProductWriter).
 * Un dato que no viene en la fila (celda vacía o columna que no está) queda en null: «no cambiar».
 *
 * Los mensajes de RowError van en inglés y fijos.
 */
final class RowParser
{
    private const STATUSES = ['draft', 'published', 'archived'];

    private const TEXT_LIMITS = ['name' => 191, 'meta_title' => 191, 'meta_description' => 320, 'gtin' => 32, 'mpn' => 64, 'sku' => 64, 'brand' => 120];

    /**
     * @param string[]                       $cells   las celdas de la fila, por posición
     * @param array<int,array<string,string>> $columns lo que devolvió CsvColumns::analyse()['columns']
     *
     * @return array<string,mixed>
     *
     * @throws RowError
     */
    public static function parse(array $cells, array $columns): array
    {
        $row = [
            'sku' => '', 'parent_sku' => '', 'name' => null, 'summary' => null, 'description' => null, 'meta_title' => null,
            'meta_description' => null, 'price' => null, 'compare_price' => null, 'sale_price' => null, 'stock' => null,
            'weight' => null, 'status' => null, 'gtin' => null, 'mpn' => null, 'brand' => null, 'tax_class' => null,
            'categories' => null, 'images' => null, 'names' => [], 'attributes' => [],
        ];
        foreach ($columns as $i => $column) {
            $value = self::unguard(isset($cells[$i]) ? trim($cells[$i]) : '');
            if ($value === '') {
                continue;
            }
            if ($column['kind'] === 'attribute') {
                $row['attributes'][$column['key']] = $value;
            } elseif ($column['kind'] === 'name') {
                self::limit('name', $value);
                $row['names'][$column['key']] = $value;
            } else {
                $row[$column['key']] = self::field($column['key'], $value);
            }
        }
        if ($row['sku'] === '') {
            throw new RowError('The sku is missing');
        }

        return $row;
    }

    /**
     * Una hoja de cálculo no puede ejecutar una celda que empiece por =, +, - o @: al exportar se le pone un apóstrofo delante.
     * Aquí se quita ese apóstrofo (solo ese caso: «'Hola» se queda como está).
     */
    public static function unguard(string $cell): string
    {
        if (strlen($cell) > 1 && $cell[0] === "'" && strpos('=+-@', $cell[1]) !== false) {
            return substr($cell, 1);
        }

        return $cell;
    }

    /**
     * @return mixed
     *
     * @throws RowError
     */
    private static function field(string $key, string $value)
    {
        switch ($key) {
            case 'price':
            case 'compare_price':
            case 'sale_price':
                try {
                    return Amounts::parse($value);
                } catch (\InvalidArgumentException $e) {
                    throw new RowError('The column ' . $key . ' has an invalid amount: ' . $value);
                }

            case 'stock':
            case 'weight':
                if (preg_match('/^\d{1,9}$/', $value) !== 1) {
                    throw new RowError('The column ' . $key . ' must be a whole number of zero or more: ' . $value);
                }

                return (int) $value;
            case 'status':
                $status = strtolower($value);
                if (!in_array($status, self::STATUSES, true)) {
                    throw new RowError('The status must be draft, published or archived: ' . $value);
                }

                return $status;
            case 'categories':
                return self::categories($value);
            case 'images':
                return self::images($value);
            case 'sku':
            case 'parent_sku':
            case 'name':
            case 'meta_title':
            case 'meta_description':
            case 'gtin':
            case 'mpn':
            case 'brand':
                self::limit($key, $value);

                return $value;
            default:
                // summary, description, tax_class
                return $value;
        }
    }

    /**
     * «Muebles > Sillas|Hogar» → [['Muebles', 'Sillas'], ['Hogar']].
     *
     * @return array<int,string[]>
     *
     * @throws RowError
     */
    private static function categories(string $value): array
    {
        $out = [];
        foreach (explode('|', $value) as $path) {
            $parts = [];
            foreach (explode('>', $path) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    if (mb_strlen($part, 'UTF-8') > 191) {
                        throw new RowError('A category name is too long: ' . mb_substr($part, 0, 40, 'UTF-8') . '…');
                    }
                    $parts[] = $part;
                }
            }
            if ($parts !== []) {
                $out[] = $parts;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     *
     * @throws RowError
     */
    private static function images(string $value): array
    {
        $out = [];
        foreach (explode('|', $value) as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            if (preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
                throw new RowError('An image address is not valid: ' . mb_substr($url, 0, 80, 'UTF-8'));
            }
            $out[] = $url;
        }

        return $out;
    }

    /** @throws RowError */
    private static function limit(string $key, string $value): void
    {
        $max = isset(self::TEXT_LIMITS[$key]) ? self::TEXT_LIMITS[$key] : 0;
        if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
            throw new RowError('The column ' . $key . ' is longer than ' . $max . ' characters');
        }
    }
}
