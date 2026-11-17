<?php
/**
 * AIFEC — la marca de la cuenta: cuando un cliente entra, además de su cookie de sesión (que el navegador no deja leer)
 * sale una marca sin secreto que el tema sí ve, para saber si merece la pena pedir «estado.json». Al salir, se quita.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CustomersKit.php';

test('entrar pone la marca legible y salir la quita; la cookie de sesión sigue sin dejarse leer', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);
    $mark = $app->cookieName('u');

    same(200, $web->get('/mi-cuenta/entrar')->status);
    check(!isset($web->cookies[$mark]), 'sin cuenta abierta no hay marca');

    $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    same(303, $r->status);
    check(isset($r->cookies[$mark]), 'al entrar sale la marca');
    same(false, $r->cookies[$mark]['options']['httponly'], 'la marca la puede leer el tema');
    same('1', $r->cookies[$mark]['value'], 'y no dice quién es');
    $session = 'af_c_' . substr($mark, strlen('af_u_'));
    check(isset($r->cookies[$session]), 'también sale la cookie de sesión');
    same(true, $r->cookies[$session]['options']['httponly'], 'que no se deja leer');

    // Mientras sigue dentro no se vuelve a mandar.
    $r = $web->get('/mi-cuenta');
    same(200, $r->status);
    check(!isset($r->cookies[$mark]), 'la marca no se repite en cada respuesta');
    same(true, $web->get('/mi-cuenta/estado.json')->status === 200 && $web->json()['logged'], 'estado.json dice que está dentro');

    $web->post('/mi-cuenta/salir', []);
    check(!isset($web->cookies[$mark]), 'al salir se quita la marca');
    same(false, $web->get('/mi-cuenta/estado.json')->status === 200 && $web->json()['logged']);
});

test('una marca sin sesión detrás (caducó) se quita sola en cuanto se pregunta por el estado', function (): void {
    [$app] = customersTestApp();
    $web = new TestClient($app);
    $mark = $app->cookieName('u');
    $web->cookies[$mark] = '1';
    $r = $web->get('/mi-cuenta/estado.json');
    same(200, $r->status);
    same(false, $web->json()['logged']);
    check(!isset($web->cookies[$mark]), 'la marca huérfana desaparece');
});
