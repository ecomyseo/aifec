<?php
/**
 * AIFEC — con IA, «no tenemos sofás» con un sofá en el catálogo no llega al cliente: se enseña lo que hay (ronda 3, 14/11/2026:
 * «No disponemos de mochilas ni botellas» y había una botella; ecom_asistente vio lo mismo con los cascos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('si la IA niega algo que el catálogo tiene con ese nombre, sale el producto y no la negación', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $ai = assistantAi($app);
    // Dos veces: al primer «no tenemos» la tienda le pide que busque otra vez, y el modelo lo repite.
    $ai->script(assistantText('Lo siento, no tenemos sofás ni banquetas en la tienda.'), assistantText('Lo siento, no tenemos sofás ni banquetas en la tienda.'));
    $r = assistantSay(new TestClient($app), '¿Tenéis algún sofá o una banqueta?');
    notContains('no tenemos', mb_strtolower((string) $r['text']));
    contains('En el catálogo sí he encontrado esto', (string) $r['text']);
    $cards = array_map(static function (array $c): int {
        return (int) $c['id'];
    }, $r['cards']);
    check(in_array($ids['variantes'], $cards, true), 'el sofá sale en una ficha');
    check(in_array($ids['trampa'], $cards, true), 'y la banqueta');
});

test('si de verdad no hay nada con ese nombre, la negación se queda', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $ai = assistantAi($app);
    $ai->script(assistantText('No tenemos patinetes eléctricos, lo siento.'), assistantText('No tenemos patinetes eléctricos, lo siento.'));
    $r = assistantSay(new TestClient($app), '¿Tenéis patinetes eléctricos?');
    contains('No tenemos patinetes', (string) $r['text']);
});
