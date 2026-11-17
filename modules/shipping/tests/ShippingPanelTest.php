<?php
/**
 * AIFEC — pruebas del panel de envíos: zonas con sus localizaciones, transportistas con sus tarifas, recogida en tienda,
 * borrados, «Probar un envío», ajustes y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/ShippingKit.php';

/**
 * Abre la pantalla de alta o de edición de un recurso y envía el formulario como lo haría el navegador.
 *
 * @param array<string,mixed> $form
 */
function shippingSave(TestClient $web, string $resource, ?int $id, array $form): \Aifec\Kernel\Response
{
    $base = '/admin/r/' . $resource;
    $web->get($id === null ? $base . '/new' : $base . '/' . $id);

    return $web->post($id === null ? $base . '/save' : $base . '/' . $id . '/save', ['_token' => aifecToken($web), 'f' => $form]);
}

function shippingDelete(TestClient $web, string $resource, int $id): \Aifec\Kernel\Response
{
    $web->get('/admin/r/' . $resource . '/' . $id);

    return $web->post('/admin/r/' . $resource . '/' . $id . '/delete', ['_token' => aifecToken($web)]);
}

/**
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function shippingZoneForm(array $over = []): array
{
    return $over + ['name' => 'Zona de prueba', 'active' => '1', 'position' => '10', 'locations' => [['country' => 'FR', 'region' => '', 'postcodes' => '']]];
}

/**
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function shippingCarrierForm(array $over = []): array
{
    return $over + [
        'name' => 'Mensajeros', 'code' => 'mensajeros', 'handler' => 'rates', 'delay' => '24-48 h', 'min_days' => '1', 'max_days' => '2', 'max_weight' => '0',
        'free_from' => '', 'tax_class_id' => '', 'tracking_url' => '', 'position' => '0', 'active' => '1', 'pickup_price' => '', 'pickup_address' => '', 'rates' => [],
    ];
}

/** La fila de una tarifa tal como la manda el navegador. @return array<string,string> */
function shippingRateRow(int $zone, string $basis, string $min, string $max, string $price): array
{
    return ['zone_id' => (string) $zone, 'basis' => $basis, 'min' => $min, 'max' => $max, 'price' => $price];
}

test('las pantallas se abren con permiso, dan 403 sin él y el menú solo enseña lo que se puede ver', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);

    $r = $web->get('/admin/shipping/zones');
    same(200, $r->status);
    contains('Zonas de envío', $r->body);
    contains('Península', $r->body, 'los nombres sembrados salen traducidos');
    contains('Baleares', $r->body);
    contains('Canarias', $r->body);
    contains('Ceuta y Melilla', $r->body);
    contains('35,38', $r->body, 'los códigos postales de cada zona');

    $r = $web->get('/admin/shipping/carriers');
    same(200, $r->status);
    contains('Transportistas', $r->body);

    same(200, $web->get('/admin/r/shipping_zones/new')->status);
    same(200, $web->get('/admin/r/carriers/new')->status);
    $r = $web->get('/admin/shipping/settings');
    same(200, $r->status);
    contains('shipping.default_country', $r->body);
    same(200, $web->get('/admin/shipping/test')->status);

    // Una sola entrada en el menú: las otras tres son pestañas.
    $menu = $web->get('/admin')->body;
    contains('href="/admin/shipping"', $menu);
    foreach (['/admin/r/shipping_zones', '/admin/r/carriers', '/admin/settings/shipping'] as $url) {
        notContains('href="' . $url . '"', $menu, $url . ' ya no es una entrada del menú');
    }

    $support = adminClient($app, 'support');
    foreach (['/admin/shipping', '/admin/shipping/carriers', '/admin/shipping/zones', '/admin/shipping/settings', '/admin/shipping/test',
        '/admin/r/shipping_zones', '/admin/r/carriers', '/admin/r/carriers/new', '/admin/settings/shipping'] as $url) {
        same(403, $support->get($url)->status, $url);
    }
    $home = $support->get('/admin')->body;
    notContains('/admin/shipping', $home);
    notContains('/admin/r/carriers', $home);
});

