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
 * Punto de entrada web: de la petición del servidor a la respuesta, en este orden:
 * recursos estáticos sin publicar → instalación pendiente → sitio → caché de página → aplicación.
 *
 * public/index.php solo hace `Front::run(...)->send()`. Las pruebas llaman a run() con una petición fabricada.
 */
final class Front
{
    /**
     * @param string       $root       raíz de la instalación
     * @param string       $dataRoot   carpeta de datos (la que contiene sites.php)
     * @param string       $publicDir  carpeta pública
     * @param string       $publicBase ruta web de la carpeta pública («» si es la raíz del dominio)
     * @param Request|null $request    petición ya construida (pruebas); null = la real
     */
    public static function run(string $root, string $dataRoot, string $publicDir, string $publicBase = '', ?Request $request = null): Response
    {
        $raw = $request !== null ? $request : Request::fromGlobals($publicBase);
        $path = $raw->path;

        if (strpos($path, '/assets/') === 0) {
            return AssetServer::serve($root, (string) substr($path, 8), $raw);
        }

        if (SiteResolver::all($dataRoot) === []) {
            if (class_exists('Aifec\\Install\\Installer')) {
                return \Aifec\Install\Installer::handle($raw, $root, $dataRoot, $publicDir, $publicBase);
            }

            return Response::text('AIFEC is not installed yet and the installer is missing.', 503);
        }

        $site = SiteResolver::resolve($dataRoot, $raw->host, $path);
        if ($site === null) {
            return Response::text('No site is configured for this address.', 404);
        }

        // La ruta de la petición pasa a ser relativa al sitio: «/demos/moda/carrito» → «/carrito».
        if ($site->basePath !== '') {
            $rel = (string) substr($path, strlen($site->basePath));
            $raw->path = $rel === '' ? '/' : $rel;
            $raw->rawPath = $raw->path;
            if ($rel === '') {
                // «/demos/moda» sin barra final: se redirige a la portada del sitio.
                return Response::redirect($publicBase . $site->basePath . '/', 301);
            }
        }

        $clock = new Clock();
        $cached = PageCache::serve($site, $raw, $clock);
        if ($cached !== null) {
            VisitLog::record($site, $raw, 200, true, $clock);

            return $cached;
        }

        $app = new App($site, $root, ['public_dir' => $publicDir, 'public_base' => $publicBase, 'clock' => $clock]);
        $response = $app->handle($raw);
        VisitLog::record($site, $raw, $response->status, false, $clock);

        return $response;
    }

    /** Ruta web de la carpeta pública a partir del nombre del guion: «/tienda/index.php» → «/tienda». */
    public static function publicBase(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }
}
