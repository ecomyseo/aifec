<?php
/**
 * AIFEC — pruebas de los eventos del servidor (protocolo de medición de GA4 y API de conversiones de Meta): interruptor apagado de
 * serie, solo con consentimiento, el mismo identificador que el del navegador, nunca pedidos antiguos y fallos que se reintentan o no.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpException;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Datalayer\Tasks;
use Aifec\Modules\Jobs\PermanentFailure;

require_once __DIR__ . '/helpers.php';

/** Una tienda con GA4 y Meta configurados para el servidor y el interruptor encendido. */
function dlServerApp(array $extra = []): \Aifec\Kernel\App
{
    $app = dlApp(['settings' => $extra + [
        'datalayer.server_events' => true, 'datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.meta_pixel' => '123456789012345', 'datalayer.meta_version' => 'v21.0',
    ]]);
    $app->setSecret('datalayer.ga4_api_secret', 'secreto-de-mentira-ga4');
    $app->setSecret('datalayer.meta_token', 'token-de-mentira-meta');

    return $app;
}

/** @return array<int,array<string,mixed>> */
function dlServerRows(\Aifec\Kernel\App $app, string $donde = '1 = 1'): array
{
    return $app->db()->select('SELECT * FROM {datalayer_events} WHERE ' . $donde . ' ORDER BY id', []);
}

function dlJobs(\Aifec\Kernel\App $app, string $donde = "type = 'datalayer.server_event'"): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {jobs} WHERE ' . $donde, []);
}

test('el interruptor nace apagado: pagar un pedido no encola nada ni manda nada', function (): void {
    $app = dlApp(['settings' => ['datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.meta_pixel' => '123456789012345']]);
    $app->setSecret('datalayer.ga4_api_secret', 'x');
    $app->setSecret('datalayer.meta_token', 'y');
    same(false, $app->service('datalayer')->config()->serverEvents(), 'de serie está apagado');
    dlOrder($app, dlWeb($app, 'sm'));
    same([], dlServerRows($app));
    same(0, dlJobs($app));
    same(0, count(dlHttp($app)->requests));
});

test('con el interruptor encendido y consentimiento, pagar encola un evento por servicio y los manda con el mismo identificador que el navegador', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $rows = dlServerRows($app);
    same(2, count($rows));
    same(['ga4', 'meta'], array_column($rows, 'destination'));
    same(['queued', 'queued'], array_column($rows, 'status'));
    same(2, dlJobs($app));
    $browser = dlEvent(dlEvents(dlThanks($web, $pedido)->body), 'purchase');
    foreach ($rows as $row) {
        same($browser['event_id'], $row['event_id'], 'mismo identificador que el del navegador');
    }
    $http = dlHttp($app);
    $http->queue(new HttpResponse(204), new HttpResponse(200, [], '{"events_received":1}'));
    same('sent', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app));
    same('sent', Tasks::serverEvent(['id' => (int) $rows[1]['id']], $app));
    $ga = $http->requests[0];
    contains('https://www.google-analytics.com/mp/collect?measurement_id=G-ABCDE12345&api_secret=', $ga->url);
    $body = json_decode((string) $ga->body, true);
    same('purchase', $body['events'][0]['name']);
    same($pedido['number'], $body['events'][0]['params']['transaction_id']);
    $order = $app->service('orders')->find($pedido['id']);
    $products = 0;
    foreach ($order['items'] as $row) {
        $products += $row['kind'] === 'product' ? (int) $row['line_net'] : 0;
    }
    same($products, dlCents($body['events'][0]['params']['value']), 'el importe es el del pedido');
    $meta = $http->requests[1];
    contains('https://graph.facebook.com/v21.0/123456789012345/events?access_token=', $meta->url);
    $data = json_decode((string) $meta->body, true)['data'][0];
    same('Purchase', $data['event_name']);
    same($browser['event_id'], $data['event_id'], 'Meta recibe el mismo identificador para no contar dos veces');
    same([hash('sha256', 'ana@cliente.test')], $data['user_data']['em']);
    notContains('ana@cliente.test', (string) $meta->body);
    same(['sent', 'sent'], array_column(dlServerRows($app), 'status'));
    same(['204'], array_unique(array_map('strval', array_slice(array_column(dlServerRows($app), 'http_status'), 0, 1))));
});

