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
 * Catálogo de las formas de pago que se cobran con UNA cuenta de PayPal («funding sources»).
 *
 * Dos familias:
 * - «redirect»: la orden se crea en el servidor con su payment_source y el comprador va al enlace «payer-action» que
 *   devuelve PayPal. Sin JavaScript de terceros en la tienda. La cartera y la tarjeta en la página de PayPal (pago como
 *   invitado: payment_source.paypal con experience_context.landing_page GUEST_CHECKOUT) se capturan desde el servidor
 *   cuando el comprador vuelve; las formas locales llevan processing_instruction ORDER_COMPLETE_ON_PAYMENT_APPROVAL y
 *   PayPal las cobra solo al aprobarlas en el banco (no hay captura desde el servidor).
 * - «sdk»: necesitan el guion de PayPal en la página donde se paga (Paga más tarde, la tarjeta con los campos de PayPal
 *   dentro de la página, Google Pay, Apple Pay, Venmo). El navegador solo APRUEBA: la orden la crea y la captura el servidor.
 *   Las que tienen «implemented» a falso (pago contra factura) solo están catalogadas, con el motivo en sdk_reason.
 *
 * Fuentes leídas el 13/11/2026:
 * - https://developer.paypal.com/docs/checkout/apm/ (tabla de países, monedas e importes mínimos; giropay y Sofort retiradas);
 * - las páginas «orders-api» de Bancontact, BLIK, eps, iDEAL, MyBank, Przelewy24 y Trustly (payment_source, la instrucción
 *   ORDER_COMPLETE_ON_PAYMENT_APPROVAL, el enlace payer-action, la captura automática y los avisos);
 * - el esquema de Orders v2 2.36 (https://developer.paypal.com/api/orders/v2/schema.yaml): los campos obligatorios de cada
 *   *_request (name y country_code; además email en p24 y trustly) y landing_page GUEST_CHECKOUT;
 * - https://developer.paypal.com/docs/checkout/apm/google-pay/ y …/apple-pay/ (36 países y 22 monedas, iguales en
 *   PrestaShop Checkout 9.5.5.3), https://developer.paypal.com/docs/checkout/pay-later/ (países, monedas e importes de
 *   Paga más tarde) y https://developer.paypal.com/docs/checkout/advanced/ (37 países del comercio y 22 monedas de la
 *   tarjeta con campos en la página).
 * Lo que la documentación no da (el mínimo de Przelewy24 en euros, su máximo en złotys, el máximo del pago contra factura)
 * sale de PrestaShop Checkout. Donde los dos no coinciden (los importes de Paga más tarde) manda la documentación.
 * Multibanco, Satispay, Alipay, Boleto, GrabPay y OXXO están en la tabla de la documentación pero no en el payment_source de
 * la petición de Orders v2 2.36: no entran hasta que se puedan crear por esta vía.
 *
 * Importes en la unidad mínima de la moneda (céntimos). Una lista de países vacía = cualquier país.
 */
final class PayPalFundingSources
{
    public const FLOW_REDIRECT = 'redirect';
    public const FLOW_SDK = 'sdk';

    /** PayPal captura desde el servidor cuando el comprador vuelve o aprueba (cartera, tarjetas y las del guion). */
    public const CAPTURE_SERVER = 'server';

    /** PayPal cobra solo al aprobar el pago (formas locales con ORDER_COMPLETE_ON_PAYMENT_APPROVAL). */
    public const CAPTURE_AUTO = 'auto';

    /** Retiradas por PayPal: no entran en el catálogo (giropay, 30/06/2024; Sofort, 18/04/2024). */
    public const RETIRED = ['giropay', 'sofort'];

