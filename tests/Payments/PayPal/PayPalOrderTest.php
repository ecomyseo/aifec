<?php
/**
 * AIFEC — pruebas de los pedidos de PayPal: crear, capturar, consultar y devolver.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\PayPal\PayPalException;

require_once __DIR__ . '/helpers.php';

/** Respuesta de PayPal al crear un pedido. */
function paypalCreatedResponse(): \Aifec\Http\HttpResponse
{
    return paypalJson(201, [
        'id' => '5O190127TN364715T',
        'status' => 'PAYER_ACTION_REQUIRED',
        'links' => [
            ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T', 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'rel' => 'payer-action', 'method' => 'GET'],
        ],
    ]);
}

test('crear un pedido manda intención, importe decimal, referencias y direcciones de vuelta', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse());
    $out = paypalClient($http)->createOrder(paypalOrderInput(), 1000);

    same(['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'approveUrl' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'], $out);

    $r = paypalApiCalls($http)[0];
    same('POST', $r->method);
    same('https://api-m.sandbox.paypal.com/v2/checkout/orders', $r->url);
    same('Bearer ' . PAYPAL_TEST_TOKEN, $r->headers['Authorization']);
    $b = paypalBody($r);
    same('CAPTURE', $b['intent']);
    $unit = $b['purchase_units'][0];
    same('49.99', $unit['amount']['value']);
    same('EUR', $unit['amount']['currency_code']);
    same('PED-1001', $unit['custom_id']);
    same('PED-1001', $unit['invoice_id']);
    same('Pedido PED-1001', $unit['description']);
    $ctx = $b['payment_source']['paypal']['experience_context'];
    same('https://tienda.test/pago/paypal/vuelta', $ctx['return_url']);
    same('https://tienda.test/pago/paypal/cancelado', $ctx['cancel_url']);
    same('Mi Tienda', $ctx['brand_name']);
    same('es-ES', $ctx['locale']);
    same('GET_FROM_FILE', $ctx['shipping_preference']);
    same(false, isset($unit['items']), 'sin líneas no se manda desglose');
});

test('el enlace de aprobación también se reconoce con rel approve', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(201, ['id' => 'X1', 'status' => 'CREATED', 'links' => [['href' => 'https://pp.test/approve', 'rel' => 'approve']]]));
    same('https://pp.test/approve', paypalClient($http)->createOrder(paypalOrderInput(), 1)['approveUrl']);
});

test('el identificador de idempotencia es estable para la misma referencia y distinto para otra', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse(), paypalCreatedResponse(), paypalCreatedResponse());
    $client = paypalClient($http);
    $client->createOrder(paypalOrderInput(), 1000);
    $client->createOrder(paypalOrderInput(['description' => 'otro texto']), 1001);
    $client->createOrder(paypalOrderInput(['reference' => 'PED-1002']), 1002);

    $calls = paypalApiCalls($http);
    $id1 = $calls[0]->headers['PayPal-Request-Id'];
    same($id1, $calls[1]->headers['PayPal-Request-Id']);
    check($id1 !== $calls[2]->headers['PayPal-Request-Id'], 'otra referencia, otro identificador');
    check(strlen($id1) <= 108 && strlen($id1) > 10, 'longitud admitida por PayPal');
    notContains('PED-1001', $id1, 'el identificador no enseña la referencia');
});