test('sin consentimiento no se manda nada: los eventos quedan como «omitidos» y no hay ninguna petición', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, ''));
    dlOrder($app, dlWeb($app));
    $rows = dlServerRows($app);
    same(4, count($rows));
    same(['skipped', 'skipped', 'skipped', 'skipped'], array_column($rows, 'status'));
    same('No consent', $rows[0]['response']);
    same(0, dlJobs($app));
    same(0, count(dlHttp($app)->requests));
});

test('con solo estadísticas se manda a Google Analytics y no a Meta (que pide marketing)', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, 's'));
    $rows = dlServerRows($app);
    same(['queued', 'skipped'], array_column($rows, 'status'));
    same(1, dlJobs($app));
    dlHttp($app)->queue(new HttpResponse(204));
    same('sent', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app));
    same(1, count(dlHttp($app)->requests));
});

test('encender el interruptor con cincuenta pedidos antiguos no manda ninguno', function (): void {
    $app = dlApp(['settings' => ['datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.meta_pixel' => '123456789012345']]);
    $app->setSecret('datalayer.ga4_api_secret', 'x');
    $app->setSecret('datalayer.meta_token', 'y');
    $web = dlWeb($app, 'sm');
    $a = aifecProduct($app, ['name' => 'Silla', 'price' => 2000, 'stock' => 1000]);
    // Veinte pedidos hechos y pagados con el interruptor apagado, y treinta que ya estaban en la base de datos antes del módulo.
    for ($i = 0; $i < 20; ++$i) {
        dlOrder($app, $web, true, [], [[$a, 0, 1]]);
    }
    $template = $app->db()->row('SELECT * FROM {orders} WHERE id = 1', []);
    for ($i = 0; $i < 30; ++$i) {
        $copy = $template;
        unset($copy['id']);
        $copy['number'] = 'OLD-' . $i;
        $copy['secret'] = 'old' . $i;
        $copy['legacy_id'] = 'L' . $i;
        $app->db()->insert('orders', $copy);
    }
    same(50, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}', []));
    $before = dlServerRows($app);
    same([], $before);
    // Se enciende el interruptor como lo haría el panel.
    $app->settings()->set('datalayer.server_events', true);
    $app->hooks()->fire('settings.saved', 'datalayer', ['datalayer.server_events'], $app);
    same([], dlServerRows($app), 'encender el interruptor no encola nada');
    same(0, dlJobs($app));
    Tasks::purge($app);
    $app->service('jobs')->work(5, 100);
    $app->service('jobs')->cron();
    same(0, count(dlHttp($app)->requests), 'ni una sola petición a un servicio de medición');
    same([], dlServerRows($app));
});

test('un pedido anterior al módulo (sin marca) no se manda aunque se pague con el interruptor encendido', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web, false);
    $app->db()->delete('datalayer_orders', ['order_id' => $pedido['id']]);
    $order = $app->service('orders')->find($pedido['id']);
    $app->service('orders')->markPaid($pedido['id'], 'gw', 'PAY-OLD', (int) $order['total']);
    same([], dlServerRows($app));
    same(0, dlJobs($app));
});

test('un pedido de pruebas de la pasarela no se manda', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web, false);
    $order = $app->service('orders')->find($pedido['id']);
    $app->service('orders')->markPaid($pedido['id'], 'gw', 'PAY-T', (int) $order['total'], [], true);
    same([], dlServerRows($app));
});

test('un evento con más de 24 horas no se manda: pasa a «omitido»', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, 'sm'));
    $rows = dlServerRows($app);
    $app->clock()->advance(25 * 3600);
    same('skipped', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app));
    same(0, count(dlHttp($app)->requests));
    same('Older than 24 hours', dlServerRows($app)[0]['response']);
});

test('si se apaga el interruptor antes de mandar, lo encolado no se manda', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, 'sm'));
    $rows = dlServerRows($app);
    $app->settings()->set('datalayer.server_events', false);
    same('skipped', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app));
    same(0, count(dlHttp($app)->requests));
});

