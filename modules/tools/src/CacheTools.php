<?php
/**
 * AIFEC — herramientas de la caché del sitio: cuánto ocupa lo guardado (datos y páginas) y cómo vaciarlo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;

/**
 * La caché guarda cada entrada en `cache/data/xx/<resumen>.php` con sus etiquetas dentro. Las páginas (las que sirve
 * la caché de página sin arrancar la aplicación) llevan la etiqueta «pages»: así se separan de los datos.
 */
final class CacheTools
{
    /** Entradas que se miran como mucho en una pasada (una caché enorme da una cota inferior). */
    private const MAX_ENTRIES = 50000;

    /**
     * @return array{data:array{files:int,bytes:int},pages:array{files:int,bytes:int}}
     */
    public static function stats(App $app): array
    {
        $out = ['data' => ['files' => 0, 'bytes' => 0], 'pages' => ['files' => 0, 'bytes' => 0]];
        foreach (self::entries($app) as $file) {
            $kind = self::isPage($file) ? 'pages' : 'data';
            ++$out[$kind]['files'];
            $out[$kind]['bytes'] += (int) @filesize($file);
        }

        return $out;
    }

    /**
     * Vacía los datos guardados o las páginas guardadas. Devuelve lo que ha quitado.
     *
     * @param string $kind data o pages
     *
     * @return array{files:int,bytes:int}
     */
    public static function clear(App $app, string $kind): array
    {
        $wantPages = $kind === 'pages';
        $out = ['files' => 0, 'bytes' => 0];
        foreach (self::entries($app) as $file) {
            if (self::isPage($file) !== $wantPages) {
                continue;
            }
            $bytes = (int) @filesize($file);
            if (@unlink($file)) {
                ++$out['files'];
                $out['bytes'] += $bytes;
            }
        }
        // Lo que ya estuviera leído en memoria en esta petición, y las copias de página que quedaran sueltas, dejan de valer.
        $app->invalidate($wantPages ? ['pages'] : ['tools-cache-cleared']);

        return $out;
    }

    /**
     * @return string[] ficheros de entradas de la caché
     */
    private static function entries(App $app): array
    {
        $base = Fs::norm($app->site()->path('cache')) . '/data';
        $out = [];
        $groups = is_dir($base) ? scandir($base) : [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            if ($group === '.' || $group === '..' || !is_dir($base . '/' . $group)) {
                continue;
            }
            $files = scandir($base . '/' . $group);
            foreach (is_array($files) ? $files : [] as $name) {
                if (substr($name, -4) === '.php') {
                    $out[] = $base . '/' . $group . '/' . $name;
                    if (count($out) >= self::MAX_ENTRIES) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    private static function isPage(string $file): bool
    {
        $head = @file_get_contents($file, false, null, 0, 1024);

        return is_string($head) && strpos($head, "'pages' =>") !== false;
    }
}