    /** Monedas en que cobra la cartera de PayPal. */
    public const PAYPAL_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP',
        'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /** Las 22 monedas de Google Pay, Apple Pay y la tarjeta con campos en la página (documentación y PrestaShop Checkout). */
    public const WALLET_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK',
        'SGD', 'THB', 'TWD', 'USD',
    ];

    /** Los 36 países de Google Pay y Apple Pay (documentación de PayPal y PrestaShop Checkout coinciden). */
    public const WALLET_COUNTRIES = [
        'AU', 'AT', 'BE', 'BG', 'CA', 'CN', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HK', 'HU', 'IE', 'IT', 'JP', 'LV', 'LI',
        'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SG', 'SK', 'SI', 'ES', 'SE', 'GB', 'US',
    ];

    /** El guion de Google que necesita Google Pay. */
    public const GOOGLE_PAY_SCRIPT = 'https://pay.google.com/gp/p/js/pay.js';

    private const APM_DOCS = 'https://developer.paypal.com/docs/checkout/apm/';

    /**
     * Todas las formas de pago, en el orden de serie.
     *
     * Cada ficha: code, name (nombre comercial, en inglés), flow, implemented (se ofrece), countries, currencies, min y max
     * (moneda => céntimos), limits (Paga más tarde: país => [moneda, mínimo, máximo]), requires (datos del comprador que exige:
     * name, country, email), accepts_email, capture (server|auto), late (el dinero puede llegar días después, por aviso),
     * approval (hay que activarla en la cuenta de PayPal), default (encendida de serie), component (el componente del guion de
     * PayPal), funding (lo que se pasa en enable-funding), scripts (otros guiones que hacen falta), origins (orígenes de más
     * para la política de contenido), three_ds (se mira el resultado de 3-D Secure antes de capturar), merchant_countries
     * (países del comercio que PayPal admite), sdk_reason (por qué necesita el guion de PayPal) y docs (la página leída).
     *
     * @return array<string,array<string,mixed>> código => ficha
     */
    public static function all(): array
    {
        $sources = [];
        foreach (self::table() as $code => $row) {
            $sources[$code] = $row + [
                'code' => $code, 'implemented' => true, 'countries' => [], 'currencies' => [], 'min' => [], 'max' => [], 'limits' => [],
                'requires' => [], 'accepts_email' => false, 'capture' => self::CAPTURE_AUTO, 'late' => false, 'approval' => false,
                'default' => false, 'component' => '', 'funding' => '', 'scripts' => [], 'origins' => [], 'three_ds' => false,
                'merchant_countries' => [], 'sdk_reason' => '', 'docs' => self::APM_DOCS,
            ];
        }

        return $sources;
    }

    /**
     * Una forma de pago, o null si no está en el catálogo (las retiradas tampoco).
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $code): ?array
    {
        $all = self::all();
        $code = strtolower(trim($code));

        return isset($all[$code]) ? $all[$code] : null;
    }

    /**
     * Las que se cobran por redirección, en el orden de serie.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function redirectable(): array
    {
        return array_filter(self::all(), static function (array $s): bool {
            return $s['flow'] === self::FLOW_REDIRECT;
        });
    }

    /**
     * Las que necesitan el JavaScript de PayPal en la página (hechas o solo catalogadas).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function needsSdk(): array
    {
        return array_filter(self::all(), static function (array $s): bool {
            return $s['flow'] === self::FLOW_SDK;
        });
    }

    /**
     * Las que este sistema ofrece: las de redirección y las del guion que están hechas, en el orden de serie.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function offerable(): array
    {
        return array_filter(self::all(), static function (array $s): bool {
            return $s['flow'] === self::FLOW_REDIRECT || ($s['flow'] === self::FLOW_SDK && !empty($s['implemented']));
        });
    }

    /** ¿Se ofrece (de redirección, o del guion y hecha)? */
    public static function isOfferable(string $code): bool
    {
        $offerable = self::offerable();

        return isset($offerable[strtolower(trim($code))]);
    }

    /**
     * ¿Puede pagar con ella un comprador de ese país, en esa moneda y por ese importe? Las solo catalogadas, nunca.
     *
     * Un importe null = aún no se sabe (no se mira el mínimo ni el máximo); un importe menor que 1 no se puede cobrar.
     */
    public static function eligible(string $code, string $country, string $currency, ?int $amount = null): bool
    {
        $source = self::get($code);
        if ($source === null || !self::isOfferable($code)) {
            return false;
        }
        $country = strtoupper(trim($country));
        $currency = strtoupper(trim($currency));
        if ($source['countries'] !== [] && !in_array($country, $source['countries'], true)) {
            return false;
        }
        if (!in_array($currency, $source['currencies'], true)) {
            return false;
        }
        // Paga más tarde: cada país tiene su moneda y sus importes.
        if ($source['limits'] !== [] && (!isset($source['limits'][$country]) || $source['limits'][$country][0] !== $currency)) {
            return false;
        }
        if ($amount === null) {
            return true;
        }
        if ($amount < 1) {
            return false;
        }
        if ($source['limits'] !== []) {
            return $amount >= (int) $source['limits'][$country][1] && $amount <= (int) $source['limits'][$country][2];
        }
        if (isset($source['min'][$currency]) && $amount < (int) $source['min'][$currency]) {
            return false;
        }
        if (isset($source['max'][$currency]) && $amount > (int) $source['max'][$currency]) {
            return false;
        }

        return true;
    }

    /**
     * La ficha de cada forma de pago, sin los valores de serie.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function table(): array
    {
        return [
            'paypal' => [
                'name' => 'PayPal', 'flow' => self::FLOW_REDIRECT, 'currencies' => self::PAYPAL_CURRENCIES, 'capture' => self::CAPTURE_SERVER,
                'default' => true, 'docs' => 'https://developer.paypal.com/docs/api/orders/v2/',
            ],
            'card' => [
                // La tarjeta en la página de PayPal, sin cuenta: payment_source.paypal con landing_page GUEST_CHECKOUT. La cuenta
                // tiene que tener encendido el pago sin cuenta de PayPal («PayPal account optional»).
                'name' => 'Card', 'flow' => self::FLOW_REDIRECT, 'currencies' => self::PAYPAL_CURRENCIES, 'capture' => self::CAPTURE_SERVER,
                'default' => true, 'docs' => 'https://developer.paypal.com/api/orders/v2/schema.yaml',
            ],
            'bancontact' => [
                'name' => 'Bancontact', 'flow' => self::FLOW_REDIRECT, 'countries' => ['BE'], 'currencies' => ['EUR'], 'min' => ['EUR' => 100],
                'requires' => ['name', 'country'], 'approval' => true, 'docs' => self::APM_DOCS . 'bancontact/orders-api/',
            ],
            'blik' => [
                'name' => 'BLIK', 'flow' => self::FLOW_REDIRECT, 'countries' => ['PL'], 'currencies' => ['PLN'], 'min' => ['PLN' => 100],
                'requires' => ['name', 'country'], 'accepts_email' => true, 'approval' => true, 'docs' => self::APM_DOCS . 'blik/orders-api/',
            ],
            'eps' => [
                'name' => 'eps', 'flow' => self::FLOW_REDIRECT, 'countries' => ['AT'], 'currencies' => ['EUR'], 'min' => ['EUR' => 100],
                'requires' => ['name', 'country'], 'approval' => true, 'docs' => self::APM_DOCS . 'eps/orders-api/',
            ],
            'ideal' => [
                'name' => 'iDEAL', 'flow' => self::FLOW_REDIRECT, 'countries' => ['NL'], 'currencies' => ['EUR'], 'min' => ['EUR' => 1],
                'requires' => ['name', 'country'], 'approval' => true, 'docs' => self::APM_DOCS . 'ideal/orders-api/',
            ],
            'mybank' => [
                // La documentación no da mínimo (PrestaShop Checkout pone 1 EUR): manda la documentación.
                'name' => 'MyBank', 'flow' => self::FLOW_REDIRECT, 'countries' => ['IT'], 'currencies' => ['EUR'],
                'requires' => ['name', 'country'], 'approval' => true, 'docs' => self::APM_DOCS . 'mybank/orders-api/',
            ],
            'p24' => [
                // El máximo de 55.000 PLN y el mínimo en euros salen de PrestaShop Checkout: la documentación no los da.
                'name' => 'Przelewy24', 'flow' => self::FLOW_REDIRECT, 'countries' => ['PL'], 'currencies' => ['PLN', 'EUR'],
                'min' => ['PLN' => 100, 'EUR' => 100], 'max' => ['PLN' => 5500000], 'requires' => ['name', 'country', 'email'], 'accepts_email' => true,
                'approval' => true, 'docs' => self::APM_DOCS . 'przelewy24/orders-api/',
            ],
            'trustly' => [
                // La captura queda PENDING y el dinero llega por aviso hasta 7 días después. PrestaShop Checkout no la ofrece.
                'name' => 'Trustly', 'flow' => self::FLOW_REDIRECT,
                'countries' => ['AT', 'DE', 'DK', 'EE', 'ES', 'FI', 'GB', 'LT', 'LV', 'NL', 'NO', 'SE'],
                'currencies' => ['EUR', 'DKK', 'SEK', 'GBP', 'NOK'], 'min' => ['EUR' => 1], 'requires' => ['name', 'country', 'email'],
                'accepts_email' => true, 'late' => true, 'approval' => true, 'docs' => self::APM_DOCS . 'trustly/orders-api/',
            ],
            'paylater' => [
                // Importes por país de la documentación (el abanico entero de cada país). PrestaShop Checkout pone otros
                // (EUR 30–2.000, USD 30–10.000, GBP 20–3.000, CAD 30–1.500, AUD 1–1.999,99): manda la documentación.
                'name' => 'Pay Later', 'flow' => self::FLOW_SDK, 'capture' => self::CAPTURE_SERVER, 'component' => 'buttons', 'funding' => 'paylater',
                'countries' => ['US', 'AU', 'CA', 'FR', 'DE', 'IT', 'ES', 'GB'], 'currencies' => ['USD', 'AUD', 'CAD', 'EUR', 'GBP'],
                'limits' => [
                    'US' => ['USD', 1000, 1000000], 'AU' => ['AUD', 100, 199999], 'CA' => ['CAD', 1000, 200000], 'FR' => ['EUR', 2000, 300000],
                    'DE' => ['EUR', 100, 1000000], 'IT' => ['EUR', 2000, 500000], 'ES' => ['EUR', 2000, 500000], 'GB' => ['GBP', 100, 300000],
                ],
                'sdk_reason' => 'PayPal only shows the Pay Later offer as a button and messages of its JavaScript SDK.',
                'docs' => 'https://developer.paypal.com/docs/checkout/pay-later/',
            ],
            'card_fields' => [
                // Los datos de la tarjeta se escriben en campos de PayPal dentro de la página: nunca pasan por la tienda. 3-D Secure
                // cuando PayPal lo pida (SCA_WHEN_REQUIRED), y el resultado se mira antes de capturar.
                'name' => 'Card fields on the shop', 'flow' => self::FLOW_SDK, 'capture' => self::CAPTURE_SERVER, 'component' => 'card-fields',
                'currencies' => self::WALLET_CURRENCIES, 'three_ds' => true, 'approval' => true,
                'merchant_countries' => array_merge(self::WALLET_COUNTRIES, ['MX']),
                'sdk_reason' => 'The card number is typed in fields hosted by PayPal inside the shop page (Card Fields), loaded by its SDK.',
                'docs' => 'https://developer.paypal.com/docs/checkout/advanced/',
            ],
            'apple_pay' => [
                // Solo Safari en dispositivos de Apple (ApplePaySession); el dominio tiene que estar acreditado en PayPal.
                'name' => 'Apple Pay', 'flow' => self::FLOW_SDK, 'capture' => self::CAPTURE_SERVER, 'component' => 'applepay',
                'countries' => self::WALLET_COUNTRIES, 'currencies' => self::WALLET_CURRENCIES, 'approval' => true,
                'sdk_reason' => 'The Apple Pay sheet is opened by the browser with the PayPal SDK and a verified domain file.',
                'docs' => self::APM_DOCS . 'apple-pay/',
            ],
            'google_pay' => [
                'name' => 'Google Pay', 'flow' => self::FLOW_SDK, 'capture' => self::CAPTURE_SERVER, 'component' => 'googlepay',
                'countries' => self::WALLET_COUNTRIES, 'currencies' => self::WALLET_CURRENCIES, 'three_ds' => true, 'approval' => true,
                'scripts' => [self::GOOGLE_PAY_SCRIPT], 'origins' => ['https://pay.google.com'],
                'sdk_reason' => 'The Google Pay sheet is opened by the Google and PayPal scripts in the page.',
                'docs' => self::APM_DOCS . 'google-pay/',
            ],
            'venmo' => [
                'name' => 'Venmo', 'flow' => self::FLOW_SDK, 'capture' => self::CAPTURE_SERVER, 'component' => 'buttons', 'funding' => 'venmo',
                'countries' => ['US'], 'currencies' => ['USD'], 'origins' => ['https://*.venmo.com'],
                'sdk_reason' => 'Venmo approval runs in the Venmo app launched by the PayPal SDK button.',
                'docs' => 'https://developer.paypal.com/docs/checkout/pay-with-venmo/',
            ],
            'pay_upon_invoice' => [
                // Catalogado, no hecho: la caja no pide la fecha de nacimiento ni enseña el texto legal de Ratepay antes de hacer
                // el pedido, y los datos bancarios que da PayPal no llegan al correo del pedido (ver el informe de P101).
                'name' => 'Pay upon Invoice', 'flow' => self::FLOW_SDK, 'implemented' => false, 'countries' => ['DE'], 'currencies' => ['EUR'],
                'min' => ['EUR' => 500], 'max' => ['EUR' => 250000], 'merchant_countries' => ['DE'], 'approval' => true,
                'sdk_reason' => 'It needs the PayPal FraudNet script in the page, plus the buyer birth date and phone.',
            ],
        ];
    }
}
