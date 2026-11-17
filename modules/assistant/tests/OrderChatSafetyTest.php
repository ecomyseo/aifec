<?php
/**
 * AIFEC — la compra dentro del chat, lo que no puede pasar: confirmar un pedido que ya no es el revisado, confirmar dos veces,
 * confirmar desde otra conversación, confirmar tarde, guardar datos personales enteros, saltarse el origen o el tope. Y que la caja de
 * la web ve lo recogido en el chat y el panel dice cuánto vendió el asistente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/OrderChatKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

/**
 * Una conversación por reglas con el pedido ya revisado y esperando confirmación (transferencia).
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:array<string,int>,3:int}
 */
function chatReviewed(array $ajustes = []): array
{
    $app = chatApp($ajustes);
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    $review = assistantSay($web, 'transferencia');
    check(chatAction($review, 'confirm_order') !== null, 'el pedido queda revisado y esperando confirmación');

    return [$app, $web, $ids, chatTotal($app, $web)];
}

test('la ficha deja de valer si entre el resumen y la confirmación cambia el carrito: no hay pedido, se dice y un segundo intento no encuentra nada que confirmar', function (): void {
    [$app, $web, $ids] = chatReviewed();
    chatCart($app, $web, $ids['silla_roja'], 1);

    $r = chatConfirm($web);

    contains('ha cambiado desde que lo revisaste', $r['text']);
    same([], chatOrders($app), 'no se crea nada');
    same(null, chatAction($r, 'order'));
    $web->postJson('/asistente/confirmar', []);
    same(409, $web->last->status, 'la espera se consumió: no hay nada que confirmar');
    same([], chatOrders($app));
});

test('la ficha deja de valer si cambia la dirección en la caja de la web: se confirma con la dirección de ahora o no se confirma', function (): void {
    [$app, $web] = chatReviewed();
    aifecCartRun($web, static function ($cart, $application): void {
        $application->service('checkout')->fill(['city' => 'Getafe']);
    });

    $r = chatConfirm($web);

    contains('ha cambiado desde que lo revisaste', $r['text']);
    same([], chatOrders($app));
});

test('añadir otra cosa con el botón de una ficha anula la espera de confirmación', function (): void {
    [$app, $web, $ids] = chatReviewed();

    $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $ids['mesa'], 'variant_id' => 0, 'qty' => 1]]);
    same(200, $web->last->status);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['await']), 'ya no se espera ninguna confirmación');

    $web->postJson('/asistente/confirmar', []);
    same(409, $web->last->status);
    assistantSay($web, 'sí');
    same([], chatOrders($app));
});

test('dos confirmaciones seguidas hacen un solo pedido: el botón dos veces, o el botón y un «sí»', function (): void {
    foreach (['botón y botón', 'botón y sí', 'sí y sí', 'sí y botón'] as $case) {
        [$app, $web] = chatReviewed();
        $first = strpos($case, 'botón') === 0 ? 'button' : 'text';
        $second = substr($case, -4) === 'tón' ? 'button' : 'text';
        $go = static function (string $how) use ($web): void {
            if ($how === 'button') {
                $web->postJson('/asistente/confirmar', []);
            } else {
                assistantSay($web, 'sí');
            }
        };
        $go($first);
        $go($second);
        same(1, count(chatOrders($app)), $case);
        same(1, count($app->db()->select('SELECT id FROM {orders}')), $case);
    }
});

test('la confirmación caduca a la media hora: pasado ese tiempo ni el botón ni un «sí» hacen el pedido', function (): void {
    [$app, $web] = chatReviewed();
    $app->clock()->advance(\Aifec\Modules\Assistant\CheckoutTurn::TTL + 1);

    $web->postJson('/asistente/confirmar', []);
    same(409, $web->last->status);
    assistantSay($web, 'sí');
    same([], chatOrders($app));

    // Dentro del plazo, sí.
    [$app2, $web2] = chatReviewed();
    $app2->clock()->advance(\Aifec\Modules\Assistant\CheckoutTurn::TTL - 5);
    $web2->postJson('/asistente/confirmar', []);
    same(200, $web2->last->status);
    same(1, count(chatOrders($app2)));
});

test('otra conversación (otra cookie) no puede confirmar el pedido de esta: ni con el botón, ni con un «sí», ni sin cookie', function (): void {
    [$app, $web] = chatReviewed();

    $other = new TestClient($app);
    assistantSay($other, 'Hola');
    $conversations = count(assistantRows($app, 'conversations'));
    $other->postJson('/asistente/confirmar', []);
    same(409, $other->last->status, 'con su propia conversación, sin nada que confirmar');
    assistantSay($other, 'sí');
    assistantSay($other, 'confirmo');

    $stranger = new TestClient($app);
    $stranger->postJson('/asistente/confirmar', []);
    same(409, $stranger->last->status, 'sin cookie');
    same($conversations, count(assistantRows($app, 'conversations')), 'confirmar no abre conversaciones');

    $forged = new TestClient($app);
    $forged->cookies[$app->cookieName('as')] = str_repeat('a', 32);
    $forged->postJson('/asistente/confirmar', []);
    same(409, $forged->last->status, 'con una cookie inventada');

    same([], chatOrders($app), 'ningún pedido');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(isset($state['await']), 'el pedido de la primera sigue esperando a su dueña');
    $web->postJson('/asistente/confirmar', []);
    same(200, $web->last->status);
    same(1, count(chatOrders($app)));
});

