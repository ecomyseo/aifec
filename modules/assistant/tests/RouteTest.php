<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La ruta POST /asistente/mensaje: conversación y cookie, origen, límites, recorte del mensaje, botón «añadir», apagado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el primer mensaje abre la conversación y pone la cookie propia del sitio; en la tabla solo queda el resumen de la ficha', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);
    same('/asistente/mensaje', $app->url()->route('assistant.message'));

    $r = $web->postJson('/asistente/mensaje', ['message' => 'Hola']);

    same(200, $r->status);
    $name = $app->cookieName('as');
    check(isset($web->cookies[$name]), 'la cookie del asistente está puesta');
    check(preg_match('/^[a-f0-9]{32}$/', $web->cookies[$name]) === 1, 'ficha de 16 bytes en hexadecimal');
    same(true, $r->cookies[$name]['options']['httponly']);
    same('Lax', $r->cookies[$name]['options']['samesite']);
    contains('no-store', $r->headers['Cache-Control'], 'la respuesta no se guarda en ninguna caché');
    contains('Puedo ayudarte', $web->json()['text']);
    same(['text', 'cards', 'actions', 'suggestions'], array_keys($web->json()));

    $conversations = assistantRows($app, 'conversations');
    same(1, count($conversations));
    same(hash('sha256', $web->cookies[$name]), $conversations[0]['token_hash'], 'la ficha en claro no se guarda');
    same('es', $conversations[0]['lang']);
    same(1, (int) $conversations[0]['messages']);
    same(['user', 'assistant'], array_column(assistantRows($app, 'conversation_messages'), 'role'));

    assistantSay($web, 'Gracias');
    same(1, count(assistantRows($app, 'conversations')), 'con la cookie sigue siendo la misma conversación');
    same(4, count(assistantRows($app, 'conversation_messages')));
    same(2, (int) assistantRows($app, 'conversations')[0]['messages']);

    $other = new TestClient($app);
    assistantSay($other, 'Hola');
    same(2, count(assistantRows($app, 'conversations')), 'otro navegador, otra conversación');
});

test('una cookie que no corresponde a ninguna conversación (mal formada, inventada o borrada) abre una nueva', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $name = $app->cookieName('as');

    foreach (['abc', str_repeat('0', 32), str_repeat('Z', 32), '../../etc/passwd'] as $bad) {
        $web = new TestClient($app);
        $web->cookies[$name] = $bad;
        same(200, $web->postJson('/asistente/mensaje', ['message' => 'Hola'])->status, $bad);
        check($web->cookies[$name] !== $bad, 'se pone una cookie nueva');
        check(preg_match('/^[a-f0-9]{32}$/', $web->cookies[$name]) === 1);
    }
    same(4, count(assistantRows($app, 'conversations')));
});

test('un envío desde otra web se rechaza con 403 y no abre ninguna conversación', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $foreign = $web->send('POST', '/asistente/mensaje', ['message' => 'Hola'], ['sec-fetch-site' => 'cross-site', 'accept' => 'application/json']);
    same(403, $foreign->status);
    same(0, count(assistantRows($app, 'conversations')));
    same(0, count(assistantRows($app, 'conversation_messages')));
    check(!isset($web->cookies[$app->cookieName('as')]), 'ni cookie');

    // Un navegador antiguo no manda Sec-Fetch-Site: entonces manda el origen.
    $headers = $web->headers;
    unset($web->headers['sec-fetch-site']);
    $origin = $web->send('POST', '/asistente/mensaje', ['message' => 'Hola'], ['origin' => 'https://malo.test', 'accept' => 'application/json']);
    same(403, $origin->status, 'el origen de otra web tampoco');
    $own = $web->send('POST', '/asistente/mensaje', ['message' => 'Hola'], ['origin' => 'https://tienda.test', 'accept' => 'application/json']);
    same(200, $own->status, 'el de la propia web sí');
    $web->headers = $headers;
});

test('el mensaje 21 en 10 minutos recibe un 429 con Retry-After y no se guarda; pasada la ventana vuelve a contestar', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    for ($i = 1; $i <= 20; ++$i) {
        same(200, $web->postJson('/asistente/mensaje', ['message' => 'Hola ' . $i])->status, 'mensaje ' . $i);
    }
    $r = $web->postJson('/asistente/mensaje', ['message' => 'Uno más']);
    same(429, $r->status);
    same('600', $r->headers['Retry-After']);
    contains('demasiado deprisa', $web->json()['error']);
    same(20, count(assistantRows($app, 'conversation_messages', "role = 'user'")), 'el mensaje rechazado no se guarda');

    $app->clock()->advance(601);
    same(200, $web->postJson('/asistente/mensaje', ['message' => 'Ya vuelvo'])->status);
});

test('borrar la cookie no sirve para saltarse el límite: hay otro por dirección IP', function (): void {
    $app = assistantApp();
    assistantShop($app);

    $status = 0;
    for ($i = 1; $i <= 61; ++$i) {
        $status = (new TestClient($app))->postJson('/asistente/mensaje', ['message' => 'Hola'])->status;
        if ($i <= 60) {
            same(200, $status, 'visitante ' . $i);
        }
    }
    same(429, $status, 'el 61 desde la misma dirección');
});

