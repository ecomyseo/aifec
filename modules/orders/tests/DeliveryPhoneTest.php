<?php
/**
 * AIFEC — el teléfono de la entrega es obligatorio: lo exigen todos los transportistas (16/11/2026). Un pedido que se envía
 * sin teléfono, o con uno que no es un teléfono, no se hace; uno que no se envía (descarga) no lo necesita.
 * Teléfonos de pruebas: rango 999 (sin atribuir; no son de nadie).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @return array<string,string> */
function deliveryAddress(string $phone): array
{
    return ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle de Ejemplo 1', 'postcode' => '28013', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => $phone];
}

test('un pedido que se envía sin teléfono no se hace, y lo dice en el campo del teléfono', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 4000, 'stock' => 5, 'weight' => 3000]);
    $r = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress('')]);
    same(false, !empty($r['ok']), 'sin teléfono no hay pedido');
    same('Escribe un teléfono para la entrega', isset($r['errors']['billing.phone']) ? $r['errors']['billing.phone'] : '', 'y dice qué falta');

    $mal = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress('llámame')]);
    same('El teléfono no es válido', isset($mal['errors']['billing.phone']) ? $mal['errors']['billing.phone'] : '', 'un texto que no es un teléfono tampoco vale');

    $bien = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress('999 000 301')]);
    same(true, !empty($bien['ok']), 'con teléfono, sí: ' . json_encode(isset($bien['errors']) ? $bien['errors'] : []));
});

test('con otra dirección de envío, el teléfono que cuenta es el de la entrega', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 4000, 'stock' => 5, 'weight' => 3000]);
    $sinEnEntrega = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress('999 000 302'), 'shipping' => deliveryAddress('')]);
    same(false, !empty($sinEnEntrega['ok']), 'sin teléfono en la entrega no hay pedido');
    check(isset($sinEnEntrega['errors']['shipping.phone']), 'y se marca en la dirección de envío: ' . json_encode($sinEnEntrega['errors']));
    $bien = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress(''), 'shipping' => deliveryAddress('+44 7700 900123')]);
    same(true, !empty($bien['ok']), 'con teléfono en la entrega (también internacional) basta, aunque la factura no lo lleve: ' . json_encode(isset($bien['errors']) ? $bien['errors'] : []));
});

test('lo que no se envía (una descarga) no necesita teléfono', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Guía', 'price' => 900, 'kind' => 'digital', 'stock' => 10]);
    $r = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => deliveryAddress('')]);
    same(true, !empty($r['ok']), 'una descarga se compra sin teléfono: ' . json_encode(isset($r['errors']) ? $r['errors'] : []));
});

test('la caja (asistente, API, MCP y UCP) no da la compra por lista sin teléfono', function (): void {
    $app = aifecOrderApp();
    $web = new TestClient($app);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 4000, 'stock' => 5, 'weight' => 3000]);
    $review = aifecCartRun($web, static function ($cart, $app) use ($p): array {
        $cart->add($p, 0, 1);
        $co = $app->service('checkout');
        $co->fill(['email' => 'ana@cliente.test', 'address' => deliveryAddress('')]);
        $co->setPayment('gw');
        $sin = $co->review();
        $co->fill(['address' => ['phone' => '999 000 303']]);

        return [$sin, $co->review()];
    });
    same(false, $review[0]['ready'], 'sin teléfono no está lista');
    same(['address'], $review[0]['missing'], 'falta la entrega');
    check(isset($review[0]['errors']['shipping.phone']), 'por el teléfono: ' . json_encode($review[0]['errors']));
    same(true, $review[1]['ready'], 'con el teléfono, lista');
});

test('con la factura a otros datos, la caja exige el teléfono de la dirección de ENTREGA', function (): void {
    $app = aifecOrderApp();
    $web = new TestClient($app);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 4000, 'stock' => 5, 'weight' => 3000]);
    $review = aifecCartRun($web, static function ($cart, $app) use ($p): array {
        $cart->add($p, 0, 1);
        $co = $app->service('checkout');
        $co->fill(['email' => 'ana@cliente.test', 'address' => deliveryAddress(''), 'billing' => deliveryAddress('999 000 304')]);
        $co->setPayment('gw');
        $sin = $co->review();
        $co->fill(['address' => ['phone' => '999 000 305']]);

        return [$sin, $co->review()];
    });
    same(false, $review[0]['ready'], 'el teléfono de la factura no vale para el transportista: ' . json_encode($review[0]['errors']));
    check(isset($review[0]['errors']['shipping.phone']), 'se marca en la entrega: ' . json_encode($review[0]['errors']));
    same(true, $review[1]['ready'], 'con el teléfono en la entrega, lista: ' . json_encode($review[1]['errors']));
});