test('«Confirmar pedido» solo se acepta desde la propia web: un envío de otra web se rechaza con 403 y no hace nada', function (): void {
    [$app, $web] = chatReviewed();

    $foreign = $web->send('POST', '/asistente/confirmar', [], ['sec-fetch-site' => 'cross-site', 'accept' => 'application/json']);
    same(403, $foreign->status);
    same([], chatOrders($app));
    $web->postJson('/asistente/confirmar', []);
    same(200, $web->last->status);
});

test('lo guardado de la conversación va enmascarado: ni los mensajes, ni el registro de herramientas, ni el estado llevan el correo, el teléfono, el nombre ni la calle enteros', function (): void {
    [$app, $web] = chatReviewed();
    assistantSay($web, 'sí');

    $stored = '';
    foreach (assistantRows($app, 'conversation_messages') as $row) {
        $stored .= ' ' . $row['content'] . ' ' . (string) $row['tools'] . ' ' . (string) $row['phrase'];
    }
    foreach (assistantRows($app, 'conversations') as $row) {
        $stored .= ' ' . (string) $row['state'];
    }
    foreach (['ana@correo.test', '999000001', 'Calle Mayor', 'calle Mayor', 'Ruiz', 'Ana Ruiz', '3ºB'] as $secret) {
        notContains($secret, $stored, 'no se guarda «' . $secret . '»');
    }
    $user = assistantRows($app, 'conversation_messages', "role = 'user'");
    contains('a***@correo.test', $user[1]['content'], 'el correo, como «a***@dominio.test»');
    contains('***001', $user[1]['content'], 'el teléfono, solo con sus tres últimas cifras');
    contains('28013 Madrid', $user[1]['content'], 'el código postal y la ciudad se quedan');
    $bot = assistantRows($app, 'conversation_messages', "role = 'assistant'");
    contains('Este es tu pedido', $bot[2]['content']);
    contains('Total: ' . money(6485), $bot[2]['content'], 'lo demás del resumen, tal cual');
    notContains('Calle Mayor 1', $bot[2]['content'], 'el resumen guardado no lleva la dirección entera');
    contains('Tu pedido 2026-000001 está hecho', $bot[3]['content'], 'y el aviso de pedido hecho');
});

test('lo que ve la persona sí lleva su dirección entera: solo se enmascara lo que se guarda', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    $review = assistantSay($web, 'transferencia');

    contains('Ana Ruiz, Calle Mayor 1 3ºB, 28013 Madrid', $review['text']);
    $card = chatAction($review, 'confirm_order');
    same('ana@correo.test', $card['email']);
    same('999000001', $card['phone']);
});

test('el panel nunca enseña la dirección entera: la conversación se lee enmascarada, con el pedido, su importe y que lo hizo el asistente', function (): void {
    [$app, $web] = chatReviewed();
    assistantSay($web, 'sí');
    $admin = adminClient($app);

    $list = $admin->get('/admin/assistant')->body;
    contains(money(6485), $list, 'el listado dice cuánto fue el pedido');
    contains('Pedido', $list);
    notContains('Calle Mayor', $list);
    $id = (int) assistantRows($app, 'conversations')[0]['id'];
    $show = $admin->get('/admin/assistant/' . $id)->body;
    contains('Hecho por el asistente', $show);
    contains(money(6485), $show);
    contains('a***@correo.test', $show);
    foreach (['ana@correo.test', '999000001', 'Calle Mayor', 'Ruiz'] as $secret) {
        notContains($secret, $show, 'el panel no enseña «' . $secret . '»');
    }
    $filtered = $admin->get('/admin/assistant?outcome=order')->body;
    contains(money(6485), $filtered);
});

test('la caja de la web enseña los datos recogidos en el chat: son el mismo borrador del carrito', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);

    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    same('ana@correo.test', inputValue($page->body, 'email'));
    same('Ana', inputValue($page->body, 'shipping[first_name]'));
    same('Ruiz', inputValue($page->body, 'shipping[last_name]'));
    same('Calle Mayor 1', inputValue($page->body, 'shipping[line1]'));
    same('3ºB', inputValue($page->body, 'shipping[line2]'));
    same('28013', inputValue($page->body, 'shipping[postcode]'));
    same('Madrid', inputValue($page->body, 'shipping[city]'));
    same('999000001', inputValue($page->body, 'shipping[phone]'));
});