test('con líneas que cuadran al céntimo se manda el desglose completo', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse());
    // 2 x 19,99 + 1 x 5,00 = 44,98; + envío 6,01 + IVA 0,00 - descuento 1,00 = 49,99
    paypalClient($http)->createOrder(paypalOrderInput([
        'items' => [
            ['name' => 'Café & té «especial» ñandú ☕', 'quantity' => 2, 'unit_amount' => 1999, 'sku' => 'CAF-1'],
            ['name' => 'Taza', 'quantity' => 1, 'unit_amount' => 500],
        ],
        'shipping' => 601,
        'tax' => 0,
        'discount' => 100,
    ]), 1000);

    $b = paypalBody(paypalApiCalls($http)[0]);
    $amount = $b['purchase_units'][0]['amount'];
    same('49.99', $amount['value']);
    same('44.98', $amount['breakdown']['item_total']['value']);
    same('6.01', $amount['breakdown']['shipping']['value']);
    same('0.00', $amount['breakdown']['tax_total']['value']);
    same('1.00', $amount['breakdown']['discount']['value']);
    same('EUR', $amount['breakdown']['item_total']['currency_code']);

    $items = $b['purchase_units'][0]['items'];
    same(2, count($items));
    same('Café & té «especial» ñandú ☕', $items[0]['name']);
    same('2', $items[0]['quantity']);
    same('19.99', $items[0]['unit_amount']['value']);
    same('CAF-1', $items[0]['sku']);
    same(false, isset($items[1]['sku']));
});

test('con líneas que no cuadran el pedido se manda sin desglose y sin líneas', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse(), paypalCreatedResponse(), paypalCreatedResponse(), paypalCreatedResponse());
    $client = paypalClient($http);
    // 1 céntimo de diferencia
    $client->createOrder(paypalOrderInput(['items' => [['name' => 'A', 'quantity' => 1, 'unit_amount' => 4998]]]), 1);
    // línea mal formada (cantidad 0)
    $client->createOrder(paypalOrderInput(['items' => [['name' => 'A', 'quantity' => 0, 'unit_amount' => 4999]]]), 2);
    // descuento negativo
    $client->createOrder(paypalOrderInput(['items' => [['name' => 'A', 'quantity' => 1, 'unit_amount' => 4999]], 'discount' => -5]), 3);
    // línea sin nombre
    $client->createOrder(paypalOrderInput(['items' => [['name' => '  ', 'quantity' => 1, 'unit_amount' => 4999]]]), 4);

    foreach (paypalApiCalls($http) as $call) {
        $unit = paypalBody($call)['purchase_units'][0];
        same('49.99', $unit['amount']['value'], 'el total se manda igual');
        same(false, isset($unit['amount']['breakdown']));
        same(false, isset($unit['items']));
    }
});

test('una línea que sí cuadra pasa, y la misma con un céntimo menos no', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse(), paypalCreatedResponse());
    $client = paypalClient($http);
    $client->createOrder(paypalOrderInput(['items' => [['name' => 'A', 'quantity' => 1, 'unit_amount' => 4999]]]), 1);
    $client->createOrder(paypalOrderInput(['items' => [['name' => 'A', 'quantity' => 1, 'unit_amount' => 4999]], 'shipping' => 1]), 2);

    $calls = paypalApiCalls($http);
    same(true, isset(paypalBody($calls[0])['purchase_units'][0]['amount']['breakdown']));
    same(false, isset(paypalBody($calls[1])['purchase_units'][0]['amount']['breakdown']), '4999 + 1 de envío no es 4999');
});

test('digital sin envío, dirección de envío y moneda sin decimales', function (): void {
    $http = paypalHttp();
    $http->queue(paypalCreatedResponse(), paypalCreatedResponse(), paypalCreatedResponse());
    $client = paypalClient($http);
    $client->createOrder(paypalOrderInput(['noShipping' => true]), 1);
    $client->createOrder(paypalOrderInput(['shippingAddress' => [
        'name' => 'Ñoño Pérez', 'line1' => 'Calle Mayor 1', 'line2' => '2º B', 'city' => 'Zaragoza',
        'state' => 'Z', 'postalCode' => '50001', 'country' => 'es',
    ]]), 2);
    $client->createOrder(paypalOrderInput(['amount' => 5000, 'currency' => 'jpy']), 3);

    $calls = paypalApiCalls($http);
    $c1 = paypalBody($calls[0]);
    same('NO_SHIPPING', $c1['payment_source']['paypal']['experience_context']['shipping_preference']);

    $c2 = paypalBody($calls[1]);
    same('SET_PROVIDED_ADDRESS', $c2['payment_source']['paypal']['experience_context']['shipping_preference']);
    $ship = $c2['purchase_units'][0]['shipping'];
    same('Ñoño Pérez', $ship['name']['full_name']);
    same('ES', $ship['address']['country_code']);
    same('Zaragoza', $ship['address']['admin_area_2']);
    same('50001', $ship['address']['postal_code']);

    $c3 = paypalBody($calls[2]);
    same('5000', $c3['purchase_units'][0]['amount']['value'], 'el yen no lleva decimales');
    same('JPY', $c3['purchase_units'][0]['amount']['currency_code']);
});

