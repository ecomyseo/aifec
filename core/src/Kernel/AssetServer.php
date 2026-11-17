<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Sirve los recursos estáticos de temas, módulos, plugins y núcleo cuando no están publicados en la
 * carpeta pública (desarrollo, o instalación recién subida). En producción, la orden «assets:publish»
 * los copia a public/assets/ con el mismo camino y los sirve el servidor web sin pasar por PHP.
 *
 *   /assets/t/<tema>/<versión>/css/theme.css      themes/<tema>/assets/css/theme.css
 *   /assets/m/<módulo>/<versión>/cart.js          modules/<módulo>/assets/cart.js (o plugins/…)
 *   /assets/c/core/<versión>/admin/admin.css      core/assets/admin/admin.css
 */
final class AssetServer
{
    private const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'webmanifest' => 'application/manifest+json',
    ];

    /**
     * Fichero del disco que corresponde a una ruta «/assets/…», o null si la ruta no es válida o no existe.
     * $path es la parte que sigue a «/assets/».
     */
    public static function locate(string $root, string $path): ?string
    {
        if (preg_match('#^([tmc])/([a-z0-9][a-z0-9-]*)/([a-z0-9]{1,16})/((?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*)$#', $path, $m) !== 1) {
            return null;
        }
        if (strpos($m[4], '..') !== false) {
            return null;
        }
        $ext = strtolower(pathinfo($m[4], PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($m[1] === 't') {
            $candidates = [$root . '/themes/' . $m[2] . '/assets/' . $m[4]];
        } elseif ($m[1] === 'm') {
            $candidates = [$root . '/modules/' . $m[2] . '/assets/' . $m[4], $root . '/plugins/' . $m[2] . '/assets/' . $m[4]];
        } else {
            $candidates = [$root . '/core/assets/' . $m[4]];
        }
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** Respuesta para una ruta «/assets/…» (404 si no existe). */
    public static function serve(string $root, string $path, Request $request): Response
    {
        $file = self::locate($root, $path);
        if ($file === null) {
            return Response::text('Not found', 404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $etag = '"' . dechex((int) filemtime($file)) . '-' . dechex((int) filesize($file)) . '"';
        $isDev = strpos($path, '/dev/') !== false;
        $headers = [
            'Content-Type' => self::TYPES[$ext],
            'ETag' => $etag,
            'Cache-Control' => $isDev ? 'no-cache' : 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($request->header('if-none-match') === $etag) {
            return new Response('', 304, $headers);
        }
        $response = new Response('', 200, $headers);
        $response->file = $file;

        return $response;
    }

    /**
     * Copia todos los recursos a la carpeta pública bajo un identificador de versión nuevo y lo apunta en
     * build.txt. Los ficheros nuevos no pisan a los que se están sirviendo: van a otra carpeta.
     *
     * @return array{build:string,files:int}
     */
    public static function publish(string $root, string $publicDir): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $publicDir = rtrim(str_replace('\\', '/', $publicDir), '/');
        $sources = [];
        foreach (['themes' => 't', 'modules' => 'm', 'plugins' => 'm'] as $base => $kind) {
            $dirs = glob($root . '/' . $base . '/*/assets', GLOB_ONLYDIR);
            foreach (is_array($dirs) ? $dirs : [] as $dir) {
                $sources[] = [$kind, basename(dirname($dir)), str_replace('\\', '/', $dir)];
            }
        }
        if (is_dir($root . '/core/assets')) {
            $sources[] = ['c', 'core', $root . '/core/assets'];
        }
        $files = [];
        $stamp = '';
        foreach ($sources as $source) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source[2], \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $full = str_replace('\\', '/', (string) $file);
                $rel = substr($full, strlen($source[2]) + 1);
                if (!isset(self::TYPES[strtolower(pathinfo($full, PATHINFO_EXTENSION))])) {
                    continue;
                }
                $files[] = [$source[0], $source[1], $rel, $full];
                $stamp .= $source[0] . $source[1] . $rel . filesize($full) . md5_file($full);
            }
        }
        $build = substr(md5($stamp), 0, 10);
        foreach ($files as $f) {
            $target = $publicDir . '/assets/' . $f[0] . '/' . $f[1] . '/' . $build . '/' . $f[2];
            if (is_file($target)) {
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            if (strtolower(pathinfo($f[3], PATHINFO_EXTENSION)) === 'js') {
                // Los guiones se publican sin comentarios ni sangrías (PageSpeed: «minifica los recursos JavaScript»).
                file_put_contents($target, self::minifyJs((string) file_get_contents($f[3])));
            } else {
                copy($f[3], $target);
            }
        }
        if (!is_dir($publicDir . '/assets')) {
            mkdir($publicDir . '/assets', 0775, true);
        }
        $tmp = $publicDir . '/assets/build.txt.' . bin2hex(random_bytes(3));
        file_put_contents($tmp, $build);
        rename($tmp, $publicDir . '/assets/build.txt');

        return ['build' => $build, 'files' => count($files)];
    }

    /**
     * Quita a un guion lo que no se ejecuta, sin interpretarlo: los comentarios que ocupan líneas enteras (de bloque o de
     * línea), la sangría y las líneas vacías. Conserva los saltos de línea (la inserción automática de punto y coma sigue igual) y
     * todo lo que va detrás de código en la misma línea, así que una cadena o una expresión regular con «//» o «/*» no se toca.
     */
    public static function minifyJs(string $js): string
    {
        $out = [];
        $inComment = false;
        foreach (preg_split('/\r\n|\r|\n/', $js) ?: [] as $line) {
            $t = ltrim($line, " \t");
            if ($inComment) {
                $end = strpos($t, '*/');
                if ($end === false) {
                    continue;
                }
                $inComment = false;
                $t = ltrim(substr($t, $end + 2), " \t");
            }
            while (strncmp($t, '/*', 2) === 0) {
                $end = strpos($t, '*/', 2);
                if ($end === false) {
                    $inComment = true;
                    $t = '';
                    break;
                }
                $t = ltrim(substr($t, $end + 2), " \t");
            }
            if (strncmp($t, '//', 2) === 0) {
                continue;
            }
            $t = rtrim($t, " \t");
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return implode("\n", $out) . "\n";
    }
}
