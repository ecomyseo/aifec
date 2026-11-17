<?php
/**
 * AIFEC — pruebas del pago simulado: cuándo se ofrece, sus tres modos, la página donde el cliente decide, el cobro único,
 * las fichas malas, la vuelta a la tienda y las devoluciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/PaymentsKit.php';

/**
 * Un pedido de dos camisetas pagado con el pago simulado en el modo dado. Devuelve [app, id de la camiseta, resultado de place()].
 *
 * @return array{0:App,1:int,2:array<string,mixed>}
 */
function simulatedOrder(string $mode, int $qty = 2): array
{
    $app = paymentsApp(['payments-simulated'], ['payments.simulated.mode' => $mode]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = paymentsPlace($app, new TestClient($app), 'simulated', [[$p, 0, $qty]]);

    return [$app, $p, $placed];
}

/** La ficha de formulario de la tienda que lleva una página. */
function simulatedToken(string $html): string
{
    check(preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1, 'la página lleva su ficha de formulario');

    return html_entity_decode($m[1], ENT_QUOTES);
}

test('no se ofrece en un sitio normal aunque esté encendido; sí en demo, en depuración o con el permiso expreso', function (): void {
    $app = paymentsApp(['payments-simulated']);
    $method = $app->service('payments')->get('simulated');
    check($method !== null, 'la forma de pago está registrada');
    $context = paymentsContext();

    same(true, $method->available($context), 'los sitios de prueba están en modo de depuración');
    $app->site()->config['debug'] = false;
    same(false, $method->available($context), 'un sitio normal no la ofrece');
    same([], paymentsCodes($app->service('payments')->available($context)));

    $app->site()->config['demo'] = true;
    same(true, $method->available($context), 'un sitio de demostración sí');
    $app->site()->config['demo'] = false;

    $app->settings()->set('payments.simulated.allow_live', true);
    same(true, $method->available($context), 'con el permiso expreso, también en una tienda real');
    $app->settings()->set('payments.simulated.enabled', false);
    same(false, $method->available($context), 'apagada no se ofrece en ningún caso');
    $app->site()->config['debug'] = true;
    same(false, $method->available($context), 'ni siquiera en depuración');
    same(true, $method->isTest(), 'es siempre de pruebas');
    same(0, $method->fee($context));
});

test('modo «approve»: el pedido queda pagado y marcado de pruebas, con su cobro apuntado', function (): void {
    list($app, $p, $placed) = simulatedOrder('approve');
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    same(PaymentAction::PAID, $placed['action']->type);
    same('SIM-' . $placed['number'], $placed['action']->reference);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same(true, $order['test']);
    same($order['total'], $order['paid_total']);
    same(1, count($order['payments']));
    same(['simulated', 'charge', 'SIM-' . $placed['number'], true], [$order['payments'][0]['method'], $order['payments'][0]['kind'], $order['payments'][0]['reference'], $order['payments'][0]['test']]);
    same(8, aifecOrderStock($app, $p), 'las existencias quedan descontadas');
});

test('modo «decline»: el pedido queda fallido y las existencias vuelven', function (): void {
    list($app, $p, $placed) = simulatedOrder('decline');
    same(false, $placed['ok']);
    same(PaymentAction::FAILED, $placed['action']->type);
    contains('rechazado', $placed['error']);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same(10, aifecOrderStock($app, $p), 'las existencias han vuelto');
    same(0, aifecOrderCount($app, 'order_payments', "status = 'ok'"), 'no hay ningún cobro bueno');
});

test('modo «ask»: el pedido queda pendiente y la caja recibe la dirección de la página de pago, con la ficha del pedido', function (): void {
    list($app, $p, $placed) = simulatedOrder('ask');
    check(!empty($placed['ok']), 'el pedido se crea');
    same(PaymentAction::REDIRECT, $placed['action']->type);
    same('/pago/simulado/' . $placed['number'] . '/' . $placed['secret'], $placed['action']->url);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('pending_payment', $order['status']);
    same('pending', $order['payment_status']);
    same(8, aifecOrderStock($app, $p));
    same(0, count($order['payments']));

    // El modo por defecto es «ask»: sin ajuste, se comporta igual.
    $app2 = paymentsApp(['payments-simulated']);
    same('ask', $app2->settings()->str('payments.simulated.mode'));
});

test('la página de pago enseña el pedido y dos botones, no se guarda en ninguna caché y no se indexa', function (): void {
    list($app, $p, $placed) = simulatedOrder('ask');
    $web = new TestClient($app);
    $r = $web->get($placed['action']->url);

    same(200, $r->status);
    contains('Simular pago correcto', $r->body);
    contains('Simular pago rechazado', $r->body);
    contains((string) $placed['number'], $r->body);
    contains('name="outcome" value="paid"', $r->body);
    contains('name="outcome" value="failed"', $r->body);
    contains('noindex', $r->body);
    notContains('href=""', $r->body);
    same('no-store, private', $r->headers['Cache-Control']);
    check(preg_match('/<form method="post" action="([^"]+)"/', $r->body, $m) === 1, 'el formulario envía a una dirección');
    same('/pago/simulado/' . $placed['number'] . '/' . $placed['secret'], html_entity_decode($m[1]));
    $match = $app->router()->match('POST', html_entity_decode($m[1]));
    check($match !== null && in_array('csrf', $match['route']->middleware, true), 'el envío pasa por el intermediario csrf');
});

test('«Simular pago correcto» paga una sola vez aunque se envíe dos veces', function (): void {
    list($app, $p, $placed) = simulatedOrder('ask');
    $web = new TestClient($app);
    $r = $web->get($placed['action']->url);
    $token = simulatedToken($r->body);
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });

    $first = $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'paid']);
    $second = $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'paid']);
    same(302, $first->status);
    same(302, $second->status);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same(true, $order['test']);
    same(1, count($order['payments']), 'un solo cobro');
    same('SIM-' . $placed['number'], $order['payments'][0]['reference']);
    same(1, $paidEvents, 'un solo aviso de pedido pagado');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo de confirmación');
    same(8, aifecOrderStock($app, $p));

    // Con el pedido ya pagado, la página y el botón de rechazo no lo cambian.
    same(302, $web->get($placed['action']->url)->status, 'un pedido ya pagado no vuelve a pedir nada');
    $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'failed']);
    same('paid', $app->service('orders')->find((int) $placed['id'])['payment_status']);
});

