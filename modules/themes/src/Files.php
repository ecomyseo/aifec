<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

/**
 * Operaciones con carpetas de un tema: listar, copiar y borrar. Nunca sigue enlaces simbólicos: un enlace dentro de un
 * tema no puede sacar a nadie de su carpeta.
 */
final class Files
{
    /**
     * Rutas relativas (con barras) de todos los ficheros de una carpeta, ordenadas.
     *
     * @return string[]
     */
    public static function files(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $found = [];
        self::walk($dir, '', $found);
        sort($found);

        return $found;
    }

    /**
     * @param string[] $found
     */
    private static function walk(string $dir, string $rel, array &$found): void
    {
        $items = is_dir($dir) ? scandir($dir) : false;
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
                self::walk($path, $relative, $found);
            } else {
                $found[] = $relative;
            }
        }
    }

    /** Copia una carpeta entera. Devuelve los ficheros copiados. */
    public static function copyTree(string $from, string $to): int
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = rtrim(str_replace('\\', '/', $to), '/');
        $count = 0;
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (self::files($from) as $rel) {
            $target = $to . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            if (copy($from . '/' . $rel, $target)) {
                ++$count;
            }
        }

        return $count;
    }

    /** Borra una carpeta entera (sin seguir enlaces). Si no existe, no hace nada. */
    public static function rmTree(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
        $items = is_dir($dir) ? scandir($dir) : false;
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

    /** Escribe un fichero creando sus carpetas. Lanza si no puede. */
    public static function write(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the folder ' . $dir);
        }
        if (file_put_contents($file, $content) === false) {
            throw new \RuntimeException('Cannot write the file ' . $file);
        }
    }
}
