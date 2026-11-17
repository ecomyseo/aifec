<?php
/**
 * AIFEC — pruebas de la petición de opinión por correo: se anota al completar el pedido, la tarea manda lo que toca hoy y nada del
 * pasado, el modo del correo manda y el enlace del correo valora una sola vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/** @return array<int,array<string,mixed>> */
function reviewsRequests(App $app): array
{
    return $app->db()->select('SELECT * FROM {review_requests} ORDER BY id');
}

/**
 * Los enlaces de los correos de petición que hay en la cola: ficha en claro => HTML del correo.
 *
 * @return array<string,string>
 */
function reviewsMailLinks(App $app): array
{
    $out = [];
    foreach (aifecOrderMails($app, 'review_request') as $mail) {
        if (preg_match_all('#https://tienda\.test/valorar/([a-f0-9]{40})#', (string) $mail['html'], $m) > 0) {
            foreach ($m[1] as $token) {
                $out[$token] = (string) $mail['html'];
            }
        }
    }

    return $out;
}

test('al completarse un pedido se anota una petición por producto, para dentro de siete días, con la ficha solo en resumen', function (): void {
    $app = reviewsApp();
    $a = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 20]);
    $b = aifecProduct($app, ['name' => 'Pantalón', 'stock' => 20]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$a, 0, 2], [$b, 0, 1]]);
    same(0, count(reviewsRequests($app)), 'pagar no anota nada: hay que completarlo');

    $app->service('orders')->setStatus($id, 'completed', '', null, false);

    $rows = reviewsRequests($app);
    same(2, count($rows));
    same([$a, $b], array_map('intval', array_column($rows, 'product_id')));
    foreach ($rows as $row) {
        same('pending', $row['status']);
        same('ana@cliente.test', $row['email']);
        same($app->clock()->sql($app->clock()->now() + 7 * 86400), $row['send_at']);
        same(64, strlen((string) $row['token']));
        same(hash('sha256', \Aifec\Modules\Reviews\ReviewRequests::token($app, $id, (int) $row['product_id'])), $row['token'], 'solo el resumen de la ficha');
    }
    $app->service('orders')->setStatus($id, 'shipped', '', null, false);
    $app->service('orders')->setStatus($id, 'completed', '', null, false);
    same(2, count(reviewsRequests($app)), 'completarlo otra vez no duplica');
});

test('no se anota nada con las peticiones apagadas ni en un pedido sin cobrar; los días son los del ajuste', function (): void {
    $off = reviewsApp(['settings' => ['reviews.ask' => false]]);
    $p = aifecProduct($off);
    reviewsCompleted($off, $p);
    same(0, count(reviewsRequests($off)), 'apagadas');

    $app = reviewsApp(['settings' => ['reviews.ask_days' => 3]]);
    $q = aifecProduct($app, ['stock' => 20]);
    $unpaid = aifecOrderPlace($app, new TestClient($app), [[$q, 0, 1]], ['email' => 'sin-pagar@cliente.test']);
    $app->hooks()->fire('order.status', $app->service('orders')->find((int) $unpaid['id']), 'processing', 'completed');
    same(0, count(reviewsRequests($app)), 'pedido sin cobrar');

    $real = reviewsCompleted($app, $q, 'real@cliente.test');
    $rows = reviewsRequests($app);
    same(1, count($rows));
    same($app->clock()->sql($app->clock()->now() + 3 * 86400), $rows[0]['send_at'], 'tres días, como dice el ajuste');
    same($real, (int) $rows[0]['order_id']);
});

