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
    // El origen del sistema (licencia AGPL-3.0 con las cláusulas adicionales de LICENSE.md): la marca del generador en cada página y
    // el aviso «Creado con AIFEC» al final. Los dos son avisos legales que la licencia obliga a conservar.
    $app->hooks()->slot('head', static function () use ($app): string {
        return '<meta name="generator" content="AIFEC ' . e(App::VERSION) . '">';
    });
    $app->hooks()->slot('body.end', static function () use ($app): string {
        return '<p class="af-powered" style="margin:0;padding:.75rem 1rem;text-align:center;font-size:.75rem;opacity:.75">'
            . '<a href="https://aifec.dev" rel="noopener" style="color:inherit">' . e(t('Created with AIFEC')) . '</a></p>';
    });

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
