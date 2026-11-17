<?php
/**
 * AIFEC — código del tema base: dos ayudas para las plantillas y, solo con la depuración encendida, la página de
 * muestra (/tema/muestra) donde se revisan todas las piezas del tema sin necesidad de ningún módulo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

if (!function_exists('base_theme_can')) {
    /**
     * ¿Se puede enlazar esa ruta con nombre (search, cart, account…)? La cabecera, la barra inferior y los paneles solo
     * pintan los accesos que existen. En la página de muestra se dan todos por buenos para poder revisarlos.
     */
    function base_theme_can(string $name): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has($name);
    }
}

if (!function_exists('base_theme_link')) {
    /** Dirección de una ruta con nombre; en la página de muestra, y solo ahí, las que no existen apuntan a la propia muestra. */
    function base_theme_link(string $name): string
    {
        $app = App::current();

        return $app->router()->has($name) ? route($name) : url('/tema/muestra');
    }
}

return static function (App $app): void {
    if (!$app->debug()) {
        return;
    }
    $router = $app->router();
    $router->setModule('theme:base');
    $router->get('/tema/muestra', static function (Request $request, App $app, array $params): Response {
        $app->view()->share('af_demo', true);

        return $app->render('sample', ['filters' => $request->str('filtros') === '1']);
    })->name('theme.sample');
    // El formulario de «añadir al carrito» de la muestra: responde lo mismo que respondería el módulo del carrito.
    $router->post('/tema/muestra', static function (Request $request, App $app, array $params): Response {
        if (!$request->wantsJson()) {
            return Response::redirect($app->url()->to('/tema/muestra'), 303);
        }

        return Response::json(['ok' => true, 'count' => max(1, $request->int('qty', 1)), 'message' => t('Added to the cart')]);
    });
    $router->setModule('core');
    // La marca de «muestra» vale solo para esa petición: una misma aplicación puede atender varias seguidas.
    $app->hooks()->on('request.end', static function () use ($app): void {
        $app->view()->share('af_demo', false);
    });
};