test('«Simular pago rechazado» deja el pedido fallido con las existencias de vuelta, y un pago posterior ya no lo cobra', function (): void {
    list($app, $p, $placed) = simulatedOrder('ask');
    $web = new TestClient($app);
    $token = simulatedToken($web->get($placed['action']->url)->body);

    same(302, $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'failed'])->status);
    $order = $app->service('orders')->find((int) $placed['id']);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same(10, aifecOrderStock($app, $p));

    $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'paid']);
    $order = $app->service('orders')->find((int) $placed['id']);
    same('failed', $order['payment_status'], 'un pedido fallido no se cobra por esta página');
    same(0, aifecOrderCount($app, 'order_payments', "status = 'ok'"));
});

test('una ficha mala, un pedido de otra forma de pago o una ficha de formulario caducada no dejan hacer nada', function (): void {
    list($app, $p, $placed) = simulatedOrder('ask');
    $web = new TestClient($app);
    $token = simulatedToken($web->get($placed['action']->url)->body);
    $bad = '/pago/simulado/' . $placed['number'] . '/ficha-de-mentira';

    same(404, $web->get($bad)->status, 'la ficha mala da 404');
    same(404, $web->post($bad, ['_token' => $token, 'outcome' => 'paid'])->status);
    same(404, $web->get('/pago/simulado/9999-999999/' . $placed['secret'])->status, 'un número que no existe también');
    same('pending', $app->service('orders')->find((int) $placed['id'])['payment_status'], 'nada ha cambiado');

    same(403, $web->post($placed['action']->url, ['_token' => 'ficha-de-mentira', 'outcome' => 'paid'])->status, 'una ficha de formulario falsa se rechaza');
    same(400, $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'regalo'])->status, 'una decisión que no existe se rechaza');
    same('pending', $app->service('orders')->find((int) $placed['id'])['payment_status']);

    // Un pedido que se paga con otra forma de pago no es de esta página, aunque se tenga su ficha.
    $app->service('payments')->register(new AifecOrderPayment('gw'));
    $other = paymentsPlace($app, $web, 'gw', [[$p, 0, 1]]);
    check(!empty($other['ok']), 'el otro pedido se crea');
    same(404, $web->get('/pago/simulado/' . $other['number'] . '/' . $other['secret'])->status);
});

test('tras decidir, el cliente vuelve a la página de su pedido, tanto si paga como si falla', function (): void {
    // La tienda (módulo de pedidos) ya trae la página del pedido: es la ruta «order.thanks», con el número y la ficha.
    list($app, $p, $placed) = simulatedOrder('ask');
    check($app->router()->has('order.thanks'), 'la tienda tiene página del pedido');
    $web = new TestClient($app);
    $token = simulatedToken($web->get($placed['action']->url)->body);
    $r = $web->post($placed['action']->url, ['_token' => $token, 'outcome' => 'paid']);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location'], 'pagado: a la página de su pedido');
    same(200, $web->get($r->headers['Location'])->status, 'que se abre');

    list($app2, $p2, $placed2) = simulatedOrder('ask');
    $web2 = new TestClient($app2);
    $token2 = simulatedToken($web2->get($placed2['action']->url)->body);
    $r = $web2->post($placed2['action']->url, ['_token' => $token2, 'outcome' => 'failed']);
    same('/pedido/' . $placed2['number'] . '/' . $placed2['secret'], $r->headers['Location'], 'fallido: también, para que pueda reintentar');
});

test('admite devoluciones y da una referencia SIMR- distinta en cada una', function (): void {
    list($app, $p, $placed) = simulatedOrder('approve', 2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $method = $app->service('payments')->get('simulated');
    same(true, $method->supportsRefund());

    $orders->refund($id, 1000, [], 'Camiseta rota', true, false);
    $orders->refund($id, 500, [], 'Otra', true, false);
    $order = $orders->find($id);
    same(2, count($order['refunds']));
    $references = [];
    foreach ($order['payments'] as $payment) {
        if ($payment['kind'] === 'refund') {
            $references[] = $payment['reference'];
        }
    }
    same(2, count($references));
    foreach ($references as $reference) {
        same(0, strpos($reference, 'SIMR-' . $placed['number'] . '-'), 'la referencia empieza por SIMR- y el número de pedido');
    }
    same(2, count(array_unique($references)), 'cada devolución tiene su referencia');
    same('SIMR-X-100', $method->refund(['number' => 'X', 'refunded_total' => 100], 100, ''));
});

test('el título y la descripción son los de serie traducidos, o los que escribe el comerciante tal cual', function (): void {
    $app = paymentsApp(['payments-simulated']);
    $method = $app->service('payments')->get('simulated');
    same('Pago de prueba', $method->info()['title']);
    contains('no cobra nada', $method->info()['description']);

    $app->settings()->set('payments.simulated.title', 'Pago de prueba & demos');
    $app->settings()->set('payments.simulated.description', '  Solo para "ensayar"  ');
    same('Pago de prueba & demos', $method->info()['title'], 'el «&» se queda como se escribió');
    same('Solo para "ensayar"', $method->info()['description']);
    same('simulated', $method->code());
});
