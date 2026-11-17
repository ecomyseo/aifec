<?php
/**
 * AIFEC — rutas de la documentación: índice, buscador, imágenes y páginas. El buscador y las imágenes van antes que la ruta que los comería.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Docs\DocsController;

return static function (Router $r, App $app): void {
    $r->get('/docs', [DocsController::class, 'index'])->name('docs');
    $r->get('/docs/buscar', [DocsController::class, 'search'])->name('docs.search');
    $r->get('/docs/_media/{fichero:[^/]+}', [DocsController::class, 'media'])->name('docs.media');
    $r->get('/docs/{ruta:.+}', [DocsController::class, 'show'])->name('docs.page');
};
