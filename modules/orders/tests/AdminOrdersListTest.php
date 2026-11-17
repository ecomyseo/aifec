<?php
/**
 * AIFEC — pruebas del listado de pedidos del panel: permisos, búsqueda, filtros, pestañas rápidas, exportación, carritos,
 * menú y ajustes de ventas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminKit.php';

/**
 * Tres pedidos: uno cobrado por la pasarela (por preparar), otro sin pagar y otro de transferencia (en espera).
 *
 * @return array{0:\Aifec\Kernel\App,1:int,2:int,3:int,4:int}
 */
function adminOrdersThree(): array
{
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $paid = adminOrdersPaid($app, 'gw', [[$p, 0, 2]], ['email' => 'ana@cliente.test']);
    $unpaid = adminOrdersPlace($app, 'gw', [[$p, 0, 1]], ['email' => 'luis@cliente.test']);
    $hold = adminOrdersPlace($app, 'transfer', [[$p, 0, 1]], ['email' => 'marta@cliente.test']);

    return [$app, $p, $paid, $unpaid, $hold];
}

test('el listado se abre con permiso, da 403 sin él y enseña número, cliente, total, cobro y estado', function (): void {
    list($app, $p, $paid, $unpaid, $hold) = adminOrdersThree();
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    $web = adminClient($app);
    $r = $web->get('/admin/orders');
    same(200, $r->status);
    $number = adminOrdersNumber($app, $paid);
    check(adminOrdersSees($r->body, $number), 'sale el número del pedido');
    contains('/admin/orders/' . $paid, $r->body, 'el número enlaza a la ficha');
    contains('ana@cliente.test', $r->body);
    contains('Ana Ruiz', $r->body);
    contains($money(4495), $r->body, 'el total va en euros con coma');
    contains('Cobrado', $r->body, 'insignia de cobro');
    contains('En preparación', $r->body, 'el estado va con su nombre traducido');
    contains('En espera', $r->body);
    contains('Pendiente de pago', $r->body);
    contains('/admin/orders/export.csv', $r->body, 'quien puede exportar ve el botón');

    $support = adminClient($app, 'support');
    $r = $support->get('/admin/orders');
    same(200, $r->status, 'soporte puede ver los pedidos');
    contains('ana@cliente.test', $r->body);
    notContains('/admin/orders/export.csv', $r->body, 'pero no ve el botón de exportar');

    same(403, adminClient($app, 'editor')->get('/admin/orders')->status);
    same(404, $web->get('/admin/orders/99999')->status);
});