test('un fallo de red o un 5xx se reintenta por la cola; un 4xx es definitivo; y el mismo evento no se manda dos veces', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, 'sm'));
    $rows = dlServerRows($app);
    $http = dlHttp($app);
    $http->queue(new HttpException('timeout'), new HttpResponse(503, [], 'caído'), new HttpResponse(204));
    throws(static function () use ($app, $rows): void {
        Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app);
    }, HttpException::class);
    throws(static function () use ($app, $rows): void {
        Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app);
    }, HttpException::class);
    same('queued', dlServerRows($app)[0]['status'], 'sigue en cola para el reintento');
    same('sent', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app));
    same('gone', Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app), 'ya enviado: no se manda otra vez');
    $http->queue(new HttpResponse(400, [], '{"error":"mala petición"}'));
    throws(static function () use ($app, $rows): void {
        Tasks::serverEvent(['id' => (int) $rows[1]['id']], $app);
    }, PermanentFailure::class);
    same('failed', dlServerRows($app)[1]['status']);
    same(400, (int) dlServerRows($app)[1]['http_status']);
    contains('mala petición', (string) dlServerRows($app)[1]['response']);
});

test('un reembolso se encola con su propio identificador y no se duplica', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $order = $app->service('orders')->find($pedido['id']);
    $refundId = $app->service('orders')->refund($pedido['id'], 500, [], 'Rotura', false, false);
    check($refundId > 0, 'se devuelve dinero');
    $rows = dlServerRows($app, "kind = 'refund'");
    same(2, count($rows));
    same('refund_' . $pedido['number'] . '_' . $refundId, $rows[0]['event_id']);
    $app->hooks()->fire('order.refunded', $app->service('orders')->find($pedido['id']), ['id' => $refundId, 'order_id' => $pedido['id'], 'amount' => 500, 'tax' => 0]);
    same(2, count(dlServerRows($app, "kind = 'refund'")), 'el mismo reembolso no se encola dos veces');
    $http = dlHttp($app);
    $http->queue(new HttpResponse(204));
    Tasks::serverEvent(['id' => (int) $rows[0]['id']], $app);
    $body = json_decode((string) $http->requests[0]->body, true);
    same('refund', $body['events'][0]['name']);
    same(500, dlCents($body['events'][0]['params']['value']), 'el importe devuelto, no el del pedido');
    same($pedido['number'], $body['events'][0]['params']['transaction_id']);
});

test('la tarea diaria cierra lo que lleva más de 24 horas en cola y borra el registro a los 90 días', function (): void {
    $app = dlServerApp();
    dlOrder($app, dlWeb($app, 'sm'));
    same(null, Tasks::purge($app), 'recién encolado no se toca');
    $app->clock()->advance(26 * 3600);
    same('2 closed, 0 deleted', Tasks::purge($app));
    same(['failed', 'failed'], array_column(dlServerRows($app), 'status'));
    $app->clock()->advance(91 * 86400);
    same('0 closed, 2 deleted', Tasks::purge($app));
    same([], dlServerRows($app));
    check(isset($app->modules()->definition('datalayer', 'jobs')['handlers']['datalayer.server_event']), 'el trabajo está declarado');
});

test('encender los eventos del servidor sin las claves no encola nada: faltan las credenciales', function (): void {
    $app = dlApp(['settings' => ['datalayer.server_events' => true, 'datalayer.ga4_id' => 'G-ABCDE12345']]);
    dlOrder($app, dlWeb($app, 'sm'));
    same([], dlServerRows($app));
});

test('una carga de histórico en modo silencioso no encola nada', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $before = $app->silence(true);
    try {
        $pedido = dlOrder($app, $web);
    } finally {
        $app->silence($before);
    }
    same([], dlServerRows($app));
    same(null, $app->db()->row('SELECT id FROM {datalayer_orders} WHERE order_id = ?', [$pedido['id']]), 'ni siquiera apunta marca');
});

test('el trabajo encolado se ejecuta de verdad por la cola de tareas: el manejador está conectado y manda el evento', function (): void {
    $app = dlServerApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $http = dlHttp($app);
    $http->queue(new HttpResponse(204), new HttpResponse(200, [], '{"events_received":1}'));
    $done = $app->service('jobs')->work(5, 50);
    check($done !== [], 'la cola trabajó');
    same(2, count($http->requests), 'se mandaron los dos eventos sin llamar a nadie a mano');
    same(['sent', 'sent'], array_column(dlServerRows($app), 'status'));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'datalayer.server_event' AND status <> 'done'", []), 'todos los trabajos acaban en hechos');
    check($pedido['id'] > 0);
});
