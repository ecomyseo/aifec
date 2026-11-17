<?php
/**
 * AIFEC — pruebas del catálogo de formas de pago de PayPal y de la orden que se crea con cada una: países, monedas e importes;
 * las retiradas fuera; el payment_source de cada forma local, la tarjeta en la página de PayPal y lo que se rechaza sin llamar
 * a PayPal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\PayPal\PayPalException;
use Aifec\Payments\PayPal\PayPalFundingSources;

require_once __DIR__ . '/helpers.php';

/** Respuesta de PayPal al crear una orden de una forma local: el comprador va a la página de su banco. */
function ppfsCreated(string $source = 'ideal'): \Aifec\Http\HttpResponse
{
    return paypalJson(201, ['id' => '7S180378AE2002441', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
        ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/7S180378AE2002441', 'rel' => 'self', 'method' => 'GET'],
        ['href' => 'https://www.sandbox.paypal.com/payment/' . $source . '?token=7S180378AE2002441', 'rel' => 'payer-action', 'method' => 'GET'],
    ]]);
}

/**
 * Crea una orden con esa forma y ese titular y devuelve el cuerpo que se mandó a PayPal.
 *
 * @param array<string,string> $payer
 * @param array<string,mixed>  $extra
 *
 * @return array<string,mixed>
 */
function ppfsBody(string $source, array $payer, array $extra = []): array
{
    $http = paypalHttp();
    $http->queue(ppfsCreated($source));
    paypalClient($http)->createOrder(paypalOrderInput($extra + ['source' => $source, 'payer' => $payer]), 1000);

    return paypalBody(paypalApiCalls($http)[0]);
}

/** El país de prueba de cada forma local. */
function ppfsCountry(string $source): string
{
    $map = ['bancontact' => 'BE', 'blik' => 'PL', 'eps' => 'AT', 'ideal' => 'NL', 'mybank' => 'IT', 'p24' => 'PL', 'trustly' => 'SE'];

    return isset($map[$source]) ? $map[$source] : 'ES';
}

/** La moneda de prueba de cada forma local. */
function ppfsCurrency(string $source): string
{
    return in_array($source, ['blik', 'p24'], true) ? 'PLN' : ($source === 'trustly' ? 'SEK' : 'EUR');
}

test('el catálogo: cada forma de redirección tiene nombre, monedas, forma de cobro y la página de la documentación', function (): void {
    $redirect = PayPalFundingSources::redirectable();
    same(['paypal', 'card', 'bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly'], array_keys($redirect));
    foreach ($redirect as $code => $s) {
        check(is_string($s['name']) && $s['name'] !== '', 'nombre de ' . $code);
        check($s['currencies'] !== [], 'monedas de ' . $code);
        check(in_array($s['capture'], [PayPalFundingSources::CAPTURE_SERVER, PayPalFundingSources::CAPTURE_AUTO], true), 'forma de cobro de ' . $code);
        same(0, strpos((string) $s['docs'], 'https://developer.paypal.com/'), 'la fuente es la documentación de PayPal: ' . $code);
        same('', $s['sdk_reason']);
    }
    same(PayPalFundingSources::CAPTURE_SERVER, $redirect['paypal']['capture']);
    same(PayPalFundingSources::CAPTURE_SERVER, $redirect['card']['capture']);
    foreach (['bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly'] as $local) {
        same(PayPalFundingSources::CAPTURE_AUTO, $redirect[$local]['capture'], 'PayPal cobra ' . $local . ' al aprobarla');
        same(true, $redirect[$local]['approval'], $local . ' hay que activarla en PayPal');
        check(in_array('name', $redirect[$local]['requires'], true) && in_array('country', $redirect[$local]['requires'], true), $local . ' exige titular y país');
    }
    same(true, $redirect['paypal']['default']);
    same(true, $redirect['card']['default']);
    same(false, $redirect['ideal']['default'], 'las locales, apagadas de serie');
    same(true, $redirect['trustly']['late'], 'Trustly confirma tarde');
});

