<?php
/**
 * AIFEC — pruebas de las facturas: emisión al pagar, numeración correlativa sin huecos, series y formatos, pedidos de pruebas y
 * datos congelados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('al pagar un pedido se emite su factura F2026-00001; el segundo pedido recibe la 00002', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $invoices = $app->service('invoices');

    $a = invoicesOrder($app, [[$p, 0, 1]]);
    $first = $invoices->forOrder($a);
    same(1, count($first));
    same('F2026-00001', $first[0]['full_number']);
    same('invoice', $first[0]['type']);
    same('F', $first[0]['series']);
    same(1, $first[0]['number']);
    same($a, $first[0]['order_id']);
    same($app->clock()->sql(), $first[0]['issued_at']);
    same(false, $first[0]['test']);
    same(null, $first[0]['refund_id']);

    $b = invoicesOrder($app, [[$p, 0, 2]]);
    same('F2026-00002', $invoices->forOrder($b)[0]['full_number']);
    same(2, invoicesCounter($app, 'F'));
});

test('emitir dos veces el mismo pedido devuelve la misma factura y no gasta otro número', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $invoices = $app->service('invoices');

    $first = $invoices->issue($id);
    $again = $invoices->issue($id);

    same($first['id'], $again['id']);
    same('F2026-00001', $again['full_number']);
    same(1, invoicesCounter($app, 'F'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'));
});

test('diez emisiones seguidas dan diez números consecutivos sin huecos', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 100]);
    $numbers = [];
    $fulls = [];
    for ($i = 1; $i <= 10; ++$i) {
        $invoice = $app->service('invoices')->issue(invoicesOrder($app, [[$p, 0, 1]]));
        $numbers[] = $invoice['number'];
        $fulls[] = $invoice['full_number'];
    }

    same(range(1, 10), $numbers);
    same('F2026-00001', $fulls[0]);
    same('F2026-00010', $fulls[9]);
    same(10, count(array_unique($fulls)));
    same(10, invoicesCounter($app, 'F'));
});

test('si la fila no se puede crear el número no se gasta: no queda ningún hueco', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $now = $app->clock()->sql();
    // Una fila ajena que ya tiene ese número impreso: la inserción de la factura fallará.
    $app->db()->insert('invoices', [
        'unique_key' => 'bloqueo', 'order_id' => 999, 'type' => 'invoice', 'series' => 'X', 'number' => 1, 'full_number' => 'F2026-00001', 'issued_at' => $now,
        'currency' => 'EUR', 'net' => 0, 'tax' => 0, 'total' => 0, 'test' => 0, 'data' => '{}', 'created_at' => $now, 'updated_at' => $now,
    ]);

    throws(function () use ($app, $id): void {
        $app->service('invoices')->issue($id);
    }, \Throwable::class);
    same(0, invoicesCounter($app, 'F'), 'el contador volvió atrás con la transacción');

    $app->db()->delete('invoices', ['unique_key' => 'bloqueo']);
    $invoice = $app->service('invoices')->issue($id);
    same('F2026-00001', $invoice['full_number']);
    same(1, invoicesCounter($app, 'F'));
});

test('un pedido de pruebas usa la serie TEST y no gasta numeración real', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $invoices = $app->service('invoices');

    $test = invoicesOrder($app, [[$p, 0, 1]], [], true);
    $invoice = $invoices->forOrder($test)[0];
    same('TEST-00001', $invoice['full_number']);
    same('TEST', $invoice['series']);
    same(true, $invoice['test']);
    same(0, invoicesCounter($app, 'F'), 'la serie real no se ha tocado');

    same('F2026-00001', $invoices->forOrder(invoicesOrder($app, [[$p, 0, 1]]))[0]['full_number'], 'el primer pedido real es el 00001');
    same('TEST-00002', $invoices->forOrder(invoicesOrder($app, [[$p, 0, 1]], [], true))[0]['full_number']);
});

test('la serie y el formato salen de los ajustes y un carácter que no vale en una dirección se cambia por un guion', function (): void {
    $app = invoicesApp(['settings' => ['invoices.series' => 'ab', 'invoices.format' => '{series}/{year}/{number:4}']]);
    $p = aifecProduct($app, ['stock' => 50]);

    $invoice = $app->service('invoices')->forOrder(invoicesOrder($app, [[$p, 0, 1]]))[0];
    same('ab-2026-0001', $invoice['full_number']);
    same('ab', $invoice['series']);
    same(1, invoicesCounter($app, 'ab'));

    $literal = invoicesApp(['settings' => ['invoices.series' => 'Z', 'invoices.format' => 'F{year}-{number:5}']]);
    $q = aifecProduct($literal, ['stock' => 50]);
    same('F2026-00001', $literal->service('invoices')->forOrder(invoicesOrder($literal, [[$q, 0, 1]]))[0]['full_number'], 'una F escrita en el formato');
    same(1, invoicesCounter($literal, 'Z'), 'y el contador es el de la serie');

    $odd = invoicesApp(['settings' => ['invoices.series' => '¿?', 'invoices.format' => '']]);
    $r = aifecProduct($odd, ['stock' => 50]);
    same('F2026-00001', $odd->service('invoices')->forOrder(invoicesOrder($odd, [[$r, 0, 1]]))[0]['full_number'], 'serie y formato vacíos o inválidos vuelven a los de serie');
});

test('no se emite factura de un pedido cancelado ni de uno que no existe; apagada la emisión automática, pagar no emite y a mano sí', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $invoices = $app->service('invoices');

    $id = invoicesOrder($app, [[$p, 0, 1]]);
    same([], $invoices->forOrder($id), 'pagar no emite con la emisión apagada');
    same('F2026-00001', $invoices->issue($id)['full_number'], 'a mano sí');

    $cancelled = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $app->service('orders')->cancel((int) $cancelled['id'], 'Ya no lo quiere');
    throws(function () use ($invoices, $cancelled): void {
        $invoices->issue((int) $cancelled['id']);
    }, \InvalidArgumentException::class);
    throws(function () use ($invoices): void {
        $invoices->issue(99999);
    }, \InvalidArgumentException::class);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'));
    same(1, invoicesCounter($app, 'F'), 'los intentos fallidos no gastaron número');
});

test('los datos se congelan al emitir: cambiar la tienda después no cambia la factura ni su PDF', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $invoices = $app->service('invoices');
    $invoice = $invoices->forOrder(invoicesOrder($app, [[$p, 0, 1]]))[0];
    same('Tienda de Pruebas S.L.', $invoice['data']['issuer']['name']);
    same('B12345678', $invoice['data']['issuer']['vat_id']);

    $app->settings()->setMany(['store.legal_name' => 'Otra Empresa S.A.', 'store.vat_id' => 'A99999999', 'invoices.legal_text' => 'Texto nuevo']);

    $after = $invoices->find($invoice['id']);
    same('Tienda de Pruebas S.L.', $after['data']['issuer']['name']);
    same('B12345678', $after['data']['issuer']['vat_id']);
    $text = invoicesPdfText((string) file_get_contents($invoices->pdf($invoice['id'])));
    contains('Tienda de Pruebas S.L.', $text);
    contains('B12345678', $text);
    notContains('Otra Empresa', $text);
    notContains('A99999999', $text);
    notContains('Texto nuevo', $text);
});

test('base e impuesto por cada tipo suman el total del pedido, y salen de los datos congelados del pedido, no de un cálculo nuevo', function (): void {
    $app = invoicesApp();
    $a = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'tax_class_id' => 'standard', 'stock' => 50]);
    $b = aifecProduct($app, ['name' => 'Libro', 'price' => 1100, 'tax_class_id' => 'reduced', 'stock' => 50]);
    $id = invoicesOrder($app, [[$a, 0, 3], [$b, 0, 2]]);
    $order = $app->service('orders')->find($id);
    $invoice = $app->service('invoices')->forOrder($id)[0];

    check(count($invoice['data']['taxes']) >= 2, 'hay dos tipos de impuesto');
    $gross = 0;
    $base = 0;
    $quota = 0;
    foreach ($invoice['data']['taxes'] as $tax) {
        $gross += $tax['base'] + $tax['amount'];
        $base += $tax['base'];
        $quota += $tax['amount'];
    }
    same($order['total'], $gross, 'base + impuesto de todos los tipos = total del pedido');
    same($order['total_net'], $base);
    same($order['total_tax'], $quota);
    same(['net' => $order['total_net'], 'tax' => $order['total_tax'], 'total' => $order['total']], ['net' => $invoice['net'], 'tax' => $invoice['tax'], 'total' => $invoice['total']]);
    same($order['taxes'], array_map(static function (array $t): array {
        return ['name' => $t['name'], 'rate' => $t['rate'], 'base' => $t['base'], 'amount' => $t['amount']];
    }, $invoice['data']['taxes']), 'los tipos son los del pedido tal cual');

    $lineNet = 0;
    $lineTax = 0;
    foreach ($invoice['data']['lines'] as $line) {
        $lineNet += $line['net'];
        $lineTax += $line['tax'];
    }
    same($order['total_net'], $lineNet, 'las líneas (con el envío) suman la base');
    same($order['total_tax'], $lineTax);
    $orderLines = [];
    foreach ($order['items'] as $item) {
        if ($item['kind'] === 'product') {
            $orderLines[$item['name']] = [$item['line_net'], $item['line_tax'], $item['tax_rate']];
        }
    }
    foreach ($invoice['data']['lines'] as $line) {
        if ($line['kind'] === 'product') {
            same($orderLines[$line['name']], [$line['net'], $line['tax'], $line['tax_rate']], 'la línea ' . $line['name'] . ' copia lo congelado');
        }
    }
});

test('la factura lleva el receptor del pedido y su dirección con nombres, sin el teléfono', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]], ['billing' => [
        'first_name' => 'Ana', 'last_name' => 'Ruiz', 'company' => 'Ruiz & Hijos S.L.', 'vat_id' => 'B87654321', 'line1' => 'Calle Mayor 1', 'postcode' => '28001',
        'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678',
    ]]);
    $invoice = $app->service('invoices')->forOrder($id)[0];

    same('Ruiz & Hijos S.L.', $invoice['data']['receiver']['name']);
    same('B87654321', $invoice['data']['receiver']['vat_id']);
    same('ES', $invoice['data']['receiver']['country']);
    same('ana@cliente.test', $invoice['data']['receiver']['email']);
    contains('Calle Mayor 1', implode("\n", $invoice['data']['receiver']['lines']));
    contains('Madrid', implode("\n", $invoice['data']['receiver']['lines']));
    contains('España', implode("\n", $invoice['data']['receiver']['lines']), 'el país con nombre, no el código');
    notContains('999345678', implode("\n", $invoice['data']['receiver']['lines']), 'el teléfono no sale en la factura');
    same($app->service('orders')->find($id)['number'], $invoice['data']['order']['number']);
});
