<?php
/**
 * AIFEC — pruebas de las formas de pago del guion de PayPal en el núcleo: el catálogo (Paga más tarde, tarjeta en la página,
 * Google Pay, Apple Pay, Venmo; el pago contra factura solo catalogado), a quién se ofrece cada una, la dirección del guion
 * con sus componentes justos, los orígenes de la política de contenido, la tabla de 3-D Secure y la orden que se crea.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\PayPal\PayPalFundingSources;
use Aifec\Payments\PayPal\PayPalSdk;

require_once __DIR__ . '/helpers.php';

/** Respuesta de PayPal al crear una orden para el guion (sin payer-action: aprueba el guion). */
function ppsdkCreated(): \Aifec\Http\HttpResponse
{
    return paypalJson(201, ['id' => '8RU61172JS455403V', 'status' => 'CREATED', 'links' => [
        ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/8RU61172JS455403V', 'rel' => 'self', 'method' => 'GET'],
    ]]);
}

/**
 * El cuerpo que se manda a PayPal al crear la orden de una forma del guion.
 *
 * @param array<string,mixed> $extra
 *
 * @return array<string,mixed>
 */
function ppsdkBody(string $source, array $extra = []): array
{
    $http = paypalHttp();
    $http->queue(ppsdkCreated());
    paypalClient($http)->createOrder(paypalOrderInput($extra + ['source' => $source]), 1000);

    return paypalBody(paypalApiCalls($http)[0]);
}

/**
 * Los parámetros de la dirección del guion.
 *
 * @return array<string,string>
 */
function ppsdkParams(string $url): array
{
    $out = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $out);

    return $out;
}

test('se ofrecen catorce formas: las nueve de redirección y cinco del guion; el pago contra factura solo está catalogado', function (): void {
    same(
        ['paypal', 'card', 'bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly', 'paylater', 'card_fields', 'apple_pay', 'google_pay', 'venmo'],
        array_keys(PayPalFundingSources::offerable())
    );
    foreach (['paylater', 'card_fields', 'apple_pay', 'google_pay', 'venmo'] as $sdk) {
        same(true, PayPalFundingSources::isOfferable($sdk), $sdk);
        same(PayPalFundingSources::CAPTURE_SERVER, PayPalFundingSources::get($sdk)['capture'], 'el servidor captura ' . $sdk);
        same(false, PayPalFundingSources::get($sdk)['default'], 'apagada de serie: ' . $sdk);
    }
    same(false, PayPalFundingSources::isOfferable('pay_upon_invoice'));
    same(false, PayPalFundingSources::get('pay_upon_invoice')['implemented']);
    check(PayPalFundingSources::get('pay_upon_invoice')['sdk_reason'] !== '', 'el motivo está escrito');
    same(['DE'], PayPalFundingSources::get('pay_upon_invoice')['countries']);
    same(['EUR' => 500], PayPalFundingSources::get('pay_upon_invoice')['min']);
});

test('Google Pay y Apple Pay: los 36 países y las 22 monedas de la documentación, que son los de PrestaShop Checkout', function (): void {
    $psCheckout = ['AU', 'AT', 'BE', 'BG', 'CA', 'CN', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HK', 'HU', 'IE', 'IT', 'JP', 'LV', 'LI', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SG', 'SK', 'SI', 'ES', 'SE', 'GB', 'US'];
    $currencies = ['AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD'];
    foreach (['google_pay', 'apple_pay'] as $wallet) {
        $s = PayPalFundingSources::get($wallet);
        same($psCheckout, $s['countries'], 'países de ' . $wallet);
        same($currencies, $s['currencies'], 'monedas de ' . $wallet);
    }
    same($currencies, PayPalFundingSources::get('card_fields')['currencies']);
    same([], PayPalFundingSources::get('card_fields')['countries'], 'la tarjeta, compradores de cualquier país');
    same(37, count(PayPalFundingSources::get('card_fields')['merchant_countries']), 'los 37 países del comercio');
    same(['US'], PayPalFundingSources::get('venmo')['countries']);
    same(['USD'], PayPalFundingSources::get('venmo')['currencies']);
    same(true, PayPalFundingSources::get('card_fields')['three_ds']);
    same(true, PayPalFundingSources::get('google_pay')['three_ds']);
    same(false, PayPalFundingSources::get('apple_pay')['three_ds']);
});

