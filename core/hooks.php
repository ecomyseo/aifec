<?php
/**
 * AIFEC — lo que el núcleo registra al arrancar: los intermediarios de serie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Gate;
use Aifec\Kernel\App;
use Aifec\Kernel\AssetServer;

return static function (App $app): void {
    $app->middleware('admin', [Gate::class, 'admin']);
    $app->middleware('customer', [Gate::class, 'customer']);
    $app->middleware('csrf', [Gate::class, 'csrf']);
    $app->middleware('api', [Gate::class, 'api']);
    // Activar o desactivar la minificación vuelve a publicar los recursos (con otra huella) y vacía la caché de páginas: el cambio se
    // ve sin pasos a mano. Si los recursos no están publicados (se sirven desde el código), solo hay que vaciar la caché.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if (array_intersect($keys, ['assets.minify_js', 'assets.minify_css']) === []) {
            return;
        }
        try {
            if (is_file($application->publicDir() . '/assets/build.txt')) {
                AssetServer::publish($application->root(), $application->publicDir(), AssetServer::optionsFor($application));
            }
        } catch (\Throwable $e) {
            $application->log()->exception($e, 'assets.minify publish');
        }
        $application->invalidate(['pages']);
    });
};
