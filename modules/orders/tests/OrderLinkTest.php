<?php
/**
 * AIFEC — el enlace para ver un pedido sin cuenta: tiene que poder montarse también después de la petición que creó el
 * pedido (el correo de «enviado», la ficha del panel). La ficha en claro no se guarda: se calcula con la clave del sitio
 * y el número, y en la tabla solo queda su resumen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\OrderMails;
use Aifec\Modules\Orders\Orders;

require_once __DIR__ . '/helpers.php';

test('la ficha de un pedido sale de la clave del sitio y del número, y en la tabla solo queda su resumen', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 12100, 'stock' => 5]);
    $web = new TestClient($app);
    $placed = aifecOrderPlace($app, $web, [[$p, 0, 1]]);
    check(!empty($placed['ok']), 'el pedido se crea');

    $number = (string) $placed['number'];
    same(48, strlen((string) $placed['secret']));
    same(Orders::viewToken($app, $number), $placed['secret'], 'la ficha se puede volver a calcular');
    $stored = (string) $app->db()->value('SELECT secret FROM {orders} WHERE id = ?', [(int) $placed['id']]);
    same(hash('sha256', (string) $placed['secret']), $stored, 'en la tabla está el resumen');
    notContains((string) $placed['secret'], $stored, 'nunca la ficha en claro');

    $orders = $app->service('orders');
    same($placed['secret'], $orders->viewTokenOf($number));
    check($orders->findBySecret($number, (string) $placed['secret']) !== null, 'con ella se ve el pedido');
    same(null, $orders->findBySecret($number, Orders::viewToken($app, $number . '9')), 'la ficha de otro número no vale');
    check(Orders::viewToken($app, $number) !== Orders::viewToken($app, $number . '9'), 'cada pedido tiene la suya');
});

test('el enlace del pedido se puede montar en una petición posterior: correos y panel', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 12100, 'stock' => 5]);
    $web = new TestClient($app);
    $id = aifecOrderPaid($app, $web, [[$p, 0, 1]]);
    $order = $app->service('orders')->find($id);
    $number = (string) $order['number'];

    if (!$app->router()->has('order.thanks')) {
        same('', (new OrderMails($app))->orderUrl($order), 'sin la ruta de la tienda no hay enlace');

        return;
    }
    $url = (new OrderMails($app))->orderUrl($order);
    contains('/' . $number . '/' . Orders::viewToken($app, $number), $url, 'sin pasarle la ficha, el enlace la lleva');
    $path = (string) parse_url($url, PHP_URL_PATH);
    same(200, (new TestClient($app))->get($path)->status, 'y el enlace abre el pedido a quien no tiene cuenta');

    // El correo de «enviado» sale en otra petición, mucho después: también lleva el enlace.
    $app->service('orders')->setStatus($id, 'shipped');
    $mails = aifecOrderMails($app, 'order_shipped');
    check(count($mails) >= 1, 'sale el correo de enviado');
    contains(Orders::viewToken($app, $number), (string) $mails[0]['html'], 'con el enlace para ver el pedido');
});

test('un pedido cuya ficha no es la del sitio (traído de otra tienda) no tiene enlace, y no se inventa', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 12100, 'stock' => 5]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    $app->db()->update('orders', ['secret' => hash('sha256', 'otra-ficha-de-otra-tienda')], ['id' => $id]);
    $order = $app->service('orders')->find($id);
    same('', $app->service('orders')->viewTokenOf((string) $order['number']));
    same('', (new OrderMails($app))->orderUrl($order));
});