test('una zona con sus localizaciones se guarda, se vuelve a ver igual y se cambia sin dejar filas viejas', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $name = 'Zona & Co «ñandú» 🌶';
    $r = shippingSave($web, 'shipping_zones', null, shippingZoneForm([
        'name' => $name, 'position' => '7',
        'locations' => [
            ['country' => 'PT', 'region' => '', 'postcodes' => ' 1000-1999 , 4 '],
            ['country' => 'ES', 'region' => ' 28 ', 'postcodes' => ''],
            ['country' => 'FR', 'region' => '', 'postcodes' => ''],
            ['country' => '', 'region' => '', 'postcodes' => ''],
        ],
    ]));
    same(302, $r->status);
    $row = $app->db()->row('SELECT * FROM {shipping_zones} WHERE position = 7');
    check($row !== null, 'la zona existe');
    same($name, $row['name'], 'tildes, comillas, «&» y emojis intactos');
    same(1, (int) $row['active']);
    $id = (int) $row['id'];
    $locations = $app->db()->select('SELECT country, region, postcodes FROM {zone_locations} WHERE zone_id = ? ORDER BY id', [$id]);
    same([
        ['country' => 'PT', 'region' => '', 'postcodes' => '1000-1999,4'],
        ['country' => 'ES', 'region' => '28', 'postcodes' => ''],
        ['country' => 'FR', 'region' => '', 'postcodes' => ''],
    ], $locations, 'tres localizaciones: la fila vacía no cuenta y los códigos quedan normalizados');

    $list = $web->get('/admin/shipping/zones')->body;
    contains('Zona &amp; Co', $list, 'el «&» sale escapado');
    notContains('Zona & Co', $list);

    $edit = $web->get('/admin/r/shipping_zones/' . $id)->body;
    same($name, inputValue($edit, 'f[name]'));
    same('1000-1999,4', inputValue($edit, 'f[locations][0][postcodes]'), 'al editar vuelven las localizaciones');
    same('28', inputValue($edit, 'f[locations][1][region]'));
    check(preg_match('/<option value="PT"[^>]*selected/', $edit) === 1, 'Portugal marcado en la primera fila');
    check(preg_match('/<option value="FR"[^>]*selected/', $edit) === 1, 'Francia marcada en la tercera');

    // Cambiarla: las localizaciones se reemplazan, no se acumulan.
    same(302, shippingSave($web, 'shipping_zones', $id, shippingZoneForm(['name' => 'Solo Italia', 'position' => '7', 'locations' => [['country' => 'IT', 'region' => '', 'postcodes' => '']]]))->status);
    same([['country' => 'IT', 'region' => '', 'postcodes' => '']], $app->db()->select('SELECT country, region, postcodes FROM {zone_locations} WHERE zone_id = ?', [$id]));
    same($id, shippingService($app)->zoneFor(shippingDest('IT', '00100')), 'y el servicio ya la ve');
    same(null, shippingService($app)->zoneFor(shippingDest('FR', '75001')), 'Francia ya no');
});

test('una zona con los códigos postales mal formados, sin localizaciones o con un país que no existe no se guarda', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    foreach (['35;38', '51999-51000', '5100-51999', 'ñandú', '35-38-40', '12345678901'] as $bad) {
        $r = shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => 'Mala', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => $bad]]]));
        same(422, $r->status, $bad);
        contains('Usa prefijos o rangos separados por comas', $r->body, $bad);
    }
    $r = shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => 'Mala', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => '28'], ['country' => 'FR', 'region' => '', 'postcodes' => 'x;y']]]));
    same(422, $r->status);
    contains('Fila 2', $r->body, 'dice en qué fila está el error');

    $r = shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => 'Vacía', 'locations' => []]));
    same(422, $r->status);
    contains('Añade al menos una fila', $r->body);
    same(422, shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => 'Rara', 'locations' => [['country' => 'ZZZ', 'region' => '', 'postcodes' => '']]]))->status, 'país que no es de la lista');
    same(422, shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => '']))->status, 'sin nombre');
    same(422, shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => str_repeat('a', 121)]))->status, 'nombre de más de 120');
    same(4, shippingCount($app, 'shipping_zones'), 'solo siguen las de serie');
    same(4, shippingCount($app, 'zone_locations'));
});