test('un pedido sin datos obligatorios o sin identificador devuelto es un error', function (): void {
    $http = paypalHttp();
    $client = paypalClient($http);
    foreach ([['reference' => ''], ['amount' => 0], ['amount' => -5], ['currency' => 'EU'], ['returnUrl' => ''], ['cancelUrl' => '']] as $bad) {
        throws(function () use ($client, $bad): void {
            $client->createOrder(paypalOrderInput($bad), 1);
        }, \InvalidArgumentException::class);
    }
    same(0, count($http->requests), 'ni siquiera pide la ficha');

    $http->queue(paypalJson(201, ['status' => 'CREATED']));
    throws(function () use ($client): void {
        $client->createOrder(paypalOrderInput(), 1);
    }, PayPalException::class);
});

test('capturar un pedido devuelve captura, importe en céntimos, correo y referencia', function (): void {
    foreach ([['49.99', 4999], ['0.10', 10], ['100', 10000], ['100.00', 10000], ['0.01', 1]] as $case) {
        $http = paypalHttp();
        $http->queue(paypalJson(201, paypalCapturedOrder($case[0])));
        $out = paypalClient($http)->captureOrder('5O190127TN364715T', 1000);

        same('COMPLETED', $out['status']);
        same('COMPLETED', $out['captureStatus']);
        same('3C679366HH908993F', $out['captureId']);
        same($case[1], $out['amount'], 'importe ' . $case[0]);
        same('EUR', $out['currency']);
        same('cliente@example.test', $out['payerEmail']);
        same('PED-1001', $out['reference']);

        $r = paypalApiCalls($http)[0];
        same('POST', $r->method);
        same('https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T/capture', $r->url);
        same('{}', $r->body);
        check(isset($r->headers['PayPal-Request-Id']), 'la captura también es idempotente');
    }
});

test('ORDER_ALREADY_CAPTURED no es un error: se consulta el pedido y se devuelve su captura', function (): void {
    $http = paypalHttp();
    $http->queue(
        paypalJson(422, [
            'name' => 'UNPROCESSABLE_ENTITY',
            'message' => 'The requested action could not be performed.',
            'debug_id' => 'abc123',
            'details' => [['issue' => 'ORDER_ALREADY_CAPTURED', 'description' => 'Order already captured.']],
        ]),
        paypalJson(200, paypalCapturedOrder('49.99'))
    );
    $out = paypalClient($http)->captureOrder('5O190127TN364715T', 1000);

    same('3C679366HH908993F', $out['captureId']);
    same(4999, $out['amount']);
    $calls = paypalApiCalls($http);
    same(2, count($calls));
    same('GET', $calls[1]->method);
    same('https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T', $calls[1]->url);
    same(1, paypalTokenCalls($http), 'la misma ficha para las dos llamadas');
});

test('otro 422 al capturar sí es un error', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(422, [
        'name' => 'UNPROCESSABLE_ENTITY',
        'message' => 'The requested action could not be performed.',
        'debug_id' => 'dbg-77',
        'details' => [['issue' => 'INSTRUMENT_DECLINED']],
    ]));
    try {
        paypalClient($http)->captureOrder('5O190127TN364715T', 1000);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(422, $e->status());
        same('UNPROCESSABLE_ENTITY', $e->paypalName());
        same('dbg-77', $e->debugId());
        same(false, $e->isTransient());
        contains('INSTRUMENT_DECLINED', $e->getMessage());
        contains('dbg-77', $e->getMessage());
    }
});

