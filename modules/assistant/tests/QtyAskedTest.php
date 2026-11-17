<?php
/**
 * AIFEC — «pon 8 en el carrito»: la cifra es una cantidad, no «el octavo» ni «el segundo» (de ecom_asistente 0.13.0: se añadía
 * una unidad, o «pon 2» añadía el segundo producto).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\RuleBrain;

require_once __DIR__ . '/helpers.php';

test('la cifra que acompaña a «pon», «añade» o «al carrito» es la cantidad', function (): void {
    same(8, RuleBrain::qtyAsked('pon 8 en el carrito')['qty']);
    same(2, RuleBrain::qtyAsked('anade 2 al carrito')['qty']);
    same(3, RuleBrain::qtyAsked('quiero 3 unidades')['qty']);
    same(5, RuleBrain::qtyAsked('5 al carrito')['qty']);
    same(4, RuleBrain::qtyAsked('ponme 4 por favor')['qty'] ?? null, '«ponme 4», sin decir el carrito');
    same(null, RuleBrain::qtyAsked('anade el segundo'), 'un ordinal no es una cantidad');
    notContains('2', RuleBrain::qtyAsked('pon 2 en el carrito')['rest'], 'y la cifra no queda para leerla como «el segundo»');
});

test('sin IA, con un producto a la vista, «pon 3 en el carrito» añade tres unidades de ese producto', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco la banqueta trampa');

    $r = assistantSay($web, 'pon 3 en el carrito');

    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines), 'un producto en el carrito: ' . $r['text']);
    same(3, (int) $lines[0]['qty'], 'con tres unidades');
    contains('Banqueta Trampa', (string) $lines[0]['name']);
});
