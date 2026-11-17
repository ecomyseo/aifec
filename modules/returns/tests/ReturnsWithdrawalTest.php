<?php
/**
 * AIFEC — pruebas del desistimiento de 14 días: sin explicación, solo dentro de su plazo, productos excluidos por ley, la página
 * pública con el formulario legal (número de pedido y correo), el límite de intentos, la aceptación automática y el importe con o
 * sin el envío de ida.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el desistimiento no pide explicación: sin motivo escrito ni comentario se acepta', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $ask = returnsAsk($app, $id, [0 => 1], 'withdrawal');
    same(true, $ask['ok'], (string) $ask['error']);
    $return = $app->service('returns')->find((int) $ask['id']);
    same('withdrawal', $return['kind']);
    same('withdrawal', $return['reason']);
    same('', $return['comment']);
    // El correo al cliente habla de desistimiento, no de devolución.
    $mail = aifecOrderMails($app, 'return_requested')[0];
    contains('solicitud de desistimiento', (string) $mail['html']);
});

test('dentro del plazo el formulario dice con claridad que se puede desistir sin motivo, y pasado el plazo ya no lo ofrece', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $web = new TestClient($app);
    $in = returnsOrder($app, [[$p, 0, 1]], ['days' => 3]);
    $body = $web->get(returnsPath($app, $in))->body;
    contains('Desistimiento (no hace falta dar un motivo)', $body);
    contains('Puedes desistir de la compra hasta el', $body);
    contains('Si eliges el desistimiento, no hace falta que expliques nada.', $body);

    $out = returnsOrder($app, [[$p, 0, 1]], ['days' => 20]);
    $late = $web->get(returnsPath($app, $out))->body;
    notContains('Desistimiento (no hace falta dar un motivo)', $late);
    notContains('Puedes desistir de la compra hasta el', $late);
    contains('Puedes pedir una devolución hasta el', $late);
    contains('No me queda bien', $late);
});

test('el desistimiento fuera de su plazo da error aunque la devolución corriente siga abierta', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $late = returnsOrder($app, [[$p, 0, 1]], ['days' => 20]);
    $bad = returnsAsk($app, $late, [0 => 1], 'withdrawal');
    same(false, $bad['ok']);
    contains('plazo de desistimiento', $bad['errors']['reason']);
    same(true, returnsAsk($app, $late, [0 => 1], 'not_fit')['ok'], 'la devolución corriente sí');

    $gone = returnsOrder($app, [[$p, 0, 1]], ['days' => 40]);
    same(false, returnsAsk($app, $gone, [0 => 1], 'withdrawal')['ok']);
});

test('un producto excluido por ley no admite desistimiento y se dice por qué; los demás de la misma compra sí', function (): void {
    $app = returnsApp();
    $marked = aifecProduct($app, ['name' => 'Taza con nombre', 'price' => 1500, 'stock' => 50, 'extra' => ['no_withdrawal' => ['label' => 'No desistimiento', 'value' => 1, 'type' => 'toggle', 'public' => false]]]);
    $normal = aifecProduct($app, ['name' => 'Libro', 'price' => 1000, 'stock' => 50]);
    $id = returnsOrder($app, [[$marked, 0, 1], [$normal, 0, 1]]);

    $bad = returnsAsk($app, $id, [0 => 1, 1 => 1], 'withdrawal');
    same(false, $bad['ok']);
    contains('Taza con nombre', $bad['errors']['lines']);
    contains('excluido del derecho de desistimiento', $bad['errors']['lines']);
    same(0, aifecOrderCount($app, 'returns'));

    $page = (new TestClient($app))->get(returnsPath($app, $id))->body;
    contains('excluido del derecho de desistimiento (personalizado, perecedero o precintado por higiene)', $page);
    notContains('TEXTO MODELO', $page, 'el texto legal de las exclusiones es un modelo, pero el aviso de revisarlo es del panel');

    $good = returnsAsk($app, $id, [1 => 1], 'withdrawal');
    same(true, $good['ok'], (string) $good['error']);
});

test('la página pública /desistimiento enseña el texto modelo con su aviso y el formulario de número de pedido y correo', function (): void {
    $app = returnsApp();
    $web = new TestClient($app);
    $page = $web->get('/desistimiento?number=2026-000001');
    same(200, $page->status);
    contains('Derecho de desistimiento', $page->body);
    contains('Tienes derecho a desistir de este contrato en un plazo de 14 días', $page->body);
    $app->settings()->set('returns.days', 30);
    contains('acepta además devoluciones durante 30 días: los 14 días del desistimiento son el mínimo legal', (new TestClient($app))->get('/desistimiento')->body, 'con un plazo de devolución más largo, se explican los dos');
    contains('Corres con los gastos directos de devolución', $page->body, 'sin decir nada la tienda, lo de la ley');
    $app->settings()->set('returns.return_shipping_payer', 'La devolución es gratis: te mandamos la etiqueta & pasamos a recogerlo.');
    $paid = (new TestClient($app))->get('/desistimiento')->body;
    contains('te mandamos la etiqueta &amp; pasamos a recogerlo', $paid, 'quién paga la vuelta, como dice la tienda');
    notContains('Corres con los gastos', $paid, 'y no lo contrario');
    $app->settings()->set('returns.return_shipping_payer', '');
    notContains('TEXTO MODELO', $page->body, 'el aviso de revisar el texto es del panel, nunca de la página pública');
    notContains('Ajustes, Devoluciones, Textos legales', $page->body);
    contains('name="number" value="2026-000001"', $page->body);
    contains('name="email"', $page->body);
    contains('action="', $page->body);
    contains('no-store', (string) $page->headers['Cache-Control']);

    // El texto de la tienda sustituye al modelo, y con él desaparece el aviso del modelo.
    $app->settings()->set('returns.withdrawal_text', "Texto propio de la tienda sobre el desistimiento & las condiciones.\nSegunda línea.");
    $own = $web->get('/desistimiento')->body;
    contains('Texto propio de la tienda sobre el desistimiento &amp; las condiciones.', $own);
    contains('Segunda línea.', $own);
    notContains('Tienes derecho a desistir', $own);
});

test('con el número de pedido y el correo correctos sale el formulario del pedido; con otro correo o un pedido que no existe, el mismo aviso y ningún dato', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta secreta', 'price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);

    $ok = $web->post('/desistimiento', ['number' => $number, 'email' => 'ANA@cliente.test']);
    same(200, $ok->status);
    contains('Camiseta secreta', $ok->body);
    contains('name="number" value="' . $number . '"', $ok->body);
    contains('name="email" value="ANA@cliente.test"', $ok->body);
    contains('/desistimiento/enviar', $ok->body);
    notContains('28001', $ok->body, 'ni una dirección del cliente');
    notContains('999345678', $ok->body, 'ni su teléfono');

    $wrongEmail = $web->post('/desistimiento', ['number' => $number, 'email' => 'otra@cliente.test']);
    $unknown = $web->post('/desistimiento', ['number' => '2026-999999', 'email' => 'ana@cliente.test']);
    same(422, $wrongEmail->status);
    same(422, $unknown->status);
    contains('No hemos encontrado ningún pedido con esos datos.', $wrongEmail->body);
    contains('No hemos encontrado ningún pedido con esos datos.', $unknown->body);
    notContains('Camiseta secreta', $wrongEmail->body);
    foreach ([$wrongEmail->body, $unknown->body] as $body) {
        preg_match('#data-af-withdrawal-error>([^<]*)<#', $body, $m);
        same('No hemos encontrado ningún pedido con esos datos.', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
});

test('el envío del formulario público vuelve a comprobar número y correo: con los buenos crea el desistimiento; con otros, nada', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);
    $lines = [returnsItemId($app, $id) => '1'];

    $bad = $web->post('/desistimiento/enviar', ['number' => $number, 'email' => 'otra@cliente.test', 'lines' => $lines, 'reason' => 'withdrawal']);
    same(422, $bad->status);
    same(0, aifecOrderCount($app, 'returns'));

    $r = $web->post('/desistimiento/enviar', ['number' => $number, 'email' => 'ana@cliente.test', 'lines' => $lines, 'reason' => 'withdrawal']);
    same(200, $r->status);
    contains('Hemos registrado tu solicitud D-2026-000001', $r->body);
    same(1, aifecOrderCount($app, 'returns'));
    same('withdrawal', $app->service('returns')->find(1)['kind']);
    same(1, count(aifecOrderMails($app, 'return_requested')));
});

test('los intentos del formulario público están limitados: el sexto con el mismo pedido da 429', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    $web = new TestClient($app);
    for ($i = 1; $i <= 5; ++$i) {
        same(422, $web->post('/desistimiento', ['number' => $number, 'email' => 'prueba' . $i . '@cliente.test'])->status, 'intento ' . $i);
    }
    same(429, $web->post('/desistimiento', ['number' => $number, 'email' => 'ana@cliente.test'])->status, 'el sexto, aunque el correo sea el bueno');
    same(429, $web->post('/desistimiento', ['number' => strtolower($number), 'email' => 'ana@cliente.test'])->status, 'el mismo número escrito de otra forma cuenta igual');
});

test('con «aceptar los desistimientos automáticamente» se aceptan solos y salen sus dos correos; sin el ajuste quedan pedidos', function (): void {
    $app = returnsApp(['settings' => ['returns.auto_approve_withdrawal' => true]]);
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $a = returnsOrder($app, [[$p, 0, 1]]);
    $ask = returnsAsk($app, $a, [0 => 1], 'withdrawal');
    $return = $app->service('returns')->find((int) $ask['id']);
    same('approved', $return['status']);
    contains('Embala los productos', $return['instructions']);
    same(1, count(aifecOrderMails($app, 'return_requested')));
    same(1, count(aifecOrderMails($app, 'return_approved')));
    same('system', $return['events'][1]['actor']);

    // Una devolución corriente nunca se acepta sola.
    $b = returnsOrder($app, [[$p, 0, 1]]);
    $plain = returnsAsk($app, $b, [0 => 1], 'not_fit');
    same('requested', $app->service('returns')->find((int) $plain['id'])['status']);

    $off = returnsApp();
    $q = aifecProduct($off, ['price' => 2000, 'stock' => 50]);
    $c = returnsOrder($off, [[$q, 0, 1]]);
    $manual = returnsAsk($off, $c, [0 => 1], 'withdrawal');
    same('requested', $off->service('returns')->find((int) $manual['id'])['status']);
});

test('el importe del desistimiento lleva el envío de ida solo si es del pedido entero y el ajuste lo permite', function (): void {
    $app = returnsApp();
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 50]);
    $q = aifecProduct($app, ['price' => 1000, 'stock' => 50]);
    $id = returnsOrder($app, [[$p, 0, 2], [$q, 0, 1]]);
    $order = $app->service('orders')->find($id);
    check((int) $order['shipping_total'] > 0, 'el pedido lleva envío');
    $shipping = (int) $app->db()->value("SELECT line_net + line_tax FROM {order_items} WHERE order_id = ? AND kind = 'shipping'", [$id]);
    check($shipping > 0, 'la línea de envío tiene importe');
    $returns = $app->service('returns');

    // Entero: todas las unidades de todas las líneas.
    $all = returnsAsk($app, $id, [0 => 2, 1 => 1], 'withdrawal');
    $proposal = $returns->proposal($returns->find((int) $all['id']));
    same($shipping, $proposal['shipping']);
    same(5000, $proposal['subtotal'] - $proposal['shipping'], 'las líneas con sus impuestos');
    same((int) $order['total'], $proposal['total'], 'el pedido entero se devuelve entero');
    $returns->cancel((int) $all['id']);

    // Parcial: solo una línea, sin envío.
    $part = returnsAsk($app, $id, [0 => 2], 'withdrawal');
    $partial = $returns->proposal($returns->find((int) $part['id']));
    same(0, $partial['shipping']);
    same(4000, $partial['total']);
    $returns->cancel((int) $part['id']);

    // Todo el pedido pero como devolución corriente: el envío de ida no se devuelve.
    $plain = returnsAsk($app, $id, [0 => 2, 1 => 1], 'not_fit');
    same(0, $returns->proposal($returns->find((int) $plain['id']))['shipping']);
    $returns->cancel((int) $plain['id']);

    // Con el ajuste apagado tampoco.
    $app->settings()->set('returns.refund_shipping', false);
    $off = returnsAsk($app, $id, [0 => 2, 1 => 1], 'withdrawal');
    $withoutShipping = $returns->proposal($returns->find((int) $off['id']));
    same(0, $withoutShipping['shipping']);
    same(5000, $withoutShipping['total']);
});