test('consultar un pedido sin capturar da importe del pedido y captura vacía', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(200, [
        'id' => 'ORD-1', 'status' => 'APPROVED',
        'purchase_units' => [['custom_id' => 'PED-7', 'amount' => ['currency_code' => 'EUR', 'value' => '12.30']]],
        'payment_source' => ['paypal' => ['email_address' => 'otro@example.test']],
    ]));
    $out = paypalClient($http)->getOrder('ORD-1', 5);

    same('APPROVED', $out['status']);
    same('', $out['captureId']);
    same(1230, $out['amount']);
    same('PED-7', $out['reference']);
    same('otro@example.test', $out['payerEmail']);
    same('GET', paypalApiCalls($http)[0]->method);
});

test('un pedido de yenes devuelve el importe sin multiplicar', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(201, paypalCapturedOrder('5000', 'JPY')));
    $out = paypalClient($http)->captureOrder('ORD-JPY', 1);
    same(5000, $out['amount']);
    same('JPY', $out['currency']);
});

test('una devolución total no manda importe y una parcial sí', function (): void {
    $http = paypalHttp();
    $http->queue(
        paypalJson(201, ['id' => 'REF-1', 'status' => 'COMPLETED']),
        paypalJson(201, ['id' => 'REF-2', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '10.50']]),
        paypalJson(201, ['id' => 'REF-3', 'status' => 'COMPLETED'])
    );
    $client = paypalClient($http);
    $total = $client->refund('3C679366HH908993F', null, 'EUR', '', 1000);
    $partial = $client->refund('3C679366HH908993F', 1050, 'EUR', 'Producto dañado: «rotura» & más', 1001);
    $other = $client->refund('3C679366HH908993F', 1000, 'EUR', '', 1002);

    same('REF-1', $total['id']);
    same(null, $total['amount'], 'la total sin importe en la respuesta no inventa uno');
    same(1050, $partial['amount']);
    same('EUR', $partial['currency']);
    same('COMPLETED', $partial['status']);
    same(1000, $other['amount'], 'sin importe en la respuesta se da el pedido');

    $calls = paypalApiCalls($http);
    same('https://api-m.sandbox.paypal.com/v2/payments/captures/3C679366HH908993F/refund', $calls[0]->url);
    same('{}', $calls[0]->body);
    $pb = paypalBody($calls[1]);
    same('10.50', $pb['amount']['value']);
    same('EUR', $pb['amount']['currency_code']);
    same('Producto dañado: «rotura» & más', $pb['note_to_payer']);

    $ids = [$calls[0]->headers['PayPal-Request-Id'], $calls[1]->headers['PayPal-Request-Id'], $calls[2]->headers['PayPal-Request-Id']];
    same(3, count(array_unique($ids)), 'cada importe tiene su identificador');
});

test('la misma devolución repetida lleva el mismo identificador de idempotencia', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(201, ['id' => 'R', 'status' => 'COMPLETED']), paypalJson(201, ['id' => 'R', 'status' => 'COMPLETED']));
    $client = paypalClient($http);
    $client->refund('CAP-1', 500, 'EUR', 'a', 1);
    $client->refund('CAP-1', 500, 'EUR', 'b', 2);
    $calls = paypalApiCalls($http);
    same($calls[0]->headers['PayPal-Request-Id'], $calls[1]->headers['PayPal-Request-Id']);
});

test('una devolución con importe cero o sin moneda es un error y no llama a PayPal', function (): void {
    $http = paypalHttp();
    $client = paypalClient($http);
    throws(function () use ($client): void {
        $client->refund('CAP-1', 0, 'EUR', '', 1);
    }, \InvalidArgumentException::class);
    throws(function () use ($client): void {
        $client->refund('CAP-1', 100, '', '', 1);
    }, \InvalidArgumentException::class);
    throws(function () use ($client): void {
        $client->refund('  ', null, 'EUR', '', 1);
    }, \InvalidArgumentException::class);
    same(0, count($http->requests));
});
