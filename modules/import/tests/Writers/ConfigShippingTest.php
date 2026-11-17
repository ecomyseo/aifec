<?php
/**
 * AIFEC — escritores de zonas de envío y de transportistas: crean, repiten sin cambios, actualizan, convierten los precios a como se
 * escriben aquí, apagan las zonas de serie y dejan notas de lo que no cabe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\CarriersWriter;
use Aifec\Modules\Import\Writers\ShippingZonesWriter;

require_once __DIR__ . '/WritersKit.php';

test('una zona se crea con sus localizaciones, se repite sin cambios y se actualiza al cambiar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new ShippingZonesWriter($app);
    $zones = wrCount($app, 'shipping_zones');
    $record = wr('shipping_zones', ['id' => 'z1', 'name' => 'Europa', 'locations' => [['country' => 'FR'], ['country' => 'DE', 'postcodes' => '10-12']]]);

    $first = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $first['result']);
    same($zones + 1, wrCount($app, 'shipping_zones'));
    same(2, wrCount($app, 'zone_locations', 'zone_id = ?', [$first['id']]));
    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same($zones + 1, wrCount($app, 'shipping_zones'), 'ni una zona nueva');

    $changed = $writer->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Europa', 'locations' => [['country' => 'IT']]]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same(['IT'], array_map('strval', $app->db()->column('SELECT country FROM {zone_locations} WHERE zone_id = ?', [$first['id']])), 'las localizaciones se sustituyen');
});

test('al traer la primera zona se apagan las zonas de serie intactas, y con ellas ninguna dirección cae en una zona sin tarifas', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    same(4, wrCount($app, 'shipping_zones', 'active = 1'), 'antes: las cuatro de serie');
    (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Todo España', 'locations' => [['country' => 'ES']]]), $ctx);

    same(1, wrCount($app, 'shipping_zones', 'active = 1'), 'solo queda la del origen');
    same(4, wrCount($app, 'shipping_zones', 'active = 0'), 'las de serie siguen ahí, apagadas');
    same(4, count(array_filter($ctx->noteTexts('shipping_zones'), static function (string $text): bool {
        return strpos($text, 'was switched off') !== false;
    })), 'y cada una queda apuntada');
});

test('una zona de serie que ya tiene tarifas, o que alguien tocó, no se apaga', function (): void {
    $app = writersApp();
    $peninsula = (int) wrValue($app, "SELECT id FROM {shipping_zones} WHERE name = 'Peninsula'");
    $carrier = $app->db()->insert('carriers', ['code' => 'mio', 'name' => 'Mío', 'handler' => 'rates', 'max_weight' => 0, 'active' => 1, 'position' => 0, 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $app->db()->insert('carrier_rates', ['carrier_id' => $carrier, 'zone_id' => $peninsula, 'basis' => 'weight', 'min' => 0, 'max' => 0, 'price' => 500]);
    $balearic = (int) wrValue($app, "SELECT id FROM {shipping_zones} WHERE name = 'Balearic Islands'");
    $app->db()->insert('zone_locations', ['zone_id' => $balearic, 'country' => 'PT', 'region' => '', 'postcodes' => '']);

    (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Francia', 'locations' => [['country' => 'FR']]]), writersContext($app));
    same(1, (int) wrValue($app, 'SELECT active FROM {shipping_zones} WHERE id = ?', [$peninsula]), 'con tarifas, se queda');
    same(1, (int) wrValue($app, 'SELECT active FROM {shipping_zones} WHERE id = ?', [$balearic]), 'tocada, se queda');
});

test('una zona del origen con el mismo nombre que una de aquí se adopta en vez de duplicarse', function (): void {
    $app = writersApp();
    $canarias = (int) wrValue($app, "SELECT id FROM {shipping_zones} WHERE name = 'Canary Islands'");
    $zones = wrCount($app, 'shipping_zones');
    $out = (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z9', 'name' => 'canary islands', 'locations' => [['country' => 'ES', 'postcodes' => '35,38,07']]]), writersContext($app));

    same(TypeWriter::UPDATED, $out['result']);
    same($canarias, $out['id']);
    same($zones, wrCount($app, 'shipping_zones'));
    same('35,38,07', wrValue($app, 'SELECT postcodes FROM {zone_locations} WHERE zone_id = ?', [$canarias]));
});

test('la provincia por letra se guarda con sus dos cifras y una localización con códigos postales inválidos se deja fuera y se dice', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $out = (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Raras', 'locations' => [['country' => 'ES', 'region' => 'TF'], ['country' => 'ES', 'postcodes' => '35-3']]]), $ctx);

    same(['38'], array_map('strval', $app->db()->column('SELECT region FROM {zone_locations} WHERE zone_id = ?', [$out['id']])), 'solo la buena; ensanchar la zona sería peor');
    check(count($ctx->noteTexts('shipping_zones')) >= 1, 'y se apunta');
});

test('un transportista de tarifas se crea con los precios como se escriben aquí, se repite sin cambios y se actualiza al cambiar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    $made = wrShippingOrigin($app, $ctx);
    [$carrierId] = $made['carriers'];
    [$z1, $z2] = $made['zones'];

    same('mensajeria', wrValue($app, 'SELECT code FROM {carriers} WHERE id = ?', [$carrierId]));
    same('rates', wrValue($app, 'SELECT handler FROM {carriers} WHERE id = ?', [$carrierId]));
    same(6000, (int) wrValue($app, 'SELECT free_from FROM {carriers} WHERE id = ?', [$carrierId]));
    same(6, wrCount($app, 'carrier_rates', 'carrier_id = ?', [$carrierId]));
    // Precios del origen sin impuestos (590 → 714 con el 21 %), como los precios de aquí, que llevan los impuestos dentro.
    same(['weight/0/5000/714', 'weight/5000/15000/1198', 'weight/15000/0/1803'], array_map('strval', $app->db()->column(
        "SELECT basis || '/' || \"min\" || '/' || \"max\" || '/' || price FROM {carrier_rates} WHERE carrier_id = ? AND zone_id = ? ORDER BY \"min\"",
        [$carrierId, $z1]
    )));
    same(1561, (int) wrValue($app, 'SELECT price FROM {carrier_rates} WHERE carrier_id = ? AND zone_id = ? AND "min" = 0', [$carrierId, $z2]));

    $record = wr('carriers', ['id' => '4', 'name' => 'Mensajeria', 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => false, 'free_from' => 6000, 'max_weight' => 0, 'handling_fee' => 0,
        'rates' => [['zone_id' => 'z1', 'basis' => 'weight', 'min' => 0, 'max' => 5000, 'price' => 590]], 'zone_ids' => ['z1'], 'position' => 0, 'active' => true]);
    same(TypeWriter::UPDATED, (new CarriersWriter($app))->write($record, $ctx)['result'], 'otro contenido del origen: se actualiza');
    same(1, wrCount($app, 'carrier_rates', 'carrier_id = ?', [$carrierId]), 'las tarifas se sustituyen');
    same(TypeWriter::UNCHANGED, (new CarriersWriter($app))->write($record, $ctx)['result']);
    same(1, wrCount($app, 'carriers', "code = 'mensajeria'"), 'sin duplicar');
});

test('si el origen escribe los precios con impuestos y aquí también no se convierte; si aquí no llevan impuestos, se sacan', function (): void {
    $same = writersApp();
    $ctx = writersContext($same);
    wrTaxOrigin($same, $ctx);
    wrShippingOrigin($same, $ctx);
    $record = wr('carriers', ['id' => '8', 'name' => 'Con IVA', 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => true, 'free_from' => null, 'max_weight' => 0, 'handling_fee' => 0,
        'rates' => [['zone_id' => 'z1', 'basis' => 'weight', 'min' => 0, 'max' => null, 'price' => 1210]], 'zone_ids' => ['z1'], 'position' => 2, 'active' => true]);
    $out = (new CarriersWriter($same))->write($record, $ctx);
    same(1210, (int) wrValue($same, 'SELECT price FROM {carrier_rates} WHERE carrier_id = ?', [$out['id']]), 'los dos con impuestos: el mismo importe');

    $net = writersApp(['tax.prices_include_tax' => false]);
    $netCtx = writersContext($net);
    wrTaxOrigin($net, $netCtx);
    wrShippingOrigin($net, $netCtx);
    $out = (new CarriersWriter($net))->write($record, $netCtx);
    same(1000, (int) wrValue($net, 'SELECT price FROM {carrier_rates} WHERE carrier_id = ?', [$out['id']]), 'aquí sin impuestos: 1210 con el 21 % son 1000');
});

test('recogida y envío gratis: el manejador y los precios que tocan', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    $made = wrShippingOrigin($app, $ctx);
    [, $pickup] = $made['carriers'];
    same('pickup', wrValue($app, 'SELECT handler FROM {carriers} WHERE id = ?', [$pickup]));
    same(0, (int) json_decode((string) wrValue($app, 'SELECT settings FROM {carriers} WHERE id = ?', [$pickup]), true)['price']);
    same(0, wrCount($app, 'carrier_rates', 'carrier_id = ?', [$pickup]), 'la recogida no lleva tabla de tarifas');

    $free = (new CarriersWriter($app))->write(wr('carriers', ['id' => '6', 'name' => 'Gratis', 'kind' => 'free', 'tax_class_id' => null, 'price_includes_tax' => false, 'free_from' => 9000, 'max_weight' => 0, 'handling_fee' => 0,
        'rates' => [], 'zone_ids' => ['z1', 'z2'], 'position' => 3, 'active' => true]), $ctx);
    same(2, wrCount($app, 'carrier_rates', 'carrier_id = ? AND price = 0', [$free['id']]), 'una tarifa a 0 en cada zona');
    same(null, wrValue($app, 'SELECT free_from FROM {carriers} WHERE id = ?', [$free['id']]), 'y no necesita «gratis desde»');
});

test('la tarifa de gestión no existe aquí: se suma a cada tarifa y se dice; la dirección de seguimiento, el plazo y el peso máximo se copian', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    $out = (new CarriersWriter($app))->write(wr('carriers', [
        'id' => '7', 'name' => 'Con gestión', 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => false, 'free_from' => null, 'max_weight' => 30000,
        'handling_fee' => 100, 'min_days' => 1, 'max_days' => 3, 'tracking_url' => 'https://envios.test/seguir/@', 'delay' => ['es' => 'En 1-3 días'],
        'rates' => [['zone_id' => 'z1', 'basis' => 'weight', 'min' => 0, 'max' => null, 'price' => 400]], 'zone_ids' => ['z1'], 'position' => 4, 'active' => true,
    ]), $ctx);
    // 400 + 100 de gestión = 500 sin impuestos; con el 21 % son 605.
    same(605, (int) wrValue($app, 'SELECT price FROM {carrier_rates} WHERE carrier_id = ?', [$out['id']]));
    check(count(array_filter($ctx->noteTexts('carriers'), static function (string $text): bool {
        return strpos($text, 'handling fee') !== false;
    })) === 1, 'la nota se pone una sola vez');
    $row = $app->db()->find('carriers', ['id' => $out['id']]);
    same('https://envios.test/seguir/{code}', $row['tracking_url']);
    same('En 1-3 días', $row['delay']);
    same([1, 3, 30000], [(int) $row['min_days'], (int) $row['max_days'], (int) $row['max_weight']]);
});

test('un transportista de un tipo que aquí no existe entra inactivo con su nota, y una tarifa de una zona que no vino se deja fuera', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    wrShippingOrigin($app, $ctx);
    $record = array_merge(neutralSample('carriers'), ['id' => '9', 'name' => 'Dron', 'kind' => 'drone', 'tax_class_id' => 'c1', 'rates' => [['zone_id' => 'z1', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 100], ['zone_id' => 'zz', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 100]]]);
    $out = (new CarriersWriter($app))->write($record, $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same(0, (int) wrValue($app, 'SELECT active FROM {carriers} WHERE id = ?', [$out['id']]));
    check(count(array_filter($ctx->noteTexts('carriers'), static function (string $text): bool {
        return strpos($text, 'does not exist here') !== false;
    })) === 1, 'se apunta el tipo');
    check(count(array_filter($ctx->noteTexts('carriers'), static function (string $text): bool {
        return strpos($text, 'zone that was not brought') !== false;
    })) === 1, 'y la zona que falta');
});

test('simulacro de zonas y transportistas: se decide lo mismo y no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $zone = (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1']), $dry);
    $carrier = (new CarriersWriter($app))->write(wr('carriers', ['id' => '4']), $dry);

    same(TypeWriter::CREATED, $zone['result']);
    same(TypeWriter::CREATED, $carrier['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
    same(4, wrCount($app, 'shipping_zones', 'active = 1'), 'ni siquiera se apagan las de serie');
});

test('zonas y transportistas se actualizan aunque se hayan editado aquí (son configuración: nunca hay conflicto)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    $made = wrShippingOrigin($app, $ctx);
    $app->clock()->advance(600);
    $app->db()->update('carriers', ['name' => 'Editado aquí', 'updated_at' => $app->clock()->sql()], ['id' => $made['carriers'][0]]);
    $app->db()->update('shipping_zones', ['name' => 'Zona editada'], ['id' => $made['zones'][0]]);
    $app->clock()->advance(600);

    $zone = (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Península y Portugal', 'locations' => [['country' => 'ES']], 'position' => 0, 'active' => true]), $ctx);
    $carrier = (new CarriersWriter($app))->write(wr('carriers', ['id' => '4', 'name' => 'Mensajeria', 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => false, 'free_from' => 9000, 'max_weight' => 0, 'handling_fee' => 0,
        'rates' => [['zone_id' => 'z1', 'basis' => 'weight', 'min' => 0, 'max' => null, 'price' => 590]], 'zone_ids' => ['z1'], 'position' => 0, 'active' => true]), $ctx);

    same(TypeWriter::UPDATED, $zone['result']);
    same(TypeWriter::UPDATED, $carrier['result']);
    same('Península y Portugal', wrValue($app, 'SELECT name FROM {shipping_zones} WHERE id = ?', [$made['zones'][0]]));
    same(['Mensajeria', 9000], [wrValue($app, 'SELECT name FROM {carriers} WHERE id = ?', [$made['carriers'][0]]), (int) wrValue($app, 'SELECT free_from FROM {carriers} WHERE id = ?', [$made['carriers'][0]])]);
});
