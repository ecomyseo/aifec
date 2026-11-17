<?php
/**
 * AIFEC — rutas del buscador: la página de resultados (con el nombre «search», que sustituye a la del catálogo), sus dos versiones
 * para máquinas (.json y .md), las sugerencias en JSON, la ayuda de la IA que llega después y las dos pantallas del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Search\SearchAdminController;
use Aifec\Modules\Search\SearchController;

return static function (Router $r, App $app): void {
    // La misma dirección que usa el catálogo: el módulo del buscador se carga después y su ruta es la que vale.
    $base = '/' . trim($app->settings()->str('urls.search', 'buscar'), '/');
    $r->get($base, [SearchController::class, 'results'])->name('search');
    $r->get($base . '.json', [SearchController::class, 'machineJson'])->name('search.json');
    $r->get($base . '.md', [SearchController::class, 'machineMarkdown'])->name('search.md');
    $r->get($base . '/sugerir.json', [SearchController::class, 'suggestions'])->name('search.suggest');
    // La ayuda de la IA que la página de resultados no espera: la pide el guion del hueco (more.js), solo desde la tienda.
    $r->get($base . '/significado.json', [SearchController::class, 'meaning'])->name('search.meaning');
    // La dirección de antes sigue contestando lo mismo.
    $r->get($base . '/sugerencias.json', [SearchController::class, 'suggestions'])->name('search.suggest.old');

    $r->group(['prefix' => '/admin/search', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [SearchAdminController::class, 'overview'])->name('admin.search');
        $r->post('/reindex', [SearchAdminController::class, 'reindex']);
    });
};
