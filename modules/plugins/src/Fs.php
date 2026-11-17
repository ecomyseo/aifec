<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Plugins;

/**
 * Operaciones con carpetas que necesita el gestor: copiar, borrar, listar y sacar una huella. Nunca sigue enlaces
 * simbólicos: un enlace dentro de un plugin no puede sacar a nadie de su carpeta.
 */
final class Fs
{
    /** Carpeta nueva, única, dentro de $base. */
    public static function makeTemp(string $base, string $prefix): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        $dir = $base . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    /** Borra una carpeta entera (sin seguir enlaces). Si no existe, no hace nada. */
    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
        $items = scandir($dir);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Copia una carpeta. $skip recibe la ruta relativa (con barras) de cada entrada y devuelve true si hay que saltarla.
     *
     * @param callable|null $skip
     *
     * @return int ficheros copiados
     */
    public static function copyTree(string $from, string $to, ?callable $skip = null, string $rel = ''): int
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = rtrim(str_replace('\\', '/', $to), '/');
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        $count = 0;
        $items = scandir($from);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $from . '/' . $item;
            $relative = $rel === '' ? $item : $rel . '/' . $item;
            if (is_link($path) || ($skip !== null && $skip($relative))) {
                continue;
            }
            if (is_dir($path)) {
                $count += self::copyTree($path, $to . '/' . $item, $skip, $relative);
            } elseif (copy($path, $to . '/' . $item)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Rutas relativas (con barras) de todos los ficheros de una carpeta, ordenadas.
     *
     * @return string[]
     */
    public static function files(string $dir, string $rel = ''): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $out = [];
        $items = is_dir($dir) ? scandir($dir) : [];
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $relative = $rel === '' ? $item : $rel . '/' . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                foreach (self::files($path, $relative) as $f) {
                    $out[] = $f;
                }
            } else {
                $out[] = $relative;
            }
        }
        sort($out);

        return $out;
    }

    /** Huella de todo lo que hay en una carpeta: nombres y contenido. Cambia si cambia cualquier fichero. */
    public static function fingerprint(string $dir): string
    {
        $parts = [];
        foreach (self::files($dir) as $rel) {
            $parts[] = $rel . ':' . sha1((string) file_get_contents(rtrim($dir, '/\\') . '/' . $rel));
        }

        return md5(implode('|', $parts));
    }

    /** Bytes que suman los ficheros de una carpeta. */
    public static function size(string $dir): int
    {
        $total = 0;
        foreach (self::files($dir) as $rel) {
            $total += (int) filesize(rtrim($dir, '/\\') . '/' . $rel);
        }

        return $total;
    }
}
