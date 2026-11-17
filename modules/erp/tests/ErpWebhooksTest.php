<?php
/**
 * AIFEC — ERP: avisos al ERP (webhooks): firma, reintentos, pausa, cursor sembrado y avisos apagados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Erp\Webhooks;

/** Una tienda con los avisos encendidos y una suscripción a pedidos pagados. Devuelve [app, conexión, suscripción]. */
function erpHooked(array $events = ['order.paid'], bool $outbound = true): array
{
    $app = erpApp(['settings' => ['erp.outbound' => $outbound]]);
    $c = erpConnection($app);
    $sub = erpSubscribe($app, $c['id'], $events);

    return [$app, $c, $sub];
}

test('al crear la suscripción se da el secreto una sola vez y se guarda cifrado', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $sub = erpSubscribe($app, $c['id'], ['order.paid']);
    check(is_string($sub['secret']) && str_starts_with($sub['secret'], 'whsec_'), 'secreto en claro al crear');
    same(true, $sub['created']);
    same($sub['secret'], $app->secret('erp.whsec.' . $c['id']));
    foreach ($app->db()->select('SELECT * FROM {settings}') as $row) {
        notContains($sub['secret'], json_encode($row), 'el secreto no está en claro en los ajustes');
    }
    $again = erpSubscribe($app, $c['id'], ['order.paid', 'order.refunded']);
    same(null, $again['secret']);
    same(false, $again['created']);
    same(['order.paid', 'order.refunded'], $app->service('erp')->webhooks()->find($c['id'])['events']);
    $new = $app->service('erp')->webhooks()->newSecret($c['id']);
    check($new !== $sub['secret'], 'un secreto nuevo');
});

test('un aviso sale firmado con HMAC-SHA256 de la marca de tiempo y el cuerpo', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    $id = erpOrder($app);
    check(aifecOrderCount($app, 'erp_deliveries') >= 1, 'se creó el aviso');
    $app->service('erp')->webhooks()->sweep();
    $request = $app->http()->requests[0];
    same('https://erp.test/hook', $request->url);
    same('POST', $request->method);
    $stamp = $request->headers['X-Aifec-Timestamp'];
    same((string) testNow(), $stamp);
    $expected = 'sha256=' . hash_hmac('sha256', $stamp . '.' . $request->body, $sub['secret']);
    same($expected, $request->headers['X-Aifec-Signature']);
    same('order.paid', $request->headers['X-Aifec-Event']);
    $payload = json_decode((string) $request->body, true);
    same('order.paid', $payload['event']);
    same(adminOrdersNumber($app, $id), $payload['data']['order']['number']);
    check(str_starts_with((string) $payload['id'], 'evt_'), 'lleva el identificador del evento');
    same('sent', (string) $app->db()->value('SELECT status FROM {erp_deliveries} ORDER BY id LIMIT 1'));
    // La firma con otro secreto no coincide.
    check($expected !== 'sha256=' . hash_hmac('sha256', $stamp . '.' . $request->body, 'otro'), 'depende del secreto');
});

test('los reintentos esperan 1 minuto, 5, 30, 2 horas y 12 horas', function (): void {
    list($app, $c, $sub) = erpHooked();
    $hooks = $app->service('erp')->webhooks();
    $app->db()->run("UPDATE {erp_webhooks} SET failures = 0");
    $app->http()->when('POST', 'erp.test', static function (HttpRequest $r): HttpResponse {
        return new HttpResponse(500, [], 'fallo');
    });
    $hooks->emit('order.paid', static function (): array {
        return ['order' => ['number' => 'X']];
    });
    $id = (int) $app->db()->value('SELECT id FROM {erp_deliveries}');
    $waits = [];
    for ($i = 0; $i < 4; ++$i) {
        $result = $hooks->attempt($id);
        same('retry', $result);
        $row = $app->db()->find('erp_deliveries', ['id' => $id]);
        $waits[] = \Aifec\Kernel\Clock::parse((string) $row['next_attempt_at']) - $app->clock()->now();
        $app->clock()->advance(end($waits));
        // Mientras no llega la hora, la pasada periódica no lo toca.
    }
    same([60, 300, 1800, 7200], $waits);
    // Con otra entrega saliendo bien entre medias, el contador de fallos seguidos se reinicia y llega la espera de 12 horas.
    $app->db()->update('erp_webhooks', ['failures' => 0], ['connection_id' => $c['id']]);
    same('retry', $hooks->attempt($id));
    $row = $app->db()->find('erp_deliveries', ['id' => $id]);
    same(43200, \Aifec\Kernel\Clock::parse((string) $row['next_attempt_at']) - $app->clock()->now());
    $app->clock()->advance(43200);
    same('failed', $hooks->attempt($id));
    same(6, (int) $app->db()->value('SELECT attempts FROM {erp_deliveries} WHERE id = ?', [$id]));
    same('failed', (string) $app->db()->value('SELECT status FROM {erp_deliveries} WHERE id = ?', [$id]));
});