test('en una localización «resto del mundo» se borran la región y los códigos postales', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    same(302, shippingSave($web, 'shipping_zones', null, shippingZoneForm(['name' => 'Mundo', 'locations' => [['country' => '*', 'region' => 'x', 'postcodes' => '28']]]))->status);
    $id = (int) $app->db()->value("SELECT id FROM {shipping_zones} WHERE name = 'Mundo'");
    same([['country' => '*', 'region' => '', 'postcodes' => '']], $app->db()->select('SELECT country, region, postcodes FROM {zone_locations} WHERE zone_id = ?', [$id]));
    same($id, shippingService($app)->zoneFor(shippingDest('JP', '100-0001')));
    contains('Resto del mundo', $web->get('/admin/shipping/zones')->body);
});

test('un transportista con tarifas guarda los importes en céntimos y los límites en gramos o céntimos, y se vuelve a ver como se escribió', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $pen = shippingZoneId($app, 'Peninsula');
    $can = shippingZoneId($app, 'Canary Islands');
    $standard = (int) $app->db()->value("SELECT id FROM {tax_classes} WHERE code = 'standard'");
    $name = 'Mensajeros & Co «ñ» 🚚';
    $r = shippingSave($web, 'carriers', null, shippingCarrierForm([
        'name' => $name, 'code' => 'mensajeros', 'max_weight' => '30000', 'free_from' => '60', 'tax_class_id' => (string) $standard, 'position' => '4',
        'tracking_url' => 'https://seguimiento.example/{code}',
        'rates' => [
            shippingRateRow($pen, 'weight', '0', '1000', '4,95'),
            shippingRateRow($pen, 'weight', '1001', '5000', '7.95'),
            shippingRateRow($pen, 'weight', '5001', '0', '15'),
            shippingRateRow($can, 'price', '0', '49,99', '19,9'),
            shippingRateRow($can, 'price', '50', '', '0'),
        ],
    ]));
    same(302, $r->status);
    $row = $app->db()->row("SELECT * FROM {carriers} WHERE code = 'mensajeros'");
    check($row !== null, 'el transportista existe');
    $id = (int) $row['id'];
    same($name, $row['name'], 'tildes, comillas, «&» y emojis intactos');
    same(6000, (int) $row['free_from'], '«60» se guarda como 6000 céntimos');
    same(30000, (int) $row['max_weight']);
    same($standard, (int) $row['tax_class_id']);
    same(1, (int) $row['min_days']);
    same(2, (int) $row['max_days']);
    same('24-48 h', $row['delay']);
    same('https://seguimiento.example/{code}', $row['tracking_url']);

    $lo = $app->db()->ident('min');
    $hi = $app->db()->ident('max');
    $rates = $app->db()->select('SELECT zone_id, basis, ' . $lo . ' AS lo, ' . $hi . ' AS hi, price FROM {carrier_rates} WHERE carrier_id = ? ORDER BY id', [$id]);
    same([
        [$pen, 'weight', 0, 1000, 495], [$pen, 'weight', 1001, 5000, 795], [$pen, 'weight', 5001, 0, 1500],
        [$can, 'price', 0, 4999, 1990], [$can, 'price', 5000, 0, 0],
    ], array_map(static function (array $x): array {
        return [(int) $x['zone_id'], (string) $x['basis'], (int) $x['lo'], (int) $x['hi'], (int) $x['price']];
    }, $rates), '«4,95» guarda 495, «7.95» 795, y los importes de los límites de un tramo por importe también van en céntimos');

    // Y el servicio ya lo usa.
    same(['mensajeros' => 795], shippingPrices(shippingService($app)->options(shippingPackage(['weight' => 3000, 'items_total' => 2000]), shippingDest('ES', '28001'))));
    same(['mensajeros' => 1990], shippingPrices(shippingService($app)->options(shippingPackage(['weight' => 3000, 'items_total' => 4999]), shippingDest('ES', '35001'))));
    same(['mensajeros' => 0], shippingPrices(shippingService($app)->options(shippingPackage(['weight' => 3000, 'items_total' => 5000]), shippingDest('ES', '35001'))));

    // Al editar, vuelve tal como se escribió: coma decimal en los importes, gramos en los pesos.
    $list = $web->get('/admin/shipping/carriers')->body;
    contains('Mensajeros &amp; Co', $list, 'el «&» sale escapado en el listado');
    notContains('Mensajeros & Co', $list);
    contains('Tabla de tarifas', $list);
    contains('60,00', $list, 'el «gratis a partir de» en euros');

    $edit = $web->get('/admin/r/carriers/' . $id)->body;
    same($name, inputValue($edit, 'f[name]'));
    same('4,95', inputValue($edit, 'f[rates][0][price]'));
    same('1000', inputValue($edit, 'f[rates][0][max]'));
    same('15,00', inputValue($edit, 'f[rates][2][price]'));
    same('0', inputValue($edit, 'f[rates][2][max]'));
    same('49,99', inputValue($edit, 'f[rates][3][max]'), 'el límite de un tramo por importe vuelve en euros');
    same('50,00', inputValue($edit, 'f[rates][4][min]'));
    same('60,00', inputValue($edit, 'f[free_from]'));
    check(preg_match('/<option value="' . $pen . '"[^>]*selected>Península/', $edit) === 1, 'la zona de la primera tarifa, con su nombre traducido');
    check(preg_match('/<option value="price"[^>]*selected/', $edit) === 1, 'la base de importe marcada');

    // Cambiar las tarifas las reemplaza.
    same(302, shippingSave($web, 'carriers', $id, shippingCarrierForm(['name' => $name, 'code' => 'mensajeros', 'rates' => [shippingRateRow($pen, 'weight', '0', '0', '3')]]))->status);
    same(1, shippingCount($app, 'carrier_rates', 'carrier_id = ?', [$id]));
    same(null, $app->db()->value('SELECT free_from FROM {carriers} WHERE id = ?', [$id]), 'vaciar «gratis a partir de» lo deja en nulo');
    same(0, (int) $app->db()->value('SELECT max_weight FROM {carriers} WHERE id = ?', [$id]));
});

