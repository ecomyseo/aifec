<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * Lo que ve el modelo de lo que devolvió una herramienta. Cuanto menos texto entra, antes contesta:
 *
 *  - forModel(): el resultado sin lo que solo sirve para pintar (direcciones de imágenes y de fichas, precios por duplicado). Las
 *    fichas las pinta la tienda, no el modelo;
 *  - line(): el mismo resultado en una línea, para los resultados de rondas anteriores de esta misma respuesta. Conserva lo que el
 *    modelo necesita para seguir (identificadores, nombres, precios).
 */
final class ToolSummary
{
    /** Productos que se enumeran en la línea de un resultado. */
    private const LINE_ITEMS = 8;

    /** Largo máximo de la línea de un resultado que no es una lista de productos. */
    private const LINE_MAX = 300;

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function forModel(string $tool, array $data): array
    {
        if ($tool !== 'search_products' || !isset($data['items']) || !is_array($data['items'])) {
            return $data;
        }
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [
                'id' => (int) (isset($item['id']) ? $item['id'] : 0),
                'name' => (string) (isset($item['name']) ? $item['name'] : ''),
                'price_formatted' => (string) (isset($item['price_formatted']) ? $item['price_formatted'] : (isset($item['price']) ? $item['price'] : '')),
                'available' => !empty($item['available']),
                'summary' => (string) (isset($item['summary']) ? $item['summary'] : ''),
                'options' => isset($item['options']) && is_array($item['options']) ? array_values($item['options']) : [],
                'answer_kind' => (string) (isset($item['answer_kind']) ? $item['answer_kind'] : ''),
            ];
            if (!empty($item['matches']) && is_array($item['matches'])) {
                $row['matches'] = array_values($item['matches']);
            }
            if (!empty($item['already_shown'])) {
                $row['already_shown'] = true;
            }
            $items[] = $row;
        }
        $out = $data;
        $out['items'] = $items;
        if (isset($data['nearby']) && is_array($data['nearby'])) {
            // Los candidatos cercanos, en corto: lo justo para decidir si encajan.
            $near = [];
            foreach ($data['nearby'] as $item) {
                if (is_array($item)) {
                    $near[] = [
                        'id' => (int) (isset($item['id']) ? $item['id'] : 0),
                        'name' => (string) (isset($item['name']) ? $item['name'] : ''),
                        'price_formatted' => (string) (isset($item['price_formatted']) ? $item['price_formatted'] : ''),
                        'available' => !empty($item['available']),
                        'summary' => (string) (isset($item['summary']) ? $item['summary'] : ''),
                        'without' => (string) (isset($item['without']) ? $item['without'] : ''),
                    ];
                }
            }
            $out['nearby'] = $near;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function line(string $tool, array $data): string
    {
        if (isset($data['error'])) {
            return $tool . ' (earlier in this answer): error: ' . mb_substr((string) $data['error'], 0, 120, 'UTF-8');
        }
        if ($tool === 'search_products' && isset($data['items']) && is_array($data['items'])) {
            $parts = [];
            foreach (array_slice($data['items'], 0, self::LINE_ITEMS) as $item) {
                if (is_array($item)) {
                    $parts[] = '#' . (int) $item['id'] . ' ' . (string) $item['name'] . ' ' . (string) (isset($item['price_formatted']) ? $item['price_formatted'] : (isset($item['price']) ? $item['price'] : ''));
                }
            }

            $near = [];
            foreach (array_slice(isset($data['nearby']) && is_array($data['nearby']) ? $data['nearby'] : [], 0, self::LINE_ITEMS) as $item) {
                if (is_array($item)) {
                    $near[] = '#' . (int) $item['id'] . ' ' . (string) $item['name'] . ' ' . (string) (isset($item['price_formatted']) ? $item['price_formatted'] : '');
                }
            }

            return 'search_products (earlier in this answer): ' . (isset($data['answer_kind']) ? 'kind=' . (string) $data['answer_kind'] . '; ' : '')
                . ($parts === [] ? 'no products' : implode('; ', $parts))
                . ($near === [] ? '' : ' | nearby candidates (not exact): ' . implode('; ', $near));
        }
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $tool . ' (earlier in this answer): ' . mb_substr($json, 0, self::LINE_MAX, 'UTF-8') . (mb_strlen($json, 'UTF-8') > self::LINE_MAX ? '…' : '');
    }
}
