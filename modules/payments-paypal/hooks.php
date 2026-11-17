<?php
/**
 * AIFEC — PayPal: registra sus formas de pago en el servicio «payments» de pedidos, una por cada forma del catálogo que se
 * ofrece (la cartera «paypal», «paypal_card», «paypal_ideal»… y las del guion: «paypal_google_pay», «paypal_apple_pay»…), en
 * el orden que ha puesto el comercio; y deja pasar los orígenes de PayPal en la política de contenido SOLO en la página de
 * pago que los pide.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Csp;
use Aifec\Kernel\Request;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSdkController;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

return static function (App $app): void {
    // Se registran todas, también las apagadas: available() decide si se ofrecen (la forma encendida, la cuenta configurada,
    // el país, la moneda y el importe), y la devolución de un pedido ya pagado con una de ellas tiene que encontrar su forma de
    // pago aunque después se apague o se borren las claves.
    $payments = $app->service('payments');
    foreach ((new PayPalSettings($app))->ordered() as $source) {
        $payments->register(new PayPalPayment($app, $source));
    }

    // La página de pago del guion deja en la petición lo que necesita; las demás páginas no llevan nada de PayPal.
    $app->hooks()->filter('csp', static function ($csp, $request = null) {
        if ($csp instanceof Csp && $request instanceof Request) {
            PayPalSdkController::policy($csp, $request);
        }

        return $csp;
    });
};
