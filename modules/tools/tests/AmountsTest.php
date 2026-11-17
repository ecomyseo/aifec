<?php
/**
 * AIFEC — pruebas de importes y números del CSV: coma o punto decimal, separador de miles, lo ambiguo y lo mal escrito.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Tools\Amounts;

require_once __DIR__ . '/helpers.php';

test('los importes se leen con coma o con punto decimal y con separador de miles', function (): void {
    same(1999, Amounts::parse('19,99'));
    same(1999, Amounts::parse('19.99'));
    same(199950, Amounts::parse('1.999,50'));
    same(199950, Amounts::parse('1,999.50'));
    same(1200, Amounts::parse('12'));
    same(50, Amounts::parse('0,5'));
    same(5, Amounts::parse('0,05'));
    same(700, Amounts::parse('  7 € '));
    same(123456700, Amounts::parse('1.234.567'));
    same(0, Amounts::parse('0'));
});

test('un importe que se puede leer de dos maneras, o que no es un importe, es un error y no se adivina', function (): void {
    foreach (['', 'abc', '1.999', '1,999', '-5', '19,99,5x', '12e3', '1,5€x', '€', '99999999999'] as $bad) {
        throws(function () use ($bad): void {
            Amounts::parse($bad);
        }, \InvalidArgumentException::class, 'el importe «' . $bad . '» no vale');
    }
});

test('un importe se escribe con coma decimal y dos decimales, sin coma flotante', function (): void {
    same('19,99', Amounts::format(1999));
    same('0,05', Amounts::format(5));
    same('1,00', Amounts::format(100));
    same('0,00', Amounts::format(0));
    same('1234567,89', Amounts::format(123456789));
    foreach ([0, 1, 99, 100, 101, 1999, 2000000000] as $cents) {
        same($cents, Amounts::parse(Amounts::format($cents)), 'ida y vuelta de ' . $cents);
    }
});

test('un número decimal se normaliza a punto y se muestra con coma', function (): void {
    same('130.5', Amounts::decimal('130,5'));
    same('0.05', Amounts::decimal('0,0500'));
    same('1234.5', Amounts::decimal('1.234,5'));
    same('7', Amounts::decimal('7'));
    same('130,5', Amounts::showDecimal('130.5000'));
    same('7', Amounts::showDecimal('7.0000'));
    same('0', Amounts::showDecimal(''));
    throws(function (): void {
        Amounts::decimal('1,23456');
    }, \InvalidArgumentException::class);
});
