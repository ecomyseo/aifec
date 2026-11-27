<?php
/** AIFEC — selección guiada y preguntas específicas de la página real del catálogo. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('el buscador de regalos pregunta por categoría y presupuesto y devuelve existencias y precios reales', function (): void {
    foreach ([false, true] as $withAi) {
        $app = assistantApp();
        $ids = assistantShop($app);
        if ($withAi) { assistantAi($app); }
        $web = new TestClient($app);
        $r = assistantSay($web, 'Ayúdame a elegir un regalo');
        contains('categoría', $r['text']);
        $r = assistantSay($web, 'Cualquier categoría');
        contains('precio máximo', $r['text']);
        $r = assistantSay($web, 'Hasta 59,90 €');
        check(count($r['cards']) > 0 && count($r['cards']) <= 3, json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ($r['cards'] as $card) {
            check($card['price_cents'] <= 5990 && $card['status'] !== 'out_of_stock');
        }
        same([], assistantCartLines($app, $web));
    }
});

test('el tratamiento formal se limita a las respuestas y no modifica textos de la tienda', function (): void {
    $app = assistantApp(['assistant.address' => 'formal']);
    assistantShop($app);
    $web = new TestClient($app);
    contains('ayudarle', $web->get('/')->body);
    $r = assistantSay($web, '¿Cuándo me llegaría?');
    contains('su código postal', $r['text']);
    contains('tu código postal', t('What is your postcode? I will quote the shipping for you.'));
});

test('las preguntas del producto usan la sección y las preguntas frecuentes publicadas en su propia ficha', function (): void {
    $app = assistantApp();
    $id = aifecProduct($app, ['name' => 'Muestra de bálsamo', 'facts' => [
        ['label' => 'Ingredientes', 'value' => 'Cera y aceite de oliva'],
        ['label' => 'Modo de empleo', 'value' => 'Extender una capa fina.'],
    ]]);
    $app->db()->insert('product_faqs', ['product_id' => $id, 'lang' => 'es', 'question' => '¿Es reciclable el envase?', 'answer' => 'La caja es de cartón.', 'position' => 0]);
    $web = new TestClient($app);
    $path = $app->url()->entity('product', $id);
    $web->get('/asistente/inicio?page_path=' . rawurlencode($path));
    check(in_array('¿Es reciclable el envase?', $web->json()['suggestions'], true));
    foreach (['¿Qué ingredientes tiene?' => 'Cera y aceite de oliva', '¿Cómo se usa?' => 'Extender una capa fina.', '¿Es reciclable el envase?' => 'La caja es de cartón.', '¿Qué medidas tiene?' => 'equipo'] as $q => $fact) {
        $web->postJson('/asistente/mensaje', ['message' => $q, 'page_path' => $path]);
        contains($fact, $web->json()['text']);
    }
});