test('un pedido de pruebas lleva su marca en el listado', function (): void {
    $app = adminOrdersApp();
    aifecOrderPayment($app)->test = true;
    $id = adminOrdersPaid($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    $web = adminClient($app);
    $body = $web->get('/admin/orders')->body;
    check(adminOrdersSees($body, adminOrdersNumber($app, $id)), 'sale el pedido');
    contains('Pruebas', $body);
});

test('el listado busca por correo, por número, por nombre y por referencia de cobro', function (): void {
    list($app, $p, $paid, $unpaid, $hold) = adminOrdersThree();
    $app->db()->run("UPDATE {customers} SET first_name = 'Marta', last_name = 'Soler & Hijos' WHERE email = 'marta@cliente.test'");
    $web = adminClient($app);
    $numbers = ['paid' => adminOrdersNumber($app, $paid), 'unpaid' => adminOrdersNumber($app, $unpaid), 'hold' => adminOrdersNumber($app, $hold)];
    $found = static function (string $query) use ($web, $numbers): array {
        $body = $web->get('/admin/orders?' . $query)->body;
        $out = [];
        foreach ($numbers as $key => $number) {
            $out[$key] = adminOrdersSees($body, $number);
        }

        return $out;
    };
    same(['paid' => true, 'unpaid' => false, 'hold' => false], $found('q=ana@cliente'), 'por correo');
    same(['paid' => false, 'unpaid' => true, 'hold' => false], $found('q=' . rawurlencode($numbers['unpaid'])), 'por número');
    same(['paid' => false, 'unpaid' => false, 'hold' => true], $found('q=' . rawurlencode('Soler & Hijos')), 'por nombre, con «&»');
    $reference = (string) $app->db()->value('SELECT reference FROM {order_payments} WHERE order_id = ?', [$paid]);
    check($reference !== '', 'el cobro tiene su referencia');
    same(['paid' => true, 'unpaid' => false, 'hold' => false], $found('q=' . rawurlencode($reference)), 'por referencia de cobro');
    same(['paid' => false, 'unpaid' => false, 'hold' => false], $found('q=nadie-se-llama-asi'));
    contains('Soler &amp; Hijos', $web->get('/admin/orders?q=Marta')->body, 'el nombre con «&» sale escapado');
    notContains('Soler & Hijos', $web->get('/admin/orders?q=Marta')->body);
});

test('el listado filtra por estado, cobro, forma de pago, fechas y por preparar', function (): void {
    list($app, $p, $paid, $unpaid, $hold) = adminOrdersThree();
    $web = adminClient($app);
    $numbers = ['paid' => adminOrdersNumber($app, $paid), 'unpaid' => adminOrdersNumber($app, $unpaid), 'hold' => adminOrdersNumber($app, $hold)];
    $found = static function (string $query) use ($web, $numbers): array {
        $body = $web->get('/admin/orders?' . $query)->body;
        $out = [];
        foreach ($numbers as $key => $number) {
            if (adminOrdersSees($body, $number)) {
                $out[] = $key;
            }
        }

        return $out;
    };
    same(['hold'], $found('f[status]=on_hold'), 'por estado');
    same(['unpaid'], $found('f[status]=pending_payment'));
    same(['paid'], $found('f[payment]=paid'), 'por cobro');
    same(['unpaid', 'hold'], array_values(array_intersect(['paid', 'unpaid', 'hold'], $found('f[payment]=pending'))));
    same(['hold'], $found('f[method]=transfer'), 'por forma de pago');
    same(['paid'], $found('f[prepare]=1'), 'por preparar: pagados sin enviar');
    $today = substr($app->clock()->sql(), 0, 10);
    same(3, count($found('f[from]=' . $today)), 'desde hoy salen todos');
    same([], $found('f[from]=2099-01-01'), 'desde una fecha futura no sale ninguno');
    same([], $found('f[to]=2000-01-01'), 'hasta una fecha pasada no sale ninguno');
    same(3, count($found('f[to]=' . $today)));
    same(3, count($found('f[status]=inventado')), 'un valor que no existe se ignora');
});

test('las pestañas rápidas llevan su número y su enlace al filtro', function (): void {
    list($app, $p, $paid, $unpaid, $hold) = adminOrdersThree();
    $web = adminClient($app);
    $body = $web->get('/admin/orders')->body;
    preg_match_all('/<a class="af-tab"[^>]*>(.*?)<\/a>/s', $body, $m);
    $labels = array_map(static function (string $html): string {
        return trim(strip_tags($html));
    }, $m[1]);
    same(['Todos3', 'Por preparar1', 'Pendiente de pago1', 'En espera1'], $labels);
    check(preg_match('/href="[^"]*f%5Bprepare%5D=1"/', $body) === 1, 'Por preparar enlaza a su filtro');
    check(preg_match('/href="[^"]*f%5Bstatus%5D=on_hold"/', $body) === 1, 'En espera enlaza a su filtro');

    $active = $web->get('/admin/orders?f[status]=on_hold')->body;
    preg_match_all('/<a class="af-tab" href="[^"]*" aria-selected="true"[^>]*>(.*?)<\/a>/s', $active, $a);
    same(['En espera1'], array_map(static function (string $html): string {
        return trim(strip_tags($html));
    }, $a[1]), 'la pestaña activa es la del filtro');
});

test('exportar el listado en CSV pide orders.export, respeta los filtros y queda en la auditoría', function (): void {
    list($app, $p, $paid, $unpaid, $hold) = adminOrdersThree();
    $web = adminClient($app);
    $r = $web->get('/admin/orders/export.csv');
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment', $r->headers['Content-Disposition']);
    contains(adminOrdersNumber($app, $paid), $r->body);
    contains('ana@cliente.test', $r->body);
    contains('44,95', $r->body, 'los importes salen en euros con coma');
    same(1, aifecOrderCount($app, 'audit', 'action = ? AND entity = ?', ['export', 'orders']));

    $filtered = $web->get('/admin/orders/export.csv?f[status]=on_hold')->body;
    contains(adminOrdersNumber($app, $hold), $filtered);
    notContains(adminOrdersNumber($app, $paid), $filtered, 'el CSV lleva solo lo filtrado');

    same(403, adminClient($app, 'support')->get('/admin/orders/export.csv')->status);
    same(1 + 1, aifecOrderCount($app, 'audit', 'action = ? AND entity = ?', ['export', 'orders']), 'lo rechazado no se apunta');
});

test('los carritos sin pedido de los últimos 30 días se ven; el convertido, el vacío y el viejo no', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['name' => 'Camiseta <roja>']);
    $open = new TestClient($app);
    $cartId = (int) aifecCartRun($open, static function ($cart) use ($p): int {
        $cart->add($p, 0, 2);

        return (int) $cart->summary()['id'];
    });
    $app->db()->run("UPDATE {carts} SET email = 'abierto@cliente.test' WHERE id = ?", [$cartId]);

    $old = new TestClient($app);
    $oldId = (int) aifecCartRun($old, static function ($cart) use ($p): int {
        $cart->add($p, 0, 1);

        return (int) $cart->summary()['id'];
    });
    $app->db()->run("UPDATE {carts} SET email = 'viejo@cliente.test', updated_at = ? WHERE id = ?", [$app->clock()->sql($app->clock()->now() - 40 * 86400), $oldId]);

    adminOrdersPlace($app, 'gw', [[$p, 0, 1]], ['email' => 'convertido@cliente.test']);
    $app->db()->run("UPDATE {carts} SET email = 'convertido@cliente.test' WHERE order_id IS NOT NULL");
    $app->db()->insert('carts', ['token' => 'vacio', 'items' => '[]', 'email' => 'vacio@cliente.test', 'lang' => 'es', 'source' => 'web', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);

    $web = adminClient($app);
    $r = $web->get('/admin/orders/carts');
    same(200, $r->status);
    contains('abierto@cliente.test', $r->body);
    contains('Camiseta &lt;roja&gt;', $r->body, 'el nombre del producto sale escapado');
    contains($app->i18n()->money(4000, 'EUR'), $r->body, 'el importe de lo que lleva');
    notContains('convertido@cliente.test', $r->body, 'el convertido en pedido no sale');
    notContains('viejo@cliente.test', $r->body, 'el de hace más de 30 días no sale');
    notContains('vacio@cliente.test', $r->body, 'el vacío no sale');
    contains('/admin/orders/carts', $web->get('/admin')->body, 'el menú lleva a los carritos');

    same(403, adminClient($app, 'editor')->get('/admin/orders/carts')->status);
});

