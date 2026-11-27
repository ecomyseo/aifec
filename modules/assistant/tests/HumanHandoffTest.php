<?php
/** AIFEC — el relevo se activa solo con el consentimiento mostrado y vuelve a la conversación correcta. */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('atención personal requiere aceptar el resumen y responde desde el panel a la conversación que lo solicitó', function (): void {
    $app = assistantApp(['assistant.hours' => 'Lunes a viernes, 9 a 18 h']);
    assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco una silla roja');
    $offer = assistantSay($web, 'Quiero hablar con una persona');
    contains('Silla roja', $offer['text']);
    contains('Lunes a viernes', $offer['text']);
    $row = assistantRows($app, 'conversations')[0];
    $url = '/admin/assistant/' . $row['id'];
    $support = adminClient($app, 'support');
    $form = ['_token' => aifecToken($support), 'reply' => 'Tenemos la silla en exposición.', 'request_id' => $row['handoff_request']];
    same(409, $support->post($url . '/reply', $form)->status);
    $declined = assistantSay($web, $offer['suggestions'][1]);
    contains('continuar', $declined['text']);
    same('', assistantRows($app, 'conversations')[0]['handoff_status']);
    $offer = assistantSay($web, 'Quiero hablar con una persona');
    $waiting = assistantSay($web, $offer['suggestions'][0]);
    same('pending', assistantRows($app, 'conversations')[0]['handoff_status']);
    contains('recibido', $waiting['text']);
    contains('Silla roja', $support->get($url)->body);
    same(409, $support->post($url . '/reply', $form)->status, 'un formulario de la solicitud anterior ya no sirve');
    $form['request_id'] = assistantRows($app, 'conversations')[0]['handoff_request'];
    $editor = adminClient($app, 'editor');
    same(403, $editor->post($url . '/reply', ['_token' => aifecToken($editor)] + $form)->status);
    same(303, $support->post($url . '/reply', $form)->status);
    same(409, $support->post($url . '/reply', $form)->status);
    $reply = assistantSay($web, $waiting['suggestions'][0]);
    contains('Tenemos la silla en exposición.', $reply['text']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {conversation_messages} WHERE content LIKE ?', ['%Tenemos la silla en exposición.%']));
    $other = assistantSay(new TestClient($app), $waiting['suggestions'][0]);
    notContains('Tenemos la silla en exposición.', $other['text']);
});
