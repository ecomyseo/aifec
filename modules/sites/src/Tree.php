<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

/**
 * Borrar una carpeta con cuidado: solo si cuelga de otra que se dice, sin seguir enlaces simbólicos y sin salirse con «..».
 * La usa Demos para quitar los medios de UNA demostración (public/media/<sitio>) y nada más.
 */
final class Tree
{
    /**
     * @param string $dir    carpeta que se borra
     * @param string $inside carpeta de la que tiene que colgar (la de los medios, nunca la raíz de nada)
     *
     * @return bool true si la carpeta ha dejado de existir
     */
    public static function remove(string $dir, string $inside): bool
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $inside = rtrim(str_replace('\\', '/', $inside), '/');
        if ($dir === '' || $inside === '' || strpos($dir, $inside . '/') !== 0 || in_array('..', explode('/', $dir), true)) {
            return !file_exists($dir);
        }
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return !file_exists($dir);
        }
        if (!is_dir($dir)) {
            return true;
        }
        $items = scandir($dir);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::remove($path, $dir);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);

        return !is_dir($dir);
    }
}
