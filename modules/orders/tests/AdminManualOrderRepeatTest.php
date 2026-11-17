<?php
/**
 * AIFEC — pruebas del pedido a mano (P103): «Repetir» un pedido desde su ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminManualOrderKit.php';

if (!function_exists('moRepeatLines')) {
    /**
     * Las líneas del borrador como «producto-variante» => unidades.
     *
     * @return array<string,int>
     */
    function moRepeatLines(TestClient $web): array
    {
        $out = [];
        foreach (moQuote($web)['lines'] as $line) {
            $out[(int) $line['product_id'] . '-' . (int) $line['variant_id']] = (int) $line['qty'];
        }
        ksort($out);

        return $out;
    }
}

test('«Repetir» abre «Pedido nuevo» con el mismo cliente, las mismas líneas (con su variante) y la misma dirección', function (): void {
    $s = moShop();
    $app = $s['app'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['shirt'], $s['v']['CAM-S'], 2], [$s['book'], 0, 1]], ['email' => 'ana@cliente.test']);
    $web = moClient($app);
    $r = $web->post('/admin/orders/' . $id . '/repeat');
    same(303, $r->status);
    same('/admin/orders/new', (string) $r->headers['Location']);
    $page = $web->follow();
    contains('está copiado en un pedido nuevo', $page->body);
    contains('Cambiar de cliente', $page->body, 'con el cliente elegido');
    contains('ana@cliente.test', $page->body);
    $expected = [(int) $s['book'] . '-0' => 1, (int) $s['shirt'] . '-' . (int) $s['v']['CAM-S'] => 2];
    ksort($expected);
    same($expected, moRepeatLines($web));
    check(preg_match('/name="address_id" value="' . (int) $s['address'] . '" checked/', $page->body) === 1, 'su dirección guardada, elegida');
    same((int) $app->db()->value('SELECT COUNT(*) FROM {addresses}'), 1, 'no se ha guardado otra');
    moStep($web, 'payment', ['mode' => 'method:transfer']);
    same(true, moQuote($web)['ready']);
});

test('«Repetir» con un producto borrado y otro agotado: se avisa y se quitan; de lo que queda poco, se pone lo que hay', function (): void {
    $s = moShop();
    $app = $s['app'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['mug'], 0, 1], [$s['book'], 0, 1], [$s['shirt'], $s['v']['CAM-M'], 3]], ['email' => 'ana@cliente.test']);
    $app->db()->delete('products', ['id' => (int) $s['mug']]);
    $app->db()->update('products', ['stock' => 0], ['id' => (int) $s['book']]);
    $app->db()->update('variants', ['stock' => 2], ['id' => (int) $s['v']['CAM-M']]);
    $app->invalidate(['catalog']);
    $web = moClient($app);
    $web->post('/admin/orders/' . $id . '/repeat');
    $page = $web->follow()->body;
    contains('Taza ya no existe: se ha dejado fuera.', $page);
    contains('Libro de cocina no está disponible', $page);
    contains('se ha dejado fuera', $page);
    contains('solo quedan 2', $page);
    same([(int) $s['shirt'] . '-' . (int) $s['v']['CAM-M'] => 2], moRepeatLines($web));
});

test('«Repetir» guarda en la cuenta del cliente una dirección del pedido que no tenía, y sin permiso da 403', function (): void {
    $s = moShop();
    $app = $s['app'];
    $address = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Avenida del Puerto 7', 'postcode' => '46001', 'city' => 'Valencia', 'region' => '46', 'country' => 'ES', 'phone' => '999345678'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['mug'], 0, 1]], ['email' => 'ana@cliente.test', 'billing' => $address]);
    same(403, moClient($app, 'support')->post('/admin/orders/' . $id . '/repeat')->status);
    $web = moClient($app);
    same(404, $web->post('/admin/orders/99999/repeat')->status, 'un pedido que no existe');
    $web->post('/admin/orders/' . $id . '/repeat');
    $saved = $app->db()->row('SELECT * FROM {addresses} WHERE customer_id = ? AND line1 = ?', [(int) $s['ana'], 'Avenida del Puerto 7']);
    check($saved !== null, 'la dirección queda en su cuenta');
    check(preg_match('/name="address_id" value="' . (int) $saved['id'] . '" checked/', $web->get('/admin/orders/new')->body) === 1, 'y elegida');
});

test('«Repetir» y confirmar: un pedido nuevo de panel, con las existencias de nuevo descontadas', function (): void {
    $s = moShop();
    $app = $s['app'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['book'], 0, 2]], ['email' => 'ana@cliente.test']);
    same(8, aifecOrderStock($app, $s['book']));
    $web = moClient($app);
    $web->post('/admin/orders/' . $id . '/repeat');
    moStep($web, 'payment', ['mode' => 'paid', 'paid_with' => 'Efectivo']);
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    check((int) $order['id'] !== $id, 'otro pedido');
    same('admin', (string) $order['source']);
    same(6, aifecOrderStock($app, $s['book']));
});
