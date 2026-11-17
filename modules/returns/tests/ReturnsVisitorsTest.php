<?php
/**
 * AIFEC — pruebas de lo que encontraron los visitantes en las devoluciones (P106): el desistimiento de un pedido pagado y
 * sin enviar, el enlace a devolver desde la página del pedido y desde «Mis pedidos», que la página pública no enseñe el
 * aviso que es del panel y que el título de /desistimiento no cambie al enviar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** El título de la página (lo que hay dentro de la etiqueta title). */
function visitorsTitle(string $html): string
{
    check(preg_match('#<title>([^<]*)</title>#', $html, $m) === 1, 'la página lleva su título');

    return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

test('un pedido pagado y sin enviar: la página del pedido deja desistir, lo dice sin fechas y al enviarlo cuenta qué pasa después', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $web = new TestClient($app);
    $path = returnsPath($app, $id);

    $page = $web->get($path);
    same(200, $page->status);
    contains('data-af-return-form', $page->body, 'el formulario sale (antes: «todavía no se ha enviado»)');
    contains('Tu pedido todavía no se ha enviado. Puedes desistir de la compra ahora', $page->body);
    contains('<option value="withdrawal"', $page->body);
    notContains('Podrás pedir una devolución cuando llegue.', $page->body, 'ya no se cierra la puerta');
    notContains('You can ask for a return once it arrives.', $page->body);
    notContains('<option value="not_fit"', $page->body, 'los motivos de una devolución corriente no se ofrecen: no ha llegado');

    $sent = $web->post($path, ['lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'withdrawal']);
    same(303, $sent->status);
    $done = $web->follow();
    contains('Hemos registrado tu desistimiento D-', $done->body);
    contains('intentaremos pararlo', $done->body, 'dice qué pasa después');
    same(1, aifecOrderCount($app, 'returns'));
    $row = $app->service('returns')->find(1);
    same('withdrawal', $row['kind']);
    same('requested', $row['status'], 'queda pedida para que la tienda la atienda');

    // Una devolución corriente de lo que no ha llegado sigue sin valer.
    $other = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $bad = $web->post(returnsPath($app, $other), ['lines' => [returnsItemId($app, $other) => '1'], 'reason' => 'not_fit']);
    same(422, $bad->status);
    contains('todavía no se ha enviado', $bad->body);
});

test('el desistimiento de un pedido sin enviar también se pide por el formulario público de número y correo', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);
    $found = $web->post('/desistimiento', ['number' => $number, 'email' => 'ana@cliente.test']);
    same(200, $found->status);
    contains('data-af-return-form', $found->body);
    $r = $web->post('/desistimiento/enviar', ['number' => $number, 'email' => 'ana@cliente.test', 'lines' => [returnsItemId($app, $id) => '1'], 'reason' => 'withdrawal']);
    same(200, $r->status);
    contains('Hemos registrado tu desistimiento', $r->body);
});

test('el título de /desistimiento es el mismo al entrar, al buscar el pedido y al enviar', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);
    $entry = visitorsTitle($web->get('/desistimiento')->body);
    contains('Derecho de desistimiento', $entry);
    $lookup = visitorsTitle($web->post('/desistimiento', ['number' => $number, 'email' => 'ana@cliente.test'])->body);
    same($entry, $lookup, 'al buscar el pedido el título no cambia');
    $bad = visitorsTitle($web->post('/desistimiento/enviar', ['number' => $number, 'email' => 'ana@cliente.test', 'lines' => [], 'reason' => 'withdrawal'])->body);
    same($entry, $bad, 'ni al enviar con un fallo');
});

test('la página pública no enseña el aviso del texto modelo, ni con el texto de serie ni en las exclusiones', function (): void {
    $app = returnsApp();
    $web = new TestClient($app);
    $page = $web->get('/desistimiento')->body;
    contains('Tienes derecho a desistir', $page, 'el texto de serie sigue');
    notContains('TEXTO MODELO', $page);
    notContains('MODEL TEXT', $page);
    notContains('Sustitúyelo en Ajustes', $page);

    $p = aifecProduct($app, ['name' => 'Taza con nombre', 'price' => 1500, 'stock' => 50, 'extra' => ['no_withdrawal' => ['label' => 'No desistimiento', 'value' => 1, 'type' => 'toggle', 'public' => false]]]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $request = $web->get(returnsPath($app, $id))->body;
    contains('excluido', $request);
    notContains('TEXTO MODELO', $request);
    notContains('MODEL TEXT', $request);
});

test('de la página del pedido y de «Mis pedidos» se llega a devolver o desistir; un pedido sin pagar o cancelado no lleva el enlace', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]], ['state' => 'processing']);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);
    $secret = $app->service('orders')->viewTokenOf($number);

    // La página del pedido enlaza con devolver o desistir por la tarjeta «Devoluciones» (y ya sale en un pedido sin enviar).
    $thanks = $web->get('/pedido/' . $number . '/' . $secret);
    same(200, $thanks->status);
    contains('data-af-returns-card', $thanks->body);
    contains('Desistir de la compra', $thanks->body);
    contains('href="/devolucion/' . $number . '/' . $secret . '"', $thanks->body);

    $unpaid = returnsOrder($app, [[$p, 0, 1]], ['state' => 'pending_payment']);
    $unpaidNumber = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$unpaid]);
    notContains('data-af-returns-card', $web->get('/pedido/' . $unpaidNumber . '/' . $app->service('orders')->viewTokenOf($unpaidNumber))->body, 'sin pagar no hay nada que devolver');

    // Con cuenta: el pedido de la cuenta enseña el enlace en el listado y en el detalle.
    // (el pedido de invitado ya creó la ficha de ese correo: se convierte en una cuenta con contraseña)
    $customer = (int) $app->db()->value('SELECT id FROM {customers} WHERE email = ?', ['ana@cliente.test']);
    check($customer > 0, 'el pedido dejó la ficha del cliente');
    $app->db()->update('customers', ['password' => \Aifec\Security\Passwords::hash('clave-larga-1234'), 'guest' => 0, 'active' => 1, 'email_verified_at' => $app->clock()->sql()], ['id' => $customer]);
    $app->db()->update('orders', ['customer_id' => $customer], ['id' => $id]);
    $app->db()->update('orders', ['customer_id' => $customer], ['id' => $unpaid]);
    $account = new TestClient($app);
    aifecCartRun($account, static function ($cart, $app): ?int {
        return $app->service('customers')->login('ana@cliente.test', 'clave-larga-1234');
    });
    $list = $account->get(route('account.orders'));
    same(200, $list->status);
    contains('data-af-order-return', $list->body, 'el listado enlaza');
    contains('/mi-cuenta/devoluciones/' . $number, $list->body);
    same(1, substr_count($list->body, 'data-af-order-return'), 'solo el pedido pagado lleva el enlace, no el que está sin pagar');
    $detail = $account->get(route('account.order', ['number' => $number]));
    same(200, $detail->status);
    contains('data-af-order-return', $detail->body, 'el detalle enlaza');
    contains('/mi-cuenta/devoluciones/' . $number, $detail->body);
    $go = $account->get('/mi-cuenta/devoluciones/' . $number);
    same(200, $go->status, 'y el enlace abre la página de devoluciones del pedido');
});
