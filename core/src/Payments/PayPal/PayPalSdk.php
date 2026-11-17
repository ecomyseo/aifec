<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\PayPal;

/**
 * El guion de PayPal en la página de pago: su dirección con los componentes justos, los orígenes que la política de contenido
 * tiene que dejar pasar y la decisión sobre 3-D Secure antes de capturar. Sin estado y sin red.
 *
 * Parámetros del guion (https://developer.paypal.com/sdk/js/configuration/): client-id del entorno, components, currency,
 * intent=capture, commit=true, enable-funding para Paga más tarde y Venmo y, solo en el sandbox, buyer-country para que
 * PayPal decida como si el comprador fuera de ese país. PrestaShop Checkout manda lo mismo (más marks, funding-eligibility,
 * vault y su código de socio, que aquí no hacen falta).
 *
 * Orígenes (https://developer.paypal.com/sdk/js/csp/): *.paypal.com y *.paypalobjects.com en script-src, connect-src,
 * frame-src e img-src; *.venmo.com con Venmo; pay.google.com con Google Pay. Los guiones que PayPal escribe en la página se
 * permiten con un nonce (data-csp-nonce), nunca con 'unsafe-inline'.
 */
final class PayPalSdk
{
    public const SCRIPT = 'https://www.paypal.com/sdk/js';

    /** Los orígenes de PayPal que necesita cualquier forma del guion. */
    public const ORIGINS = ['https://*.paypal.com', 'https://*.paypalobjects.com'];

    /**
     * Los componentes del guion para una forma de pago: buttons (Paga más tarde, Venmo), card-fields, googlepay, applepay, y
     * messages si se pinta el mensaje de financiación. Vacío si no es una forma del guion hecha.
     *
     * @return string[]
     */
    public static function components(string $source, bool $message = false): array
    {
        $known = PayPalFundingSources::get($source);
        if ($known === null || $known['flow'] !== PayPalFundingSources::FLOW_SDK || empty($known['implemented']) || $known['component'] === '') {
            return [];
        }
        $out = [(string) $known['component']];
        if ($message && $source === 'paylater') {
            $out[] = 'messages';
        }

        return $out;
    }

    /**
     * La dirección del guion de PayPal para pagar con esa forma, o '' si no es una forma del guion hecha.
     */
    public static function url(string $source, string $clientId, string $currency, bool $sandbox, string $buyerCountry = '', bool $message = false): string
    {
        $components = self::components($source, $message);
        $currency = strtoupper(trim($currency));
        if ($components === [] || trim($clientId) === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return '';
        }
        $params = ['client-id' => trim($clientId), 'components' => implode(',', $components), 'currency' => $currency, 'intent' => 'capture', 'commit' => 'true'];
        $known = PayPalFundingSources::get($source);
        if ($known !== null && $known['funding'] !== '') {
            $params['enable-funding'] = (string) $known['funding'];
        }
        $buyerCountry = strtoupper(trim($buyerCountry));
        if ($sandbox && preg_match('/^[A-Z]{2}$/', $buyerCountry) === 1) {
            // Solo en el sandbox: en producción PayPal decide por el propio comprador.
            $params['buyer-country'] = $buyerCountry;
        }

        return self::SCRIPT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Los otros guiones que hacen falta (el de Google para Google Pay).
     *
     * @return string[]
     */
    public static function scripts(string $source): array
    {
        $known = PayPalFundingSources::get($source);

        return $known !== null && self::components($source) !== [] ? array_values($known['scripts']) : [];
    }

    /**
     * Los orígenes que la política de contenido de la página de pago tiene que dejar pasar con esa forma.
     *
     * @return string[]
     */
    public static function origins(string $source): array
    {
        $known = PayPalFundingSources::get($source);
        if ($known === null || self::components($source) === []) {
            return [];
        }

        return array_values(array_unique(array_merge(self::ORIGINS, $known['origins'])));
    }

    /**
     * ¿Se puede capturar tras 3-D Secure? La tabla de https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/response-parameters/:
     * POSSIBLE (y YES) sí; NO solo si la tarjeta no participa (enrollment N, U o B); UNKNOWN nunca. Sin resultado de
     * autenticación (PayPal no pidió 3-D Secure) también se sigue.
     */
    public static function liabilityAccepted(string $shift, string $enrollment = '', string $authentication = ''): bool
    {
        $shift = strtoupper(trim($shift));
        $enrollment = strtoupper(trim($enrollment));
        if ($shift === '') {
            return true;
        }
        if ($shift === 'POSSIBLE' || $shift === 'YES') {
            return true;
        }
        if ($shift === 'NO') {
            return in_array($enrollment, ['N', 'U', 'B'], true) && ($authentication === '' || strtoupper(trim($authentication)) === 'NOT_APPLICABLE');
        }

        return false;
    }
}
