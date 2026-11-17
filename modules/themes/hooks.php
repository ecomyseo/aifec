<?php
/**
 * AIFEC — módulo de temas: publica el servicio «themes» y engancha la vista previa del personalizador.
 *
 * La vista previa es una petición de la tienda con «?af_preview=1» hecha por quien lleva la cookie de vista previa (solo la
 * da el panel): sus valores sin publicar se ponen EN MEMORIA durante esa petición (ver Preview y Memory), y la respuesta
 * nunca se guarda en la caché de página, lleva «noindex» y su canónica no lleva el parámetro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Themes\Memory;
use Aifec\Modules\Themes\Preview;
use Aifec\Modules\Themes\Themes;

return static function (App $app): void {
    $app->bind('themes', static function (App $app): Themes {
        return new Themes($app);
    });

    $hooks = $app->hooks();

    // Al empezar: si la petición pide la vista previa y trae un borrador válido, se pone en memoria. No devuelve respuesta.
    $hooks->filter('request.start', static function ($early, Request $request, App $app) {
        if (Preview::requested($request)) {
            $saved = Preview::begin($app, $request);
            if ($saved !== null) {
                $request->setAttr('themes.preview', $saved);
            }
        }

        return $early;
    });

    // Al acabar: se devuelven los ajustes a como estaban (la misma aplicación puede atender otra petición después).
    $hooks->on('request.end', static function (Request $request, $response, App $app): void {
        $saved = $request->attr('themes.preview');
        if (is_array($saved)) {
            Memory::restore($app, $saved);
            $request->setAttr('themes.preview', null);
        }
    });

    // La respuesta de una vista previa nunca se guarda en caché y no se indexa (ni siquiera la de quien no tiene borrador).
    $hooks->filter('response', static function ($response, Request $request, App $app) {
        if ($response instanceof Response && Preview::requested($request)) {
            $response->cacheTtl = 0;
            $response->cacheTags = [];
            $response->headers['Cache-Control'] = 'no-store, private';
            $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $response;
    });

    // El <head> de una vista previa: noindex y canónica sin el parámetro.
    $hooks->filter('page.meta', static function ($page, App $app) {
        if (is_array($page) && Preview::requested($app->request())) {
            $page['robots'] = 'noindex, nofollow';
            if (isset($page['canonical']) && is_string($page['canonical'])) {
                $page['canonical'] = Preview::stripParam($page['canonical']);
            }
        }

        return $page;
    }, 99);
};