test('la pasada periódica solo envía lo que ya toca y un reintento no se adelanta', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->queue(new HttpResponse(503, [], ''), new HttpResponse(200, [], 'ok'));
    $hooks = $app->service('erp')->webhooks();
    $hooks->emit('order.paid', static function (): array {
        return ['order' => ['number' => 'A']];
    });
    $first = $hooks->sweep();
    same(0, $first['sent']);
    same(1, count($app->http()->requests));
    $again = $hooks->sweep();
    same(0, $again['sent']);
    same(1, count($app->http()->requests), 'a los 0 segundos no se reintenta');
    $app->clock()->advance(61);
    same(1, $hooks->sweep()['sent']);
    same(2, count($app->http()->requests));
    same(2, (int) $app->db()->value('SELECT attempts FROM {erp_deliveries}'));
});

test('a los cinco fallos seguidos la suscripción se pausa, se avisa a la tienda y no se envía más', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->when('POST', 'erp.test', static function (HttpRequest $r): HttpResponse {
        throw new HttpException('Connection timed out');
    });
    $hooks = $app->service('erp')->webhooks();
    $hooks->emit('order.paid', static function (): array {
        return ['order' => ['number' => 'A']];
    });
    $id = (int) $app->db()->value('SELECT id FROM {erp_deliveries}');
    $results = [];
    for ($i = 0; $i < 5; ++$i) {
        $results[] = $hooks->attempt($id);
        $app->clock()->advance(1);
    }
    same(['retry', 'retry', 'retry', 'retry', 'paused'], $results);
    $hook = $hooks->find($c['id']);
    same(true, $hook['paused']);
    same(5, $hook['failures']);
    contains('5 times in a row', (string) $hook['paused_reason']);
    $mail = aifecOrderMails($app, 'erp_webhook_paused');
    same(1, count($mail));
    same('tienda@ejemplo.test', $mail[0]['to_email']);
    // Pausada: lo nuevo no genera avisos y lo pendiente no sale.
    $before = count($app->http()->requests);
    same(0, $hooks->emit('order.paid', static function (): array {
        return ['order' => []];
    }));
    $hooks->sweep();
    same($before, count($app->http()->requests));
});

test('reactivar una suscripción pausada siembra el cursor en ahora y vuelve a enviar', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->db()->update('erp_webhooks', ['paused' => 1, 'failures' => 5, 'paused_reason' => 'x'], ['connection_id' => $c['id']]);
    $app->clock()->advance(7200);
    $hook = $app->service('erp')->webhooks()->find($c['id']);
    check($app->service('erp')->webhooks()->reactivate((int) $hook['id']), 'se reactiva');
    $hook = $app->service('erp')->webhooks()->find($c['id']);
    same(false, $hook['paused']);
    same(0, $hook['failures']);
    same($app->clock()->sql(), $hook['since_at']);
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    erpOrder($app);
    $app->service('erp')->webhooks()->sweep();
    same(1, count($app->http()->requests));
});

test('«Reenviar» vuelve a mandar un aviso ya enviado o fallido', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->queue(new HttpResponse(200, [], 'ok'), new HttpResponse(200, [], 'ok'));
    $hooks = $app->service('erp')->webhooks();
    $hooks->emit('order.paid', static function (): array {
        return ['order' => ['number' => 'A']];
    });
    $hooks->sweep();
    $id = (int) $app->db()->value('SELECT id FROM {erp_deliveries}');
    same('sent', $hooks->resend($id));
    same(2, count($app->http()->requests));
    same($app->http()->requests[0]->body, $app->http()->requests[1]->body);
    same('missing', $hooks->resend(9999));
    // Pausada o con los avisos apagados, no se reenvía.
    $app->db()->update('erp_webhooks', ['paused' => 1], ['connection_id' => $c['id']]);
    same('blocked', $hooks->resend($id));
});

