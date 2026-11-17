<?php
/**
 * AIFEC — Redsys: registra sus dos formas de pago (tarjeta y Bizum) en el servicio «payments» de pedidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\PaymentsRedsys\GooglePayCredential;
use Aifec\Modules\PaymentsRedsys\RedsysGooglePay;
use Aifec\Modules\PaymentsRedsys\RedsysPayment;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

return static function (App $app): void {
    // Las dos se registran siempre: Bizum decide en available() si se ofrece, y una devolución de un pedido ya pagado con
    // Bizum tiene que encontrar su forma de pago aunque se haya apagado después.
    $app->service('payments')->register(new RedsysPayment($app, RedsysPayment::CODE_CARD));
    $app->service('payments')->register(new RedsysPayment($app, RedsysPayment::CODE_BIZUM));
    // Google Pay con el token de una plataforma (UCP): solo se ofrece cuando llega un token; registrada siempre por lo mismo.
    $app->service('payments')->register(new RedsysGooglePay($app));

    // La declaración del manejador «com.google.pay» para el perfil y las sesiones de UCP (null si no está listo).
    $app->hooks()->filter('ucp.payment_handlers', static function ($handlers) use ($app) {
        if (!is_array($handlers)) {
            return $handlers;
        }
        $googlePay = (new RedsysSettings($app))->googlePayHandler();
        if ($googlePay !== null) {
            $handlers['com.google.pay'][] = $googlePay;
        }

        return $handlers;
    });

    // Un pago de UCP con el manejador de Google Pay de esta tienda: el token se guarda solo para esta petición y se devuelve
    // la forma de pago que lo cobra. Cualquier otro instrumento se deja pasar (el valor que llega, sin tocar).
    $app->hooks()->filter('ucp.payment_instrument', static function ($method, $instrument) use ($app) {
        if ($method !== '' || !is_array($instrument)) {
            return $method;
        }
        $settings = new RedsysSettings($app);
        $handler = isset($instrument['handler_id']) && is_string($instrument['handler_id']) ? $instrument['handler_id'] : '';
        $credential = isset($instrument['credential']) && is_array($instrument['credential']) ? $instrument['credential'] : [];
        $token = isset($credential['token']) && is_string($credential['token']) ? $credential['token'] : '';
        if ($handler !== RedsysSettings::GOOGLEPAY_HANDLER_ID || $token === '' || strlen($token) > 20000 || !$settings->googlePayReady()) {
            return $method;
        }
        $display = isset($instrument['display']) && is_array($instrument['display']) ? $instrument['display'] : [];
        $text = static function (string $key) use ($display): string {
            return isset($display[$key]) && is_scalar($display[$key]) ? (string) $display[$key] : '';
        };
        GooglePayCredential::stage($token, $text('brand'), $text('last_digits'), $text('description'));

        return RedsysGooglePay::CODE;
    });

    // Terminado el intento de cobro: cómo acabó («paid», «declined», «failed», «authentication») y el token fuera de memoria.
    $app->hooks()->filter('ucp.payment_finish', static function ($outcome, $method) {
        if ($method !== RedsysGooglePay::CODE) {
            return $outcome;
        }
        GooglePayCredential::clear();

        return RedsysGooglePay::lastOutcome();
    });
};
