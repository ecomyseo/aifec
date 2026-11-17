<?php
/**
 * AIFEC — pruebas de la aritmética del dinero y del cálculo de un pedido. Las cifras esperadas están hechas a mano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Commerce\Totals;

test('dinero: redondeo, tantos por ciento y separación de impuestos', function (): void {
    same(3, Money::divRound(5, 2), '2,5 sube a 3');
    same(-3, Money::divRound(-5, 2), 'y −2,5 se aleja de cero');
    same(2, Money::divRound(4, 2));
    same(0, Money::divRound(1, 3));
    same(1, Money::divRound(2, 3));
    same(0, Money::divRound(0, 7));
    throws(function (): void {
        Money::divRound(1, 0);
    }, \InvalidArgumentException::class);

    same(420, Money::percent(1999, 2100));
    same(100, Money::percent(1000, 1000));
    same(111, Money::percent(333, 3333));
    same(-420, Money::percent(-1999, 2100));

    same([1652, 347], Money::split(1999, 2100));
    same([100, 21], Money::split(121, 2100));
    same([100, 0], Money::split(100, 0));
    same([1, 0], Money::split(1, 2100));
    same([-165, -35], Money::split(-200, 2100));
    same([347, 1999], Money::add(1652, 2100));
    same([21, 121], Money::add(100, 2100));
    same([0, 100], Money::add(100, 0));

    same('19.99', Money::decimal(1999));
    same('0.05', Money::decimal(5));
    same('-2.50', Money::decimal(-250));
    same('120.00', Money::decimal(12000));
});

test('dinero: repartir no pierde ni inventa un céntimo', function (): void {
    same([34, 33, 33], Money::allocate(100, [1, 1, 1]));
    same(['a' => 1000, 'b' => 0], Money::allocate(1000, ['a' => 1999, 'b' => 1]));
    same([-34, -33, -33], Money::allocate(-100, [1, 1, 1]));
    same([0, 0], Money::allocate(5, [0, 0]));
    same(['x' => 0, 'y' => 7], Money::allocate(7, ['x' => 0, 'y' => 5]));
    same([], Money::allocate(10, []));
    throws(function (): void {
        Money::allocate(10, [1, -1]);
    }, \InvalidArgumentException::class);

    // Mil repartos con números cualesquiera (serie fija, sin azar): siempre suma el total y nadie recibe de más.
    $seed = 12345;
    $next = static function (int $max) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;

        return $seed % ($max + 1);
    };
    for ($i = 0; $i < 1000; $i++) {
        $total = $next(100000);
        $weights = [];
        $n = 1 + $next(6);
        for ($j = 0; $j < $n; $j++) {
            $weights[] = $next(50000);
        }
        $shares = Money::allocate($total, $weights);
        $sum = array_sum($weights);
        same($sum === 0 ? 0 : $total, array_sum($shares), 'reparto ' . $i);
        foreach ($shares as $k => $share) {
            check($share >= 0 && ($weights[$k] > 0 || $share === 0), 'una parte sin peso no recibe nada');
            if ($sum > 0) {
                check(abs($share - $total * $weights[$k] / $sum) < 1.0001, 'cada parte se desvía menos de un céntimo de su proporción exacta');
            }
        }
    }
});

/**
 * @return array<string,mixed>
 */
