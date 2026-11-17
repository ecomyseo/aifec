<?php
/**
 * AIFEC — pruebas de los pedidos y las descargas en la zona de cliente de la tienda: solo los del cliente que ha entrado,
 * los hechos como invitado ocultos hasta verificar el correo, paginación, detalle y enlaces de descarga.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/CustomersKit.php';
require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!function_exists('customersFrontOrder')) {
    /**
     * Un pedido escrito directamente en la base de datos (pagado y completado, sin líneas). Devuelve su id.
     *
     * @param array<string,mixed> $extra columnas que pisan las de serie
     */
    function customersFrontOrder(App $app, int $customerId, string $number, string $placed, array $extra = []): int
    {
        $now = $app->clock()->sql();

        return $app->db()->insert('orders', $extra + [
            'number' => $number, 'customer_id' => $customerId, 'email' => 'ana@cliente.test', 'status' => 'completed',
            'payment_status' => 'paid', 'items_total' => 1000, 'total' => 1000, 'placed_at' => $placed, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Inicia sesión en la tienda con la cuenta de pruebas (la contraseña es «clave-larga-1»). */
    function customersFrontOrdersLogin(App $app, string $email = 'ana@cliente.test'): TestClient
    {
        $web = new TestClient($app);
        same(303, $web->post('/mi-cuenta/entrar', ['email' => $email, 'password' => 'clave-larga-1'])->status, 'entrar con ' . $email);

        return $web;
    }

    /** Los números de pedido que enlaza una página, en orden. */
    function customersFrontOrderLinks(string $html): array
    {
        preg_match_all('#href="/mi-cuenta/pedidos/([^"?]+)"#', $html, $m);

        return $m[1];
    }
}

test('pedidos: cada cliente ve los suyos y los de otro dan 404', function (): void {
    $app = aifecOrderApp();
    $taza = aifecProduct($app, ['name' => 'Taza', 'price' => 1500]);
    $ana = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersMakeAccount($app, 'luis@cliente.test', 'clave-larga-1');
    $orders = $app->service('orders');
    $mine = aifecOrderPaid($app, new TestClient($app), [[$taza, 0, 1]], ['email' => 'ana@cliente.test']);
    $his = aifecOrderPaid($app, new TestClient($app), [[$taza, 0, 2]], ['email' => 'luis@cliente.test']);
    $myNumber = (string) $orders->find($mine)['number'];
    $hisNumber = (string) $orders->find($his)['number'];
    same($ana, $orders->find($mine)['customer_id'], 'el pedido es de su cuenta');

    $web = customersFrontOrdersLogin($app, 'ana@cliente.test');
    $list = $web->get('/mi-cuenta/pedidos');
    same(200, $list->status);
    same([$myNumber], customersFrontOrderLinks($list->body));
    notContains($hisNumber, $list->body);
    same(200, $web->get('/mi-cuenta/pedidos/' . $myNumber)->status);
    same(404, $web->get('/mi-cuenta/pedidos/' . $hisNumber)->status, 'el de otro cliente no existe para ella');
    same(404, $web->get('/mi-cuenta/pedidos/NO-EXISTE-1')->status);
    contains('/mi-cuenta/pedidos', $web->get('/mi-cuenta')->body, 'el menú enseña los pedidos');

    $luis = customersFrontOrdersLogin($app, 'luis@cliente.test');
    same([$hisNumber], customersFrontOrderLinks($luis->get('/mi-cuenta/pedidos')->body));
    same(404, $luis->get('/mi-cuenta/pedidos/' . $myNumber)->status);
});

test('pedido: el detalle enseña líneas, totales, direcciones, seguimiento y los mensajes públicos, todo escapado', function (): void {
    $app = aifecOrderApp();
    $cafe = aifecProduct($app, ['name' => 'Café & Té <b>', 'price' => 1000, 'sku' => 'CAFE']);
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $address = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'company' => 'Ruiz & <u>Hijos</u>', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678'];
    $id = aifecOrderPaid($app, new TestClient($app), [[$cafe, 0, 2]], ['email' => 'ana@cliente.test', 'billing' => $address, 'note' => 'Llamar al timbre & esperar']);
    $orders = $app->service('orders');
    $orders->addNote($id, 'Sale mañana & <b>sin falta</b>', true);
    $orders->addNote($id, 'Nota interna que no se ve', false);
    $app->db()->update('orders', ['tracking_code' => 'SEG<1>23'], ['id' => $id]);
    $order = $orders->find($id);

    $web = customersFrontOrdersLogin($app);
    $r = $web->get('/mi-cuenta/pedidos/' . $order['number']);
    same(200, $r->status);
    $body = $r->body;
    contains('Pedido ' . $order['number'], $body);
    contains('Café &amp; Té &lt;b&gt;', $body);
    notContains('Café & Té', $body);
    contains('Ruiz &amp; &lt;u&gt;Hijos&lt;/u&gt;', $body);
    contains('Calle Mayor 1', $body);
    contains('28001 Madrid', $body);
    contains('España', $body);
    contains('Llamar al timbre &amp; esperar', $body);
    contains('Sale mañana &amp; &lt;b&gt;sin falta&lt;/b&gt;', $body);
    notContains('Nota interna', $body);
    contains('SEG&lt;1&gt;23', $body);
    contains($app->i18n()->money((int) $order['total'], 'EUR'), $body);
    contains($app->i18n()->money(2000, 'EUR'), $body, 'la línea: 2 unidades de 10 €');
    contains('Dirección de facturación', $body);
    same(1, substr_count($body, '<h1'));
    contains('<meta name="robots" content="noindex, nofollow">', $body);
    contains('no-store', $r->headers['Cache-Control']);
});

test('pedidos: el listado se pagina de diez en diez y la portada enseña los cinco últimos', function (): void {
    $app = aifecOrderApp();
    $ana = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $luis = customersMakeAccount($app, 'luis@cliente.test', 'clave-larga-1');
    for ($i = 1; $i <= 12; ++$i) {
        customersFrontOrder($app, $ana, sprintf('T-%03d', $i), '2026-11-01 10:00:' . sprintf('%02d', $i));
    }
    customersFrontOrder($app, $luis, 'L-001', '2026-11-02 10:00:00');
    $web = customersFrontOrdersLogin($app);

    $one = $web->get('/mi-cuenta/pedidos');
    $numbers = customersFrontOrderLinks($one->body);
    same(10, count($numbers));
    same('T-012', $numbers[0], 'los más recientes primero');
    same('T-003', $numbers[9]);
    contains('rel="next"', $one->body);
    notContains('rel="prev"', $one->body);
    $two = $web->get('/mi-cuenta/pedidos?page=2');
    same(['T-002', 'T-001'], customersFrontOrderLinks($two->body));
    contains('rel="prev"', $two->body);
    same(['T-002', 'T-001'], customersFrontOrderLinks($web->get('/mi-cuenta/pedidos?page=99')->body), 'una página de más cae en la última');
    same(10, count(customersFrontOrderLinks($web->get('/mi-cuenta/pedidos?page=-3')->body)));
    same(10, count(customersFrontOrderLinks($web->get('/mi-cuenta/pedidos?page=abc')->body)));
    notContains('L-001', $one->body . $two->body);

    $home = $web->get('/mi-cuenta');
    same(['T-012', 'T-011', 'T-010', 'T-009', 'T-008'], customersFrontOrderLinks($home->body));
    contains('Ver todos mis pedidos', $home->body);
});

test('pedidos: una cuenta sin pedidos lo dice, y una cuenta migrada sin verificar no ve ninguno hasta confirmar el correo', function (): void {
    $app = aifecOrderApp();
    $ana = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = customersFrontOrdersLogin($app);
    contains('Todavía no tienes pedidos', $web->get('/mi-cuenta/pedidos')->body);

    $old = customersMakeAccount($app, 'vieja@cliente.test', 'clave-larga-1', ['email_verified_at' => null]);
    customersFrontOrder($app, $old, 'M-001', '2026-01-01 10:00:00', ['email' => 'vieja@cliente.test']);
    $migrated = customersFrontOrdersLogin($app, 'vieja@cliente.test');
    $r = $migrated->get('/mi-cuenta/pedidos');
    same([], customersFrontOrderLinks($r->body), 'sin ficha de registro no se enseña ninguno');
    contains('1 pedido anterior está oculto', $r->body);
    same(404, $migrated->get('/mi-cuenta/pedidos/M-001')->status);

    $app->db()->update('customers', ['email_verified_at' => $app->clock()->sql()], ['id' => $old]);
    same(['M-001'], customersFrontOrderLinks($migrated->get('/mi-cuenta/pedidos')->body));
    same(200, $migrated->get('/mi-cuenta/pedidos/M-001')->status);
});

test('pedidos hechos como invitado: tras registrarse quedan ocultos hasta verificar el correo; los nuevos se ven', function (): void {
    $app = aifecOrderApp();
    $taza = aifecProduct($app, ['name' => 'Taza', 'price' => 1500]);
    $orders = $app->service('orders');
    $guestId = aifecOrderPaid($app, new TestClient($app), [[$taza, 0, 1]], ['email' => 'ana@cliente.test']);
    $guestNumber = (string) $orders->find($guestId)['number'];
    $app->clock()->advance(7200);

    $web = new TestClient($app);
    $r = $web->post('/mi-cuenta/registro', ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'accept_privacy' => 'on']);
    same(303, $r->status);
    $list = $web->get('/mi-cuenta/pedidos');
    same(200, $list->status);
    notContains($guestNumber, $list->body, 'el pedido del invitado no se enseña');
    contains('1 pedido anterior está oculto hasta que confirmes tu correo', $list->body);
    contains('1 pedido anterior aparecerá cuando confirmes tu correo', $web->get('/mi-cuenta')->body);
    same(404, $web->get('/mi-cuenta/pedidos/' . $guestNumber)->status, 'ni su ficha');

    $newId = aifecOrderPaid($app, new TestClient($app), [[$taza, 0, 2]], ['email' => 'ana@cliente.test']);
    $newNumber = (string) $orders->find($newId)['number'];
    same([$newNumber], customersFrontOrderLinks($web->get('/mi-cuenta/pedidos')->body), 'el hecho después del registro sí');
    same(200, $web->get('/mi-cuenta/pedidos/' . $newNumber)->status);

    $welcome = aifecOrderMails($app, 'customer_welcome');
    same(1, count($welcome));
    check(preg_match('#/mi-cuenta/verificar/([A-Za-z0-9_-]{20,})#', (string) $welcome[0]['html'], $m) === 1, 'la bienvenida lleva el enlace de verificación');
    same(303, $web->get('/mi-cuenta/verificar/' . $m[1])->status);

    $after = $web->get('/mi-cuenta/pedidos');
    same([$newNumber, $guestNumber], customersFrontOrderLinks($after->body), 'verificado, salen los dos');
    notContains('oculto', $after->body);
    same(200, $web->get('/mi-cuenta/pedidos/' . $guestNumber)->status);
});

test('descargas: solo los ficheros de pedidos pagados y el botón da un enlace nuevo que anula el anterior', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersMakeAccount($app, 'luis@cliente.test', 'clave-larga-1');
    $orders = $app->service('orders');
    $paid = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]], ['email' => 'ana@cliente.test']);
    $paidNumber = (string) $orders->find($paid)['number'];
    $oldLink = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    check(preg_match('#/descarga/([a-f0-9]{48})#', $oldLink, $m) === 1, 'el correo lleva el enlace');
    $oldToken = $m[1];

    // Un pedido sin pagar con la descarga ya concedida (a mano): no se enseña.
    $placed = aifecOrderPlace($app, new TestClient($app), [[$guia, 0, 1]], ['email' => 'ana@cliente.test']);
    check(!empty($placed['ok']), 'se crea el pedido sin pagar');
    $pendingNumber = (string) $orders->find((int) $placed['id'])['number'];
    $orders->downloads()->grant($orders->find((int) $placed['id']));
    $pendingDownload = (int) $app->db()->value('SELECT id FROM {order_downloads} WHERE order_id = ?', [(int) $placed['id']]);
    $download = (int) $app->db()->value('SELECT id FROM {order_downloads} WHERE order_id = ?', [$paid]);
    check($pendingDownload > 0 && $download > 0 && $pendingDownload !== $download, 'hay dos descargas');

    $web = customersFrontOrdersLogin($app);
    $page = $web->get('/mi-cuenta/descargas');
    same(200, $page->status);
    contains('guia.txt', $page->body);
    contains('/mi-cuenta/pedidos/' . $paidNumber, $page->body);
    notContains($pendingNumber, $page->body, 'el pedido sin pagar no aparece');
    contains('/mi-cuenta/descargas/' . $download . '/enlace', $page->body);
    notContains('/mi-cuenta/descargas/' . $pendingDownload . '/enlace', $page->body);
    contains('guia.txt', $web->get('/mi-cuenta/pedidos/' . $paidNumber)->body, 'el pedido pagado también las enseña');
    notContains('guia.txt', $web->get('/mi-cuenta/pedidos/' . $pendingNumber)->body, 'el pendiente no');

    same(404, $web->post('/mi-cuenta/descargas/' . $pendingDownload . '/enlace')->status, 'una descarga de un pedido sin pagar no se da');
    same(404, customersFrontOrdersLogin($app, 'luis@cliente.test')->post('/mi-cuenta/descargas/' . $download . '/enlace')->status, 'la de otro cliente tampoco');
    same(404, $web->post('/mi-cuenta/descargas/999999/enlace')->status);

    $r = $web->post('/mi-cuenta/descargas/' . $download . '/enlace');
    same(303, $r->status);
    check(preg_match('#^https://tienda\.test/descarga/([a-f0-9]{48})$#', $r->headers['Location'], $n) === 1, 'manda al enlace de descarga');
    check($n[1] !== $oldToken, 'es un enlace nuevo');
    $file = $web->follow();
    same(200, $file->status);
    check($file->file !== null && is_file($file->file), 'se sirve el fichero');
    same('Contenido de guia.txt', (string) file_get_contents($file->file));
    same(404, (new TestClient($app))->get('/descarga/' . $oldToken)->status, 'el enlace anterior ya no vale');
});

