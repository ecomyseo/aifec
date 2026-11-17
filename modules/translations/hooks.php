<?php
/**
 * AIFEC — traducciones: el catálogo de textos propio del sitio (que el motor de idiomas carga por encima del código), el servicio
 * «translations» y dos oyentes del contenido traducido (dirección amigable del idioma nuevo y el puente de «ai.apply» de páginas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Translations\ContentHooks;
use Aifec\Modules\Translations\Translations;

return static function (App $app): void {
    // <datos>/<sitio>/lang/<idioma>.php: lo que el comercio traduce o cambia en el panel manda sobre los catálogos del código.
    $app->i18n()->setSiteDir($app->site()->path('lang'));

    $app->bind('translations', static function (App $app): Translations {
        return new Translations($app);
    });

    // El nombre o el título traducido de un producto, una página o una entrada da su dirección en ese idioma (si no tenía).
    $app->hooks()->on('ai.approved', static function (array $proposal) use ($app): void {
        ContentHooks::approved($app, $proposal);
    });

    // Las propuestas de páginas y entradas se aplican con el aplicador del módulo de contenidos.
    $app->hooks()->filter('ai.apply', static function ($done, $proposal = null) use ($app): bool {
        return ContentHooks::applyContent($app, $done, $proposal);
    });
};