test('Paga más tarde: cada país con su moneda y sus importes, los de la documentación', function (): void {
    same([
        'US' => ['USD', 1000, 1000000], 'AU' => ['AUD', 100, 199999], 'CA' => ['CAD', 1000, 200000], 'FR' => ['EUR', 2000, 300000],
        'DE' => ['EUR', 100, 1000000], 'IT' => ['EUR', 2000, 500000], 'ES' => ['EUR', 2000, 500000], 'GB' => ['GBP', 100, 300000],
    ], PayPalFundingSources::get('paylater')['limits']);
});

// Tabla de casos: [forma, país, moneda, importe, ¿se ofrece?, por qué].
foreach ([
    ['google_pay', 'ES', 'EUR', 2000, true, 'Google Pay en España en euros'],
    ['google_pay', 'AR', 'EUR', 2000, false, 'Google Pay no está en Argentina'],
    ['google_pay', 'ES', 'ARS', 2000, false, 'Google Pay no cobra en pesos argentinos'],
    ['google_pay', 'JP', 'JPY', 500, true, 'Google Pay en Japón en yenes'],
    ['apple_pay', 'ES', 'EUR', 2000, true, 'Apple Pay en España'],
    ['apple_pay', 'MX', 'MXN', 2000, false, 'Apple Pay no está en México'],
    ['apple_pay', 'US', 'CNY', 2000, false, 'Apple Pay no cobra en yuanes'],
    ['paylater', 'ES', 'EUR', 2000, true, 'Paga más tarde en España desde 20 EUR'],
    ['paylater', 'ES', 'EUR', 1999, false, 'Paga más tarde en España no baja de 20 EUR'],
    ['paylater', 'ES', 'EUR', 500000, true, 'Paga más tarde en España hasta 5.000 EUR'],
    ['paylater', 'ES', 'EUR', 500001, false, 'Paga más tarde en España no pasa de 5.000 EUR'],
    ['paylater', 'ES', 'GBP', 5000, false, 'Paga más tarde en España solo en euros'],
    ['paylater', 'DE', 'EUR', 100, true, 'en Alemania desde 1 EUR'],
    ['paylater', 'FR', 'EUR', 300001, false, 'en Francia hasta 3.000 EUR'],
    ['paylater', 'GB', 'GBP', 5000, true, 'en el Reino Unido en libras'],
    ['paylater', 'PT', 'EUR', 5000, false, 'Paga más tarde no está en Portugal'],
    ['card_fields', 'AR', 'EUR', 2000, true, 'la tarjeta en la página: comprador de cualquier país'],
    ['card_fields', 'ES', 'XXX', 2000, false, 'la tarjeta no cobra en una moneda que no existe'],
    ['card_fields', 'ES', 'EUR', 0, false, 'un importe cero no se cobra'],
    ['venmo', 'US', 'USD', 2000, true, 'Venmo en Estados Unidos en dólares'],
    ['venmo', 'ES', 'USD', 2000, false, 'Venmo solo para Estados Unidos'],
    ['venmo', 'US', 'EUR', 2000, false, 'Venmo solo en dólares'],
    ['pay_upon_invoice', 'DE', 'EUR', 5000, false, 'el pago contra factura no se ofrece'],
] as $case) {
    test('el guion se ofrece o no: ' . $case[5], function () use ($case): void {
        same($case[4], PayPalFundingSources::eligible($case[0], $case[1], $case[2], $case[3]));
    });
}

foreach ([
    ['paylater', false, 'buttons', 'paylater'],
    ['paylater', true, 'buttons,messages', 'paylater'],
    ['card_fields', true, 'card-fields', null],
    ['google_pay', false, 'googlepay', null],
    ['apple_pay', false, 'applepay', null],
    ['venmo', true, 'buttons', 'venmo'],
] as $case) {
    test('la dirección del guion para ' . $case[0] . ($case[1] ? ' con el mensaje' : '') . ' lleva sus componentes justos', function () use ($case): void {
        $url = PayPalSdk::url($case[0], 'CLIENT-ID-SANDBOX', 'eur', true, 'es', $case[1]);
        same(0, strpos($url, 'https://www.paypal.com/sdk/js?'));
        $p = ppsdkParams($url);
        same('CLIENT-ID-SANDBOX', $p['client-id']);
        same($case[2], $p['components']);
        same('EUR', $p['currency']);
        same('capture', $p['intent']);
        same('true', $p['commit']);
        same($case[3], isset($p['enable-funding']) ? $p['enable-funding'] : null);
        same('ES', $p['buyer-country'], 'en el sandbox, el país del comprador');
    });
}

