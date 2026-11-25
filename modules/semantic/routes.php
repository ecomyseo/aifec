<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Semantic\AuditController;
use Aifec\Modules\Semantic\BotsController;
use Aifec\Modules\Semantic\SemanticController;

/*
 * Lo que piden los robots y los agentes: el resumen para modelos de lenguaje, los feeds de producto y el documento que dice qué
 * puertas tiene la tienda. La versión en texto de cada página no tiene ruta: la sirve el gancho «request.start».
 */
return static function (Router $r, App $app): void {
    $r->get('/llms.txt', [SemanticController::class, 'llms'])->name('semantic.llms');
    $r->get('/llms-full.txt', [SemanticController::class, 'llmsFull'])->name('semantic.llms_full');
    $r->get('/sitemap-md.xml', [SemanticController::class, 'mdSitemap'])->name('semantic.md_sitemap');
    $r->get('/feeds/index.json', [SemanticController::class, 'feedsIndex'])->name('semantic.feeds_index');
    $r->get('/feeds/{file:google\.xml|products\.json|openai\.json}', [SemanticController::class, 'feed'])->name('semantic.feed');
    $r->get('/.well-known/aifec.json', [SemanticController::class, 'wellKnown'])->name('semantic.well_known');

    // El panel: la auditoría AIFEC y los robots y asistentes.
    $r->group(['prefix' => '/admin/semantic', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/audit', [AuditController::class, 'show'])->name('admin.semantic.audit');
        $r->post('/audit/run', [AuditController::class, 'run'])->name('admin.semantic.audit.run');
        $r->get('/bots', [BotsController::class, 'show'])->name('admin.semantic.bots');
    });
};