test('crear una suscripción con 50 pedidos antiguos no envía ninguno', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $c = erpConnection($app);
    // Cincuenta pedidos que ya existían (de hace un mes) y tres de verdad cobrados antes de suscribirse.
    erpOrderRows($app, 50, '2026-10-12 10:00:00');
    $real = [erpOrder($app), erpOrder($app), erpOrder($app)];
    $app->clock()->advance(3600);
    $sub = erpSubscribe($app, $c['id'], ['order.created', 'order.paid', 'order.status_changed', 'order.refunded', 'invoice.issued', 'customer.created']);
    same('2026-11-12 13:00:00', $app->service('erp')->webhooks()->find($c['id'])['since_at']);
    // Aunque algo vuelva a lanzar los ganchos de esos pedidos (una importación, una tarea que los toque), ninguno avisa: pasaron antes.
    foreach ($app->db()->select("SELECT id FROM {orders} WHERE number LIKE 'T-%'") as $row) {
        $app->hooks()->fire('order.placed', $app->service('orders')->find((int) $row['id']));
    }
    foreach ($real as $id) {
        $order = $app->service('orders')->find($id);
        $app->hooks()->fire('order.placed', $order);
        $app->hooks()->fire('order.paid', $order);
        foreach ($app->service('invoices')->forOrder($id) as $invoice) {
            $app->hooks()->fire('invoice.issued', $invoice);
        }
    }
    $app->service('erp')->webhooks()->sweep();
    $app->service('jobs')->work(1);
    $app->service('jobs')->cron();
    same(0, aifecOrderCount($app, 'erp_deliveries'));
    same(0, count($app->http()->requests));
    // Lo que ocurre después sí avisa.
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    erpOrder($app);
    check(aifecOrderCount($app, 'erp_deliveries') > 0, 'lo nuevo sí avisa');
});

test('con los avisos apagados no sale nada: se apartan y se ven en el panel; al encenderlos salen', function (): void {
    list($app, $c, $sub) = erpHooked(['order.paid'], false);
    erpOrder($app);
    $app->service('erp')->webhooks()->sweep();
    $app->service('jobs')->work(2);
    same(0, count($app->http()->requests));
    same('held', (string) $app->db()->value('SELECT status FROM {erp_deliveries}'));
    same(null, $app->db()->value('SELECT next_attempt_at FROM {erp_deliveries}'));
    // «Probar» y «Reenviar» tampoco salen.
    $test = $app->service('erp')->webhooks()->test((int) $sub['id']);
    same(false, $test['ok']);
    same(0, count($app->http()->requests));
    $web = adminClient($app);
    contains('order.paid', $web->get('/admin/erp/connections/' . $c['id'])->body);
    // Se encienden: la pasada suelta lo apartado reciente y lo envía.
    $app->settings()->set('erp.outbound', true);
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    $app->service('erp')->webhooks()->sweep();
    same(1, count($app->http()->requests));
    same('sent', (string) $app->db()->value('SELECT status FROM {erp_deliveries}'));
});

test('lo apartado con más de 24 horas caduca y no se envía solo', function (): void {
    list($app, $c, $sub) = erpHooked(['order.paid'], false);
    erpOrder($app);
    $app->clock()->advance(86400 + 60);
    $app->settings()->set('erp.outbound', true);
    $r = $app->service('erp')->webhooks()->sweep();
    same(1, $r['expired']);
    same(0, count($app->http()->requests));
    same('expired', (string) $app->db()->value('SELECT status FROM {erp_deliveries}'));
    // A mano sí se puede reenviar.
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    same('sent', $app->service('erp')->webhooks()->resend((int) $app->db()->value('SELECT id FROM {erp_deliveries}')));
});

test('cada suscripción recibe solo los eventos que pidió', function (): void {
    $app = erpApp(['settings' => ['erp.outbound' => true]]);
    $a = erpConnection($app, null, 'A');
    $b = erpConnection($app, null, 'B');
    erpSubscribe($app, $a['id'], ['order.paid'], 'https://a.test/hook');
    erpSubscribe($app, $b['id'], ['product.stock_changed'], 'https://b.test/hook');
    $app->http()->queue(new HttpResponse(200, [], 'ok'), new HttpResponse(200, [], 'ok'), new HttpResponse(200, [], 'ok'));
    $id = aifecProduct($app, ['name' => 'Taza', 'sku' => 'TZ', 'stock' => 10, 'price' => 500]);
    $app->service('catalog')->adjustStock($id, 0, -2, 'prueba');
    $app->service('erp')->webhooks()->sweep();
    same(1, count($app->http()->requests));
    same('https://b.test/hook', $app->http()->requests[0]->url);
    $payload = json_decode((string) $app->http()->requests[0]->body, true);
    same('product.stock_changed', $payload['event']);
    same('TZ', $payload['data']['sku']);
    same(8, $payload['data']['stock']);
});

