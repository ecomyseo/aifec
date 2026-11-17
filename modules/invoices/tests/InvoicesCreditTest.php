<?php
/**
 * AIFEC — pruebas de las facturas rectificativas: una por devolución, con su serie, los importes en negativo y la referencia a la
 * factura original; el reparto entre tipos de impuesto sin perder un céntimo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Un pedido pagado de dos camisetas de 20,00 € y envío de 4,95 € (total 44,95 €).
 *
 * @return array{0:\Aifec\Kernel\App,1:int,2:int}
 */
function invoicesCreditOrder(array $settings = []): array
{
    $app = invoicesApp(['settings' => $settings]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);

    return [$app, $p, invoicesOrder($app, [[$p, 0, 2]])];
}

test('una devolución parcial genera su rectificativa: serie R, importes en negativo y referencia a la factura original', function (): void {
    list($app, $p, $id) = invoicesCreditOrder();
    $order = $app->service('orders')->find($id);
    same(4495, $order['total']);

    $refundId = $app->service('orders')->refund($id, 1500, [], 'Prenda con defecto', false, false);

    $all = $app->service('invoices')->forOrder($id);
    same(2, count($all));
    $invoice = $all[0];
    $credit = $all[1];
    same('invoice', $invoice['type']);
    same('credit', $credit['type']);
    same('R2026-00001', $credit['full_number']);
    same('R', $credit['series']);
    same($refundId, $credit['refund_id']);
    same($invoice['id'], $credit['original_id']);
    same(-1500, $credit['total']);
    same(-1500, $credit['net'] + $credit['tax']);
    check($credit['net'] < 0 && $credit['tax'] < 0, 'base y cuota en negativo');
    same($invoice['full_number'], $credit['data']['original']['full_number']);
    same('Prenda con defecto', $credit['data']['reason']);
    $gross = 0;
    foreach ($credit['data']['taxes'] as $tax) {
        check($tax['base'] < 0 && $tax['amount'] < 0, 'cada tipo, en negativo');
        $gross += $tax['base'] + $tax['amount'];
    }
    same(-1500, $gross);
    foreach ($credit['data']['lines'] as $line) {
        check($line['net'] < 0 && $line['total'] < 0, 'las líneas, en negativo');
    }
    same(1, invoicesCounter($app, 'F'), 'la serie de facturas no se tocó');
    same(1, invoicesCounter($app, 'R'));

    $text = invoicesPdfText((string) file_get_contents($app->service('invoices')->pdf($credit['id'])));
    contains('Factura rectificativa', $text);
    contains('R2026-00001', $text);
    contains('Rectifica la factura F2026-00001', $text);
    contains('-15,00 €', $text);
    contains('Prenda con defecto', $text);
    contains('Devolución de la factura F2026-00001', $text);
});

test('cada devolución tiene su propia rectificativa y repetir la llamada devuelve la misma sin gastar otro número', function (): void {
    list($app, $p, $id) = invoicesCreditOrder();
    $orders = $app->service('orders');
    $first = $orders->refund($id, 1000, [], 'Primera', false, false);
    $second = $orders->refund($id, 500, [], 'Segunda', false, false);
    $invoices = $app->service('invoices');

    $a = $invoices->credit($id, $first);
    $b = $invoices->credit($id, $second);
    same('R2026-00001', $a['full_number']);
    same('R2026-00002', $b['full_number']);
    same($a['id'], $invoices->credit($id, $first)['id']);
    same(2, invoicesCounter($app, 'R'));
    same(-500, $b['total']);

    throws(function () use ($invoices, $id): void {
        $invoices->credit($id, 99999);
    }, \InvalidArgumentException::class);
    throws(function () use ($invoices): void {
        $invoices->credit(99999, 1);
    }, \InvalidArgumentException::class);
    $other = invoicesOrder($app, [[aifecProduct($app, ['stock' => 20]), 0, 1]]);
    throws(function () use ($invoices, $other, $first): void {
        $invoices->credit($other, $first);
    }, \InvalidArgumentException::class);
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {invoices} WHERE type = 'credit'"));
});