test('las tarifas con límites que no se entienden, un máximo bajo el mínimo, un código repetido o unos días al revés no se guardan', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $pen = shippingZoneId($app, 'Peninsula');

    foreach (['1,5', '1.5', 'abc', '-3', '12 kg'] as $bad) {
        $r = shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '0', $bad, '4')]]));
        same(422, $r->status, 'peso «' . $bad . '»');
        contains('gramos enteros', $r->body, $bad);
    }
    $r = shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'price', 'abc', '', '4')]]));
    same(422, $r->status);
    contains('son importes', $r->body);
    $r = shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '2000', '1000', '4')]]));
    same(422, $r->status);
    contains('«Hasta» no puede ser menor que «Desde»', $r->body);
    $r = shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '0', '1000', '4'), shippingRateRow($pen, 'weight', '1001', 'x', '5')]]));
    same(422, $r->status);
    contains('Fila 2', $r->body, 'dice en qué fila está el error');

    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '0', '0', '-4')]]))->status, 'precio negativo');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '0', '0', 'gratis')]]))->status, 'precio que no es un importe');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'weight', '0', '0', '')]]))->status, 'sin precio');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow(99999, 'weight', '0', '0', '4')]]))->status, 'zona que no existe');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['rates' => [shippingRateRow($pen, 'volumen', '0', '0', '4')]]))->status, 'base que no existe');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['min_days' => '5', 'max_days' => '2']))->status, 'días al revés');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['handler' => 'no-existe']))->status, 'cálculo que no está registrado');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['name' => '']))->status, 'sin nombre');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['code' => 'Con Espacios']))->status, 'código con espacios');
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['free_from' => 'mucho']))->status, 'gratis a partir de que no es un importe');
    same(0, shippingCount($app, 'carriers'), 'nada de lo rechazado se guardó');
    same(0, shippingCount($app, 'carrier_rates'));

    same(302, shippingSave($web, 'carriers', null, shippingCarrierForm())->status);
    same(422, shippingSave($web, 'carriers', null, shippingCarrierForm(['name' => 'Otro']))->status, 'el mismo código dos veces');
    same(1, shippingCount($app, 'carriers'));
});