test('en producción no va buyer-country; sin cliente, con moneda mala o con una forma que no es del guion, no hay dirección', function (): void {
    same(false, isset(ppsdkParams(PayPalSdk::url('google_pay', 'CLIENT-LIVE', 'EUR', false, 'ES'))['buyer-country']));
    same('', PayPalSdk::url('google_pay', '', 'EUR', true, 'ES'));
    same('', PayPalSdk::url('google_pay', 'C', 'EURO', true, 'ES'));
    same('', PayPalSdk::url('ideal', 'C', 'EUR', true, 'NL'), 'iDEAL va por redirección');
    same('', PayPalSdk::url('paypal', 'C', 'EUR', true, 'ES'));
    same('', PayPalSdk::url('pay_upon_invoice', 'C', 'EUR', true, 'DE'), 'la solo catalogada no carga nada');
    same([], PayPalSdk::components('trustly'));
    same(['buttons'], PayPalSdk::components('paylater'));
    same(['card-fields'], PayPalSdk::components('card_fields', true), 'el mensaje solo es de Paga más tarde');
});

test('los orígenes de la política de contenido: PayPal para todas, Google para Google Pay, Venmo para Venmo; nada para las demás', function (): void {
    same(['https://*.paypal.com', 'https://*.paypalobjects.com'], PayPalSdk::origins('card_fields'));
    same(['https://*.paypal.com', 'https://*.paypalobjects.com'], PayPalSdk::origins('apple_pay'));
    same(['https://*.paypal.com', 'https://*.paypalobjects.com', 'https://pay.google.com'], PayPalSdk::origins('google_pay'));
    same(['https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com'], PayPalSdk::origins('venmo'));
    same([], PayPalSdk::origins('ideal'));
    same([], PayPalSdk::origins('pay_upon_invoice'));
    same(['https://pay.google.com/gp/p/js/pay.js'], PayPalSdk::scripts('google_pay'));
    same([], PayPalSdk::scripts('apple_pay'), 'Apple Pay usa la sesión del propio Safari: ningún guion de Apple');
    same([], PayPalSdk::scripts('paylater'));
});

// La tabla de 3-D Secure de la documentación: [enrollment, authentication, liability_shift, ¿se captura?].
foreach ([
    ['Y', 'Y', 'POSSIBLE', true], ['Y', 'N', 'NO', false], ['Y', 'R', 'NO', false], ['Y', 'A', 'POSSIBLE', true],
    ['Y', 'U', 'UNKNOWN', false], ['Y', 'U', 'NO', false], ['Y', 'C', 'UNKNOWN', false], ['Y', '', 'NO', false],
    ['N', '', 'NO', true], ['U', '', 'NO', true], ['U', '', 'UNKNOWN', false], ['B', '', 'NO', true], ['', '', 'UNKNOWN', false],
    ['', '', 'YES', true], ['', '', '', true],
] as $row) {
    test('3-D Secure: enrollment ' . ($row[0] !== '' ? $row[0] : '-') . ', authentication ' . ($row[1] !== '' ? $row[1] : '-') . ', liability ' . ($row[2] !== '' ? $row[2] : 'sin resultado') . ($row[3] ? ' se captura' : ' no se captura'), function () use ($row): void {
        same($row[3], PayPalSdk::liabilityAccepted($row[2], $row[0], $row[1]));
    });
}

