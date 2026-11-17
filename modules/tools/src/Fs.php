<?php
/**
 * AIFEC — utilidades de ficheros del módulo de herramientas: pruebas de escritura y tamaño de una carpeta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

final class Fs
{
    /** Ruta con barras normales y sin barra final. */
    public static function norm(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * ¿Se puede escribir en esa carpeta (o crearla)? Si no existe se prueba en la primera que sí exista por encima.
     * La única medida fiable es escribir un fichero y borrarlo: los permisos de Windows y de las carpetas montadas engañan.
     */
    public static function writable(string $dir): bool
    {
        $probe = self::norm($dir);
        if ($probe === '') {
            return false;
        }
        while (!is_dir($probe)) {
            if (file_exists($probe)) {
                return false;
            }
            $parent = self::norm(dirname($probe));
            if ($parent === $probe || $parent === '' || $parent === '.') {
                return false;
            }
            $probe = $parent;
        }
        $file = $probe . '/.aifec-probe-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($file, 'x') !== false;
        if ($ok) {
            @unlink($file);
        }

        return $ok;
    }

    /**
     * Tamaño de una carpeta. Mira como mucho $maxEntries ficheros: en una carpeta enorme el resultado es una cota inferior.
     *
     * @return array{bytes:int,files:int,truncated:bool}
     */
    public static function size(string $dir, int $maxEntries = 20000): array
    {
        $out = ['bytes' => 0, 'files' => 0, 'truncated' => false];
        $dir = self::norm($dir);
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($out['files'] >= $maxEntries) {
                $out['truncated'] = true;
                break;
            }
            if ($file->isFile()) {
                $out['bytes'] += (int) $file->getSize();
                ++$out['files'];
            }
        }

        return $out;
    }
}
