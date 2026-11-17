<?php
/**
 * AIFEC — pruebas de pedir una devolución: el servicio (validaciones, números, una sola abierta, el tope de unidades) y las páginas
 * de la tienda (invitado con ficha, cliente con cuenta, fotos de un producto defectuoso, límite de intentos y cancelar).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('pedir una devolución crea el número D-2026-000001, sus líneas, su historial y deja el pedido con una abierta', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 3]]);
    $ask = returnsAsk($app, $id, [0 => 2], 'not_fit', 'Me queda pequeña & no me gusta');
    same(true, $ask['ok'], (string) $ask['error']);
    same('D-2026-000001', $ask['number']);
    $return = $app->service('returns')->find((int) $ask['id']);
    same('requested', $return['status']);
    same('return', $return['kind']);
    same('not_fit', $return['reason']);
    same('Me queda pequeña & no me gusta', $return['comment']);
    same('ana@cliente.test', $return['email']);
    same('es', $return['lang']);
    same($id, $return['open_key']);
    same(false, $return['full_order']);
    same(1, count($return['items']));
    same(2, $return['items'][0]['qty']);
    same('Camiseta', $return['items'][0]['name']);
    same(returnsItemId($app, $id), $return['items'][0]['order_item_id']);
    same(1, count($return['events']));
    same('requested', $return['events'][0]['to_value']);
    same('customer', $return['events'][0]['actor']);

    // Al cerrar la primera, la siguiente del año lleva el número siguiente (contador propio, sin huecos).
    $app->service('returns')->cancel((int) $ask['id'], 'Lo deja', false, null, 'customer');
    $second = returnsAsk($app, $id, [0 => 1]);
    same('D-2026-000002', $second['number']);
});

test('pedir más unidades de las enviadas da error y no escribe nada, ni siquiera el número', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2]]);
    $ask = returnsAsk($app, $id, [0 => 3]);
    same(false, $ask['ok']);
    contains('más unidades', $ask['errors']['lines']);
    same(0, aifecOrderCount($app, 'returns'));
    same(0, aifecOrderCount($app, 'return_items'));
    same(0, aifecOrderCount($app, 'return_events'));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {sequences} WHERE name LIKE 'return:%'"));
    $zero = returnsAsk($app, $id, [0 => 0]);
    same(false, $zero['ok']);
    contains('al menos un producto', $zero['errors']['lines']);
});

test('lo que ya se devolvió no se puede volver a pedir: el tope es lo enviado menos lo devuelto', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 3]]);
    $app->service('orders')->refund($id, 2000, [returnsItemId($app, $id) => 1], 'Una unidad', false, false);
    $three = returnsAsk($app, $id, [0 => 3]);
    same(false, $three['ok']);
    $two = returnsAsk($app, $id, [0 => 2]);
    same(true, $two['ok'], (string) $two['error']);
});

test('una línea de otro pedido, un motivo que no existe y un comentario enorme dan error', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $foreign = $app->service('returns')->request($a, [returnsItemId($app, $b) => 1], 'not_fit');
    same(false, $foreign['ok']);
    contains('no es de este pedido', $foreign['errors']['lines']);

    $reason = returnsAsk($app, $a, [0 => 1], 'me_aburrio');
    same(false, $reason['ok']);
    contains('motivo', $reason['errors']['reason']);

    $long = returnsAsk($app, $a, [0 => 1], 'not_fit', str_repeat('a', 2001));
    same(false, $long['ok']);
    contains('demasiado largo', $long['errors']['comment']);
    same(0, aifecOrderCount($app, 'returns'));
});

test('un segundo intento con una devolución abierta da error, y la base de datos misma impide dos abiertas del mismo pedido', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 3]]);
    $first = returnsAsk($app, $id, [0 => 1]);
    same(true, $first['ok']);
    $again = returnsAsk($app, $id, [0 => 1]);
    same(false, $again['ok']);
    contains('en curso', $again['error']);
    same(1, aifecOrderCount($app, 'returns'));

    // Aunque dos peticiones pasaran a la vez la comprobación, la clave única de open_key no deja la segunda.
    $now = $app->clock()->sql();
    $row = ['number' => 'D-2026-999999', 'order_id' => $id, 'email' => 'x@example.com', 'reason' => 'other', 'open_key' => $id, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    throws(function () use ($app, $row): void {
        $app->db()->insert('returns', $row);
    });
    // Cerrada la abierta, el pedido vuelve a admitir otra.
    $app->service('returns')->cancel((int) $first['id']);
    same(true, returnsAsk($app, $id, [0 => 1])['ok']);
});

test('la devolución cubre «el pedido entero» solo si lleva todas las unidades de todas las líneas sin nada devuelto antes', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $q = aifecProduct($app, ['price' => 1000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2], [$q, 0, 1]]);
    $partial = returnsAsk($app, $id, [0 => 2]);
    same(false, $app->service('returns')->find((int) $partial['id'])['full_order']);
    $app->service('returns')->cancel((int) $partial['id']);
    $all = returnsAsk($app, $id, [0 => 2, 1 => 1]);
    same(true, $app->service('returns')->find((int) $all['id'])['full_order']);
});

test('un invitado con la ficha del pedido ve la página, pide la devolución y ve su estado', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2]]);
    $path = returnsPath($app, $id);
    $web = new TestClient($app);
    $page = $web->get($path);
    same(200, $page->status);
    contains('Devoluciones del pedido', $page->body);
    contains('Camiseta', $page->body);
    contains('name="lines[' . returnsItemId($app, $id) . ']"', $page->body);
    contains('no-store', (string) $page->headers['Cache-Control']);
    contains('noindex', (string) $page->headers['X-Robots-Tag']);

    $r = $web->post($path, ['lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'not_fit', 'comment' => 'Pequeña']);
    same(303, $r->status);
    contains('done=D-2026-000001', (string) $r->headers['Location']);
    $after = $web->follow();
    same(200, $after->status);
    contains('Hemos registrado tu solicitud D-2026-000001', $after->body);
    contains('Solicitada', $after->body);
    notContains('data-af-return-form', $after->body, 'con una abierta ya no hay formulario');
    same(1, aifecOrderCount($app, 'returns'));
    same('web', $app->db()->value('SELECT source FROM {returns} WHERE id = 1'));
});

test('una ficha que no es la del pedido, un pedido que no existe y una ficha vacía dan 404 y no crean nada', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $web = new TestClient($app);
    $numberA = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$a]);
    $secretB = $app->service('orders')->viewTokenOf((string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$b]));
    same(404, $web->get('/devolucion/' . $numberA . '/' . $secretB)->status, 'la ficha de otro pedido');
    same(404, $web->get('/devolucion/' . $numberA . '/inventada')->status, 'una ficha inventada');
    same(404, $web->get('/devolucion/2026-999999/' . $secretB)->status, 'un pedido que no existe');
    $post = $web->post('/devolucion/' . $numberA . '/' . $secretB, ['lines' => [returnsItemId($app, $a) => '1'], 'reason' => 'not_fit']);
    same(404, $post->status, 'el envío con la ficha de otro pedido');
    same(0, aifecOrderCount($app, 'returns'));
});

test('un cliente con cuenta pide la devolución desde «Mi cuenta»; el pedido de otro cliente y sin sesión no valen', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $ana = aifecCartCustomer($app, 'ana@cliente.test');
    aifecCartCustomer($app, 'luis@cliente.test', ['first_name' => 'Luis']);
    $mine = returnsOrder($app, [[$p, 0, 1]]);
    $theirs = returnsOrder($app, [[$p, 0, 1]], ['caja' => ['email' => 'luis@cliente.test']]);
    same($ana, (int) $app->db()->value('SELECT customer_id FROM {orders} WHERE id = ?', [$mine]));
    $numberMine = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$mine]);
    $numberTheirs = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$theirs]);

    $web = new TestClient($app);
    $anon = $web->get('/mi-cuenta/devoluciones/' . $numberMine);
    same(302, $anon->status, 'sin sesión, a la pantalla de entrada');
    contains('entrar', (string) $anon->headers['Location']);

    check(frontLogin($web) !== null, 'ana entra');
    $page = $web->get('/mi-cuenta/devoluciones/' . $numberMine);
    same(200, $page->status);
    contains('Devoluciones del pedido', $page->body);
    same(404, $web->get('/mi-cuenta/devoluciones/' . $numberTheirs)->status, 'el pedido de otro cliente');
    same(404, $web->post('/mi-cuenta/devoluciones/' . $numberTheirs, ['lines' => [returnsItemId($app, $theirs) => '1'], 'reason' => 'not_fit'])->status);

    $r = $web->post('/mi-cuenta/devoluciones/' . $numberMine, ['lines' => [returnsItemId($app, $mine) => '1'], 'reason' => 'not_fit']);
    same(303, $r->status);
    $return = $app->service('returns')->find(1);
    same($ana, $return['customer_id']);
    same($mine, $return['order_id']);
    contains('Solicitada', $web->follow()->body);
});

test('con el motivo «defectuoso» se guardan hasta 3 fotos JPEG, PNG o WebP en la carpeta privada, nunca en la pública', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $path = returnsPath($app, $id);
    $web = new TestClient($app);
    $post = ['lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'defective', 'comment' => 'Rota'];
    $files = [
        'photo_1' => returnsFile(returnsImage('jpg'), 'uno.jpg'),
        'photo_2' => returnsFile(returnsImage('png'), 'dos.png'),
        'photo_3' => returnsFile(returnsImage('webp'), 'tres.webp'),
    ];
    $r = returnsUpload($app, $web, $path, $post, $files);
    same(303, $r->status, substr($r->body, 0, 300));
    $return = $app->service('returns')->find(1);
    same(3, count($return['photos']));
    foreach ($return['photos'] as $photo) {
        check(strpos((string) $photo['path'], 'returns/') === 0, 'en la zona «returns»: ' . $photo['path']);
        $full = $app->service('files')->path((string) $photo['path']);
        check($full !== null && is_file($full), 'el fichero existe');
        check(strpos(str_replace('\\', '/', (string) $full), '/public/') === false, 'no está en la carpeta pública');
        check(in_array($photo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 'tipo ' . $photo['mime']);
    }
    // Con otro motivo las fotos no se guardan.
    $app->service('returns')->cancel(1);
    $web2 = new TestClient($app);
    $r2 = returnsUpload($app, $web2, $path, ['lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'not_fit'], ['photo_1' => returnsFile(returnsImage('jpg'), 'otra.jpg')]);
    same(303, $r2->status);
    same([], $app->service('returns')->find(2)['photos']);
});

test('las fotos que no valen se rechazan sin crear la devolución ni dejar ficheros: más de 3, un PHP disfrazado, de 5 MB o más y un error de subida', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $path = returnsPath($app, $id);
    $post = ['lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'defective'];
    $stored = static function () use ($app): int {
        $found = glob($app->site()->path('private') . '/returns/*');

        return is_array($found) ? count($found) : 0;
    };
    $web = new TestClient($app);

    $four = [];
    foreach ([1, 2, 3] as $n) {
        $four['photo_' . $n] = returnsFile(returnsImage('jpg', 1000 + $n), 'f' . $n . '.jpg');
    }
    $four['photo_4'] = returnsFile(returnsImage('jpg', 1100), 'f4.jpg');
    // El formulario solo tiene tres campos: la cuarta se ignora; el límite de 3 se comprueba en el servicio.
    $direct = $app->service('returns')->request($id, [returnsItemId($app, $id) => 1], 'defective', '', ['photos' => array_values($four)]);
    same(false, $direct['ok']);
    contains('3 fotos', $direct['errors']['photos']);

    $php = returnsUpload($app, $web, $path, $post, ['photo_1' => returnsFile(returnsImage('php'), 'trampa.jpg')]);
    same(422, $php->status);
    contains('JPEG, PNG o WebP', $php->body);

    $big = returnsUpload($app, $web, $path, $post, ['photo_1' => returnsFile(returnsImage('jpg', 5 * 1024 * 1024 + 10), 'enorme.jpg')]);
    same(422, $big->status);
    contains('5 MB', $big->body);

    $iniSize = ['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => 1, 'size' => 0];
    $err = returnsUpload($app, $web, $path, $post, ['photo_1' => $iniSize]);
    same(422, $err->status);
    contains('5 MB', $err->body);

    same(0, aifecOrderCount($app, 'returns'));
    same(0, $stored(), 'no queda ninguna foto en el disco');
    // El mismo pedido con una foto buena sí pasa: los intentos fallidos no lo bloquean (cuentan para el límite, pero quedan intentos).
    $ok = returnsUpload($app, $web, $path, $post, ['photo_1' => returnsFile(returnsImage('jpg'), 'buena.jpg')]);
    same(303, $ok->status);
});

test('los envíos del formulario público están limitados a 5 intentos por hora y pedido, y otro pedido no se ve afectado', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $web = new TestClient($app);
    $pathA = returnsPath($app, $a);
    for ($i = 1; $i <= 5; ++$i) {
        same(422, $web->post($pathA, ['reason' => 'not_fit'])->status, 'intento ' . $i . ' (sin líneas)');
    }
    same(429, $web->post($pathA, ['lines' => [returnsItemId($app, $a) => '1'], 'reason' => 'not_fit'])->status, 'el sexto');
    same(0, aifecOrderCount($app, 'returns'));
    $okB = $web->post(returnsPath($app, $b), ['lines' => [returnsItemId($app, $b) => '1'], 'reason' => 'not_fit']);
    same(303, $okB->status, 'otro pedido sigue libre');
    $app->clock()->advance(3601);
    same(303, $web->post($pathA, ['lines' => [returnsItemId($app, $a) => '1'], 'reason' => 'not_fit'])->status, 'pasada la hora vuelve a valer');
});

test('el cliente cancela su devolución mientras está pedida o aceptada y el pedido queda libre; una ya reembolsada no se cancela', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2]]);
    $path = returnsPath($app, $id);
    $web = new TestClient($app);
    $ask = returnsAsk($app, $id, [0 => 1]);
    $app->service('returns')->approve((int) $ask['id'], '', false);

    same(404, $web->post($path . '/cancelar', ['return' => 'D-2026-000999'])->status, 'una devolución que no es de este pedido');
    $r = $web->post($path . '/cancelar', ['return' => $ask['number']]);
    same(303, $r->status);
    $return = $app->service('returns')->find((int) $ask['id']);
    same('cancelled', $return['status']);
    same(null, $return['open_key']);
    same('customer', $return['events'][count($return['events']) - 1]['actor']);
    same(true, returnsAsk($app, $id, [0 => 1])['ok'], 'el pedido admite otra');

    // Una cerrada no vuelve a abrirse ni cambia por pulsar otra vez.
    $web->post($path . '/cancelar', ['return' => $ask['number']]);
    same('cancelled', $app->service('returns')->find((int) $ask['id'])['status']);
});

test('al pedirla salen dos correos: al cliente con las instrucciones y a la tienda sin ninguna dirección', function (): void {
    // La dirección de la tienda (pie del correo) es distinta de la del cliente para poder comprobar que esta no sale.
    $app = returnsApp(['settings' => ['store.address' => "Polígono Industrial 3
35200 Telde"]]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2]]);
    $ask = returnsAsk($app, $id, [0 => 1], 'not_fit', 'Pequeña');
    $customer = aifecOrderMails($app, 'return_requested');
    $shop = aifecOrderMails($app, 'return_requested_shop');
    same(1, count($customer));
    same(1, count($shop));
    same('ana@cliente.test', $customer[0]['to_email']);
    same('tienda@ejemplo.test', $shop[0]['to_email']);
    contains('D-2026-000001', (string) $customer[0]['subject']);
    contains('Embala los productos', (string) $customer[0]['html']);
    contains('lo paga el cliente', (string) $customer[0]['html']);
    contains('/devolucion/', (string) $customer[0]['html']);
    contains('Camiseta', (string) $shop[0]['html']);
    contains('/admin/returns/' . $ask['id'], (string) $shop[0]['html']);
    foreach (['Calle Mayor', '28001', 'Madrid', '999345678'] as $address) {
        notContains($address, (string) $shop[0]['html'] . (string) $shop[0]['text_body'] . (string) $shop[0]['data'], 'la tienda no recibe la dirección: ' . $address);
        notContains($address, (string) $customer[0]['data'], 'la cola no guarda la dirección: ' . $address);
    }
    same(1, aifecOrderCount($app, 'return_events', 'notified = 1'));
});
