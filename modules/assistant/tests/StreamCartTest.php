<?php
/**
 * AIFEC — la respuesta por partes y el carrito. Con stream=1 las cabeceras salen antes de la primera línea, y con ellas las
 * cookies: un carrito que naciera a mitad de la respuesta (el asistente añade un producto a quien no tenía carrito) quedaría en la
 * base de datos sin que el navegador recibiera su cookie, y la petición siguiente lo vería vacío sin un solo error. El cliente de
 * las pruebas hace lo mismo que el servidor: recoge las cookies ANTES de ejecutar el escritor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/FastKit.php';

/** Carritos que hay en la base de datos. */
function streamCarts(\Aifec\Kernel\App $app): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
}

test('stream=1 y un visitante sin carrito: lo que el asistente añade sigue en el carrito en la petición siguiente', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantRound([['cart_add', ['product_id' => $ids['silla_roja']]]]), assistantText('Añadida.'));
    check(!isset($web->cookies[$app->cookieName('k')]), 'el visitante llega sin carrito');

    $stream = assistantStream($web, 'Añade la silla roja');

    $done = $stream['lines'][count($stream['lines']) - 1]['done'];
    same('Añadida.', $done['text']);
    check(isset($stream['response']->cookies[$app->cookieName('k')]), 'la cookie del carrito viaja con las cabeceras de la respuesta por partes');
    check(isset($stream['response']->cookies[$app->cookieName('ck')]), 'y su marca, la que mira el tema');
    check(isset($web->cookies[$app->cookieName('k')]), 'el navegador se queda con ella');

    // La petición siguiente es otra: solo tiene las cookies que recibió.
    $summary = $web->get('/carrito/summary.json');
    same(200, $summary->status);
    same(1, (int) $web->json()['count'], 'el carrito tiene la silla');
    contains('Silla', $web->get('/carrito')->body, 'y la página del carrito la enseña');
    same(1, streamCarts($app), 'un solo carrito, el del visitante');
});

test('stream=1 sin añadir nada: el carrito que se abrió para tener la cookie a tiempo no se queda en la base de datos', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantText('Hola, ¿qué buscas?'), assistantRound([['cart_add', ['product_id' => $ids['silla_roja']]]]), assistantText('Añadida.'));

    assistantStream($web, 'Hola');
    same(0, streamCarts($app), 'nadie añadió nada: no queda un carrito vacío');
    same(0, (int) json_decode($web->get('/carrito/summary.json')->body, true)['count'], 'y el resumen dice que no hay nada');

    // La cookie que quedó apunta a un carrito que ya no existe: es lo mismo que no tener. El siguiente mensaje abre otro y funciona.
    assistantStream($web, 'Añade la silla roja');
    same(1, streamCarts($app));
    $web->get('/carrito/summary.json');
    same(1, (int) $web->json()['count'], 'lo añadido en el segundo mensaje está en el carrito');
});

test('stream=1 con un carrito que ya existe: ni se crea otro ni se repiten las cookies', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $web->postJson('/carrito/add', ['product_id' => $ids['silla_roja'], 'variant_id' => 0, 'qty' => 1]);
    $before = $web->cookies[$app->cookieName('k')];
    $ai->script(assistantRound([['cart_add', ['product_id' => $ids['silla_roja']]]]), assistantText('Otra más.'));

    $stream = assistantStream($web, 'Añade otra silla roja');

    check(!isset($stream['response']->cookies[$app->cookieName('k')]), 'no hace falta mandar la cookie otra vez');
    same($before, $web->cookies[$app->cookieName('k')], 'el carrito es el mismo');
    same(1, streamCarts($app));
    $web->get('/carrito/summary.json');
    same(2, (int) $web->json()['count'], 'dos sillas en el mismo carrito');
});

test('sin stream no se abre ningún carrito por adelantado: nace cuando se añade algo, como siempre', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantText('Hola.'));

    $web->postJson('/asistente/mensaje', ['message' => 'Hola']);

    same(0, streamCarts($app));
    check(!isset($web->cookies[$app->cookieName('k')]), 'ni cookie de carrito');
});
