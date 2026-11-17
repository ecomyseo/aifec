<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Kernel\App;

/**
 * Los campos de un documento del índice y lo que pesa cada uno. El orden de ALL es el de las columnas f_* de
 * search_postings y de las len_* de search_docs: las listas de apariciones se guardan como listas de números en ese orden.
 */
final class Fields
{
    /** nombre, referencias y códigos de barras, marca, categorías, etiquetas, atributos y datos clave, resumen, descripción y términos de la IA */
    public const ALL = ['name', 'ref', 'brand', 'cats', 'tags', 'attrs', 'summary', 'desc', 'extra'];

    private const DEFAULT_WEIGHTS = [
        'name' => 6.0, 'ref' => 4.0, 'brand' => 3.0, 'cats' => 2.5, 'tags' => 2.0,
        'attrs' => 2.0, 'summary' => 1.5, 'desc' => 1.0, 'extra' => 0.8,
    ];

    /**
     * El peso de cada campo según los ajustes (search.w_<campo>); lo que no sea un número se queda en el de fábrica.
     *
     * @return array<string,float>
     */
    public static function weights(App $app): array
    {
        $out = [];
        foreach (self::ALL as $field) {
            $value = $app->settings()->get('search.w_' . $field, self::DEFAULT_WEIGHTS[$field]);
            $out[$field] = is_numeric($value) ? max(0.0, (float) $value) : self::DEFAULT_WEIGHTS[$field];
        }

        return $out;
    }

    public static function defaultWeight(string $field): float
    {
        return isset(self::DEFAULT_WEIGHTS[$field]) ? self::DEFAULT_WEIGHTS[$field] : 1.0;
    }

    /**
     * Las columnas f_* de search_postings, en orden.
     *
     * @return string[]
     */
    public static function postingColumns(): array
    {
        $columns = [];
        foreach (self::ALL as $field) {
            $columns[] = 'f_' . $field;
        }

        return $columns;
    }

    /**
     * Las columnas len_* de search_docs, en orden.
     *
     * @return string[]
     */
    public static function lengthColumns(): array
    {
        $columns = [];
        foreach (self::ALL as $field) {
            $columns[] = 'len_' . $field;
        }

        return $columns;
    }
}
