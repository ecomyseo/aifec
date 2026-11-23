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
use Aifec\Modules\Semantic\AuditAdmin;
use Aifec\Modules\Semantic\AuditJobs;
use Aifec\Modules\Semantic\AuditService;
use Aifec\Modules\Semantic\BotsAttribution;
use Aifec\Modules\Semantic\PageMeta;
use Aifec\Modules\Semantic\Semantic;
use Aifec\Modules\Semantic\SemanticJobs;
use Aifec\Modules\Semantic\TextVersion;

/*
 * Datos para máquinas: publica el servicio «semantic», añade los datos estructurados y la dirección de la versión en texto a
 * cada página (filtro «page.meta»), sirve la versión en texto de cada página pública («request.start») y mantiene al día los
 * feeds de producto (un trabajo único, con cinco minutos de retraso, cuando cambia un precio o unas existencias).
 */
return static function (App $app): void {
    // El servicio con la auditoría AIFEC incluida (audit() y auditProduct()).
    $app->bind('semantic', static function (App $app): Semantic {
        return new AuditService($app);
    });

    // De dónde viene el pedido: una visita llegada desde un asistente deja su marca en la sesión (antes que la versión en texto).
    $app->hooks()->filter('request.start', [BotsAttribution::class, 'early'], 1);

    // La nota de AIFEC en la ficha de producto del panel, sin tocar el módulo del catálogo.
    $app->hooks()->filter('admin.definitions', [AuditAdmin::class, 'definitions']);

    // Un producto guardado se vuelve a puntuar (solo ese, en segundo plano).
    $app->hooks()->on('admin.resource.saved', static function (string $resource = '', $id = null) use ($app): void {
        if ($resource === 'products' && is_numeric($id)) {
            AuditJobs::queueProduct($app, (int) $id);
        }
    });

    // Después de SEO (que pone la canónica y los robots): aquí solo se completan «jsonld» y «markdown».
    $app->hooks()->filter('page.meta', [PageMeta::class, 'apply'], 20);

    // Lo que un asistente que solo sigue enlaces tiene que poder encontrar desde cualquier página (revisión externa del
    // 17/11/2026: el llms.txt existía, pero ninguna página lo enlazaba y un lector que no se inventa direcciones no llegaba a él).
    $app->hooks()->slot('head', static function () use ($app): string {
        return $app->router()->has('semantic.llms')
            ? '<link rel="alternate" type="text/plain" title="llms.txt" href="' . e($app->url()->abs('/llms.txt', [], '')) . '">'
            : '';
    });
    $app->hooks()->slot('footer.columns', static function () use ($app): string {
        $router = $app->router();
        $url = $app->url();
        $apiOn = class_exists(\Aifec\Modules\Api\Gate::class) && \Aifec\Modules\Api\Gate::enabled($app);
        $links = [];
        if ($router->has('semantic.llms')) {
            $links[] = [$url->abs('/llms.txt', [], ''), t('Guide for AI assistants (llms.txt)')];
        }
        if ($apiOn && $router->has('api.links.guide')) {
            $links[] = [$url->abs('/buy-with-links', [], ''), t('How an assistant buys here')];
        }
        if ($router->has('mcp.card') && $app->settings()->bool('mcp.enabled', true)) {
            $links[] = [$url->abs('/mcp', [], ''), t('MCP server')];
        }
        if ($apiOn && $router->has('api.docs')) {
            $links[] = [$url->abs('/api', [], ''), t('API for programs')];
        }
        if ($links === []) {
            return '';
        }
        $items = '';
        foreach ($links as $link) {
            $items .= '<li class="af-nav-item"><a class="af-nav-link" href="' . e($link[0]) . '">' . e($link[1]) . '</a></li>';
        }

        return '<nav class="af-footer-col af-footer-ai" aria-label="' . e(t('For AI assistants')) . '"><p class="af-footer-title">'
            . e(t('For AI assistants')) . '</p><ul class="af-nav-list">' . $items . '</ul></nav>';
    });

    // «<dirección>.md» y «Accept: text/markdown» en la dirección normal.
    $app->hooks()->filter('request.start', [TextVersion::class, 'early']);
    // Y la cabecera «Link: <….md>; rel="alternate"; type="text/markdown"» en cada página que tiene versión en texto.
    $app->hooks()->filter('response', [TextVersion::class, 'link']);

    // Los feeds: lo que cambia un precio, unas existencias o lo que cuelga del producto.
    $stale = static function (string $resource = '', $id = null) use ($app): void {
        if (in_array($resource, ['products', 'product', 'variants', 'product_variants', 'categories', 'category', 'brands', 'brand'], true)) {
            SemanticJobs::queue($app);
        }
    };
    $app->hooks()->on('admin.resource.saved', $stale);
    $app->hooks()->on('admin.resource.deleted', $stale);
    $app->hooks()->on('stock.changed', static function () use ($app): void {
        SemanticJobs::queue($app);
    });

    // Los ajustes del módulo salen en las fichas, en el llms.txt y en los feeds.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'semantic') {
            $application->invalidate(['catalog', 'cms', 'home', 'semantic', 'settings']);
            SemanticJobs::queue($application);
        }
    });
};
