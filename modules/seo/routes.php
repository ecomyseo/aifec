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
use Aifec\Modules\Seo\AuditController;
use Aifec\Modules\Seo\SeoController;

/*
 * SEO: lo que piden los robots (robots.txt y los mapas del sitio) y la auditoría del panel.
 */
return static function (Router $r, App $app): void {
    $r->get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
    // La clave de IndexNow, en la raíz para que valga para todas las direcciones de la tienda (17/11/2026).
    $r->get('/indexnow.txt', [SeoController::class, 'indexNowKey'])->name('seo.indexnow');
    $r->get('/sitemap.xml', [SeoController::class, 'sitemapIndex'])->name('seo.sitemap');
    $r->get('/sitemap-{type:pages|posts|products|categories|brands}-{n:\d+}\.xml', [SeoController::class, 'sitemapPart'])->name('seo.sitemap.part');

    $r->group(['prefix' => '/admin/seo', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/audit', [AuditController::class, 'show'])->name('admin.seo.audit');
    });
};
