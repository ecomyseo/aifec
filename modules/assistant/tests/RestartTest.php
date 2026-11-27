<?php
/** AIFEC — reiniciar en un navegador invalida su estado y respeta los otros carritos y conversaciones. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('reiniciar revoca la cookie anterior sin vaciar el carrito ni afectar a otro navegador', function (): void {
    $app = assistantApp();
    $items = assistantShop($app);
    $one = new TestClient($app);
    $two = new TestClient($app);
    $one->postJson('/asistente/mensaje', ['add' => ['product_id' => $items['silla_roja']]]);
    assistantSay($two, 'Hola');
    $name = $app->cookieName('as');
    $previous = $one->cookies[$name];
    $others = $two->cookies[$name];
    $conversations = $app->service('assistant')->conversations();
    same(200, $one->postJson('/asistente/reiniciar', ['id' => $conversations->findByToken($others)['id']])->status);
    same(null, $conversations->findByToken($previous));
    check($conversations->findByToken($others) !== null);
    same(1, count(assistantCartLines($app, $one)));
    $one->cookies[$name] = $previous;
    same(409, $one->postJson('/asistente/confirmar', [])->status);
    assistantSay($one, 'Hola');
    check($previous !== $one->cookies[$name]);
    same(1, (int) $conversations->findByToken($one->cookies[$name])['messages']);
});