test('el menú lleva a pedidos, carritos, estados y ajustes de ventas solo a quien puede verlos', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $body = $web->get('/admin')->body;
    foreach (['/admin/orders', '/admin/orders/carts', '/admin/settings/orders'] as $path) {
        contains('href="' . $path . '"', $body, $path);
    }
    // Los estados de pedido son una pestaña de «Ajustes › Ventas» (P102): se llega desde la barra de pestañas de esa pantalla.
    contains('role="tab" href="/admin/r/order_states"', $web->get('/admin/settings/orders')->body, 'la pestaña de los estados');
    $editor = adminClient($app, 'editor');
    $body = $editor->get('/admin')->body;
    notContains('href="/admin/orders"', $body);
    notContains('href="/admin/r/order_states"', $body);
    notContains('href="/admin/settings/orders"', $body);
});

test('los ajustes de ventas se guardan desde un solo formulario con tres pestañas y se leen de la base', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $page = $web->get('/admin/settings/orders');
    same(200, $page->status);
    foreach (['orgeneral', 'orpayments', 'ordownloads'] as $tab) {
        contains($tab, $page->body, 'pestaña ' . $tab);
    }
    contains('name="f[orders.number_format]"', $page->body);

    $r = adminOrdersPost($web, '/admin/settings/orders', ['f' => [
        'orders.number_format' => '{year}/{number:5}', 'orders.guest_checkout' => '0', 'orders.require_vat_id' => '1', 'orders.pending_minutes' => '45',
        'orders.payment_order' => 'transfer, gw', 'orders.download_limit' => '3', 'orders.download_days' => '30',
    ]]);
    same(302, $r->status);
    $app->settings()->reload();
    $settings = $app->settings();
    same('{year}/{number:5}', $settings->str('orders.number_format'));
    same(false, $settings->bool('orders.guest_checkout', true), 'el interruptor se puede apagar');
    same(true, $settings->bool('orders.require_vat_id'));
    same(45, $settings->int('orders.pending_minutes'));
    same('transfer, gw', $settings->str('orders.payment_order'));
    same(3, $settings->int('orders.download_limit'));
    same(30, $settings->int('orders.download_days'));
    same(['transfer', 'gw'], array_map(static function ($method): string {
        return $method->code();
    }, $app->service('payments')->available(['total' => 1000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true])), 'el orden de pago guardado se aplica');

    same(403, adminClient($app, 'support')->get('/admin/settings/orders')->status, 'soporte no cambia los ajustes de ventas');
});
