<?php
/** AIFEC — preguntar por dos políticas en un solo turno. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('envíos y devoluciones devuelve la política de la tienda y solicita el destino sin abrir la caja', function (): void {
    foreach ([false, true] as $provider) {
        $app = assistantApp(['assistant.returns_text' => 'Envía las devoluciones en su embalaje.']);
        $app->boot();
        if ($provider) { assistantAi($app); }
        $web = new TestClient($app);
        $response = assistantSay($web, '¿Cómo funcionan los envíos y las devoluciones?');
        contains('código postal', $response['text']);
        contains('embalaje', $response['text']);
        $state = json_decode(assistantRows($app, 'conversations')[0]['state'], true);
        same('postcode', $state['expect']);
        check(empty($state['flow']['active']));
    }
});
