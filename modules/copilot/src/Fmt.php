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
 * Cómo se enseña lo que cambia una acción: importes, estados y filas de cambio. Todo ya traducido y sin HTML (la pantalla escapa).
 */
final class Fmt
{
    public static function money(App $app, ?int $cents): string
    {
        return $cents === null ? '—' : $app->i18n()->money($cents, $app->settings()->str('shop.currency', 'EUR'));
    }

    /**
     * Una fila de «antes → después».
     *
     * @return array{label:string,from:string,to:string}
     */
    public static function change(string $label, string $from, string $to): array
    {
        return ['label' => $label, 'from' => $from, 'to' => $to];
    }

    /** El nombre de un producto en el idioma principal (o en el primero que tenga). Vacío si no existe. */
    public static function productName(App $app, int $id): string
    {
        $db = $app->db();
        $name = $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, $app->defaultLang()]);
        if (!is_string($name) || $name === '') {
            $name = $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? ORDER BY id', [$id]);
        }

        return is_string($name) ? $name : '';
    }

    /**
     * Los nombres de varios productos de una vez (en el idioma principal; si falta, el primero que tengan).
     *
     * @param int[] $ids
     *
     * @return array<int,string> identificador => nombre
     */
    public static function productNames(App $app, array $ids): array
    {
        $names = [];
        $lang = $app->defaultLang();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 200) as $chunk) {
            $params = [];
            $in = $app->db()->in($chunk, $params);
            foreach ($app->db()->select('SELECT product_id, lang, name FROM {product_i18n} WHERE product_id IN (' . $in . ') ORDER BY id', $params) as $row) {
                $id = (int) $row['product_id'];
                if (!isset($names[$id]) || (string) $row['lang'] === $lang) {
                    $names[$id] = (string) $row['name'];
                }
            }
        }

        return $names;
    }

    /** Quién aplica: el identificador de la persona del panel que ha entrado, o null. */
    public static function userId(App $app): ?int
    {
        return $app->auth()->adminId();
    }

    /**
     * El texto de varios nombres para una frase («Silla, Mesa y 3 más»).
     *
     * @param string[] $names
     */
    public static function names(array $names, int $show = 3): string
    {
        $shown = array_slice($names, 0, $show);
        $rest = count($names) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? ' ' . tn('and {n} more', 'and {n} more', $rest) : '');
    }
}