test('la devolución de un pedido con dos tipos de impuesto reparte el importe entre ellos sin perder un céntimo', function (): void {
    $app = invoicesApp();
    $a = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'tax_class_id' => 'standard', 'stock' => 50]);
    $b = aifecProduct($app, ['name' => 'Libro', 'price' => 1100, 'tax_class_id' => 'reduced', 'stock' => 50]);
    $id = invoicesOrder($app, [[$a, 0, 3], [$b, 0, 2]]);
    $order = $app->service('orders')->find($id);
    check(count($order['taxes']) >= 2, 'el pedido tiene dos tipos');

    foreach ([3333, 1, 777] as $amount) {
        $refund = $app->service('orders')->refund($id, $amount, [], 'Reparto ' . $amount, false, false);
        $credit = $app->service('invoices')->credit($id, $refund);
        $gross = 0;
        $net = 0;
        $tax = 0;
        foreach ($credit['data']['taxes'] as $row) {
            $gross += $row['base'] + $row['amount'];
            $net += $row['base'];
            $tax += $row['amount'];
        }
        same(-$amount, $gross, 'el importe devuelto se reparte entero (' . $amount . ')');
        same(-$amount, $credit['total']);
        same($net, $credit['net']);
        same($tax, $credit['tax']);
        same(-$amount, $credit['net'] + $credit['tax']);
    }
});

test('si el pedido no tenía factura, la rectificativa emite antes la original y la referencia', function (): void {
    list($app, $p, $id) = invoicesCreditOrder(['invoices.auto' => false]);
    $invoices = $app->service('invoices');
    same([], $invoices->forOrder($id));

    $refund = $app->service('orders')->refund($id, 1000, [], 'Sin factura previa', false, false);
    same([], $invoices->forOrder($id), 'con la emisión apagada una devolución no genera nada sola');

    $credit = $invoices->credit($id, $refund);
    $all = $invoices->forOrder($id);
    same(2, count($all));
    same('F2026-00001', $all[0]['full_number'], 'la factura se emitió antes');
    same($all[0]['id'], $credit['original_id']);
    same('R2026-00001', $credit['full_number']);
});

test('una devolución entera deja la rectificativa por todo lo cobrado y las dos facturas cuadran a cero', function (): void {
    list($app, $p, $id) = invoicesCreditOrder();
    $order = $app->service('orders')->find($id);
    $app->service('orders')->refund($id, $order['total'], [], 'Todo', false, true);

    $all = $app->service('invoices')->forOrder($id);
    same(2, count($all));
    same(0, $all[0]['total'] + $all[1]['total'], 'factura + rectificativa = 0');
    same(0, $all[0]['net'] + $all[1]['net']);
    same(0, $all[0]['tax'] + $all[1]['tax']);
});

test('la rectificativa de un pedido de pruebas va a la serie TEST, como su factura, sin tocar la numeración real', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]], [], true);

    $app->service('orders')->refund($id, 500, [], 'Prueba', false, false);

    $all = $app->service('invoices')->forOrder($id);
    same(['TEST-00001', 'TEST-00002'], array_column($all, 'full_number'));
    same(0, invoicesCounter($app, 'F'));
    same(0, invoicesCounter($app, 'R'));
});

test('la serie y el formato de las rectificativas salen de sus ajustes', function (): void {
    list($app, $p, $id) = invoicesCreditOrder(['invoices.credit_series' => 'AB', 'invoices.credit_format' => '{series}-{number:3}']);

    $refund = $app->service('orders')->refund($id, 800, [], 'Ajuste', false, false);

    $credit = $app->service('invoices')->credit($id, $refund);
    same('AB-001', $credit['full_number']);
    same('AB', $credit['series']);
    same(1, invoicesCounter($app, 'AB'));
});
