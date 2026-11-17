<?php
/**
 * AIFEC — funciones de PHP 8 que el sistema usa y que en PHP 7.4 no existen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);

        return $len <= strlen($haystack) && substr_compare($haystack, $needle, -$len) === 0;
    }
}

if (!function_exists('array_is_list')) {
    /** @param array<mixed> $array */
    function array_is_list(array $array): bool
    {
        $i = 0;
        foreach ($array as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('get_debug_type')) {
    /** @param mixed $value */
    function get_debug_type($value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }
        $map = ['boolean' => 'bool', 'integer' => 'int', 'double' => 'float', 'NULL' => 'null'];
        $type = gettype($value);

        return $map[$type] ?? $type;
    }
}