test('las retiradas (giropay, Sofort) no están en el catálogo y no se pueden pedir', function (): void {
    foreach (PayPalFundingSources::RETIRED as $retired) {
        same(null, PayPalFundingSources::get($retired), $retired);
        same(false, PayPalFundingSources::eligible($retired, 'DE', 'EUR', 2000));
        $http = paypalHttp();
        throws(function () use ($http, $retired): void {
            paypalClient($http)->createOrder(paypalOrderInput(['source' => $retired, 'payer' => ['name' => 'Ana', 'country' => 'DE']]), 1);
        }, \InvalidArgumentException::class);
        same([], paypalApiCalls($http), 'no se llama a PayPal');
    }
});

test('los países y monedas de cada forma local son los de la documentación', function (): void {
    $expected = [
        'bancontact' => [['BE'], ['EUR']], 'blik' => [['PL'], ['PLN']], 'eps' => [['AT'], ['EUR']], 'ideal' => [['NL'], ['EUR']],
        'mybank' => [['IT'], ['EUR']], 'p24' => [['PL'], ['PLN', 'EUR']],
        'trustly' => [['AT', 'DE', 'DK', 'EE', 'ES', 'FI', 'GB', 'LT', 'LV', 'NL', 'NO', 'SE'], ['EUR', 'DKK', 'SEK', 'GBP', 'NOK']],
    ];
    foreach ($expected as $code => $pair) {
        $s = PayPalFundingSources::get($code);
        same($pair[0], $s['countries'], 'países de ' . $code);
        same($pair[1], $s['currencies'], 'monedas de ' . $code);
    }
    same([], PayPalFundingSources::get('paypal')['countries'], 'la cartera, cualquier país');
    same(PayPalFundingSources::PAYPAL_CURRENCIES, PayPalFundingSources::get('card')['currencies']);
    same(['EUR' => 1], PayPalFundingSources::get('ideal')['min'], 'iDEAL desde 0,01 EUR');
    same(['PLN' => 100], PayPalFundingSources::get('blik')['min'], 'BLIK desde 1 PLN');
});

// Tabla de casos: [forma, país, moneda, importe, ¿se ofrece?, por qué].
foreach ([
    ['paypal', 'ES', 'EUR', 2000, true, 'España en euros: la cartera'],
    ['card', 'ES', 'EUR', 2000, true, 'España en euros: la tarjeta'],
    ['ideal', 'ES', 'EUR', 2000, false, 'iDEAL no es para España'],
    ['ideal', 'NL', 'EUR', 2000, true, 'iDEAL en los Países Bajos'],
    ['ideal', 'NL', 'USD', 2000, false, 'iDEAL no cobra en dólares'],
    ['blik', 'PL', 'PLN', 2000, true, 'BLIK en Polonia en złotys'],
    ['blik', 'PL', 'EUR', 2000, false, 'BLIK solo en PLN'],
    ['p24', 'PL', 'PLN', 2000, true, 'Przelewy24 en złotys'],
    ['p24', 'PL', 'EUR', 2000, true, 'Przelewy24 también en euros'],
    ['p24', 'PL', 'PLN', 5500001, false, 'Przelewy24 hasta 55.000 PLN'],
    ['p24', 'PL', 'PLN', 5500000, true, 'Przelewy24 justo en el máximo'],
    ['bancontact', 'BE', 'EUR', 99, false, 'Bancontact desde 1 EUR'],
    ['bancontact', 'BE', 'EUR', 100, true, 'Bancontact justo en el mínimo'],
    ['ideal', 'NL', 'EUR', 1, true, 'iDEAL desde un céntimo'],
    ['ideal', 'NL', 'EUR', 0, false, 'un importe cero no se cobra'],
    ['trustly', 'ES', 'EUR', 2000, true, 'Trustly en España'],
    ['trustly', 'FR', 'EUR', 2000, false, 'Trustly no está en Francia'],
    ['paypal', 'ES', 'XXX', 2000, false, 'una moneda que PayPal no cobra'],
    ['card', 'US', 'RUB', 2000, false, 'rublos, no'],
    // P101: las del guion ya se ofrecen (sus casos, en PayPalSdkSourcesTest); la solo catalogada, nunca.
    ['pay_upon_invoice', 'DE', 'EUR', 5000, false, 'el pago contra factura solo está catalogado'],
    ['apple_pay', 'AR', 'EUR', 5000, false, 'Apple Pay no está en Argentina'],
    ['ideal', 'nl', 'eur', 2000, true, 'país y moneda en minúsculas'],
] as $case) {
    test('se ofrece o no: ' . $case[5], function () use ($case): void {
        same($case[4], PayPalFundingSources::eligible($case[0], $case[1], $case[2], $case[3]));
    });
}

