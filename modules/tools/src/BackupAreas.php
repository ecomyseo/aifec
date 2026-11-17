<?php
/**
 * AIFEC — qué carpetas del sitio entran en una copia completa y cuáles se dejan fuera.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;

/**
 * Entran los medios públicos del sitio («media») y los ficheros privados que subió el sitio («private»: facturas, adjuntos,
 * descargas, importaciones). No entran: `config.php` (lleva la clave secreta y la contraseña de la base de datos, y ni siquiera
 * está en estas carpetas), las cachés, los registros, los temporales, las copias anteriores ni los enlaces simbólicos.
 */
final class BackupAreas
{
    /** Nombres de carpeta que nunca se copian, en cualquier nivel. */
    private const SKIP_DIRS = ['cache', 'tmp', 'logs', '.git'];

    /**
     * La carpeta de cada parte.
     *
     * @return array<string,string> parte => ruta absoluta con barras normales y sin barra final
     */
    public static function dirs(App $app): array
    {
        return [
            'media' => Fs::norm($app->site()->mediaDir($app->publicDir())),
            'private' => Fs::norm($app->site()->path('private')),
        ];
    }

    /** ¿Se deja fuera esta ruta (relativa a la carpeta de su parte)? */
    public static function skip(string $part, string $rel): bool
    {
        $segments = explode('/', $rel);
        $file = (string) end($segments);
        if (strncmp($file, '.tmp-', 5) === 0 || strncmp($file, '.run-', 5) === 0) {
            return true;
        }
        if ($part === 'private' && $segments[0] === 'backups') {
            return true;
        }
        foreach (array_slice($segments, 0, -1) as $dir) {
            if (in_array($dir, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los ficheros de una parte, en orden alfabético de ruta.
     *
     * @return string[] rutas relativas
     */
    public static function files(string $dir, string $part): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $cut = strlen($dir) + 1;
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $file->getPathname()), $cut);
            if ($rel === '' || self::skip($part, $rel)) {
                continue;
            }
            $out[] = $rel;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /** Una ruta de un `.zip` o de una lista no puede salirse de su carpeta ni ser rara. */
    public static function safeRelative(string $rel): bool
    {
        if ($rel === '' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false || $rel[0] === '/' || preg_match('/^[A-Za-z]:/', $rel) === 1) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
