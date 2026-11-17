<?php
/**
 * AIFEC — pruebas de las descargas de lo digital comprado: concesión al pagar, ficha que solo se guarda como resumen,
 * ruta de descarga, límite, caducidad, fichas inventadas y enlaces nuevos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Un pedido digital pagado con un fichero real. Devuelve [app, producto, id del pedido, ficha en claro del enlace, navegador].
 *
 * @param array<string,mixed> $settings
 *
 * @return array{0:App,1:int,2:int,3:string,4:TestClient}
 */
function orderDownloadsPaid(array $settings = []): array
{
    $app = aifecOrderApp(['settings' => $settings]);
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    $id = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]], ['billing' => ['first_name' => 'Ana', 'country' => 'ES']]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    check(count($mails) === 1, 'hay un correo de confirmación');
    $token = orderDownloadsToken((string) $mails[0]['html']);

    return [$app, $guia, $id, $token, new TestClient($app)];
}

/** La ficha en claro que lleva el enlace de descarga de un correo. */
function orderDownloadsToken(string $html): string
{
    check(preg_match('#/descarga/([a-f0-9]{48})#', $html, $m) === 1, 'el correo lleva el enlace de descarga');

    return $m[1];
}

test('al pagar un pedido solo digital se concede la descarga, el pedido se completa y la ficha solo se guarda como resumen', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid();
    $orders = $app->service('orders');
    $order = $orders->find($id);

    same('completed', $order['status']);
    same('not_needed', $order['fulfillment_status']);
    same($app->clock()->sql(), $order['completed_at']);
    $rows = $app->db()->select('SELECT * FROM {order_downloads}');
    same(1, count($rows));
    same(hash('sha256', $token), $rows[0]['token']);
    check($rows[0]['token'] !== $token, 'la ficha en claro no se guarda');
    same(0, (int) $rows[0]['max_downloads']);
    same(null, $rows[0]['expires_at']);
    $list = $orders->downloads()->forOrder($id);
    same(1, count($list));
    same(['guia.txt', 'Guía', 0, null, true], [$list[0]['name'], $list[0]['item'], $list[0]['downloads'], $list[0]['remaining'], $list[0]['available']]);
    contains('Tus descargas', (string) aifecOrderMails($app, 'order_confirmed')[0]['html']);
    contains('https://tienda.test/descarga/' . $token, (string) aifecOrderMails($app, 'order_confirmed')[0]['html']);
});

test('GET /descarga/{ficha} entrega el fichero y cuenta la descarga', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid();
    $response = $web->get('/descarga/' . $token);

    same(200, $response->status);
    check($response->file !== null && is_file($response->file), 'se sirve un fichero de verdad');
    same('Contenido de guia.txt', (string) file_get_contents($response->file));
    contains('attachment; filename="guia.txt"', $response->headers['Content-Disposition']);
    contains('no-store', $response->headers['Cache-Control']);
    same(1, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
    same(1, $app->service('orders')->downloads()->forOrder($id)[0]['downloads']);
    same($app->clock()->sql(), $app->db()->value('SELECT last_at FROM {order_downloads}'));
});

test('el límite de descargas se respeta y la última deja el enlace agotado', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid(['orders.download_limit' => 2]);
    same(2, (int) $app->db()->value('SELECT max_downloads FROM {order_downloads}'));

    same(200, $web->get('/descarga/' . $token)->status);
    same(200, $web->get('/descarga/' . $token)->status);
    same(403, $web->get('/descarga/' . $token)->status);
    same(2, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
    $item = $app->service('orders')->downloads()->forOrder($id)[0];
    same([0, false], [$item['remaining'], $item['available']]);
});

test('el enlace caduca con el plazo del ajuste', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid(['orders.download_days' => 1]);
    same($app->clock()->sql($app->clock()->now() + 86400), $app->db()->value('SELECT expires_at FROM {order_downloads}'));

    same(200, $web->get('/descarga/' . $token)->status);
    $app->clock()->advance(2 * 86400);
    same(403, $web->get('/descarga/' . $token)->status);
    same(1, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
    $item = $app->service('orders')->downloads()->forOrder($id)[0];
    same([true, false], [$item['expired'], $item['available']]);
});

test('una ficha inventada o mal formada da 404 y no cuenta nada', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid();

    same(404, $web->get('/descarga/' . str_repeat('a', 48))->status);
    same(404, $web->get('/descarga/' . strtoupper(str_repeat('b', 47)) . 'z')->status);
    same(404, $web->get('/descarga/corta')->status);
    same(404, $web->get('/descarga/' . substr($token, 0, 47))->status);
    same(0, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
});

test('un pedido devuelto del todo ya no deja descargar', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid();
    $orders = $app->service('orders');
    same(200, $web->get('/descarga/' . $token)->status);

    $orders->refund($id, $orders->find($id)['total'], [], 'Devolución', false, false);
    same(403, $web->get('/descarga/' . $token)->status);
    same(1, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
    same(false, $orders->downloads()->forOrder($id)[0]['available']);
});

test('renew emite un enlace nuevo, el viejo deja de valer y un pedido ajeno no puede pedirlo', function (): void {
    list($app, $guia, $id, $token, $web) = orderDownloadsPaid();
    $downloads = $app->service('orders')->downloads();
    $downloadId = $downloads->forOrder($id)[0]['id'];

    same(null, $downloads->renew($downloadId, $id + 1));
    $url = $downloads->renew($downloadId, $id);
    check($url !== null && preg_match('#^https://tienda\.test/descarga/[a-f0-9]{48}$#', $url) === 1, 'el enlace nuevo tiene la forma de siempre');
    same(404, $web->get('/descarga/' . $token)->status);
    same(200, $web->get((string) preg_replace('#^https://tienda\.test#', '', (string) $url))->status);
});

test('una línea con varios ficheros concede uno por fichero y conceder otra vez no repite nada', function (): void {
    $app = aifecOrderApp();
    $curso = aifecProduct($app, ['name' => 'Curso', 'kind' => 'digital', 'price' => 3000, 'manage_stock' => 0]);
    aifecOrderFile($app, $curso, 'tema1.txt');
    aifecOrderFile($app, $curso, 'tema2.txt');
    $camiseta = aifecProduct($app, ['stock' => 5, 'weight' => 100]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$curso, 0, 2], [$camiseta, 0, 1]]);
    $orders = $app->service('orders');

    same(2, aifecOrderCount($app, 'order_downloads'));
    same(['tema1.txt', 'tema2.txt'], array_column($orders->downloads()->forOrder($id), 'name'));
    same('processing', $orders->find($id)['status'], 'con algo físico, el pedido se prepara');
    same([], $orders->downloads()->grant($orders->find($id)));
    same(2, aifecOrderCount($app, 'order_downloads'));
});

test('un fichero de una variante solo se concede a quien compra esa variante', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1000, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'general.txt');
    aifecOrderFile($app, $guia, 'solo-otra-variante.txt', 9999);
    $id = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]]);

    same(['general.txt'], array_column($app->service('orders')->downloads()->forOrder($id), 'name'));
});