test('la tarea reviews.ask está declarada y manda el correo cuando toca, con un enlace por producto, y solo una vez', function (): void {
    $app = reviewsApp(['settings' => ['mail.mode' => 'live']]);
    $a = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 20]);
    $b = aifecProduct($app, ['name' => 'Pantalón', 'stock' => 20]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$a, 0, 1], [$b, 0, 1]]);
    $app->service('orders')->setStatus($id, 'completed', '', null, false);
    check(in_array('reviews.ask', array_column($app->service('jobs')->tasks(), 'task'), true), 'la tarea existe');

    $app->clock()->advance(6 * 86400);
    same(null, $app->service('jobs')->runTask('reviews.ask'), 'a los seis días todavía no toca');
    same(0, count(aifecOrderMails($app, 'review_request')));

    $app->clock()->advance(86400 + 3600);
    same('1 emails, 2 links', $app->service('jobs')->runTask('reviews.ask'));
    $mails = aifecOrderMails($app, 'review_request');
    same(1, count($mails), 'un correo por pedido');
    same('ana@cliente.test', $mails[0]['to_email']);
    same('pending', $mails[0]['status']);
    contains('Valorar Camiseta', $mails[0]['html']);
    contains('Valorar Pantalón', $mails[0]['html']);
    contains('Gracias por tu pedido', $mails[0]['html']);
    same(2, count(reviewsMailLinks($app)));
    same(['sent', 'sent'], array_column(reviewsRequests($app), 'status'));

    same(null, $app->service('jobs')->runTask('reviews.ask'), 'la segunda pasada no manda nada');
    same(1, count(aifecOrderMails($app, 'review_request')));
});

test('el modo del correo manda: con el correo retenido el mensaje queda retenido y no sale', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    reviewsCompleted($app, $p);
    $app->clock()->advance(7 * 86400 + 60);

    $app->service('jobs')->runTask('reviews.ask');

    $mails = aifecOrderMails($app, 'review_request');
    same(1, count($mails));
    same('held', $mails[0]['status'], 'mail.mode vale hold por defecto');
    same(0, (int) $app->service('mailer')->sendPending()['sent']);
});

test('nunca manda lo anterior: un pedido completado antes de que el módulo anotara nada, o que se pasó de su día, no recibe correo', function (): void {
    $app = reviewsApp(['settings' => ['mail.mode' => 'live']]);
    $p = aifecProduct($app, ['stock' => 20]);

    // Un pedido «antiguo»: se completa sin que el módulo se entere (como el histórico de una tienda que lo instala ahora).
    $old = reviewsPaidOrder($app, $p, 'viejo@cliente.test');
    $app->db()->update('orders', ['status' => 'completed', 'completed_at' => $app->clock()->sql($app->clock()->now() - 40 * 86400)], ['id' => $old]);
    same(0, count(reviewsRequests($app)), 'no hay filas del histórico');

    // Uno que sí se anota pero la tarea no corre en su día (sitio parado): al volver, ha caducado.
    $late = reviewsCompleted($app, $p, 'tarde@cliente.test');
    $app->clock()->advance(7 * 86400 + 2 * 86400);
    same(null, $app->service('jobs')->runTask('reviews.ask'));
    same(0, count(aifecOrderMails($app, 'review_request')));
    same(['expired'], array_column(reviewsRequests($app), 'status'));
    same($late, (int) reviewsRequests($app)[0]['order_id']);
});

test('con las peticiones apagadas la tarea no manda lo que ya estaba anotado; y no se pide la opinión de un producto que ya se valoró', function (): void {
    $app = reviewsApp(['settings' => ['mail.mode' => 'live']]);
    $a = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 20]);
    $b = aifecProduct($app, ['name' => 'Pantalón', 'stock' => 20]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$a, 0, 1], [$b, 0, 1]]);
    $app->service('orders')->setStatus($id, 'completed', '', null, false);
    $app->clock()->advance(7 * 86400 + 60);

    $app->settings()->set('reviews.ask', false);
    same(null, $app->service('jobs')->runTask('reviews.ask'));
    same(0, count(aifecOrderMails($app, 'review_request')));

    $app->settings()->set('reviews.ask', true);
    reviewsSubmit($app, $a);
    same('1 emails, 1 links', $app->service('jobs')->runTask('reviews.ask'));
    contains('Valorar Pantalón', aifecOrderMails($app, 'review_request')[0]['html']);
    notContains('Valorar Camiseta', aifecOrderMails($app, 'review_request')[0]['html']);
    same(['used', 'sent'], array_column(reviewsRequests($app), 'status'));
});

