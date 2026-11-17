<?php
/**
 * AIFEC — pruebas de las valoraciones: enviar una opinión, validar, una por producto y correo, compra verificada y texto plano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('una opinión válida entra pendiente, sin compra verificada, y no sale en la lista pública ni toca la nota del producto', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $result = reviewsSubmit($app, $p);

    same(true, $result['ok']);
    same('pending', $result['status']);
    same(false, $result['verified']);
    $row = reviewsRows($app)[0];
    same('pending', $row['status']);
    same('ana@cliente.test', $row['email']);
    same(5, (int) $row['rating']);
    same(null, $row['published_at']);
    same(0, $app->service('reviews')->forProduct($p)['count']);
    same(null, $app->service('catalog')->product($p)['rating']);
});

test('se rechaza el texto corto, el largo, la nota fuera de rango, sin nombre y con un correo mal escrito, y no se guarda nada', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);

    $short = reviewsSubmit($app, $p, ['body' => 'Muy bien']);
    same(false, $short['ok']);
    check(isset($short['errors']['body']), 'el texto de 8 letras es corto');

    $long = reviewsSubmit($app, $p, ['body' => str_repeat('a', 2001)]);
    check(isset($long['errors']['body']), 'el texto de 2001 letras es largo');
    same(true, reviewsSubmit($app, $p, ['body' => str_repeat('é', 2000), 'email' => 'otra@cliente.test'])['ok'], '2000 caracteres justos valen (cuenta caracteres, no bytes)');

    foreach (['0', '6', '', 'cinco', '-1'] as $rating) {
        check(isset(reviewsSubmit($app, $p, ['rating' => $rating, 'email' => 'x' . md5($rating) . '@cliente.test'])['errors']['rating']), 'la nota «' . $rating . '» no vale');
    }
    check(isset(reviewsSubmit($app, $p, ['name' => '  '])['errors']['name']), 'sin nombre');
    check(isset(reviewsSubmit($app, $p, ['name' => str_repeat('n', 61)])['errors']['name']), 'nombre demasiado largo');
    foreach (['sin-arroba', 'a@', '', 'a b@cliente.test'] as $email) {
        check(isset(reviewsSubmit($app, $p, ['email' => $email])['errors']['email']), 'el correo «' . $email . '» no vale');
    }
    check(isset(reviewsSubmit($app, $p, ['title' => str_repeat('t', 101), 'email' => 'tt@cliente.test'])['errors']['title']), 'título largo');

    same(1, count(reviewsRows($app)), 'solo la de 2000 caracteres se guardó');
});

test('un producto que no existe o no está publicado no recibe opiniones', function (): void {
    $app = reviewsApp();
    $draft = aifecProduct($app, ['status' => 'draft']);

    same(false, reviewsSubmit($app, 99999)['ok']);
    same(false, reviewsSubmit($app, $draft)['ok']);
    same(0, count(reviewsRows($app)));
});

test('una sola opinión por producto y correo (sin mirar mayúsculas), pero el mismo correo puede valorar otro producto', function (): void {
    $app = reviewsApp();
    $a = aifecProduct($app, ['name' => 'Camiseta']);
    $b = aifecProduct($app, ['name' => 'Pantalón']);

    same(true, reviewsSubmit($app, $a)['ok']);
    $again = reviewsSubmit($app, $a, ['email' => 'ANA@Cliente.test']);
    same(false, $again['ok']);
    check(isset($again['errors']['email']), 'el error cae en el correo');
    same(true, reviewsSubmit($app, $b)['ok'], 'otro producto, mismo correo');
    same(2, count(reviewsRows($app)));
    same('ana@cliente.test', reviewsRows($app)[0]['email'], 'el correo se guarda en minúsculas');
});

test('es compra verificada con un pedido pagado de ese correo con ese producto, y no sin él', function (): void {
    $app = reviewsApp();
    $a = aifecProduct($app, ['name' => 'Camiseta']);
    $b = aifecProduct($app, ['name' => 'Pantalón']);
    $orderId = reviewsPaidOrder($app, $a, 'Ana@Cliente.test');

    $with = reviewsSubmit($app, $a);
    same(true, $with['verified']);
    same($orderId, (int) reviewsRows($app, 'id = ?', [$with['id']])[0]['order_id']);
    same(1, (int) reviewsRows($app, 'id = ?', [$with['id']])[0]['verified']);

    same(false, reviewsSubmit($app, $b)['verified'], 'compró la camiseta, no el pantalón');
    same(false, reviewsSubmit($app, $a, ['email' => 'otra@cliente.test'])['verified'], 'otro correo');
});

test('un pedido sin pagar o devuelto del todo no da compra verificada', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 50]);

    $unpaid = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['email' => 'sin-pagar@cliente.test']);
    check(!empty($unpaid['ok']), 'el pedido sin pagar se crea');
    same(false, reviewsSubmit($app, $p, ['email' => 'sin-pagar@cliente.test'])['verified'], 'sin pagar');

    $refunded = reviewsPaidOrder($app, $p, 'devuelto@cliente.test');
    $order = $app->service('orders')->find($refunded);
    $app->service('orders')->refund($refunded, (int) $order['total'], [], 'Devolución', false, false);
    same(false, reviewsSubmit($app, $p, ['email' => 'devuelto@cliente.test'])['verified'], 'devuelto del todo');
});

test('el texto con HTML se guarda como texto plano tal cual y no se interpreta; los controles raros se quitan', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $body = "Me ha gustado <script>alert(1)</script> & mucho.\r\n\r\n\r\n\r\nSegundo párrafo con ñ y 😀\x00\x07 fin.";

    $result = reviewsSubmit($app, $p, ['body' => $body, 'title' => "Bien <b>hecho</b>\n"]);
    same(true, $result['ok']);
    $row = reviewsRows($app)[0];
    contains('<script>alert(1)</script> & mucho.', $row['body']);
    contains("\n\nSegundo párrafo con ñ y 😀 fin.", $row['body']);
    notContains("\r", $row['body']);
    notContains("\x00", $row['body']);
    notContains("\n\n\n", $row['body']);
    same('Bien <b>hecho</b>', $row['title']);
});

test('con la publicación automática de verificadas, una verificada sale publicada y la que no lo es sigue pendiente', function (): void {
    $app = reviewsApp(['settings' => ['reviews.auto_publish_verified' => true]]);
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    reviewsPaidOrder($app, $p, 'ana@cliente.test');

    $verified = reviewsSubmit($app, $p);
    same('published', $verified['status']);
    $other = reviewsSubmit($app, $p, ['email' => 'otra@cliente.test', 'rating' => '3']);
    same('pending', $other['status']);

    same(1, $app->service('reviews')->forProduct($p)['count']);
    same(['average' => 5.0, 'count' => 1], $app->service('catalog')->product($p)['rating'], 'la media ya cuenta la verificada');
});