test('la orden de la tarjeta en la página pide 3-D Secure cuando haga falta y lleva la vuelta por si el banco saca de la página', function (): void {
    $b = ppsdkBody('card_fields');
    same(['card'], array_keys($b['payment_source']));
    same('SCA_WHEN_REQUIRED', $b['payment_source']['card']['attributes']['verification']['method']);
    same('https://tienda.test/pago/paypal/vuelta', $b['payment_source']['card']['experience_context']['return_url']);
    same('https://tienda.test/pago/paypal/cancelado', $b['payment_source']['card']['experience_context']['cancel_url']);
    same(false, isset($b['payment_source']['card']['number']), 'el número lo pone el guion de PayPal, nunca la tienda');
    same(false, isset($b['processing_instruction']), 'la captura la hace el servidor');
    same('49.99', $b['purchase_units'][0]['amount']['value']);
    same('EUR', $b['purchase_units'][0]['amount']['currency_code']);
});

test('Google Pay y Apple Pay se crean sin payment_source: lo añade el guion al confirmar', function (): void {
    foreach (['google_pay', 'apple_pay'] as $wallet) {
        $b = ppsdkBody($wallet);
        same(false, isset($b['payment_source']), $wallet);
        same(false, isset($b['processing_instruction']));
        same('CAPTURE', $b['intent']);
        same('PED-1001', $b['purchase_units'][0]['custom_id']);
    }
});

test('Paga más tarde va por la cartera con PAY_NOW; Venmo, por su nodo con la marca', function (): void {
    $later = ppsdkBody('paylater');
    same(['paypal'], array_keys($later['payment_source']));
    same('PAY_NOW', $later['payment_source']['paypal']['experience_context']['user_action']);
    same(false, isset($later['payment_source']['paypal']['experience_context']['landing_page']));

    $venmo = ppsdkBody('venmo', ['currency' => 'USD']);
    same(['venmo'], array_keys($venmo['payment_source']));
    same('Mi Tienda', $venmo['payment_source']['venmo']['experience_context']['brand_name']);
    same('GET_FROM_FILE', $venmo['payment_source']['venmo']['experience_context']['shipping_preference']);
    same('USD', $venmo['purchase_units'][0]['amount']['currency_code']);
});

test('cada reintento de la página de pago crea su orden (otro identificador de idempotencia); el mismo, la misma', function (): void {
    $http = paypalHttp();
    $http->queue(ppsdkCreated(), ppsdkCreated(), ppsdkCreated());
    $client = paypalClient($http);
    $client->createOrder(paypalOrderInput(['source' => 'google_pay', 'attempt' => 1]), 1);
    $client->createOrder(paypalOrderInput(['source' => 'google_pay', 'attempt' => 2]), 1);
    $client->createOrder(paypalOrderInput(['source' => 'google_pay', 'attempt' => 1]), 1);
    $ids = array_map(static function ($r): string {
        return $r->headers['PayPal-Request-Id'];
    }, paypalApiCalls($http));
    check($ids[0] !== $ids[1], 'otro intento, otra orden');
    same($ids[0], $ids[2], 'el mismo intento repetido, la misma orden');
});

test('el pago contra factura no se puede crear: solo está catalogado', function (): void {
    $http = paypalHttp();
    throws(function () use ($http): void {
        paypalClient($http)->createOrder(paypalOrderInput(['source' => 'pay_upon_invoice']), 1);
    }, \InvalidArgumentException::class);
    same([], paypalApiCalls($http));
});

test('la orden consultada trae el resultado de 3-D Secure de la tarjeta o de Google Pay, y nada si no lo hubo', function (): void {
    $auth = ['liability_shift' => 'NO', 'three_d_secure' => ['enrollment_status' => 'Y', 'authentication_status' => 'N']];
    $cases = [
        [['card' => ['last_digits' => '1111', 'authentication_result' => $auth]], ['shift' => 'NO', 'enrollment' => 'Y', 'authentication' => 'N']],
        [['google_pay' => ['card' => ['authentication_result' => ['liability_shift' => 'POSSIBLE']]]], ['shift' => 'POSSIBLE', 'enrollment' => '', 'authentication' => '']],
        [['paypal' => ['email_address' => 'x@example.com']], []],
    ];
    foreach ($cases as $case) {
        $http = paypalHttp();
        $http->queue(paypalJson(200, ['id' => 'ORD-3DS', 'status' => 'APPROVED', 'payment_source' => $case[0], 'purchase_units' => [['amount' => ['currency_code' => 'EUR', 'value' => '1.00']]]]));
        same($case[1], paypalClient($http)->getOrder('ORD-3DS', 1)['liability']);
    }
});