test('el enlace del correo abre el formulario del producto y valora una sola vez, como compra verificada', function (): void {
    $app = reviewsApp(['settings' => ['mail.mode' => 'live']]);
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $orderId = reviewsCompleted($app, $p);
    $app->clock()->advance(7 * 86400 + 60);
    $app->service('jobs')->runTask('reviews.ask');
    $links = array_keys(reviewsMailLinks($app));
    same(1, count($links));
    $token = $links[0];
    $web = new TestClient($app);

    $page = $web->get('/valorar/' . $token);
    same(200, $page->status);
    contains('Valorar Camiseta', $page->body);
    contains('value="Ana"', $page->body, 'el nombre sale rellenado');
    notContains('name="email"', $page->body, 'el correo no se pide: sale de la petición');
    contains('noindex', $page->body);

    $sent = $web->post('/valorar/' . $token, ['name' => 'Ana', 'rating' => '4', 'title' => 'Bien', 'body' => 'Buena camiseta, llegó a tiempo y como en la foto.', 'email' => 'otro@cliente.test']);
    same(302, $sent->status);
    $rows = reviewsRows($app);
    same(1, count($rows));
    same('ana@cliente.test', $rows[0]['email'], 'el correo es el del pedido aunque se mande otro');
    same(1, (int) $rows[0]['verified']);
    same($orderId, (int) $rows[0]['order_id']);
    same('used', reviewsRequests($app)[0]['status']);
    same((int) $rows[0]['id'], (int) reviewsRequests($app)[0]['review_id']);

    same(404, $web->get('/valorar/' . $token)->status, 'la ficha ya se gastó');
    $twice = $web->post('/valorar/' . $token, ['name' => 'Ana', 'rating' => '5', 'body' => 'Otra opinión con la misma ficha de un solo uso.']);
    same(404, $twice->status);
    same(1, count(reviewsRows($app)), 'no se valora dos veces');
});

test('un error al valorar desde el enlace no gasta la ficha; una ficha inventada o de otro tipo da 404', function (): void {
    $app = reviewsApp(['settings' => ['mail.mode' => 'live']]);
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    reviewsCompleted($app, $p);
    $app->clock()->advance(7 * 86400 + 60);
    $app->service('jobs')->runTask('reviews.ask');
    $token = array_keys(reviewsMailLinks($app))[0];
    $web = new TestClient($app);

    $bad = $web->post('/valorar/' . $token, ['name' => 'Ana', 'rating' => '5', 'body' => 'corta']);
    same(422, $bad->status);
    contains('Escribe al menos 10 caracteres.', $bad->body);
    same('sent', reviewsRequests($app)[0]['status'], 'la ficha sigue sin gastar');
    same(0, count(reviewsRows($app)));

    $ok = $web->post('/valorar/' . $token, ['name' => 'Ana', 'rating' => '5', 'body' => 'Ahora sí, un texto con la longitud suficiente.']);
    same(302, $ok->status);

    same(404, $web->get('/valorar/' . str_repeat('a', 40))->status);
    same(404, $web->get('/valorar/' . $token . 'x')->status);
    same(403, $web->post('/valorar/' . $token, ['rating' => '5'], ['sec-fetch-site' => 'cross-site'])->status);
});

test('al olvidar a un cliente sus opiniones quedan anónimas y se borran sus peticiones pendientes', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['stock' => 20]);
    $customer = aifecCartCustomer($app, 'ana@cliente.test');
    $id = reviewsSubmit($app, $p)['id'];
    same($customer, (int) reviewsRows($app)[0]['customer_id'], 'se enlaza con la cuenta del correo');
    $app->service('reviews')->publish($id);
    reviewsCompleted($app, $p, 'ana@cliente.test');
    check(count(reviewsRequests($app)) > 0, 'hay una petición anotada');

    $export = $app->hooks()->apply('customer.export', ['orders' => []], $customer);
    same(1, count($export['reviews']), 'sus opiniones entran en la copia de sus datos');
    same($p, $export['reviews'][0]['product_id']);
    same('ana@cliente.test', $export['reviews'][0]['email']);

    $app->hooks()->fire('customer.anonymised', $customer, 'ana@cliente.test');

    $row = reviewsRows($app)[0];
    same('Anonymous', $row['name']);
    same('deleted-' . $id . '@anonymous.invalid', $row['email']);
    same(null, $row['customer_id']);
    same('published', $row['status'], 'la opinión publicada se queda');
    same(0, count(reviewsRequests($app)));
});
