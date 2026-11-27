<?php
/** AIFEC — guardar guías en el panel y usar el documento correcto para la pregunta y el idioma. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('el comerciante edita una comparativa, el chat conserva los párrafos y el proveedor lee el documento completo', function (): void {
    $app = assistantApp(['i18n.languages' => ['es', 'en']]);
    $ids = assistantShop($app);
    $admin = adminClient($app);
    $base = '/admin/r/assistant_guides';
    same(200, $admin->get($base . '/new')->status);
    $form = ['kind' => 'comparison', 'lang' => 'es', 'title' => 'Guía de sillas', 'active' => 1,
        'phrases' => "qué silla elijo\nqué silla compro", 'product_ids' => (string) $ids['silla_roja'],
        'body' => "Para el comedor, el modelo rojo se limpia con un paño.\n\n" . str_repeat('Detalles del acabado. ', 80) . "\n\nPara el escritorio, mide el espacio entre los brazos."];
    same(422, $admin->post($base . '/save', ['_token' => aifecToken($admin), 'f' => $form])->status);
    $form['product_ids'] .= ',' . $ids['silla_azul'];
    same(302, $admin->post($base . '/save', ['_token' => aifecToken($admin), 'f' => $form])->status);
    $web = new TestClient($app);
    $r = assistantSay($web, '¿Qué silla elijo?');
    same('Para el comedor, el modelo rojo se limpia con un paño.', $r['text']);
    same([$ids['silla_roja'], $ids['silla_azul']], array_column($r['cards'], 'id'));
    $ai = assistantAi($app);
    $ai->script(assistantText('{"paragraphs":[2,999]}'));
    $answer = assistantSay(new TestClient($app), '¿Qué silla compro?');
    same('Para el escritorio, mide el espacio entre los brazos.', $answer['text']);
    same('assistant.guide', $ai->asked[0]['task']);
    $app->db()->update('products', ['status' => 'draft'], ['id' => $ids['silla_roja']]);
    $reply = assistantSay(new TestClient($app), '¿Qué silla elijo?');
    notContains('se limpia con un paño', $reply['text']);
});
