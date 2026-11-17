<?php
/**
 * AIFEC — pruebas de las zonas de envío: datos de serie y qué zona gana para cada destino.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/ShippingKit.php';

test('al instalar salen las cuatro zonas de España y ningún transportista', function (): void {
    $app = shippingTestApp();
    same(4, shippingCount($app, 'shipping_zones'));
    same(4, shippingCount($app, 'zone_locations'));
    same(0, shippingCount($app, 'carriers'));
    same(0, shippingCount($app, 'carrier_rates'));
    same('', (string) $app->db()->value("SELECT postcodes FROM {zone_locations} WHERE zone_id = ?", [shippingZoneId($app, 'Peninsula')]), 'Península es ES sin códigos postales');
    same('07', (string) $app->db()->value("SELECT postcodes FROM {zone_locations} WHERE zone_id = ?", [shippingZoneId($app, 'Balearic Islands')]));
    same('35,38', (string) $app->db()->value("SELECT postcodes FROM {zone_locations} WHERE zone_id = ?", [shippingZoneId($app, 'Canary Islands')]));
    same('51,52', (string) $app->db()->value("SELECT postcodes FROM {zone_locations} WHERE zone_id = ?", [shippingZoneId($app, 'Ceuta and Melilla')]));
    same(4, shippingCount($app, 'zone_locations', "country = 'ES'"));
});

test('la migración de serie no toca una tienda que ya tiene zonas', function (): void {
    $app = shippingTestApp();
    $app->db()->run('DELETE FROM {zone_locations}');
    $app->db()->run('DELETE FROM {shipping_zones}');
    shippingAddZone($app, 'Mía', [['country' => 'FR']]);

    $seed = require dirname(__DIR__) . '/migrations/0002_seed.php';
    $seed(new \Aifec\Db\Schema($app->db()), $app->db());

    same(1, shippingCount($app, 'shipping_zones'), 'no se añadió ninguna zona de serie');
    same('Mía', (string) $app->db()->value('SELECT name FROM {shipping_zones}'));

    // Y en una tienda vacía las crea una vez.
    $app->db()->run('DELETE FROM {zone_locations}');
    $app->db()->run('DELETE FROM {shipping_zones}');
    $seed(new \Aifec\Db\Schema($app->db()), $app->db());
    $seed(new \Aifec\Db\Schema($app->db()), $app->db());
    same(4, shippingCount($app, 'shipping_zones'), 'ejecutada dos veces sigue habiendo cuatro');
});

test('cada código postal de España cae en su zona', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    $pen = shippingZoneId($app, 'Peninsula');

    same($pen, $shipping->zoneFor(shippingDest('ES', '28001', '28')), 'Madrid');
    same($pen, $shipping->zoneFor(shippingDest('ES', '08001')), 'Barcelona');
    same(shippingZoneId($app, 'Balearic Islands'), $shipping->zoneFor(shippingDest('ES', '07001')), 'Palma');
    same(shippingZoneId($app, 'Canary Islands'), $shipping->zoneFor(shippingDest('ES', '35001')), 'Las Palmas');
    same(shippingZoneId($app, 'Canary Islands'), $shipping->zoneFor(shippingDest('ES', '38001')), 'Santa Cruz de Tenerife');
    same(shippingZoneId($app, 'Ceuta and Melilla'), $shipping->zoneFor(shippingDest('ES', '51001')), 'Ceuta');
    same(shippingZoneId($app, 'Ceuta and Melilla'), $shipping->zoneFor(shippingDest('ES', '52001')), 'Melilla');
    same($pen, $shipping->zoneFor(shippingDest('ES', '')), 'sin código postal, la zona del país');
    same($pen, $shipping->zoneFor(shippingDest('es', ' 28001 ')), 'el país en minúsculas y el código con espacios');
});

test('un país sin zona no tiene zona, y una zona «resto del mundo» lo recoge sin quitarle nada a España', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    same(null, $shipping->zoneFor(shippingDest('FR', '75001')), 'Francia sin zona');
    same(null, $shipping->zoneFor(shippingDest('PT', '1000')));

    $world = shippingAddZone($app, 'Mundo', [['country' => '*']]);
    same($world, $shipping->zoneFor(shippingDest('FR', '75001')), 'con «*», Francia cae ahí');
    same($world, $shipping->zoneFor(shippingDest('JP', '100-0001')));
    same(shippingZoneId($app, 'Peninsula'), $shipping->zoneFor(shippingDest('ES', '28001')), 'España sigue en su zona: un país gana a «*»');
    same(shippingZoneId($app, 'Canary Islands'), $shipping->zoneFor(shippingDest('ES', '35001')));
});

test('gana la localización más concreta: códigos postales, región, país, resto del mundo', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    $pen = shippingZoneId($app, 'Peninsula');
    $region = shippingAddZone($app, 'Región 28', [['country' => 'ES', 'region' => '28']], 20);
    $range = shippingAddZone($app, 'Rango Madrid', [['country' => 'ES', 'postcodes' => '28000-28099']], 30);

    same($range, $shipping->zoneFor(shippingDest('ES', '28001', '28')), 'el rango de códigos postales gana a la región');
    same($region, $shipping->zoneFor(shippingDest('ES', '28500', '28')), 'fuera del rango, la región gana al país');
    same($pen, $shipping->zoneFor(shippingDest('ES', '28500', '29')), 'otra región: el país');
    same($pen, $shipping->zoneFor(shippingDest('ES', '28500')), 'sin región en el destino la zona de región no casa');
    same($range, $shipping->zoneFor(shippingDest('ES', '28099')), 'el extremo del rango entra');
    same($region, $shipping->zoneFor(shippingDest('ES', '28100', '28')), 'justo después del rango, no');
    same($range, $shipping->zoneFor(shippingDest('ES', '28050', 'cualquiera')), 'con códigos postales la región ni se mira');
});

test('una zona inactiva se ignora, y a igual concreción gana la de menor posición', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    $canarias = shippingZoneId($app, 'Canary Islands');
    $app->db()->update('shipping_zones', ['active' => 0], ['id' => $canarias]);
    same(shippingZoneId($app, 'Peninsula'), $shipping->zoneFor(shippingDest('ES', '35001')), 'sin Canarias, el código cae en la península');

    $app->db()->update('shipping_zones', ['active' => 1], ['id' => $canarias]);
    same($canarias, $shipping->zoneFor(shippingDest('ES', '35001')));

    // Dos zonas igual de concretas para 35001: gana la de menor posición.
    $a = shippingAddZone($app, 'A', [['country' => 'ES', 'postcodes' => '35']], 5);
    $b = shippingAddZone($app, 'B', [['country' => 'ES', 'postcodes' => '35']], -1);
    same($b, $shipping->zoneFor(shippingDest('ES', '35001')), 'la de posición -1');
    $app->db()->update('shipping_zones', ['position' => 100], ['id' => $b]);
    same($canarias, $shipping->zoneFor(shippingDest('ES', '35001')), 'con A en 5, B en 100 y Canarias en 2, gana Canarias');
    check($a > 0, 'la zona A existe');
});

test('los rangos y los prefijos de códigos postales se leen como en los impuestos', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    $mixed = shippingAddZone($app, 'Mezcla', [['country' => 'PT', 'postcodes' => '1000-1999, 4']], 10);
    same($mixed, $shipping->zoneFor(shippingDest('PT', '1500')), 'dentro del rango');
    same($mixed, $shipping->zoneFor(shippingDest('PT', '4000-123')), 'un prefijo casa con un código portugués con guion');
    same(null, $shipping->zoneFor(shippingDest('PT', '2000')), 'fuera de todo');
    same(null, $shipping->zoneFor(shippingDest('PT', '')), 'sin código postal no casa con una lista de códigos');
});

test('un destino sin país usa el país de partida de los ajustes', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    same('ES', $shipping->defaultCountry());
    same(shippingZoneId($app, 'Peninsula'), $shipping->zoneFor(['postcode' => '28001']));
    same(shippingZoneId($app, 'Peninsula'), $shipping->zoneFor([]), 'un destino vacío es el de la tienda');
    same(true, $shipping->estimateInCart());

    $fr = shippingAddZone($app, 'Francia', [['country' => 'FR']]);
    $app->settings()->set('shipping.default_country', 'fr');
    $app->settings()->set('shipping.estimate_in_cart', false);
    same('FR', $shipping->defaultCountry());
    same($fr, $shipping->zoneFor(['postcode' => '75001']), 'ahora el país de partida es Francia');
    same(false, $shipping->estimateInCart());

    $app->settings()->set('shipping.default_country', 'basura');
    same('ES', $shipping->defaultCountry(), 'un valor que no es un país vuelve a ES');
});

test('un destino con datos raros no rompe nada', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    same(null, $shipping->zoneFor(['country' => 'FR', 'postcode' => ['no', 'texto'], 'region' => null]));
    same(shippingZoneId($app, 'Peninsula'), $shipping->zoneFor(['country' => 12345, 'postcode' => '28001']), 'un país que no es texto de dos letras se toma como el de partida');
    same(null, $shipping->zoneFor(shippingDest('FR', "75001'; DROP TABLE af_carriers; --")));
    same(0, shippingCount($app, 'carriers'));
    same(4, shippingCount($app, 'shipping_zones'));
});