test('sin importe conocido no se mira el mínimo, pero sí el país y la moneda', function (): void {
    same(true, PayPalFundingSources::eligible('bancontact', 'BE', 'EUR', null));
    same(false, PayPalFundingSources::eligible('bancontact', 'ES', 'EUR', null));
    same(true, PayPalFundingSources::eligible('paypal', '', 'EUR', null), 'la cartera no pide país');
    same(false, PayPalFundingSources::eligible('ideal', '', 'EUR', null), 'una local sin país, no');
});

test('las del SDK están catalogadas con su motivo y no son de redirección', function (): void {
    $sdk = PayPalFundingSources::needsSdk();
    same(['paylater', 'card_fields', 'apple_pay', 'google_pay', 'venmo', 'pay_upon_invoice'], array_keys($sdk));
    foreach ($sdk as $code => $s) {
        check($s['sdk_reason'] !== '', 'motivo de ' . $code);
        same(PayPalFundingSources::FLOW_SDK, $s['flow']);
    }
    $http = paypalHttp();
    throws(function () use ($http): void {
        paypalClient($http)->createOrder(paypalOrderInput(['source' => 'pay_upon_invoice']), 1);
    }, \InvalidArgumentException::class);
    same([], paypalApiCalls($http));
});

foreach (['bancontact', 'blik', 'eps', 'ideal', 'mybank', 'p24', 'trustly'] as $local) {
    test('la orden de ' . $local . ' lleva su payment_source con titular y país, la instrucción de cobrar al aprobar y la vuelta', function () use ($local): void {
        $country = ppfsCountry($local);
        $b = ppfsBody($local, ['name' => 'Ana Ruiz Núñez & Cía', 'country' => strtolower($country), 'email' => 'ana@cliente.test'], ['currency' => ppfsCurrency($local)]);

        same([$local], array_keys($b['payment_source']), 'una sola forma');
        $node = $b['payment_source'][$local];
        same('Ana Ruiz Núñez & Cía', $node['name'], 'el titular tal cual, con tildes y «&»');
        same($country, $node['country_code']);
        same('ORDER_COMPLETE_ON_PAYMENT_APPROVAL', $b['processing_instruction'], 'PayPal la cobra al aprobarla');
        same('CAPTURE', $b['intent']);
        same('https://tienda.test/pago/paypal/vuelta', $node['experience_context']['return_url']);
        same('https://tienda.test/pago/paypal/cancelado', $node['experience_context']['cancel_url']);
        same('Mi Tienda', $node['experience_context']['brand_name']);
        same(false, isset($node['experience_context']['user_action']), 'user_action es solo de la cartera');
        same(false, isset($node['experience_context']['landing_page']));
        same(ppfsCurrency($local), $b['purchase_units'][0]['amount']['currency_code']);
        same('49.99', $b['purchase_units'][0]['amount']['value']);
        same(in_array($local, ['blik', 'p24', 'trustly'], true), isset($node['email']), 'el correo solo donde se admite');
    });
}

test('la tarjeta en la página de PayPal va por payment_source.paypal con GUEST_CHECKOUT; la cartera, sin él', function (): void {
    $card = ppfsBody('card', []);
    same(['paypal'], array_keys($card['payment_source']));
    $ctx = $card['payment_source']['paypal']['experience_context'];
    same('GUEST_CHECKOUT', $ctx['landing_page']);
    same('PAY_NOW', $ctx['user_action']);
    same('IMMEDIATE_PAYMENT_REQUIRED', $ctx['payment_method_preference']);
    same(false, isset($card['processing_instruction']), 'la tarjeta se captura desde el servidor');

    $wallet = ppfsBody('paypal', []);
    same(false, isset($wallet['payment_source']['paypal']['experience_context']['landing_page']));
    same(false, isset($wallet['processing_instruction']));
});