test('la recogida en tienda guarda su precio y su dirección en el ajuste, sin tarifas, y cambiar de cálculo no pierde las tarifas', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $pen = shippingZoneId($app, 'Peninsula');
    $address = "Calle Mayor 1 & ñ\nPlanta 2";
    same(302, shippingSave($web, 'carriers', null, shippingCarrierForm([
        'name' => 'Recogida', 'code' => 'recogida', 'handler' => 'pickup', 'pickup_price' => '2,50', 'pickup_address' => $address, 'delay' => 'Hoy', 'min_days' => '', 'max_days' => '',
    ]))->status);
    $id = (int) $app->db()->value("SELECT id FROM {carriers} WHERE code = 'recogida'");
    $settings = json_decode((string) $app->db()->value('SELECT settings FROM {carriers} WHERE id = ?', [$id]), true);
    same(['price' => 250, 'address' => $address], $settings);
    same(0, shippingCount($app, 'carrier_rates'), 'no hay tarifas');
    same(null, $app->db()->value('SELECT min_days FROM {carriers} WHERE id = ?', [$id]), 'sin días queda nulo');
    same(['recogida' => 250], shippingPrices(shippingService($app)->options(shippingPackage(), shippingDest('ES', '35001'))), 'ya se ofrece, en cualquier zona');

    $edit = $web->get('/admin/r/carriers/' . $id)->body;
    same('2,50', inputValue($edit, 'f[pickup_price]'));
    contains('Calle Mayor 1 &amp; ñ', $edit, 'la dirección vuelve a la pantalla, escapada');
    notContains('Calle Mayor 1 & ñ', $edit);
    contains('Recogida en tienda', $edit);

    // Pasa a tabla de tarifas y vuelve a recogida: las tarifas del medio no se pierden mientras no se vuelva a guardar con «rates».
    same(302, shippingSave($web, 'carriers', $id, shippingCarrierForm(['name' => 'Recogida', 'code' => 'recogida', 'rates' => [shippingRateRow($pen, 'weight', '0', '0', '9')]]))->status);
    same(1, shippingCount($app, 'carrier_rates', 'carrier_id = ?', [$id]));
    same(302, shippingSave($web, 'carriers', $id, shippingCarrierForm(['name' => 'Recogida', 'code' => 'recogida', 'handler' => 'pickup', 'pickup_price' => '0', 'rates' => []]))->status);
    same(1, shippingCount($app, 'carrier_rates', 'carrier_id = ?', [$id]), 'con la recogida, las tarifas quedan guardadas y sin uso');
    $settings = json_decode((string) $app->db()->value('SELECT settings FROM {carriers} WHERE id = ?', [$id]), true);
    same(0, $settings['price']);

    same(422, shippingSave($web, 'carriers', $id, shippingCarrierForm(['name' => 'Recogida', 'code' => 'recogida', 'handler' => 'pickup', 'pickup_price' => '-1']))->status, 'precio negativo');
});

