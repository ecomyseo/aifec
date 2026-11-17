<?php
/**
 * AIFEC — consentimiento: publica el servicio «consent», pinta el aviso y la cabecera de Consent Mode v2 en la tienda y vacía la
 * caché de página cuando cambian los ajustes o la lista de cookies.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Consent\ConsentService;

require_once __DIR__ . '/src/helpers.php';

return static function (App $app): void {
    $app->bind('consent', static function (App $app): ConsentService {
        return new ConsentService($app);
    });

    $service = static function () use ($app): ConsentService {
        /** @var ConsentService $consent */
        $consent = $app->service('consent');

        return $consent;
    };

    // Lo primero de la página: el valor por defecto de Consent Mode (todo denegado salvo lo decidido) antes de cualquier etiqueta.
    // No depende de quien visita: es el mismo texto para todos y la página sigue siendo cacheable.
    $app->hooks()->slot('head', static function () use ($service): string {
        return $service()->head();
    });

    // Ese valor por defecto va escrito en la página (tiene que correr antes que cualquier etiqueta): su huella va en la política
    // de contenido para que el navegador lo ejecute. Y los guiones que esperan permiso se activan copiando su texto, así que
    // quien los use con código escrito (no con una dirección) tiene que declarar su huella o pedir la política relajada.
    $app->hooks()->filter('csp', static function ($csp) use ($service) {
        if ($csp instanceof \Aifec\Kernel\Csp && $service()->enabled()) {
            $csp->inline($service()->headJs());
        }

        return $csp;
    });

    // El aviso y su segunda capa. También el mismo HTML para todos: lo activa consent.js leyendo la cookie.
    $app->hooks()->slot('body.end', static function () use ($service): string {
        return $service()->notice();
    });

    // El aviso, la versión y la lista de cookies están dentro de todas las páginas guardadas.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'consent' || $module === 'datalayer') {
            $application->invalidate(['pages']);
        }
    });
    $flush = static function ($resource = '', $id = null) use ($app): void {
        if ((string) $resource === 'consent_cookies') {
            $app->invalidate(['pages']);
        }
    };
    $app->hooks()->on('admin.resource.saved', $flush);
    $app->hooks()->on('admin.resource.deleted', $flush);
};
