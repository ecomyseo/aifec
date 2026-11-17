<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Veinte órdenes en castellano y veinte en inglés que el copiloto entiende SIN IA, cada una con la acción y los datos que tiene
 * que sacar, y lo que no tiene que entender nunca (charla, preguntas, borrar).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Las cuarenta órdenes: texto con «{n}» (el número del pedido), acción esperada y los datos que tiene que llevar.
 *
 * @return array<int,array{0:string,1:string,2:array<string,mixed>}>
 */
function copilotExampleCases(): array
{
    return [
        // Castellano
        ['Marca el pedido {n} como enviado con el seguimiento 4455', 'orders.ship', ['order' => '{n}', 'tracking' => '4455']],
        ['Cambia el pedido {n} a completado', 'orders.status', ['order' => '{n}', 'status' => 'completed']],
        ['Pon el pedido {n} en espera', 'orders.status', ['status' => 'on_hold']],
        ['Añade una nota privada al pedido {n}: llamar mañana', 'orders.note', ['order' => '{n}', 'note' => 'llamar mañana', 'public' => false]],
        ['Reembolsa el pedido {n}', 'orders.refund', ['order' => '{n}']],
        ['Pon el precio de la silla roja a 59,90', 'catalog.price', ['product' => 'silla roja', 'price' => 5990]],
        ['Sube 5 euros el precio de SILLA-A', 'catalog.price', ['product' => 'silla-a', 'delta' => 500]],
        ['Baja un 10 % el precio de SILLA-A', 'catalog.price', ['product' => 'silla-a', 'percent' => -1000]],
        ['Sube un 5 % los precios de la categoría Sillas', 'catalog.bulk_price', ['category' => 'Sillas', 'percent' => 500]],
        ['Baja un 20 % los precios de la marca Acme', 'catalog.bulk_price', ['brand' => 'Acme', 'percent' => -2000]],
        ['Pon el stock de la silla roja a 20', 'catalog.stock', ['product' => 'silla roja', 'quantity' => 20]],
        ['Añade 5 unidades a SILLA-A', 'catalog.stock', ['product' => 'silla-a', 'delta' => 5]],
        ['Desactiva la silla roja', 'catalog.visibility', ['product' => 'silla roja', 'active' => false]],
        ['Crea un producto borrador llamado Silla Verde a 45,50', 'catalog.create', ['name' => 'Silla Verde', 'price' => 4550]],
        ['Crea el cupón VERANO10 con un 10 % hasta el 31/12/2026', 'coupons.create', ['code' => 'VERANO10', 'percent' => 1000, 'ends' => '2026-12-31']],
        ['Pon la tarifa de GLS a 6,90', 'shipping.rate', ['carrier' => 'GLS', 'price' => 690]],
        ['Envío gratis a partir de 50 € con GLS', 'shipping.free_from', ['carrier' => 'GLS', 'amount' => 5000]],
        ['Cambia el nombre de la tienda a Muebles Ejemplo', 'settings.set', ['setting' => 'store.name', 'value' => 'Muebles Ejemplo']],
        ['Muestra los precios sin impuestos', 'settings.set', ['setting' => 'tax.display_with_tax', 'value' => '0']],
        ['Pon 12 productos por página', 'settings.set', ['setting' => 'catalog.per_page', 'value' => '12']],
        // Inglés
        ['Mark order {n} as shipped with tracking 4455', 'orders.ship', ['order' => '{n}', 'tracking' => '4455']],
        ['Change order {n} to completed', 'orders.status', ['order' => '{n}', 'status' => 'completed']],
        ['Put order {n} on hold', 'orders.status', ['status' => 'on_hold']],
        ['Add a private note to order {n}: call tomorrow', 'orders.note', ['order' => '{n}', 'note' => 'call tomorrow', 'public' => false]],
        ['Refund order {n}', 'orders.refund', ['order' => '{n}']],
        ['Set the price of SILLA-R to 59.90', 'catalog.price', ['product' => 'silla-r', 'price' => 5990]],
        ['Raise the price of SILLA-A by 5 euros', 'catalog.price', ['product' => 'silla-a', 'delta' => 500]],
        ['Lower the price of SILLA-A by 10 %', 'catalog.price', ['product' => 'silla-a', 'percent' => -1000]],
        ['Raise the prices of the category Sillas by 5 %', 'catalog.bulk_price', ['category' => 'Sillas', 'percent' => 500]],
        ['Lower the prices of the brand Acme by 20 %', 'catalog.bulk_price', ['brand' => 'Acme', 'percent' => -2000]],
        ['Set the stock of SILLA-R to 20', 'catalog.stock', ['product' => 'silla-r', 'quantity' => 20]],
        ['Add 5 units to SILLA-A', 'catalog.stock', ['product' => 'silla-a', 'delta' => 5]],
        ['Deactivate SILLA-R', 'catalog.visibility', ['product' => 'silla-r', 'active' => false]],
        ['Create a draft product named Green Chair at 45.50', 'catalog.create', ['name' => 'Green Chair', 'price' => 4550]],
        ['Create the coupon SUMMER10 with 10 % off until 2026-12-31', 'coupons.create', ['code' => 'SUMMER10', 'percent' => 1000, 'ends' => '2026-12-31']],
        ['Set the GLS rate to 6.90', 'shipping.rate', ['carrier' => 'GLS', 'price' => 690]],
        ['Free shipping for orders over 50 € with GLS', 'shipping.free_from', ['carrier' => 'GLS', 'amount' => 5000]],
        ['Set the shop name to Acme Shop', 'settings.set', ['setting' => 'store.name', 'value' => 'Acme Shop']],
        ['Show the prices with tax', 'settings.set', ['setting' => 'tax.display_with_tax', 'value' => '1']],
        ['Set products per page to 12', 'settings.set', ['setting' => 'catalog.per_page', 'value' => '12']],
    ];
}

