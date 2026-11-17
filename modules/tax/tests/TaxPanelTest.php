<?php
/**
 * AIFEC — pruebas del panel de impuestos: listados, altas y cambios de clases y tipos, validación, borrados, permisos y ajustes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/TaxKit.php';

/**
 * Abre la pantalla de alta o de edición de un recurso y envía el formulario como lo haría el navegador.
 *
 * @param array<string,mixed> $form
 */
function taxSave(TestClient $web, string $resource, ?int $id, array $form): \Aifec\Kernel\Response
{
    $base = '/admin/r/' . $resource;
    $web->get($id === null ? $base . '/new' : $base . '/' . $id);

    return $web->post($id === null ? $base . '/save' : $base . '/' . $id . '/save', ['_token' => aifecToken($web), 'f' => $form]);
}

/**
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function taxRateForm(App $app, array $over = []): array
{
    return $over + ['tax_class_id' => (string) taxClassId($app, 'standard'), 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => '4',
        'name' => 'Tipo de prueba', 'priority' => '0', 'active' => '1'];
}

/**
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function taxClassForm(array $over = []): array
{
    return $over + ['name' => 'Nueva clase', 'code' => 'nueva', 'is_default' => '0', 'position' => '5'];
}

function taxDelete(TestClient $web, string $resource, int $id): \Aifec\Kernel\Response
{
    $web->get('/admin/r/' . $resource . '/' . $id);

    return $web->post('/admin/r/' . $resource . '/' . $id . '/delete', ['_token' => aifecToken($web)]);
}

test('los listados y los ajustes se abren con permiso, dan 403 sin él y el menú solo enseña lo que se puede ver', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);

    $r = $web->get('/admin/r/tax_classes');
    same(200, $r->status);
    contains('Clases de impuesto', $r->body);
    contains('Tipo general', $r->body, 'los nombres sembrados salen traducidos');
    contains('super-reduced', $r->body);

    $r = $web->get('/admin/r/tax_rates');
    same(200, $r->status);
    contains('Tipos de impuesto', $r->body);
    contains('21 %', $r->body);
    contains('10 %', $r->body);
    contains('IGIC 7 %', $r->body);
    contains('35,38', $r->body, 'los códigos postales');

    $r = $web->get('/admin/settings/tax');
    same(200, $r->status);
    contains('txgeneral', $r->body);
    contains('El tipo más alto del carrito', $r->body, 'la opción «highest» va traducida');
    contains('Tipo reducido', $r->body, 'y las clases también');

    $menu = $web->get('/admin')->body;
    contains('/admin/settings/tax', $menu);
    // Desde P102, «Impuestos» es una entrada con tres pestañas: las clases y los tipos se ven en su barra.
    $tabs = $web->get('/admin/settings/tax')->body;
    contains('role="tab" href="/admin/r/tax_classes"', $tabs);
    contains('role="tab" href="/admin/r/tax_rates"', $tabs);

    $support = adminClient($app, 'support');
    foreach (['/admin/r/tax_classes', '/admin/r/tax_rates', '/admin/settings/tax', '/admin/r/tax_rates/new'] as $url) {
        same(403, $support->get($url)->status, $url);
    }
    $home = $support->get('/admin')->body;
    notContains('/admin/r/tax_rates', $home, 'quien no puede verlo, no lo ve en el menú');
    notContains('/admin/settings/tax', $home);
});

test('el listado de tipos filtra por clase y por país', function (): void {
    $app = taxTestApp();
    taxAddRate($app, 'standard', 'PT', 2300, 'IVA PT 23 %');
    $web = adminClient($app);

    $body = $web->get('/admin/r/tax_rates?f[class]=' . taxClassId($app, 'reduced'))->body;
    contains('IVA 10 %', $body);
    contains('IGIC 3 %', $body);
    notContains('IVA 21 %', $body, 'la clase general no sale');
    notContains('IVA PT 23 %', $body);

    $body = $web->get('/admin/r/tax_rates?f[country]=PT')->body;
    contains('IVA PT 23 %', $body);
    notContains('IVA 21 %', $body, 'solo Portugal');

    $body = $web->get('/admin/r/tax_rates?q=igic')->body;
    contains('IGIC 7 %', $body, 'la búsqueda por nombre');
    notContains('IVA 21 %', $body);
});

test('un tipo con los códigos postales mal formados da error en ese campo y no se guarda', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    foreach (['35;38', '51999-51000', '5100-51999', 'ñandú', '35-38-40', '12345678901'] as $bad) {
        $r = taxSave($web, 'tax_rates', null, taxRateForm($app, ['postcodes' => $bad, 'name' => 'Malo']));
        same(422, $r->status, $bad);
        contains('Usa prefijos o rangos separados por comas', $r->body, $bad);
    }
    same(0, taxCount($app, 'tax_rates', "name = 'Malo'"));
});

test('un tipo bueno se guarda en centésimas con los códigos normalizados, y se enseña como se escribió', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    $name = 'Tasa & Co «ñandú» 🌶';
    $r = taxSave($web, 'tax_rates', null, taxRateForm($app, [
        'tax_class_id' => (string) taxClassId($app, 'reduced'), 'rate' => '10,5', 'postcodes' => ' 35 , 38 ', 'region' => ' Canarias ', 'name' => $name, 'priority' => '3',
    ]));
    same(302, $r->status);
    $row = $app->db()->row('SELECT * FROM {tax_rates} WHERE priority = 3');
    check($row !== null, 'la fila existe');
    same(1050, (int) $row['rate'], '«10,5» se guarda como 1050');
    same('35,38', $row['postcodes'], 'los códigos, sin espacios');
    same('Canarias', $row['region']);
    same($name, $row['name'], 'tildes, comillas, «&» y emojis intactos');
    same('ES', $row['country']);
    same(taxClassId($app, 'reduced'), (int) $row['tax_class_id']);
    same(1, (int) $row['active']);

    $id = (int) $row['id'];
    $list = $web->get('/admin/r/tax_rates')->body;
    contains('10,5 %', $list, 'un tipo con decimales se pinta con coma');
    contains('Tasa &amp; Co', $list, 'el «&» sale escapado');
    notContains('Tasa & Co', $list);

    $edit = $web->get('/admin/r/tax_rates/' . $id)->body;
    same('10,5', inputValue($edit, 'f[rate]'), 'al editar vuelve «10,5»');
    same($name, inputValue($edit, 'f[name]'));
    same('35,38', inputValue($edit, 'f[postcodes]'));

    // Dos decimales, y los extremos.
    same(302, taxSave($web, 'tax_rates', null, taxRateForm($app, ['rate' => '7,25', 'name' => 'Con dos decimales']))->status);
    same(725, (int) $app->db()->value("SELECT rate FROM {tax_rates} WHERE name = 'Con dos decimales'"));
    contains('7,25 %', $web->get('/admin/r/tax_rates')->body);
    same(302, taxSave($web, 'tax_rates', null, taxRateForm($app, ['rate' => '0', 'name' => 'Cero']))->status);
    same(0, (int) $app->db()->value("SELECT rate FROM {tax_rates} WHERE name = 'Cero'"));
    same(302, taxSave($web, 'tax_rates', null, taxRateForm($app, ['rate' => '100', 'name' => 'Cien']))->status);
    same(10000, (int) $app->db()->value("SELECT rate FROM {tax_rates} WHERE name = 'Cien'"));

    // Un tipo de serie, editado, conserva sus datos.
    $igic = (int) $app->db()->value("SELECT id FROM {tax_rates} WHERE name = 'IGIC 7 %' AND tax_class_id = ?", [taxClassId($app, 'standard')]);
    same('7', inputValue($web->get('/admin/r/tax_rates/' . $igic)->body, 'f[rate]'), 'el 700 se enseña como «7»');
});

test('un tipo fuera de 0 a 100, vacío o que no es un número no se guarda', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    foreach (['101', '-1', 'abc', '', '100,01'] as $bad) {
        same(422, taxSave($web, 'tax_rates', null, taxRateForm($app, ['rate' => $bad, 'name' => 'Fuera']))->status, 'tipo «' . $bad . '»');
    }
    same(422, taxSave($web, 'tax_rates', null, taxRateForm($app, ['name' => '']))->status, 'sin nombre');
    same(422, taxSave($web, 'tax_rates', null, taxRateForm($app, ['name' => str_repeat('a', 61)]))->status, 'nombre de más de 60');
    same(422, taxSave($web, 'tax_rates', null, taxRateForm($app, ['country' => 'ZZZ']))->status, 'país que no es de la lista');
    same(422, taxSave($web, 'tax_rates', null, taxRateForm($app, ['tax_class_id' => '9999']))->status, 'clase que no existe');
    same(0, taxCount($app, 'tax_rates', "name = 'Fuera'"));
});

test('al guardar o borrar un tipo en el panel, el servicio suelta lo que tenía leído', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $standard = taxClassId($app, 'standard');
    same(['rate' => 0, 'name' => ''], $tax->rate($standard, ['country' => 'PT', 'postcode' => '1000']), 'antes no hay tipo y queda leído');

    $web = adminClient($app);
    same(302, taxSave($web, 'tax_rates', null, taxRateForm($app, ['country' => 'PT', 'rate' => '23', 'name' => 'IVA PT 23 %']))->status);
    same(['rate' => 2300, 'name' => 'IVA PT 23 %'], $tax->rate($standard, ['country' => 'PT', 'postcode' => '1000']), 'tras guardar, ya lo ve');

    $id = (int) $app->db()->value("SELECT id FROM {tax_rates} WHERE name = 'IVA PT 23 %'");
    same(302, taxSave($web, 'tax_rates', $id, taxRateForm($app, ['country' => 'PT', 'rate' => '22', 'name' => 'IVA PT 22 %']))->status);
    same(2200, $tax->rate($standard, ['country' => 'PT', 'postcode' => '1000'])['rate'], 'tras cambiarlo, el nuevo');

    same(302, taxDelete($web, 'tax_rates', $id)->status);
    same(0, taxCount($app, 'tax_rates', 'id = ?', [$id]));
    same(['rate' => 0, 'name' => ''], $tax->rate($standard, ['country' => 'PT', 'postcode' => '1000']), 'tras borrarlo, vuelve a no haber');
});

test('las clases: alta, código único y en formato de dirección, y nombre con «&» escapado', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    same(302, taxSave($web, 'tax_classes', null, taxClassForm(['name' => 'Libros & revistas', 'code' => 'libros', 'position' => '8']))->status);
    $row = (array) $app->db()->row("SELECT * FROM {tax_classes} WHERE code = 'libros'");
    same('Libros & revistas', $row['name']);
    same(8, (int) $row['position']);
    same(0, (int) $row['is_default']);
    contains('Libros &amp; revistas', $web->get('/admin/r/tax_classes')->body);

    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['code' => 'libros']))->status, 'código repetido');
    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['code' => 'standard']))->status, 'código de una clase de serie');
    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['code' => 'Con Espacios']))->status, 'código que no es una dirección');
    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['code' => '']))->status, 'sin código');
    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['name' => '']))->status, 'sin nombre');
    same(422, taxSave($web, 'tax_classes', null, taxClassForm(['code' => str_repeat('a', 33)]))->status, 'código de más de 32');
    same(1, taxCount($app, 'tax_classes', "code = 'libros'"));
});

test('marcar otra clase por defecto desmarca la anterior, y la de por defecto no se deja sin marcar', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $standard = taxClassId($app, 'standard');
    same($standard, $tax->defaultClassId(), 'antes, la general (y queda leído)');

    $web = adminClient($app);
    same(302, taxSave($web, 'tax_classes', null, taxClassForm(['name' => 'Especial', 'code' => 'especial', 'is_default' => '1']))->status);
    $special = taxClassId($app, 'especial');
    same(1, taxCount($app, 'tax_classes', 'is_default = 1'), 'solo una por defecto');
    same(1, (int) $app->db()->value('SELECT is_default FROM {tax_classes} WHERE id = ?', [$special]));
    same(0, (int) $app->db()->value('SELECT is_default FROM {tax_classes} WHERE id = ?', [$standard]), 'la anterior ya no lo es');
    same($special, $tax->defaultClassId(), 'el servicio ya lo sabe');

    // Dejar la de por defecto sin marcar es un error.
    $r = taxSave($web, 'tax_classes', $special, taxClassForm(['name' => 'Especial', 'code' => 'especial', 'is_default' => '0']));
    same(422, $r->status);
    contains('Siempre tiene que haber una clase por defecto', $r->body);
    same(1, (int) $app->db()->value('SELECT is_default FROM {tax_classes} WHERE id = ?', [$special]));

    // Volver a marcar la general.
    same(302, taxSave($web, 'tax_classes', $standard, taxClassForm(['name' => 'Standard rate', 'code' => 'standard', 'is_default' => '1', 'position' => '0']))->status);
    same(1, taxCount($app, 'tax_classes', 'is_default = 1'));
    same($standard, $tax->defaultClassId());
});

test('el código de una clase en uso no se cambia, y el de una vacía sí', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    $reduced = taxClassId($app, 'reduced');

    $edit = $web->get('/admin/r/tax_classes/' . $reduced)->body;
    contains('no se puede cambiar', $edit, 'el aviso de la clase en uso');
    check(preg_match('/<input\b[^>]*name="f\[code\]"[^>]*>/', $edit, $m) === 1 && strpos($m[0], 'readonly') !== false, 'el campo del código va en solo lectura');

    $r = taxSave($web, 'tax_classes', $reduced, taxClassForm(['name' => 'Tipo reducido', 'code' => 'reducido', 'position' => '1']));
    same(422, $r->status);
    contains('su código no se puede cambiar', $r->body);
    same('reduced', (string) $app->db()->value('SELECT code FROM {tax_classes} WHERE id = ?', [$reduced]));

    same(302, taxSave($web, 'tax_classes', $reduced, taxClassForm(['name' => 'Reducido & más', 'code' => 'reduced', 'position' => '1']))->status, 'con el mismo código, el resto sí cambia');
    same('Reducido & más', (string) $app->db()->value('SELECT name FROM {tax_classes} WHERE id = ?', [$reduced]));

    // Una clase vacía: el código se puede cambiar, hasta que la usa un producto.
    taxSave($web, 'tax_classes', null, taxClassForm(['name' => 'Temporal', 'code' => 'temp']));
    $temp = taxClassId($app, 'temp');
    notContains('no se puede cambiar', $web->get('/admin/r/tax_classes/' . $temp)->body);
    same(302, taxSave($web, 'tax_classes', $temp, taxClassForm(['name' => 'Temporal', 'code' => 'temp2']))->status);
    same('temp2', (string) $app->db()->value('SELECT code FROM {tax_classes} WHERE id = ?', [$temp]));

    $app->db()->pdo()->exec('CREATE TABLE ' . $app->db()->table('products') . ' (id INTEGER PRIMARY KEY, tax_class_id INTEGER)');
    $app->db()->insert('products', ['tax_class_id' => $temp]);
    $r = taxSave($web, 'tax_classes', $temp, taxClassForm(['name' => 'Temporal', 'code' => 'temp3']));
    same(422, $r->status, 'con un producto que la usa, ya no');
    same('temp2', (string) $app->db()->value('SELECT code FROM {tax_classes} WHERE id = ?', [$temp]));
});

test('no se borra la clase por defecto, ni una con tipos, ni una que usa un producto; una vacía sí', function (): void {
    $app = taxTestApp();
    $web = adminClient($app);
    $standard = taxClassId($app, 'standard');
    $reduced = taxClassId($app, 'reduced');

    same(302, taxDelete($web, 'tax_classes', $standard)->status);
    contains('no se puede borrar', $web->follow()->body, 'la de por defecto');
    same(1, taxCount($app, 'tax_classes', 'id = ?', [$standard]));

    same(302, taxDelete($web, 'tax_classes', $reduced)->status);
    contains('tiene tipos de impuesto', $web->follow()->body, 'una con tipos');
    same(1, taxCount($app, 'tax_classes', 'id = ?', [$reduced]));
    same(true, taxCount($app, 'tax_rates', 'tax_class_id = ?', [$reduced]) > 0, 'y sus tipos siguen');

    // Sin la tabla de productos no se rompe nada: una clase vacía se borra.
    taxSave($web, 'tax_classes', null, taxClassForm(['name' => 'Vacía', 'code' => 'vacia']));
    $empty = taxClassId($app, 'vacia');
    same(302, taxDelete($web, 'tax_classes', $empty)->status);
    same(0, taxCount($app, 'tax_classes', 'id = ?', [$empty]), 'una clase vacía se borra');

    // Con la tabla de productos, una clase que usa un producto se queda.
    taxSave($web, 'tax_classes', null, taxClassForm(['name' => 'En uso', 'code' => 'en-uso']));
    $used = taxClassId($app, 'en-uso');
    $app->db()->pdo()->exec('CREATE TABLE ' . $app->db()->table('products') . ' (id INTEGER PRIMARY KEY, tax_class_id INTEGER)');
    $app->db()->insert('products', ['tax_class_id' => $used]);
    same(302, taxDelete($web, 'tax_classes', $used)->status);
    contains('Hay productos', $web->follow()->body, 'una que usa un producto');
    same(1, taxCount($app, 'tax_classes', 'id = ?', [$used]));
    $app->db()->delete('products', ['tax_class_id' => $used]);
    same(302, taxDelete($web, 'tax_classes', $used)->status);
    same(0, taxCount($app, 'tax_classes', 'id = ?', [$used]), 'sin productos, ya se borra');
});

test('quien no tiene permiso no puede guardar ni borrar nada: 403 y la base no cambia', function (): void {
    $app = taxTestApp();
    adminClient($app);
    $support = adminClient($app, 'support');
    $standard = taxClassId($app, 'standard');
    $before = taxCount($app, 'tax_rates');

    $token = aifecToken($support);
    same(403, $support->post('/admin/r/tax_rates/save', ['_token' => $token, 'f' => taxRateForm($app, ['name' => 'Colado'])])->status);
    same(403, $support->post('/admin/r/tax_classes/save', ['_token' => $token, 'f' => taxClassForm(['code' => 'colada'])])->status);
    same(403, $support->post('/admin/r/tax_classes/' . $standard . '/delete', ['_token' => $token])->status);
    same(403, $support->post('/admin/settings/tax', ['_token' => $token, 'f' => ['tax.prices_include_tax' => '0']])->status);
    same($before, taxCount($app, 'tax_rates'));
    same(0, taxCount($app, 'tax_classes', "code = 'colada'"));
    same(true, $app->settings()->bool('tax.prices_include_tax', true), 'el ajuste no cambió');
});

test('los ajustes se guardan y el servicio los refleja; un país que no está en la lista se rechaza', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $web = adminClient($app);
    same(true, $tax->pricesIncludeTax());
    same('ES', $tax->baseDestination()['country']);

    $form = [
        'tax.prices_include_tax' => '0', 'tax.display_with_tax' => '0', 'tax.base_country' => 'PT', 'tax.base_postcode' => '1000-001',
        'tax.shipping_class' => 'reduced', 'tax.eu_b2b_exempt' => '1',
    ];
    $web->get('/admin/settings/tax');
    $r = $web->post('/admin/settings/tax', ['_token' => aifecToken($web), 'f' => $form]);
    same(302, $r->status);
    $app->settings()->reload();
    same(false, $tax->pricesIncludeTax());
    same(false, $tax->displayWithTax());
    same(['country' => 'PT', 'region' => '', 'postcode' => '1000-001', 'city' => ''], $tax->baseDestination());
    same('reduced', $app->settings()->str('tax.shipping_class'));
    same(true, $app->settings()->bool('tax.eu_b2b_exempt'));
    same(true, $tax->exempt(['vat_id' => 'ES12345678Z'], ['country' => 'ES']), 'el ajuste de empresas de la UE llega al servicio');

    // Al volver a abrir, la pantalla enseña lo guardado.
    $body = $web->get('/admin/settings/tax')->body;
    check(preg_match('/<option value="PT"[^>]*selected/', $body) === 1, 'Portugal marcado');
    check(preg_match('/<option value="reduced"[^>]*selected/', $body) === 1, 'la clase del envío marcada');

    // Encendido otra vez y con el país a otro sitio.
    $form['tax.prices_include_tax'] = '1';
    $form['tax.eu_b2b_exempt'] = '0';
    $web->get('/admin/settings/tax');
    same(302, $web->post('/admin/settings/tax', ['_token' => aifecToken($web), 'f' => $form])->status);
    $app->settings()->reload();
    same(true, $tax->pricesIncludeTax());
    same(false, $app->settings()->bool('tax.eu_b2b_exempt'));

    // Valores que no valen.
    foreach ([['tax.base_country' => 'ZZ'], ['tax.shipping_class' => 'no-existe'], ['tax.base_postcode' => str_repeat('1', 13)]] as $bad) {
        $web->get('/admin/settings/tax');
        $r = $web->post('/admin/settings/tax', ['_token' => aifecToken($web), 'f' => $bad + $form]);
        same(422, $r->status, key($bad));
    }
    $app->settings()->reload();
    same('PT', $app->settings()->str('tax.base_country'), 'lo rechazado no se guardó');
});

test('autocomprobación: el servicio, el filtro sellado, los permisos, el menú y los recursos están conectados', function (): void {
    $app = taxTestApp();
    check($app->has('tax'), 'el servicio tax está publicado');
    same([], $app->modules()->failed(), 'el módulo carga sin fallos');
    check(in_array('tax', $app->modules()->loaded(), true), 'el módulo está cargado');
    check($app->modules()->definition('tax', 'settings') !== [], 'los ajustes están declarados');

    $admin = $app->modules()->definition('tax', 'admin');
    foreach (['tax.view', 'tax.edit'] as $cap) {
        check(isset($admin['caps'][$cap]), 'el permiso ' . $cap);
    }
    foreach (['tax_classes', 'tax_rates'] as $resource) {
        check(\Aifec\Admin\Admin::resource($app, $resource) !== null, 'el recurso ' . $resource);
    }

    // Sellado de verdad: el módulo de impuestos mismo tiene concedida «pricing»; otro, no.
    $before = count($app->hooks()->errors());
    $app->hooks()->setCurrentModule('otro');
    $app->hooks()->filter('tax.rate', static function (array $rate): array {
        return $rate;
    });
    $app->hooks()->setCurrentModule('core');
    same($before + 1, count($app->hooks()->errors()), 'un módulo sin la capacidad es rechazado');
});
