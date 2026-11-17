<?php
/**
 * AIFEC — el carrito de prueba cuesta aquí lo mismo que en el origen: tras escribir los impuestos y los envíos de ejemplo (IVA, IGIC por código
 * postal, Ceuta, dos zonas, tarifas por peso con tres tramos, envío gratis desde 60 €), el servicio de impuestos y el de envíos devuelven, para
 * seis direcciones y tres pesos, las cifras hechas a mano.
 *
 * Las cifras: el origen escribe los envíos SIN impuestos (590, 990, 1490 en la península; 1290, 1890, 2690 en Canarias) y aquí los precios
 * llevan el 21 % dentro. Con el redondeo de Money: 590 → 714, 990 → 1198, 1490 → 1803, 1290 → 1561, 1890 → 2287 y 2690 → 3255.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/WritersKit.php';

test('seis direcciones y tres pesos cuestan aquí las cifras hechas a mano del origen', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    $shipping = $app->service('shipping');

    $mainland = [1000 => 714, 8000 => 1198, 20000 => 1803];
    $canarias = [1000 => 1561, 8000 => 2287, 20000 => 3255];
    $cases = [
        'Madrid' => [wrDest('ES', '28013'), $mainland],
        'Lisboa' => [wrDest('PT', '1000-001'), $mainland],
        'Santa Cruz de Tenerife' => [wrDest('ES', '38001'), $canarias],
        'Las Palmas' => [wrDest('ES', '35001'), $canarias],
        'Ceuta' => [wrDest('ES', '51001'), $mainland],
        'París' => [wrDest('FR', '75001'), null],
    ];
    foreach ($cases as $name => $case) {
        foreach ([1000, 8000, 20000] as $weight) {
            $options = $shipping->options(['weight' => $weight, 'items_total' => 2000, 'qty' => 1, 'needs_shipping' => true], $case[0]);
            $expected = $case[1] === null ? [] : ['mensajeria' => $case[1][$weight], 'recogida' => 0];
            same($expected, wrPrices($options), $name . ', ' . $weight . ' g');
        }
    }
});

test('el envío es gratis desde 60 € de artículos y no antes', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    $shipping = $app->service('shipping');
    $madrid = wrDest('ES', '28013');

    $paid = $shipping->options(['weight' => 1000, 'items_total' => 5999, 'qty' => 1, 'needs_shipping' => true], $madrid);
    same(['mensajeria' => 714, 'recogida' => 0], wrPrices($paid));
    same(false, $paid[0]['free']);
    $free = $shipping->options(['weight' => 1000, 'items_total' => 6000, 'qty' => 1, 'needs_shipping' => true], $madrid);
    same(['mensajeria' => 0, 'recogida' => 0], wrPrices($free));
    same(true, $free[0]['free']);
    same(false, $free[1]['needs_address'], 'la recogida no pide dirección');
    same(true, $free[0]['needs_address']);
});

test('el impuesto del envío sale de la clase que dice el origen', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $class = wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    $options = $app->service('shipping')->options(['weight' => 1000, 'items_total' => 2000, 'qty' => 1, 'needs_shipping' => true], wrDest('ES', '35001'));

    same($class, $options[0]['tax_class_id'], 'la clase del transportista es la importada');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $app->service('tax')->rate($options[0]['tax_class_id'], wrDest('ES', '35001')), 'y en Canarias le toca el IGIC');
    same(['rate' => 0, 'name' => ''], $app->service('tax')->rate($options[0]['tax_class_id'], wrDest('PT', '1000-001')), 'en Portugal el origen no traía tipos: sin tipo, como allí');
});

test('un paquete que no necesita envío no tiene opciones aunque el origen tenga transportistas', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    same([], $app->service('shipping')->options(['weight' => 0, 'items_total' => 2000, 'qty' => 1, 'needs_shipping' => false], wrDest('ES', '28013')));
});