test('la orden de la tarjeta y la de la cartera del mismo pedido llevan identificadores de idempotencia distintos', function (): void {
    $http = paypalHttp();
    $http->queue(ppfsCreated('paypal'), ppfsCreated('paypal'));
    $client = paypalClient($http);
    $client->createOrder(paypalOrderInput(), 1);
    $client->createOrder(paypalOrderInput(['source' => 'card']), 1);
    $calls = paypalApiCalls($http);
    check($calls[0]->headers['PayPal-Request-Id'] !== $calls[1]->headers['PayPal-Request-Id'], 'otra forma, otro identificador');
});

test('una forma local sin titular, de otro país o sin el correo que exige no se manda a PayPal', function (): void {
    $cases = [
        ['ideal', ['name' => '', 'country' => 'NL']],
        ['ideal', ['name' => 'Ana', 'country' => 'ES']],
        ['ideal', ['name' => 'Ana', 'country' => '']],
        ['p24', ['name' => 'Ana', 'country' => 'PL', 'email' => '']],
        ['trustly', ['name' => 'Ana', 'country' => 'SE', 'email' => 'no-es-un-correo']],
        ['bancontact', ['country' => 'BE']],
    ];
    foreach ($cases as $case) {
        $http = paypalHttp();
        throws(function () use ($http, $case): void {
            paypalClient($http)->createOrder(paypalOrderInput(['source' => $case[0], 'payer' => $case[1]]), 1);
        }, \InvalidArgumentException::class);
        same([], paypalApiCalls($http), 'sin llamar a PayPal: ' . $case[0]);
    }
});

test('el «no» de PayPal porque la forma no está activada en la cuenta trae su motivo (issue) aparte', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(422, [
        'name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'debug_id' => 'dbg-apm',
        'details' => [['issue' => 'NOT_ENABLED_FOR_PAYMENT_SOURCE', 'field' => '/payment_source/ideal']],
    ]));
    try {
        paypalClient($http)->createOrder(paypalOrderInput(['source' => 'ideal', 'payer' => ['name' => 'Ana', 'country' => 'NL']]), 1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(422, $e->status());
        same('NOT_ENABLED_FOR_PAYMENT_SOURCE', $e->issue());
        same(false, $e->isTransient());
    }

    // Un detalle sin «issue» (solo descripción) no se toma por un código.
    $http = paypalHttp();
    $http->queue(paypalJson(400, ['name' => 'INVALID_REQUEST', 'details' => [['description' => 'something odd']]]));
    try {
        paypalClient($http)->createOrder(paypalOrderInput(), 1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same('', $e->issue());
        contains('something odd', $e->getMessage());
    }
});

test('consultar la orden de una forma local dice con qué se pagó y si la captura está completa o pendiente', function (): void {
    $http = paypalHttp();
    $order = [
        'id' => 'ORD-T', 'status' => 'COMPLETED', 'payment_source' => ['trustly' => ['name' => 'Ana', 'country_code' => 'SE']],
        'purchase_units' => [['custom_id' => 'PED-9', 'payments' => ['captures' => [['id' => 'CAP-T', 'status' => 'PENDING', 'amount' => ['currency_code' => 'SEK', 'value' => '150.00']]]]]],
    ];
    $http->queue(paypalJson(200, $order));
    $out = paypalClient($http)->getOrder('ORD-T', 1);
    same('trustly', $out['source']);
    same('COMPLETED', $out['status']);
    same('PENDING', $out['captureStatus'], 'Trustly: la orden completa y la captura pendiente');
    same(15000, $out['amount']);
    same('SEK', $out['currency']);
    same('PED-9', $out['reference']);
});