test('los hechos de pedidos, devoluciones, facturas y clientes salen con su evento', function (): void {
    list($app, $c, $sub) = erpHooked(['order.created', 'order.paid', 'order.status_changed', 'order.refunded', 'invoice.issued', 'customer.created']);
    $id = erpOrder($app);
    $app->service('orders')->setStatus($id, 'shipped', '', null, false);
    $order = $app->service('orders')->find($id);
    $app->service('orders')->refund($id, 500, [], 'Rotura', false, false);
    $app->service('customers')->register(['email' => 'alta@cliente.test', 'password' => 'clave-larga-1234', 'first_name' => 'Alta']);
    $events = $app->db()->column('SELECT event FROM {erp_deliveries} ORDER BY id');
    foreach (['order.created', 'order.paid', 'order.status_changed', 'order.refunded', 'invoice.issued', 'customer.created'] as $event) {
        check(in_array($event, $events, true), 'sale ' . $event . ' (salió: ' . implode(',', $events) . ')');
    }
    $status = json_decode((string) $app->db()->value("SELECT payload FROM {erp_deliveries} WHERE event = 'order.status_changed' ORDER BY id DESC LIMIT 1"), true);
    same('processing', $status['data']['from']);
    same('shipped', $status['data']['to']);
    same((string) $order['number'], $status['data']['order']['number']);
    $refund = json_decode((string) $app->db()->value("SELECT payload FROM {erp_deliveries} WHERE event = 'order.refunded'"), true);
    same(500, $refund['data']['refund']['amount']);
    $customer = json_decode((string) $app->db()->value("SELECT payload FROM {erp_deliveries} WHERE event = 'customer.created'"), true);
    same('alta@cliente.test', $customer['data']['customer']['email']);
    notContains('password', (string) $app->db()->value("SELECT payload FROM {erp_deliveries} WHERE event = 'customer.created'"));
});

test('la dirección de un aviso tiene que ser https y no apuntar a una red interna', function (): void {
    $app = erpApp();
    $hooks = $app->service('erp')->webhooks();
    check($hooks->urlProblem('https://erp.example.com/hooks/aifec') === null, 'una dirección normal vale');
    foreach (['http://erp.example.com/hook', 'ftp://erp.example.com', 'https://localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook',
        'https://192.168.1.20/hook', 'https://' . 'usuario' . ':' . 'x' . '@erp.example.com/hook', 'https://erp.local/hook', 'esto no es una dirección'] as $url) {
        check($hooks->urlProblem($url) !== null, 'no vale: ' . $url);
    }
    $c = erpConnection($app);
    throws(static function () use ($app, $c): void {
        $app->service('erp')->webhooks()->save($c['id'], 'http://erp.example.com/hook', ['order.paid'], true);
    }, \InvalidArgumentException::class);
    throws(static function () use ($app, $c): void {
        $app->service('erp')->webhooks()->save($c['id'], 'https://erp.example.com/hook', [], true);
    }, \InvalidArgumentException::class);
    throws(static function () use ($app, $c): void {
        $app->service('erp')->webhooks()->save($c['id'], 'https://erp.example.com/hook', ['no.existe'], true);
    }, \InvalidArgumentException::class);
});

test('«Probar» manda un aviso de prueba una sola vez y su fallo no cuenta para la pausa', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->queue(new HttpResponse(200, [], 'ok'), new HttpResponse(500, [], 'no'));
    $ok = $app->service('erp')->webhooks()->test((int) $sub['id']);
    same(true, $ok['ok']);
    same(200, $ok['status']);
    $bad = $app->service('erp')->webhooks()->test((int) $sub['id']);
    same(false, $bad['ok']);
    same(500, $bad['status']);
    same(0, $app->service('erp')->webhooks()->find($c['id'])['failures']);
    same(2, count($app->http()->requests));
    same('webhook.test', json_decode((string) $app->http()->requests[0]->body, true)['event']);
    // Un aviso de prueba que falla no se reintenta.
    same(0, $app->service('erp')->webhooks()->sweep()['sent']);
    same(2, count($app->http()->requests));
});

test('en modo silencioso (una migración) no se avisa de nada', function (): void {
    list($app, $c, $sub) = erpHooked();
    $before = $app->silence(true);
    try {
        erpOrder($app);
    } finally {
        $app->silence($before);
    }
    same(0, aifecOrderCount($app, 'erp_deliveries'));
});

test('el aviso lo envía el trabajo en segundo plano «erp.deliver»', function (): void {
    list($app, $c, $sub) = erpHooked();
    $app->http()->queue(new HttpResponse(200, [], 'ok'));
    erpOrder($app);
    same('pending', (string) $app->db()->value('SELECT status FROM {erp_deliveries} ORDER BY id LIMIT 1'));
    check(aifecOrderCount($app, 'jobs', "type = 'erp.deliver'") >= 1, 'hay un trabajo encolado');
    $app->service('jobs')->work(2);
    same('sent', (string) $app->db()->value('SELECT status FROM {erp_deliveries} ORDER BY id LIMIT 1'));
    same(0, aifecOrderCount($app, 'jobs', "status = 'failed'"));
});