test('un mensaje larguísimo se recorta a 1.000 caracteres y uno vacío o sin cuerpo es un 400 que no abre conversación', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    same(400, $web->postJson('/asistente/mensaje', ['message' => '   '])->status);
    same(400, $web->postJson('/asistente/mensaje', [])->status);
    same(400, $web->postJson('/asistente/mensaje', ['add' => ['product_id' => 'x']])->status);
    same(0, count(assistantRows($app, 'conversations')));

    // Palabras de verdad: una sola «palabra» de 1.500 letras es un fichero pegado y de eso solo se guarda el principio.
    $long = str_repeat('qué más ', 190);
    same(200, $web->postJson('/asistente/mensaje', ['message' => $long])->status);
    $user = assistantRows($app, 'conversation_messages', "role = 'user'")[0];
    same(trim(mb_substr($long, 0, 1000, 'UTF-8')), $user['content']);
    same(true, mb_strlen((string) $user['content'], 'UTF-8') >= 996, 'se recorta a 1.000 caracteres, no a menos');

    same(200, $web->postJson('/asistente/mensaje', ['message' => str_repeat('á', 1500)])->status);
    $pasted = assistantRows($app, 'conversation_messages', "role = 'user'")[1];
    same(str_repeat('á', 120) . '…', $pasted['content'], 'una ristra sin espacios no se guarda entera');
});

test('los caracteres de control se quitan del mensaje y lo demás se guarda tal cual (el panel lo escapa)', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    assistantSay($web, "Hola\x00 mundo\x07 <b>&amp;</b>\nlínea dos");

    $user = assistantRows($app, 'conversation_messages', "role = 'user'")[0];
    same("Hola mundo <b>&amp;</b>\nlínea dos", $user['content']);
});

test('el botón «añadir» de una ficha usa la herramienta con el carrito de esta sesión y el precio del catálogo, aunque se intente mandar otro', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $ids['silla_roja'], 'variant_id' => 0, 'qty' => 1, 'price' => 1, 'unit_price' => 1]]);
    $r = $web->json();

    contains('Añadido a tu carrito: Silla roja.', $r['text']);
    same([['type' => 'cart_changed', 'count' => 1]], $r['actions']);
    same(['Ir a la caja', 'Ver mi carrito', 'Seguir mirando'], $r['suggestions'], 'tras añadir (P105): ir a la caja, ver el carrito o seguir mirando');
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same(5990, $lines[0]['unit_price'], 'el precio es el del catálogo');
    same(1, (int) assistantRows($app, 'conversations')[0]['cart_added']);
    $messages = assistantRows($app, 'conversation_messages');
    same(['assistant'], array_column($messages, 'role'), 'el botón no inventa un mensaje del cliente');
    same('cart_add', json_decode((string) $messages[0]['tools'], true)[0]['name']);

    $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $ids['silla_roja'], 'qty' => 0]]);
    same(2, array_values(assistantCartLines($app, $web))[0]['qty'], 'una cantidad de 0 cuenta como 1');
});

test('el botón «añadir» no añade lo que no se puede comprar: un producto con variantes, uno agotado, uno que no existe', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    foreach ([$ids['variantes'], $ids['silla_azul'], 987654] as $productId) {
        $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $productId]]);
        $r = $web->json();
        same([], $r['actions'], 'producto ' . $productId);
        check($r['text'] !== '', 'dice por qué');
    }
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    same(0, (int) assistantRows($app, 'conversations')[0]['cart_added']);
});

test('«assistant.max_messages» pone tope a los mensajes del cliente en una conversación y pasado el tope no se llama a la IA', function (): void {
    $app = assistantApp(['assistant.max_messages' => 3]);
    assistantShop($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);

    for ($i = 1; $i <= 3; ++$i) {
        assistantSay($web, 'Mensaje ' . $i);
    }
    same(3, count($ai->asked));
    $limit = assistantSay($web, 'Mensaje 4');

    contains('Llevamos un buen rato hablando', $limit['text']);
    same([], $limit['cards']);
    same(3, count($ai->asked), 'la IA no recibe el mensaje que pasa del tope');
    same(3, count(assistantRows($app, 'conversation_messages', "role = 'user'")), 'ni se guarda');
    same(3, (int) assistantRows($app, 'conversations')[0]['messages']);

    $fresh = new TestClient($app);
    contains('Respuesta de mentira.', assistantSay($fresh, 'Hola')['text'], 'otra conversación empieza de cero');
});

test('con «assistant.enabled» apagado la ruta no contesta (404, también para JavaScript) y no se abre ninguna conversación', function (): void {
    $app = assistantApp(['assistant.enabled' => false]);
    assistantShop($app);
    $web = new TestClient($app);

    $r = $web->postJson('/asistente/mensaje', ['message' => 'Hola']);
    same(404, $r->status);
    same(0, count(assistantRows($app, 'conversations')));
    check(!isset($web->cookies[$app->cookieName('as')]), 'sin cookie');
    same(404, $web->post('/asistente/mensaje', ['message' => 'Hola'])->status);
    same(404, $web->postJson('/asistente/mensaje', ['add' => ['product_id' => 1]])->status);
});

test('una conversación que acaba en un pedido hecho en el mismo navegador se apunta con el número del pedido', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'Hola');
    same(null, assistantRows($app, 'conversations')[0]['order_id']);

    $placed = aifecOrderPlace($app, $web, [[$ids['silla_roja'], 0, 1]]);

    check(!empty($placed['ok']), 'el pedido se crea');
    same((int) $placed['id'], (int) assistantRows($app, 'conversations')[0]['order_id']);

    $stranger = new TestClient($app);
    aifecOrderPlace($app, $stranger, [[$ids['mesa'], 0, 1]]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {conversations} WHERE order_id IS NOT NULL'), 'quien no habló con el asistente no marca nada');
});