function aifecBasket(): array
{
    return [
        'prices_include_tax' => true,
        'lines' => [
            ['key' => 'a', 'unit_price' => 1999, 'qty' => 2, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'],
            ['key' => 'b', 'unit_price' => 500, 'qty' => 1, 'tax_rate' => 1000, 'tax_name' => 'IVA 10 %'],
        ],
        'shipping' => ['label' => 'GLS 24 h', 'price' => 495, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'],
    ];
}

test('pedido con precios que llevan el IVA dentro: el desglose cuadra al céntimo', function (): void {
    $t = Totals::calculate(aifecBasket());
    same(4498, $t['items_total']);
    same(0, $t['discount_total']);
    same(['subtotal' => 3998, 'net' => 3304, 'tax' => 694, 'gross' => 3998], array_intersect_key($t['lines']['a'], ['subtotal' => 1, 'net' => 1, 'tax' => 1, 'gross' => 1]));
    same(['net' => 455, 'tax' => 45, 'gross' => 500], array_intersect_key($t['lines']['b'], ['net' => 1, 'tax' => 1, 'gross' => 1]));
    same(['net' => 409, 'tax' => 86, 'gross' => 495], array_intersect_key($t['shipping'], ['net' => 1, 'tax' => 1, 'gross' => 1]));
    same(4993, $t['total'], 'lo que se cobra es exactamente la suma de los precios publicados');
    same(4168, $t['total_net']);
    same(825, $t['total_tax']);
    same([
        ['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 3713, 'amount' => 780],
        ['name' => 'IVA 10 %', 'rate' => 1000, 'base' => 455, 'amount' => 45],
    ], $t['taxes']);
});

test('descuentos: tanto por ciento repartido entre líneas, importe fijo con tope y envío gratis', function (): void {
    $t = Totals::calculate(aifecBasket() + ['discounts' => [['code' => 'OTOÑO', 'label' => 'Cupón OTOÑO', 'kind' => 'percent', 'percent' => 1000]]]);
    same(450, $t['discount_total']);
    same(400, $t['lines']['a']['discount']);
    same(50, $t['lines']['b']['discount']);
    same(3598, $t['lines']['a']['total']);
    same([2974, 624], [$t['lines']['a']['net'], $t['lines']['a']['tax']]);
    same([409, 41], [$t['lines']['b']['net'], $t['lines']['b']['tax']]);
    same(4543, $t['total']);
    same([['code' => 'OTOÑO', 'label' => 'Cupón OTOÑO', 'kind' => 'percent', 'amount' => 450]], $t['discounts']);

    $t = Totals::calculate(aifecBasket() + ['discounts' => [['code' => 'GORDO', 'kind' => 'fixed', 'amount' => 50000]]]);
    same(4498, $t['discount_total'], 'un descuento fijo no pasa del importe de los artículos');
    same(0, $t['lines']['a']['total']);
    same(495, $t['total'], 'el envío se sigue cobrando');

    $t = Totals::calculate(aifecBasket() + ['discounts' => [['code' => 'PORTES', 'kind' => 'free_shipping']]]);
    same(0, $t['shipping']['price']);
    same(true, $t['shipping']['free']);
    same(4498, $t['total']);
    same(3304, $t['taxes'][0]['base'], 'un envío gratis no aparece en el desglose de impuestos');

    $t = Totals::calculate(aifecBasket() + ['discounts' => [['kind' => 'fixed', 'amount' => 1000, 'keys' => ['b']]]]);
    same(500, $t['discount_total'], 'limitado a una línea, no pasa de esa línea');
    same(0, $t['lines']['a']['discount']);

    // Dos descuentos seguidos: el segundo se calcula sobre lo que deja el primero.
    $t = Totals::calculate(aifecBasket() + ['discounts' => [['kind' => 'fixed', 'amount' => 498], ['kind' => 'percent', 'percent' => 5000]]]);
    same(498 + 2000, $t['discount_total']);
    same(4498 - 2498 + 495, $t['total']);

    $basket = aifecBasket();
    $basket['lines'][0]['discountable'] = false;
    $t = Totals::calculate($basket + ['discounts' => [['kind' => 'percent', 'percent' => 1000]]]);
    same(50, $t['discount_total'], 'una línea marcada como no descontable queda fuera');

    throws(function (): void {
        Totals::calculate(aifecBasket() + ['discounts' => [['kind' => 'regalo']]]);
    }, \InvalidArgumentException::class);
});

test('precios sin impuestos, clientes exentos, recargos y líneas de ajuste', function (): void {
    $t = Totals::calculate([
        'prices_include_tax' => false,
        'lines' => [['key' => 'a', 'unit_price' => 1652, 'qty' => 1, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %']],
        'shipping' => ['label' => 'Envío', 'price' => 409, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'],
    ]);
    same([1652, 347, 1999], [$t['lines']['a']['net'], $t['lines']['a']['tax'], $t['lines']['a']['gross']]);
    same([409, 86, 495], [$t['shipping']['net'], $t['shipping']['tax'], $t['shipping']['gross']]);
    same(2494, $t['total']);

    $t = Totals::calculate(['prices_include_tax' => true, 'tax_exempt' => true, 'lines' => [['key' => 'a', 'unit_price' => 1999, 'qty' => 1, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %']]]);
    same([1652, 0, 1652], [$t['lines']['a']['net'], $t['lines']['a']['tax'], $t['lines']['a']['gross']], 'quien no paga impuestos paga la base del precio publicado');
    same(1652, $t['total']);
    same([['name' => 'IVA 21 %', 'rate' => 0, 'base' => 1652, 'amount' => 0]], $t['taxes']);

    $t = Totals::calculate(aifecBasket() + [
        'fees' => [['label' => 'Contra reembolso', 'amount' => 300, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %']],
        'adjustments' => [['label' => 'Descuento de socio', 'amount' => -200, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %', 'source' => 'plugin:socios']],
    ]);
    same(300, $t['fee_total']);
    same([248, 52], [$t['fees'][0]['net'], $t['fees'][0]['tax']]);
    same(-200, $t['adjustment_total']);
    same([-165, -35], [$t['adjustments'][0]['net'], $t['adjustments'][0]['tax']]);
    same('plugin:socios', $t['adjustments'][0]['source']);
    same(4993 + 300 - 200, $t['total']);

    throws(function (): void {
        Totals::calculate(aifecBasket() + ['adjustments' => [['label' => 'Demasiado', 'amount' => -99999, 'tax_rate' => 2100]]]);
    }, \DomainException::class);

    $empty = Totals::calculate(['prices_include_tax' => true]);
    same(0, $empty['total']);
    same(null, $empty['shipping']);
    same([], $empty['taxes']);
});

test('mil pedidos cualesquiera: los totales son siempre la suma de sus líneas', function (): void {
    $seed = 987;
    $next = static function (int $max) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;

        return $seed % ($max + 1);
    };
    $rates = [0, 400, 700, 1000, 2100];
    for ($i = 0; $i < 1000; $i++) {
        $inclusive = $next(1) === 1;
        $lines = [];
        for ($j = 0, $n = 1 + $next(5); $j < $n; $j++) {
            $lines[] = ['key' => 'k' . $j, 'unit_price' => $next(30000), 'qty' => 1 + $next(4), 'tax_rate' => $rates[$next(4)], 'tax_name' => 'T'];
        }
        $discounts = [];
        if ($next(2) > 0) {
            $discounts[] = ['kind' => 'percent', 'percent' => $next(10000)];
        }
        if ($next(2) > 0) {
            $discounts[] = ['kind' => 'fixed', 'amount' => $next(20000)];
        }
        $t = Totals::calculate([
            'prices_include_tax' => $inclusive, 'tax_exempt' => $next(9) === 0, 'lines' => $lines, 'discounts' => $discounts,
            'shipping' => $next(1) === 1 ? ['price' => $next(2000), 'tax_rate' => 2100, 'tax_name' => 'T'] : null,
            'fees' => $next(3) === 0 ? [['label' => 'F', 'amount' => $next(500), 'tax_rate' => 2100, 'tax_name' => 'T']] : [],
        ]);
        same($t['total_net'] + $t['total_tax'], $t['total'], 'pedido ' . $i);
        $gross = isset($t['shipping']) ? $t['shipping']['gross'] : 0;
        $net = isset($t['shipping']) ? $t['shipping']['net'] : 0;
        $discount = 0;
        foreach ($t['lines'] as $line) {
            $gross += $line['gross'];
            $net += $line['net'];
            $discount += $line['discount'];
            check($line['total'] >= 0 && $line['discount'] <= $line['subtotal'], 'ninguna línea queda en negativo');
            same($line['net'] + $line['tax'], $line['gross']);
        }
        foreach ($t['fees'] as $fee) {
            $gross += $fee['gross'];
            $net += $fee['net'];
        }
        same($gross, $t['total']);
        same($net, $t['total_net']);
        same($discount, $t['discount_total'], 'lo descontado en las líneas es lo que dicen los descuentos');
        $base = 0;
        $amount = 0;
        foreach ($t['taxes'] as $tax) {
            $base += $tax['base'];
            $amount += $tax['amount'];
        }
        same($t['total_tax'], $amount, 'el desglose de impuestos suma la cuota total');
        if ($inclusive && !$t['tax_exempt']) {
            same($t['items_total'] - $t['discount_total'] + (isset($t['shipping']) ? $t['shipping']['price'] : 0) + $t['fee_total'], $t['total'], 'con el IVA dentro se cobra exactamente lo publicado');
        }
    }
});