test('no se borra una zona con tarifas; sin ellas se borra con sus localizaciones. Un transportista se borra con sus tarifas, salvo que tenga pedidos', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'uno');
    shippingAddRate($app, $c, $pen, 'weight', 0, 0, 500);

    same(302, shippingDelete($web, 'shipping_zones', $pen)->status);
    contains('Esta zona tiene tarifas', $web->follow()->body);
    same(1, shippingCount($app, 'shipping_zones', 'id = ?', [$pen]), 'la zona sigue');
    same(1, shippingCount($app, 'zone_locations', 'zone_id = ?', [$pen]), 'y sus localizaciones');

    $free = shippingZoneId($app, 'Balearic Islands');
    same(302, shippingDelete($web, 'shipping_zones', $free)->status);
    same(0, shippingCount($app, 'shipping_zones', 'id = ?', [$free]), 'una zona sin tarifas se borra');
    same(0, shippingCount($app, 'zone_locations', 'zone_id = ?', [$free]), 'con sus localizaciones');
    same($pen, shippingService($app)->zoneFor(shippingDest('ES', '07001')), 'y Baleares cae ahora en la península');

    // Un transportista con pedidos no se borra: se desactiva.
    $app->db()->pdo()->exec('CREATE TABLE ' . $app->db()->table('orders') . ' (id INTEGER PRIMARY KEY, carrier_id INTEGER)');
    $app->db()->insert('orders', ['carrier_id' => $c]);
    same(302, shippingDelete($web, 'carriers', $c)->status);
    contains('Hay pedidos enviados con este transportista', $web->follow()->body);
    same(1, shippingCount($app, 'carriers', 'id = ?', [$c]));
    $app->db()->delete('orders', ['carrier_id' => $c]);

    same(302, shippingDelete($web, 'carriers', $c)->status);
    same(0, shippingCount($app, 'carriers', 'id = ?', [$c]));
    same(0, shippingCount($app, 'carrier_rates', 'carrier_id = ?', [$c]), 'las tarifas se van con el transportista');

    // Y ahora la zona de la península ya no tiene tarifas.
    same(302, shippingDelete($web, 'shipping_zones', $pen)->status);
    same(0, shippingCount($app, 'shipping_zones', 'id = ?', [$pen]));
});

test('«Probar un envío» enseña la zona y las opciones con su precio, y avisa cuando no hay zona o no hay transportista', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $pen = shippingZoneId($app, 'Peninsula');
    $c = shippingAddCarrier($app, 'mensajeros', ['name' => 'Mensajeros & Co', 'delay' => '24-48 h', 'free_from' => 5000]);
    shippingAddRate($app, $c, $pen, 'weight', 0, 1000, 495);
    shippingAddRate($app, $c, $pen, 'weight', 1001, 0, 795);

    $r = $web->get('/admin/shipping/test');
    notContains('Resultado', $r->body, 'sin probar no hay resultado');
    contains('value="ES" selected', $r->body, 'el país de partida viene marcado');

    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&weight=500&amount=20');
    same(200, $r->status);
    contains('Resultado', $r->body);
    contains('Península', $r->body, 'la zona, traducida');
    contains('Madrid', $r->body, 'y la provincia del código postal');
    contains('Mensajeros &amp; Co', $r->body, 'el «&» sale escapado');
    notContains('Mensajeros & Co', $r->body);
    contains('24-48 h', $r->body);
    contains('4,95', $r->body, 'el precio de la tabla');
    same('28001', inputValue($r->body, 'postcode'), 'el formulario conserva lo escrito');

    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&weight=2000&amount=20');
    contains('7,95', $r->body, 'otro tramo de peso');
    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&weight=2000&amount=50');
    contains('Gratis', $r->body, 'con 50 € de productos llega a «gratis a partir de»');
    notContains('7,95', $r->body);
    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&weight=2000&amount=49,99');
    contains('7,95', $r->body, 'y con 49,99 € no');

    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=35001&weight=500&amount=20');
    contains('Canarias', $r->body, 'Canarias es una zona');
    contains('Ningún transportista puede enviar este paquete a este destino', $r->body, 'pero este transportista no tiene tarifas allí');

    $r = $web->get('/admin/shipping/test?run=1&country=FR&postcode=75001&weight=500&amount=20');
    contains('Ninguna zona cubre este destino', $r->body);

    foreach (['weight=1,5', 'weight=abc', 'weight=-5'] as $bad) {
        $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&amount=20&' . $bad);
        contains('Escribe el peso en gramos enteros', $r->body, $bad);
        notContains('Resultado', $r->body, 'con un dato malo no se calcula');
    }
    $r = $web->get('/admin/shipping/test?run=1&country=ES&postcode=28001&weight=500&amount=mucho');
    contains('Escribe un importe', $r->body);
    notContains('Resultado', $r->body);

    $r = $web->get('/admin/shipping/test?run=1&country=zz9&postcode=%3Cb%3Ex%3C%2Fb%3E&weight=500');
    same(200, $r->status, 'un país y un código raros no rompen la pantalla');
    notContains('<b>x</b>', $r->body);
    contains('&lt;b&gt;x&lt;/b&gt;', $r->body, 'lo escrito vuelve escapado');
});

