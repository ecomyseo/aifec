<?php
/**
 * AIFEC — privacidad y transparencia en el punto de entrada del asistente.
 * @author Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('una pregunta de salud no llega al proveedor, al historial ni a las recomendaciones', function (): void {
    foreach ([false, true] as $enabled) {
        $app = assistantApp();
        $fake = assistantAi($app);
        $fake->on = $enabled;
        $web = new TestClient($app);
        $reply = assistantSay($web, '¿Puedo usar el sérum de vitamina C estando embarazada?');
        contains('médico o farmacéutico', $reply['text']);
        same([], $reply['cards']);
        same([], $fake->asked);
        $rows = assistantRows($app, 'conversation_messages');
        $saved = (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
        notContains('embarazada', $saved);
        contains('[sensitive question]', $saved);
        $english = assistantSay($web, 'I am taking medication. Can I use this product?');
        contains('doctor or pharmacist', $english['text']);
        same([], $english['cards']);
        same([], $fake->asked);
    }
});

test('las advertencias se leen de la ficha publicada exacta y no se guardan con el perfil de salud', function (): void {
    $app = assistantApp();
    $app->boot();
    aifecProduct($app, ['name' => 'Crema de muestra', 'facts' => [['label' => 'Advertencias', 'value' => 'Uso externo.']]]);
    $web = new TestClient($app);
    $reply = assistantSay($web, '¿Puedo usar «Crema de muestra» durante el embarazo?');
    same([], $reply['cards']);
    contains('Uso externo.', $reply['text']);
    notContains('Uso externo.', json_encode(assistantRows($app, 'conversation_messages')));
    $missing = assistantSay($web, '¿Puedo usar «Producto inexistente» durante el embarazo?');
    contains('ese nombre exacto', $missing['text']);
});

test('la presentación y el aviso reflejan el modo disponible', function (): void {
    foreach ([false, true] as $enabled) {
        $app = assistantApp();
        $fake = assistantAi($app);
        $fake->on = $enabled;
        $body = (new TestClient($app))->get('/')->body;
        same($enabled, strpos($body, 'Estás hablando con una inteligencia artificial') !== false);
        same($enabled, strpos($body, 'Soy el asistente de IA') !== false);
        same(!$enabled, strpos($body, 'Soy el asistente de compras') !== false);
    }
});

test('el filtro conserva el resto de una lista al retirar una promesa de salud', function (): void {
    $filter = [\Aifec\Modules\Assistant\SensitiveQuestion::class, 'withoutClaims'];
    same("Alivia, 30 ml\nAceite de oliva", $filter("Alivia, 30 ml\nCura la dermatitis\nAceite de oliva"));
    same("Pack A\nPack B", $filter("Pack A\nThis product treats eczema\nPack B"));
});

test('más información quita los controles de la ficha y recorta la primera frase en palabras enteras', function (): void {
    $text = \Aifec\Modules\Assistant\ShopTools::plain('<button>Añadir al carrito</button><p>' . str_repeat('lavable ', 85) . '</p>', 1000);
    $reply = \Aifec\Modules\Assistant\ProductTalk::describe(['name' => 'Funda', 'summary' => $text]);
    notContains('Añadir al carrito', $reply);
    same(70, substr_count($reply, 'lavable'));
    contains('lavable…', $reply);
});

test('más información lee la ficha abierta o un enlace propio y rechaza un dominio distinto', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $id = (int) $ids['variantes'];
    $path = $app->url()->entity('product', $id);
    $web = new TestClient($app);
    $web->postJson('/asistente/mensaje', ['message' => 'Más información', 'page_path' => $path]);
    contains('Sofá Nube', $web->json()['text']);
    $link = $app->url()->entityAbs('product', $id);
    $result = assistantSay(new TestClient($app), 'Más información sobre ' . $link);
    contains('Sofá Nube', $result['text']);
    same(0, \Aifec\Modules\Assistant\ProductTalk::linkedProduct($app, 'https://externa.example' . $path));
});
