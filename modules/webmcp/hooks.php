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
use Aifec\Modules\Webmcp\Webmcp;

/*
 * WebMCP: las marcas de los formularios y el guion que registra las herramientas. Lo mismo para todo el mundo (nada
 * personal en el HTML), así que las páginas siguen siendo cacheables.
 */
return static function (App $app): void {
    $app->bind('webmcp', static function (App $application): Webmcp {
        return new Webmcp($application);
    });

    // Cada petición empieza sin nombres de herramienta escritos (en una misma página no pueden repetirse, y la siguiente es otra).
    $app->hooks()->filter('request.start', static function ($early) use ($app) {
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');
        $webmcp->reset();

        return $early;
    }, 1);

    // Las plantillas piden la marca del formulario y la de cada campo con los huecos «webmcp.form» y «webmcp.param».
    $app->hooks()->slot('webmcp.form', static function (string $form = '', array $vars = []) use ($app): string {
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');

        return $webmcp->formAttributes($form, $vars);
    });
    $app->hooks()->slot('webmcp.param', static function (string $form = '', string $field = '') use ($app): string {
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');

        return $webmcp->paramAttribute($form, $field);
    });

    // El guion: con defer, y solo si WebMCP está encendido. Él mismo no hace nada si el navegador no ofrece la API.
    $app->hooks()->slot('body.end', static function () use ($app): string {
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');
        if (!$webmcp->enabled()) {
            return '';
        }
        $url = $app->url();

        return '<script src="' . e($url->asset('m', 'webmcp', 'webmcp.js')) . '" data-webmcp-tools="' . e($url->route('webmcp.tools', [], '')) . '" defer></script>';
    });

    // El llms.txt cuenta que la tienda ofrece sus herramientas al navegador del agente.
    $app->hooks()->filter('llms.sections', static function ($sections) use ($app) {
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');
        if (!is_array($sections) || !$webmcp->enabled()) {
            return $sections;
        }
        foreach ($sections as $index => $section) {
            if (is_array($section) && isset($section['id']) && $section['id'] === 'howto' && isset($section['links']) && is_array($section['links'])) {
                $sections[$index]['links'][] = ['title' => t('WebMCP tools for agents in the browser'), 'url' => $app->url()->abs('/webmcp/tools.json', [], ''), 'note' => '', 'ref' => null];
            }
        }

        return $sections;
    });

    // Encender o apagar WebMCP cambia todas las páginas guardadas en la caché.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'webmcp') {
            $application->invalidate(['pages']);
        }
    });
};