test('los ajustes se guardan y el servicio los lee; un país que no está en la lista se rechaza', function (): void {
    $app = shippingTestApp();
    $shipping = shippingService($app);
    $web = adminClient($app);
    same('ES', $shipping->defaultCountry());
    same(true, $shipping->estimateInCart());

    $web->get('/admin/shipping/settings');
    $r = $web->post('/admin/settings/shipping', ['_token' => aifecToken($web), 'f' => ['shipping.default_country' => 'PT', 'shipping.estimate_in_cart' => '0']]);
    same(302, $r->status);
    $app->settings()->reload();
    same('PT', $shipping->defaultCountry());
    same(false, $shipping->estimateInCart());
    $body = $web->get('/admin/shipping/settings')->body;
    check(preg_match('/<option value="PT"[^>]*selected/', $body) === 1, 'Portugal marcado al volver a abrir');
    $r = $web->get('/admin/shipping/test');
    contains('value="PT" selected', $r->body, 'y «Probar un envío» parte de él');

    $web->get('/admin/shipping/settings');
    same(422, $web->post('/admin/settings/shipping', ['_token' => aifecToken($web), 'f' => ['shipping.default_country' => 'ZZ', 'shipping.estimate_in_cart' => '1']])->status);
    $app->settings()->reload();
    same('PT', $shipping->defaultCountry(), 'lo rechazado no se guardó');
});

test('quien no tiene permiso no puede guardar ni borrar nada: 403 y la base no cambia', function (): void {
    $app = shippingTestApp();
    adminClient($app);
    $support = adminClient($app, 'support');
    $pen = shippingZoneId($app, 'Peninsula');
    $token = aifecToken($support);

    same(403, $support->post('/admin/r/shipping_zones/save', ['_token' => $token, 'f' => shippingZoneForm(['name' => 'Colada'])])->status);
    same(403, $support->post('/admin/r/carriers/save', ['_token' => $token, 'f' => shippingCarrierForm(['code' => 'colado'])])->status);
    same(403, $support->post('/admin/r/shipping_zones/' . $pen . '/delete', ['_token' => $token])->status);
    same(403, $support->post('/admin/settings/shipping', ['_token' => $token, 'f' => ['shipping.default_country' => 'PT']])->status);
    same(4, shippingCount($app, 'shipping_zones'));
    same(0, shippingCount($app, 'carriers'));
    same('ES', shippingService($app)->defaultCountry());
});

test('un transportista sin plazo ni «gratis a partir de» dice «Sin indicar» y enlaza a su ficha, no un guion', function (): void {
    $app = shippingTestApp();
    $id = shippingAddCarrier($app, 'sinplazo');
    $web = adminClient($app);
    foreach (['/admin/shipping/carriers'] as $url) {
        $body = $web->get($url)->body;
        contains('>Sin indicar</a>', $body, $url);
        contains('href="/admin/r/carriers/' . $id . '"', $body, $url . ' enlaza a la ficha del transportista');
        notContains('<span class="af-muted">—</span>', $body, $url . ' ya no deja un guion mudo');
    }
    $app->db()->update('carriers', ['delay' => '24-48 h', 'free_from' => 5000], ['id' => $id]);
    $body = $web->get('/admin/shipping/carriers')->body;
    contains('24-48 h', $body);
    notContains('>Sin indicar</a>', $body, 'con los dos rellenos no queda nada sin indicar');
});
