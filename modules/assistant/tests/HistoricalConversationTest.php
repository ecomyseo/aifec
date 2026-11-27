<?php
/**
 * AIFEC — regresiones comerciales de la conversación, con un catálogo ficticio.
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

require_once __DIR__ . '/OrderChatKit.php';

test('la pregunta sobre la piel del comprador no describe el artículo que tiene en el carrito', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    foreach (['¿Qué recomendáis para mi piel?', '¿Cómo cuido mis fibras capilares?'] as $question) {
        $reply = assistantSay($web, $question);
        notContains('Silla roja', $reply['text']);
        check(!in_array($ids['silla_roja'], array_column($reply['cards'], 'id'), true));
    }
});

test('el plazo solicitado por el comprador pide un destino con el que cotizar', function (): void {
    $app = assistantApp();
    $reply = assistantSay(new TestClient($app), '¿Cuándo me llegaría?');
    contains('¿Cuál es tu código postal? Te calculo el envío.', $reply['text']);
    same(0, (int) assistantRows($app, 'conversations')[0]['unanswered']);
    same([], $reply['cards']);
});

test('el chat muestra los productos dentro de un presupuesto aproximado', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $ids = [];
    foreach ([2999, 3000, 4600, 4601] as $price) {
        $ids[$price] = aifecProduct($app, ['name' => 'Atril ' . $price, 'price' => $price, 'stock' => 3]);
    }
    searchService($app)->reindexNow();
    $reply = assistantSay(new TestClient($app), 'Busco atril de unos 40 €');
    $shown = array_column($reply['cards'], 'id');
    sort($shown);
    same([$ids[3000], $ids[4600]], $shown);
});

test('una errata en el nombre conserva el artículo encontrado en las tarjetas del chat', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $id = aifecProduct($app, ['name' => 'Notebook', 'price' => 4500, 'stock' => 4]);
    searchService($app)->reindexNow();
    $reply = assistantSay(new TestClient($app), 'Busco notebok');
    check(in_array($id, array_column($reply['cards'], 'id'), true), 'el artículo corregido debe aparecer');
});

test('un botón sobre materiales durante la caja conserva la dirección y evita guardar datos de cliente', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    $before = chatDraft($app, $web);
    assistantSay($web, 'Materiales');
    $rows = assistantRows($app, 'conversation_messages', "role = 'assistant'");
    $log = json_decode((string) end($rows)['tools'], true) ?: [];
    check(!in_array('checkout_set_customer', array_column($log, 'name'), true), 'el botón nunca es una dirección');
    same($before, chatDraft($app, $web));
});

test('una pregunta de fuera de la tienda no recomienda productos aunque su descripción la mencione', function (): void {
    $app = assistantApp();
    assistantShop($app);
    aifecProduct($app, ['name' => 'Cuaderno escolar', 'summary' => '¿Quién ganó el partido?', 'stock' => 5, 'price' => 500]);
    searchService($app)->reindexNow();
    $reply = assistantSay(new TestClient($app), '¿Quién ganó el partido?');
    same([], $reply['cards']);
    contains('tienda', $reply['text']);
});
