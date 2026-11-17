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
use Aifec\Modules\Seo\Meta;
use Aifec\Modules\Seo\SeoAdmin;
use Aifec\Modules\Seo\Verify;

/*
 * SEO: títulos y descripciones por plantilla, idiomas alternativos y reglas de robots en el filtro «page.meta»; los códigos de
 * verificación de Google y Bing en el hueco «head»; y, en el panel, el aviso de sitio cerrado y la pieza del inicio.
 */
return static function (App $app): void {
    // Las páginas de la tienda: lo que el controlador no puso, se completa aquí.
    $app->hooks()->filter('page.meta', [Meta::class, 'apply']);

    // «seo.demo_notice»: una banda en todas las páginas que dice que es una tienda de demostración con productos y marcas
    // ficticios (17/11/2026: para no competir con las tiendas de verdad de las que salieron los productos).
    $app->hooks()->slot('body.start', static function () use ($app): string {
        if (!$app->settings()->bool('seo.demo_notice', false)) {
            return '';
        }

        return '<div class="af-demo-band" role="note" style="background:#1b1b1b;color:#fff;text-align:center;font-size:.85rem;line-height:1.4;padding:.45rem 1rem">'
            . e(t('Demo shop: the products and brands are fictional and nothing is charged.')) . '</div>';
    });

    // Verificación de propiedad: solo el código, validado, dentro de su etiqueta.
    $app->hooks()->slot('head', static function () use ($app): string {
        return Verify::tags($app);
    });

    // Los mapas y el robots.txt están en la caché de página: se vacían al cambiar los ajustes del módulo y al guardar o borrar
    // algo que sale en el mapa (los contenidos también vacían su propia etiqueta; esta cubre el mapa y la auditoría).
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'seo') {
            $application->invalidate(['seo', 'catalog', 'cms']);
        }
    });
    $flush = static function (string $resource, $id = null) use ($app): void {
        if (in_array($resource, ['products', 'product', 'categories', 'category', 'brands', 'brand', 'pages', 'page', 'posts', 'post', 'redirects', 'media'], true)) {
            $app->invalidate(['seo']);
        }
    };
    $app->hooks()->on('admin.resource.saved', $flush);
    $app->hooks()->on('admin.resource.deleted', $flush);

    // El inicio del panel.
    $app->hooks()->filter('admin.status', [SeoAdmin::class, 'status']);
};