test('cuarenta órdenes en castellano y en inglés se entienden sin IA: la acción y los datos que tocan', function (): void {
    $app = copilotApp();
    copilotShop($app);
    $order = copilotOrder($app);
    shippingAddCarrier($app, 'gls', ['name' => 'GLS']);
    $web = copilotAdmin($app);
    $wrong = [];

    foreach (copilotExampleCases() as $case) {
        list($text, $action, $args) = $case;
        $text = str_replace('{n}', $order['n'], $text);
        $row = copilotAsk($web, $text);
        if ($row['action'] !== $action) {
            $wrong[] = $text . ' → ' . ($row['action'] === '' ? 'no entendida' : $row['action']) . ' (esperaba ' . $action . ')';
            continue;
        }
        foreach ($args as $name => $value) {
            $value = is_string($value) ? str_replace('{n}', $order['n'], $value) : $value;
            if (!array_key_exists($name, $row['args']) || $row['args'][$name] !== $value) {
                $wrong[] = $text . ' → ' . $name . ' = ' . json_encode($row['args'][$name] ?? null) . ' (esperaba ' . json_encode($value) . ')';
            }
        }
    }
    same([], $wrong);
    same(40, (int) $app->db()->value('SELECT COUNT(*) FROM {copilot_proposals}'), 'cada orden dejó su fila');
});

test('lo que no es una orden de las que sabe hacer no se entiende: charla, preguntas y borrar; queda apuntado y no cambia nada', function (): void {
    $app = copilotApp();
    copilotShop($app);
    $order = copilotOrder($app);
    $web = copilotAdmin($app);
    $products = (int) $app->db()->value('SELECT COUNT(*) FROM {products}');
    $orders = (int) $app->db()->value('SELECT COUNT(*) FROM {orders}');

    foreach (['hola, qué tal', 'cuéntame un chiste', 'what is the capital of France', 'borra todos los productos', 'elimina el pedido ' . $order['n'], 'delete everything', '??'] as $text) {
        $row = copilotAsk($web, $text);
        same(['failed', ''], [$row['status'], $row['action']], $text);
        same(true, copilotService($app)->present($row)['can_apply'] === false, $text . ' → nada que aplicar');
    }
    same($products, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    same($orders, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('devolver el dinero, cancelar o anular un pedido, dicho de varias maneras, solo da el enlace al pedido', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    foreach (['Devuélveme el dinero del pedido ' . $order['n'], 'Anula el pedido ' . $order['n'], 'Cancel order ' . $order['n'], 'Please refund the order ' . $order['n']] as $text) {
        $row = copilotAsk($web, $text);
        same('orders.refund', $row['action'], $text);
    }
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]));
});
