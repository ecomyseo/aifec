<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Util;

/**
 * Utilidades de arrays: acceso por ruta con puntos, filtros de claves y agrupaciones.
 * Ninguna función lee estado global ni escribe fuera de sus argumentos.
 */
final class Arr
{
    /** Valor en una ruta con puntos («a.b.0»). Si no existe, $default. */
    public static function get(array $a, string $path, $default = null)
    {
        $found = false;
        $value = self::locate($a, $path, $found);

        return $found ? $value : $default;
    }

    /** Escribe en una ruta con puntos, creando los niveles que falten. */
    public static function set(array &$a, string $path, $value): void
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $cur = &$a;
        foreach ($keys as $k) {
            if (!isset($cur[$k]) || !is_array($cur[$k])) {
                $cur[$k] = [];
            }
            $cur = &$cur[$k];
        }
        $cur[$last] = $value;
    }

    public static function has(array $a, string $path): bool
    {
        $found = false;
        self::locate($a, $path, $found);

        return $found;
    }

    /** Quita la clave de una ruta con puntos. Si un nivel no existe, no hace nada. */
    public static function forget(array &$a, string $path): void
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $cur = &$a;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return;
            }
            $cur = &$cur[$k];
        }
        if (is_array($cur)) {
            unset($cur[$last]);
        }
    }

    /** @param string[] $keys */
    public static function only(array $a, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $a)) {
                $out[$k] = $a[$k];
            }
        }

        return $out;
    }

    /** @param string[] $keys */
    public static function except(array $a, array $keys): array
    {
        return array_diff_key($a, array_flip($keys));
    }

    /**
     * Valores de una columna de un listado de filas. Con $indexBy, las claves salen de esa columna
     * (las filas sin clave válida se añaden al final con índice numérico).
     */
    public static function pluck(array $rows, string $key, ?string $indexBy = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $value = is_array($row) && array_key_exists($key, $row) ? $row[$key] : null;
            $index = $indexBy === null ? null : self::indexOf($row, $indexBy);
            if ($index === null) {
                $out[] = $value;
            } else {
                $out[$index] = $value;
            }
        }

        return $out;
    }

    /** Agrupa filas por el valor de una columna. Las filas sin valor válido van al grupo ''. */
    public static function groupBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $group = self::indexOf($row, $key);
            $out[$group === null ? '' : $group][] = $row;
        }

        return $out;
    }

    /** Indexa filas por el valor de una columna. Sin clave válida, se añaden al final. */
    public static function keyBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $index = self::indexOf($row, $key);
            if ($index === null) {
                $out[] = $row;
            } else {
                $out[$index] = $row;
            }
        }

        return $out;
    }

    /** Primer valor que cumple $fn($valor, $clave), o $default. Sin $fn, el primer valor. */
    public static function first(array $a, ?callable $fn = null, $default = null)
    {
        foreach ($a as $k => $v) {
            if ($fn === null || $fn($v, $k)) {
                return $v;
            }
        }

        return $default;
    }

    /** Aplana arrays anidados hasta $depth niveles. Las claves se pierden. */
    public static function flatten(array $a, int $depth = PHP_INT_MAX): array
    {
        $out = [];
        foreach ($a as $v) {
            if (is_array($v) && $depth > 0) {
                $out = array_merge($out, self::flatten($v, $depth - 1));
            } else {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** null y false → []; array → el mismo; cualquier otro valor → [$v]. */
    public static function wrap($value): array
    {
        if ($value === null || $value === false) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /** Verdadero si el array tiene claves que no son 0, 1, 2… en orden. El vacío no es asociativo. */
    public static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Ordena filas por una columna de forma estable: las iguales conservan su orden original,
     * también en orden descendente.
     */
    public static function sortBy(array $rows, string $key, bool $desc = false): array
    {
        $items = [];
        foreach (array_values($rows) as $i => $row) {
            $items[] = [
                'v' => is_array($row) && array_key_exists($key, $row) ? $row[$key] : null,
                'i' => $i,
                'row' => $row,
            ];
        }
        usort($items, function (array $a, array $b) use ($desc): int {
            $cmp = $desc ? ($b['v'] <=> $a['v']) : ($a['v'] <=> $b['v']);

            return $cmp !== 0 ? $cmp : ($a['i'] <=> $b['i']);
        });

        $out = [];
        foreach ($items as $item) {
            $out[] = $item['row'];
        }

        return $out;
    }

    /**
     * Busca una ruta con puntos. Marca $found con true o false.
     *
     * @param mixed $found
     *
     * @return mixed
     */
    private static function locate(array $a, string $path, &$found)
    {
        $cur = $a;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                $found = false;

                return null;
            }
            $cur = $cur[$k];
        }
        $found = true;

        return $cur;
    }

    /** Valor de una columna usable como clave de array, o null si no lo es. */
    private static function indexOf($row, string $key)
    {
        if (!is_array($row) || !array_key_exists($key, $row)) {
            return null;
        }
        $value = $row[$key];
        if (is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
