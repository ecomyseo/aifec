<?php
/**
 * AIFEC — pruebas del aviso del banco y de la vuelta del cliente: solo el aviso con la firma buena da el pedido por pagado,
 * una sola vez; lo demás (firma falsa, importe o moneda distintos, pago denegado, número desconocido, fallo interno) no
 * cobra nada; y las vueltas del navegador nunca marcan un pedido como pagado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsRedsys\RedsysPayment;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/RedsysKit.php';

test('un aviso autorizado y bien firmado da el pedido por pagado, apunta el cobro y contesta OK', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $number = $params['DS_MERCHANT_ORDER'];

    $r = rdsNotify($app, rdsAviso($number, (int) $order['total']));
    same(200, $r->status);
    same('OK', $r->body);
    notContains('no-cache', (string) ($r->headers['Cache-Control'] ?? ''));

    $order = rdsOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same($order['total'], $order['paid_total']);
    same(true, $order['test'], 'el entorno es el de pruebas: el pedido queda marcado');
    $charges = rdsPayments($order, 'charge');
    same(1, count($charges), 'un cobro');
    same(['redsys', $number, 'ok', (int) $order['total'], true], [$charges[0]['method'], $charges[0]['reference'], $charges[0]['status'], $charges[0]['amount'], $charges[0]['test']]);
    same([], rdsPayments($order, RedsysPayment::ATTEMPT), 'el intento pendiente ya no sobra');
    same('123456', $charges[0]['raw']['Ds_AuthorisationCode'], 'se guarda lo necesario para entender el cobro');
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
    same(9, aifecOrderStock($app, $p), 'las existencias siguen descontadas');
});

test('el mismo aviso otra vez contesta OK y no hace nada más: un cobro, un correo, un solo «pedido pagado»', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $post = rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']);
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });

    same('OK', rdsNotify($app, $post)->body);
    same('OK', rdsNotify($app, $post)->body);
    same(200, rdsNotify($app, $post)->status);

    $order = rdsOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(1, count(rdsPayments($order, 'charge')), 'un solo cobro');
    same(1, $paidEvents, 'un solo aviso de pedido pagado');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo de confirmación');
    same(1, aifecOrderCount($app, 'order_payments', "status = 'ok'"));
});

test('el aviso no necesita sesión ni ficha de formulario: lo manda el servidor del banco', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $match = $app->router()->match('POST', '/pago/redsys/aviso');

    check($match !== null, 'la ruta existe');
    same('payment.redsys.notify', $match['route']->name);
    same([], $match['route']->middleware, 'sin el intermediario csrf');
    $bank = new TestClient($app);
    $bank->headers = [];
    same('OK', $bank->post('/pago/redsys/aviso', rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']))->body, 'ni siquiera cabeceras de navegador');
});

test('una firma mala da 403 y no cambia nada; tampoco vale la clave de pruebas contra un entorno de producción', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $number = $params['DS_MERCHANT_ORDER'];
    $before = aifecOrderSnapshot($app);

    $forged = rdsAviso($number, (int) $order['total'], [], RDS_LIVE_KEY);
    $r = rdsNotify($app, $forged);
    same(403, $r->status);
    notContains('OK', $r->body);

    $tampered = rdsAviso($number, (int) $order['total']);
    $tampered['Ds_MerchantParameters'] = base64_encode((string) json_encode(['Ds_Order' => $number, 'Ds_Amount' => '1', 'Ds_Currency' => '978', 'Ds_Response' => '0000']));
    same(403, rdsNotify($app, $tampered)->status, 'unos parámetros cambiados con la firma de otros');
    same(403, rdsNotify($app, [])->status, 'sin nada');
    same(403, rdsNotify($app, ['Ds_MerchantParameters' => 'no-es-base64!', 'Ds_Signature' => 'x'])->status, 'con basura');
    same($before, aifecOrderSnapshot($app), 'ninguna tabla ha cambiado');
    same('pending', rdsOrderRow($app, $placed)['payment_status']);

    // En producción la clave de pruebas (que es pública) no puede dar un pedido por pagado.
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    same(403, rdsNotify($app, rdsAviso($number, (int) $order['total']))->status);
    same('pending', rdsOrderRow($app, $placed)['payment_status']);
    same('OK', rdsNotify($app, rdsAviso($number, (int) $order['total'], [], RDS_LIVE_KEY))->body, 'con la clave del entorno, sí');
    same('paid', rdsOrderRow($app, $placed)['payment_status']);
});

test('un pedido hecho y cobrado en producción no queda marcado como de pruebas', function (): void {
    $app = rdsApp();
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = rdsPlace($app, new TestClient($app), 'redsys', $p);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    $total = (int) rdsOrderRow($app, $placed)['total'];

    same('OK', rdsNotify($app, rdsAviso(rdsParams($placed['action'])['DS_MERCHANT_ORDER'], $total, [], RDS_LIVE_KEY))->body);
    $order = rdsOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(false, $order['test'], 'un cobro real no es de pruebas');
    same(false, rdsPayments($order, 'charge')[0]['test']);
});

test('sin clave configurada nadie puede dar un pedido por pagado', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $app->setSecret(RedsysSettings::SECRET_TEST, '');

    same(403, rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']))->status);
    same('pending', rdsOrderRow($app, $placed)['payment_status']);
});

test('un importe distinto al del pedido lo deja en espera y no lo da por pagado', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);

    $r = rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'] - 100));
    same(200, $r->status, 'el aviso se ha procesado: el banco no tiene que reintentar');
    same('OK', $r->body);
    $order = rdsOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status'], 'no está pagado');
    same(0, (int) $order['paid_total']);
    same(0, count(aifecOrderMails($app, 'order_confirmed')), 'sin correo de pedido pagado');
    $charges = rdsPayments($order, 'charge');
    same(1, count($charges));
    same((int) $order['total'] - 100, $charges[0]['amount'], 'queda apuntado lo que de verdad se cobró, para revisarlo');

    // Repetido, no se apunta otra vez ni se cambia nada.
    same('OK', rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'] - 100))->body);
    same(1, count(rdsPayments(rdsOrderRow($app, $placed), 'charge')));
});

test('una moneda que no es la del pedido lo deja en espera y no lo da por pagado', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);

    $r = rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'], ['Ds_Currency' => '840']));
    same('OK', $r->body);
    $order = rdsOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same([], rdsPayments($order, 'charge'), 'no se apunta como cobro en euros');
    same(0, count(aifecOrderMails($app, 'order_confirmed')));
    contains('otra moneda', json_encode($order['history'], JSON_UNESCAPED_UNICODE));
});

test('un aviso denegado deja el pedido fallido con el motivo legible y las existencias de vuelta, una sola vez', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    same(9, aifecOrderStock($app, $p));
    $post = rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'], ['Ds_Response' => '0101', 'Ds_AuthorisationCode' => '']);

    same('OK', rdsNotify($app, $post)->body);
    same('OK', rdsNotify($app, $post)->body, 'repetido, igual');
    $order = rdsOrderRow($app, $placed);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same(10, aifecOrderStock($app, $p), 'las existencias han vuelto');
    same(0, aifecOrderCount($app, 'order_payments', "status = 'ok'"), 'ningún cobro bueno');
    same(0, count(aifecOrderMails($app, 'order_confirmed')));
    $history = json_encode($order['history'], JSON_UNESCAPED_UNICODE);
    contains('Tarjeta caducada', $history);
    contains('101', $history);
    foreach (rdsPayments($order, RedsysPayment::ATTEMPT) as $attempt) {
        same('failed', $attempt['status'], 'el intento ya no está pendiente');
    }
});

test('otros códigos de respuesta, cada uno con su explicación; los que no son un resultado final no tocan el pedido', function (): void {
    $cases = [
        [116, 'Saldo insuficiente'],
        [184, 'autenticación'],
        [9915, 'cancelada por el cliente'],
        [9999, null],
        [9998, null],
        [900, null],
    ];
    foreach ($cases as $case) {
        list($app, $p, $placed, $params) = rdsOrder('redsys');
        $order = rdsOrderRow($app, $placed);
        $r = rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'], ['Ds_Response' => sprintf('%04d', $case[0])]));
        same('OK', $r->body, 'código ' . $case[0]);
        $order = rdsOrderRow($app, $placed);
        if ($case[1] === null) {
            same('pending', $order['payment_status'], 'el código ' . $case[0] . ' no es un resultado final');
            same('pending_payment', $order['status']);
        } else {
            same('failed', $order['status'], 'el código ' . $case[0] . ' es un fallo');
            contains($case[1], json_encode($order['history'], JSON_UNESCAPED_UNICODE));
        }
    }
});

test('un número de Redsys que no es de ningún pedido da 404 aunque la firma sea buena', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $before = aifecOrderSnapshot($app);

    same(404, rdsNotify($app, rdsAviso('9999ZZ', (int) $order['total']))->status);
    same($before, aifecOrderSnapshot($app));
    same('pending', rdsOrderRow($app, $placed)['payment_status']);

    // Un pedido que no se paga por Redsys tampoco se cobra por aquí, aunque exista una fila con ese número.
    $app->service('payments')->register(new AifecOrderPayment('gw'));
    $other = rdsPlace($app, new TestClient($app), 'gw', $p);
    $app->db()->insert('order_payments', [
        'order_id' => (int) $other['id'], 'method' => 'redsys', 'kind' => RedsysPayment::ATTEMPT, 'reference' => '5555A', 'amount' => 0,
        'currency' => 'EUR', 'status' => 'pending', 'test' => 1, 'raw' => null, 'created_at' => $app->clock()->sql(),
    ]);
    same(404, rdsNotify($app, rdsAviso('5555A', (int) rdsOrderRow($app, $other)['total']))->status);
    same('pending', rdsOrderRow($app, $other)['payment_status']);
});

test('un pedido de Bizum se cobra igual y el cobro queda apuntado con su método', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('bizum');
    $order = rdsOrderRow($app, $placed);

    $post = rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total'], ['Ds_Card_Brand' => '', 'Ds_ProcessedPayMethod' => '68']);
    same('OK', rdsNotify($app, $post)->body);
    same('OK', rdsNotify($app, $post)->body);
    $order = rdsOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    $charges = rdsPayments($order, 'charge');
    same(1, count($charges));
    same('bizum', $charges[0]['method']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('si algo falla por dentro contesta 500 para que el banco reintente, y lo apunta sin la clave ni la tarjeta', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $order = rdsOrderRow($app, $placed);
    $post = rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']);
    $app->db()->run('DROP TABLE {order_payments}');

    $r = rdsNotify($app, $post);
    same(500, $r->status);
    notContains('OK', $r->body);
    $logged = json_encode($app->log()->records, JSON_UNESCAPED_UNICODE);
    contains('redsys.notify', $logged, 'el fallo queda apuntado');
    notContains(RDS_KEY, $logged);
    notContains('454881', $logged, 'ni el número de tarjeta');
    notContains($post['Ds_Signature'], $logged, 'ni la firma');
    notContains($post['Ds_MerchantParameters'], $logged, 'ni el aviso entero');
});

test('la vuelta «ok» lleva a la página de gracias y NO marca el pedido como pagado', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $app->router()->get('/pedido/{number}/{secret}', static function (Request $request, App $app): Response {
        return Response::text('gracias');
    })->name('order.thanks');
    $web = new TestClient($app);
    $url = '/pago/redsys/ok/' . $placed['number'] . '/' . $placed['secret'];

    $r = $web->get($url);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
    $order = rdsOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'el aviso del banco es lo único que paga');
    same('pending_payment', $order['status']);
    same(0, aifecOrderCount($app, 'order_payments', "status = 'ok'"));

    // Aunque la dirección de vuelta lleve datos de pago, no se miran.
    $web->get($url . '?Ds_Response=0000&Ds_Amount=' . $order['total']);
    same('pending', rdsOrderRow($app, $placed)['payment_status']);

    same(404, $web->get('/pago/redsys/ok/' . $placed['number'] . '/ficha-de-mentira')->status, 'una ficha mala');
    same(404, $web->get('/pago/redsys/ok/9999-999999/' . $placed['secret'])->status, 'un número que no existe');
});

test('la vuelta «ko» reabre el carrito, lleva a la caja con un aviso y no toca el pago del pedido', function (): void {
    $app = rdsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);
    $placed = rdsPlace($app, $web, 'redsys', $p);
    check(!empty($placed['ok']), 'el pedido se crea');
    $cartRows = (int) aifecOrderCount($app, 'carts', 'order_id IS NOT NULL');
    same(1, $cartRows, 'el carrito está ligado al pedido');

    $r = $web->get('/pago/redsys/ko/' . $placed['number'] . '/' . $placed['secret']);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    same(0, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'), 'el carrito vuelve a estar libre');
    $page = $web->follow();
    contains('El pago no se ha completado. Puedes intentarlo de nuevo.', $page->body);
    $order = rdsOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'la vuelta no cobra ni rechaza nada');

    same(404, $web->get('/pago/redsys/ko/' . $placed['number'] . '/ficha-de-mentira')->status);
});

test('la vuelta «ko» de un pedido que el banco ya había cobrado lleva a la página de gracias, sin tocar el carrito', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $app->router()->get('/pedido/{number}/{secret}', static function (Request $request, App $app): Response {
        return Response::text('gracias');
    })->name('order.thanks');
    $order = rdsOrderRow($app, $placed);
    rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']));

    $r = (new TestClient($app))->get('/pago/redsys/ko/' . $placed['number'] . '/' . $placed['secret']);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
    same('paid', rdsOrderRow($app, $placed)['payment_status']);
});

test('el aviso y las vueltas no se confunden con los de otra forma de pago', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $app->service('payments')->register(new AifecOrderPayment('gw'));
    $other = rdsPlace($app, new TestClient($app), 'gw', $p);
    $web = new TestClient($app);

    same(404, $web->get('/pago/redsys/ok/' . $other['number'] . '/' . $other['secret'])->status);
    same(404, $web->get('/pago/redsys/ko/' . $other['number'] . '/' . $other['secret'])->status);
    same('pending', rdsOrderRow($app, $other)['payment_status']);
});
