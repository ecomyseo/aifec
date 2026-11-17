<?php
/**
 * AIFEC — pruebas de lo que la tienda enseña al añadir al carrito: la respuesta de «add» trae el mensaje («Añadido al
 * carrito: <producto>»), lo que dice el botón un momento y el enlace al carrito; un error trae su mensaje; cambiar una
 * cantidad no dice «añadido». Y el guion del tema base usa las tres cosas.
 *
 * Nace de un fallo (13/11/2026): la respuesta no traía «message», el guion no enseñaba ningún aviso y al pulsar «Añadir al
 * carrito» solo cambiaba el número del carrito.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('añadir por JSON dice qué se ha añadido, qué pone el botón y dónde está el carrito', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Taza & plato', 'price' => 1210, 'tax_class_id' => 'standard', 'stock' => 10]);
    $web = new TestClient($app);

    same(200, $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 1])->status);
    $json = $web->json();
    same(true, $json['ok']);
    same('Añadido al carrito: Taza & plato', $json['message'], 'el mensaje lleva el nombre tal cual (el guion lo pone con textContent)');
    same('Añadido', $json['added'], 'lo que dice el botón un momento');
    same('Ver el carrito', $json['cart']['label']);
    check(is_string($json['cart']['url']) && substr($json['cart']['url'], -8) === '/carrito', 'el enlace es el del carrito: ' . $json['cart']['url']);
    same(1, $json['count']);
});

test('un error al añadir trae su mensaje y ni «added» ni el enlace', function (): void {
    $app = aifecCartApp();
    $web = new TestClient($app);

    same(422, $web->postJson('/carrito/add', ['product_id' => 999999, 'qty' => 1])->status);
    $json = $web->json();
    same(false, $json['ok']);
    check($json['error'] !== '', 'hay un error que contar');
    same($json['error'], $json['message'], 'el mensaje es el error');
    check(!isset($json['added']) && !isset($json['cart']), 'sin «añadido»');
});

test('cambiar una cantidad no dice «añadido»', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    $key = (string) $web->json()['line']['key'];

    $web->postJson('/carrito/update', ['key' => $key, 'qty' => 3]);
    $json = $web->json();
    same(true, $json['ok']);
    same('', $json['message']);
    check(!isset($json['added']) && !isset($json['cart']), 'solo al añadir');
});

test('el guion del tema base enseña el aviso con el enlace y cambia el botón al añadir', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 3) . '/themes/base/assets/js/theme.js');
    contains("Aifec.toast(message(data), 'ok', data && data.cart ? data.cart : null);", $js, 'el aviso lleva el enlace al carrito');
    contains('flashAdded(button, data && data.added ? String(data.added) : \'\');', $js, 'el botón dice «Añadido»');
    contains('if (data.message) {', $js, 'el aviso sale de «message»');
    contains("button.classList.add('is-added');", $js);
    check(strpos($js, 'innerHTML') === false, 'sin innerHTML: el nombre del producto se pone como texto');
    foreach (['base', 'aifec', 'atelier', 'despensa', 'gmartos', 'taller'] as $theme) {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/themes/' . $theme . '/assets/css/theme.css');
        contains('.is-added{', $css, $theme . ': el botón recién pulsado se distingue');
        contains('.af-toast a{', $css, $theme . ': el enlace del aviso');
        contains('+ var(--af-consent-h,0px))', $css, $theme . ': el aviso sube por encima del aviso de cookies');
    }
});