test('descargas: sin descargas, con el plazo vencido o con las descargas agotadas no hay botón y el envío da 404', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = customersFrontOrdersLogin($app);
    contains('Todavía no tienes nada que descargar', $web->get('/mi-cuenta/descargas')->body);

    $paid = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]], ['email' => 'ana@cliente.test']);
    $download = (int) $app->db()->value('SELECT id FROM {order_downloads} WHERE order_id = ?', [$paid]);
    contains('/mi-cuenta/descargas/' . $download . '/enlace', $web->get('/mi-cuenta/descargas')->body);

    $app->db()->update('order_downloads', ['max_downloads' => 1, 'downloads' => 1], ['id' => $download]);
    $page = $web->get('/mi-cuenta/descargas');
    contains('No quedan descargas', $page->body);
    notContains('/enlace', $page->body);
    same(404, $web->post('/mi-cuenta/descargas/' . $download . '/enlace')->status);

    $app->db()->update('order_downloads', ['max_downloads' => 0, 'downloads' => 0, 'expires_at' => '2026-11-01 00:00:00'], ['id' => $download]);
    $page = $web->get('/mi-cuenta/descargas');
    contains('El plazo de descarga ha terminado', $page->body);
    notContains('/enlace', $page->body);
    same(404, $web->post('/mi-cuenta/descargas/' . $download . '/enlace')->status);
});

test('las páginas de pedidos y descargas: noindex, no-store, un solo h1 y sin sesión mandan a entrar', function (): void {
    $app = aifecOrderApp();
    $ana = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersFrontOrder($app, $ana, 'T-001', '2026-11-01 10:00:00');
    $anon = new TestClient($app);
    foreach (['/mi-cuenta/pedidos', '/mi-cuenta/pedidos/T-001', '/mi-cuenta/descargas'] as $path) {
        $r = $anon->get($path);
        same(302, $r->status, $path);
        same('/mi-cuenta/entrar?next=' . rawurlencode($path), $r->headers['Location'], $path);
    }
    $web = customersFrontOrdersLogin($app);
    foreach (['/mi-cuenta/pedidos', '/mi-cuenta/pedidos/T-001', '/mi-cuenta/descargas', '/mi-cuenta'] as $path) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        contains('<meta name="robots" content="noindex, nofollow">', $r->body, $path);
        contains('no-store', $r->headers['Cache-Control'], $path);
        same(1, substr_count($r->body, '<h1'), 'un solo h1 en ' . $path);
    }
    same(403, $web->post('/mi-cuenta/descargas/1/enlace', [], ['sec-fetch-site' => 'cross-site'])->status);
});
