<?php
/**
 * AIFEC — capa de datos: publica el servicio «datalayer», pinta la configuración y los destinos en la cabecera, los datos de la
 * ficha, del carrito, de la caja y del pedido, y apunta lo que hace falta para medir desde el servidor (solo lo que ocurre ahora).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Datalayer\Datalayer;

return static function (App $app): void {
    $app->bind('datalayer', static function (App $app): Datalayer {
        return new Datalayer($app);
    });

    $dl = static function () use ($app): Datalayer {
        /** @var Datalayer $datalayer */
        $datalayer = $app->service('datalayer');

        return $datalayer;
    };

    // La cabecera: la configuración, los guiones de los destinos (esperando su consentimiento) y datalayer.js, con «defer».
    $app->hooks()->slot('head', static function () use ($dl): string {
        return $dl()->head()->render();
    });

    // Con algún destino en el navegador (GTM, Google, Meta, TikTok, Microsoft) la política de contenido deja de limitar los
    // guiones: los suyos escriben y cargan otros. Sin ninguno (o midiendo solo desde el servidor) la política sigue estricta.
    $app->hooks()->filter('csp', static function ($csp) use ($dl) {
        if ($csp instanceof \Aifec\Kernel\Csp && $dl()->head()->hasLoaders()) {
            $csp->relax();
        }

        return $csp;
    });

    // Los datos de cada página, en un JSON incrustado.
    $app->hooks()->slot('product.bottom', static function (array $product) use ($dl): string {
        return $dl()->pages()->product($product);
    });
    $app->hooks()->slot('cart.after', static function (array $cart) use ($dl): string {
        return $dl()->pages()->cart($cart);
    });
    $app->hooks()->slot('checkout.after', static function (array $summary) use ($dl): string {
        return $dl()->pages()->checkout($summary);
    });
    $app->hooks()->slot('order.thanks', static function (array $order, $first = false) use ($dl): string {
        return $dl()->pages()->thanks($order);
    });

    // Un pedido nuevo: se apunta la decisión de cookies y los identificadores de medición de quien lo hace.
    $app->hooks()->on('order.placed', static function (array $order) use ($dl, $app): void {
        if ($dl()->config()->enabled()) {
            $dl()->marks()->record($order);
        }
    });

    // Lo que pasa AHORA con un pedido (pagado o devuelto) se encola para el servidor, si el interruptor está encendido.
    $app->hooks()->on('order.paid', static function (array $order) use ($dl): void {
        $dl()->server()->onPaid($order);
    });
    $app->hooks()->on('order.refunded', static function (array $order, array $refund) use ($dl): void {
        $dl()->server()->onRefunded($order, $refund);
    });

    // «login» y «sign_up»: marca de un día que la página siguiente convierte en evento.
    $app->hooks()->on('customer.login', static function ($customer = null) use ($dl): void {
        $dl()->markers()->login();
    });
    $app->hooks()->on('customer.registered', static function ($customer = null) use ($dl): void {
        $dl()->markers()->registered();
    });

    // Las cookies de los destinos encendidos, en el inventario del consentimiento.
    $app->hooks()->filter('consent.cookies', static function ($list) use ($dl): array {
        return $dl()->cookies(is_array($list) ? $list : []);
    });

    // Cambiar los destinos cambia todas las páginas guardadas.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if ($module === 'datalayer') {
            $application->invalidate(['pages']);
        }
    });
};