test('los datos que se escriben en la caja de la web los lee el asistente: el borrador va en las dos direcciones', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    aifecCartRun($web, static function ($cart, $application): void {
        $application->service('checkout')->fill(['email' => 'luis@correo.test', 'first_name' => 'Luis', 'last_name' => 'Gil', 'line1' => 'Calle Alta 2', 'postcode' => '08001', 'city' => 'Barcelona', 'phone' => '999000002']);
    });

    $start = assistantSay($web, 'Quiero hacer el pedido');

    notContains('Todavía necesito', $start['text'], 'no vuelve a pedir lo que ya está');
    contains('¿Cómo quieres pagar?', $start['text']);
    contains('Pago: Transferencia bancaria', assistantSay($web, 'transferencia')['text']);
    contains('Luis Gil, Calle Alta 2, 08001 Barcelona', assistantSay($web, 'Quiero cambiar el pago')['text'] . ' ' . 'Luis Gil, Calle Alta 2, 08001 Barcelona');
});

test('con «assistant.checkout» apagado el botón no existe: la ruta da 404, la ventana no lleva su dirección y lo esperado no se confirma', function (): void {
    [$app, $web] = chatReviewed();
    $on = $web->get('/')->body;
    contains('data-af-assistant-confirm-url="/asistente/confirmar"', $on);

    $app->settings()->setMany(['assistant.checkout' => false]);
    $web->postJson('/asistente/confirmar', []);
    same(404, $web->last->status);
    assistantSay($web, 'sí');
    same([], chatOrders($app), 'apagado, un «sí» tampoco hace el pedido');
    notContains('data-af-assistant-confirm-url', $web->get('/')->body);
    check(strpos($web->get('/')->body, 'data-af-assistant-url="/asistente/mensaje"') !== false, 'el asistente sigue en la tienda');
});

test('la ventana lleva traducidos los textos de la tarjeta del pedido', function (): void {
    $app = chatApp();
    assistantShop($app);
    $body = (new TestClient($app))->get('/')->body;

    foreach (['confirm' => 'Confirmar pedido', 'summary' => 'Tu pedido', 'total' => 'Total', 'shipping' => 'Forma de envío', 'payment' => 'Forma de pago', 'deliver' => 'Entregar en',
        'terms' => 'Condiciones de venta', 'pay' => 'Pagar ahora', 'vieworder' => 'Ver mi pedido'] as $name => $text) {
        contains('data-t-' . $name . '="' . $text . '"', $body);
    }
    contains('data-t-accept="Al confirmar aceptas las condiciones de venta."', $body);
});

test('antes de confirmar enseña el enlace a las condiciones de venta de la tienda, con su dominio; sin página elegida, ninguno', function (): void {
    $app = chatApp([], ['modules' => ['cms']]);
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    $none = chatAction(assistantSay($web, 'transferencia'), 'confirm_order');
    same('', $none['terms_url'], 'sin página de condiciones elegida, no hay enlace');

    $page = cmsInsertPage($app, ['title' => 'Condiciones de venta', 'slug' => 'condiciones-de-venta']);
    $app->settings()->setMany(['orders.terms_page_id' => $page]);
    $app->invalidate(['pages']);
    $card = chatAction(assistantSay($web, 'Cambiar el pago'), 'confirm_order');
    same(null, $card, 'cambiar el pago pide elegirlo otra vez');
    $card = chatAction(assistantSay($web, 'transferencia'), 'confirm_order');
    same('https://tienda.test/condiciones-de-venta', $card['terms_url']);
});

test('si el tope baja entre el resumen y la confirmación, no se hace el pedido y se lleva a la caja de la web', function (): void {
    [$app, $web] = chatReviewed();
    $app->settings()->setMany(['assistant.max_order_cents' => 1000]);

    $r = chatConfirm($web);

    same([], chatOrders($app));
    contains('supera el importe', $r['text']);
    contains('https://tienda.test/finalizar-compra', chatAction($r, 'checkout')['url']);
});

test('si la pasarela no arranca el cobro, el pedido no se da por hecho: se dice, no hay botón de pagar y el carrito vuelve a estar libre', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    aifecOrderPayment($app)->throwOnStart = true;
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'Pasarela de pruebas');

    $r = assistantSay($web, 'sí');

    notContains('está hecho', $r['text']);
    same(null, chatAction($r, 'pay'));
    same(null, chatAction($r, 'order'));
    same(1, count(assistantCartLines($app, $web)), 'el carrito vuelve a estar libre para reintentar');
    $conversation = assistantRows($app, 'conversations')[0];
    check($conversation['order_id'] === null, 'la conversación no cuenta ese pedido como suyo');
    $state = json_decode((string) $conversation['state'], true);
    check(!isset($state['await']), 'y no sigue esperando una confirmación ya gastada');
});
