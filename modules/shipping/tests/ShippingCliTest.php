<?php
/**
 * AIFEC — shipping:rate: da una tarifa a un transportista para una zona (y la cambia si ya existe ese tramo); con ella, Palma ya
 * tiene envío (14/11/2026: «No podemos enviar a este destino» a un cliente de Baleares).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/ShippingKit.php';

test('shipping:rate añade la tarifa de Baleares, la cambia sin duplicarla y rechaza lo que no existe', function (): void {
    $app = shippingTestApp();
    $carrier = shippingAddCarrier($app, 'estandar');
    shippingAddRate($app, $carrier, shippingZoneId($app, 'Peninsula'), 'weight', 0, 40000, 490);
    same([], shippingService($app)->options(shippingPackage(), shippingDest('ES', '07001', '07')), 'antes, a Palma no se envía');

    $commands = require dirname(__DIR__) . '/cli.php';
    $run = $commands['shipping:rate']['run'];
    $io = new \Aifec\Cli\Console(false);
    same(0, $run($app, ['estandar', 'Balearic Islands', '990'], $io));
    contains('Rate added', $io->output);
    same(['estandar' => 990], shippingPrices(shippingService($app)->options(shippingPackage(), shippingDest('ES', '07001', '07'))), 'ya se envía a Palma');

    same(0, $run($app, ['estandar', 'Balearic Islands', '1190'], $io));
    same(1, shippingCount($app, 'carrier_rates', 'zone_id = ?', [shippingZoneId($app, 'Balearic Islands')]), 'el mismo tramo no se duplica');
    same(['estandar' => 1190], shippingPrices(shippingService($app)->options(shippingPackage(), shippingDest('ES', '07001', '07'))));

    same(1, $run($app, ['nada', 'Balearic Islands', '990'], $io), 'transportista que no existe');
    same(1, $run($app, ['estandar', 'Atlantis', '990'], $io), 'zona que no existe');
    same(2, $run($app, ['estandar'], $io), 'faltan datos');
});
