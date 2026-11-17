<?php
/**
 * AIFEC — pruebas del panel de clientes: listado, ficha, alta y edición, borrado, anonimizar, copia de datos, exportación,
 * direcciones, grupos y ajustes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Passwords;

require_once __DIR__ . '/CustomersKit.php';

/** Una fila de «audit» por acción, para comprobar que lo importante queda apuntado. */
function customersAuditCount(\Aifec\Kernel\App $app, string $action, string $entity): int
{
    return customersCount($app, 'audit', 'action = ? AND entity = ?', [$action, $entity]);
}

test('el listado se abre con permiso, da 403 sin él y enseña nombre, correo y grupo', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['orders_count' => 2, 'total_spent' => 12345]);
    customersMakeGuest($app, 'invitado@cliente.test');

    $web = adminClient($app);
    $r = $web->get('/admin/r/customers');
    same(200, $r->status);
    contains('ana@cliente.test', $r->body);
    contains('Ana Ruiz', $r->body);
    contains('invitado@cliente.test', $r->body);
    contains('Invitado', $r->body, 'los invitados llevan su insignia');
    contains('123,45', $r->body, 'el total gastado va en euros con coma');
    contains('/admin/customers/export.csv', $r->body, 'quien puede exportar ve el botón');
    notContains('/admin/r/customers/export.csv', $r->body);

    $support = adminClient($app, 'support');
    $r = $support->get('/admin/r/customers');
    same(200, $r->status, 'soporte puede ver clientes');
    contains('ana@cliente.test', $r->body);
    notContains('/admin/customers/export.csv', $r->body, 'pero no ve el botón de exportar');

    $editor = adminClient($app, 'editor');
    same(403, $editor->get('/admin/r/customers')->status);
    same(403, $editor->get('/admin/r/customer_groups')->status);
});

test('el listado busca por nombre, correo, teléfono y NIF y filtra por grupo, invitado y publicidad', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['phone' => '999111111', 'vat_id' => '12345678Z', 'company' => 'Panadería Sol', 'marketing' => 1]);
    $app->db()->insert('customer_groups', ['name' => 'Mayoristas', 'discount' => 1000, 'tax_exempt' => 0, 'is_default' => 0]);
    $group = (int) $app->db()->value("SELECT id FROM {customer_groups} WHERE name = 'Mayoristas'");
    customersMakeAccount($app, 'bea@cliente.test', 'clave-larga-1', ['first_name' => 'Bea', 'last_name' => 'López', 'phone' => '999222222', 'group_id' => $group]);
    customersMakeGuest($app, 'invitado@cliente.test', ['first_name' => 'Cris']);
    $web = adminClient($app);

    $found = static function (string $query) use ($web): array {
        $body = $web->get('/admin/r/customers?' . $query)->body;

        return [
            'ana' => strpos($body, 'ana@cliente.test') !== false,
            'bea' => strpos($body, 'bea@cliente.test') !== false,
            'invitado' => strpos($body, 'invitado@cliente.test') !== false,
        ];
    };
    same(['ana' => true, 'bea' => false, 'invitado' => false], $found('q=Ruiz'), 'por apellido');
    same(['ana' => false, 'bea' => true, 'invitado' => false], $found('q=' . rawurlencode('López')), 'por apellido con tilde');
    same(['ana' => true, 'bea' => false, 'invitado' => false], $found('q=ana@cliente'), 'por correo');
    same(['ana' => false, 'bea' => true, 'invitado' => false], $found('q=999222222'), 'por teléfono');
    same(['ana' => true, 'bea' => false, 'invitado' => false], $found('q=12345678Z'), 'por NIF');
    same(['ana' => true, 'bea' => false, 'invitado' => false], $found('q=' . rawurlencode('Panadería')), 'por empresa');
    same(['ana' => false, 'bea' => false, 'invitado' => false], $found('q=nadie-se-llama-asi'));
    same(['ana' => false, 'bea' => true, 'invitado' => false], $found('f[group]=' . $group), 'por grupo');
    same(['ana' => false, 'bea' => false, 'invitado' => true], $found('f[guest]=1'), 'solo invitados');
    same(['ana' => true, 'bea' => true, 'invitado' => false], $found('f[guest]=0'), 'solo con cuenta');
    same(['ana' => true, 'bea' => false, 'invitado' => false], $found('f[marketing]=1'), 'acepta publicidad');
    same(['ana' => false, 'bea' => true, 'invitado' => true], $found('f[marketing]=0'), 'no acepta publicidad');
    same(['ana' => true, 'bea' => true, 'invitado' => true], $found('sort=email&dir=asc'), 'se puede ordenar');
});

test('la ficha se abre con el formulario y, para quien solo puede ver, de solo lectura', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['company' => 'Ruiz & Hijos', 'phone' => '999345678']);
    $app->service('customers')->saveAddress($id, customersAddressData(['line1' => 'Calle <Mayor> 5']));

    $web = adminClient($app);
    $r = $web->get('/admin/r/customers/' . $id);
    same(200, $r->status);
    same('ana@cliente.test', inputValue($r->body, 'f[email]'));
    same('Ruiz & Hijos', inputValue($r->body, 'f[company]'));
    contains('cudata', $r->body);
    contains('cuaccess', $r->body);
    contains('Ruiz &amp; Hijos', $r->body, 'el dato con «&» sale escapado');
    contains('Calle &lt;Mayor&gt; 5', $r->body, 'la dirección de la columna lateral sale escapada');
    notContains('Calle <Mayor>', $r->body);
    contains('/admin/customers/' . $id . '/addresses', $r->body, 'enlace para gestionar las direcciones');
    contains('/admin/customers/' . $id . '/data.json', $r->body, 'quien tiene el permiso de privacidad ve la descarga');
    contains('/admin/customers/' . $id . '/anonymise', $r->body);
    notContains('clave-larga-1', $r->body);
    notContains((string) customersRow($app, $id)['password'], $r->body, 'el resumen de la contraseña no vuelve a la pantalla');

    $support = adminClient($app, 'support');
    $r = $support->get('/admin/r/customers/' . $id);
    same(200, $r->status, 'soporte ve la ficha');
    contains('ana@cliente.test', $r->body);
    contains('Ruiz &amp; Hijos', $r->body);
    notContains('name="f[email]"', $r->body, 'sin formulario');
    notContains('/data.json', $r->body);
    notContains('/anonymise', $r->body);
    same(403, $support->post('/admin/r/customers/' . $id . '/save', ['_token' => aifecToken($support), 'f' => ['email' => 'x@x.test']])->status);

    same(404, $web->get('/admin/r/customers/99999')->status);
    same(403, adminClient($app, 'editor')->get('/admin/r/customers/' . $id)->status);
});

test('el alta y la edición guardan lo que deben, releyendo la base', function (): void {
    [$app] = customersTestApp();
    $web = adminClient($app);
    $web->get('/admin/r/customers/new');
    $r = $web->post('/admin/r/customers/save', ['_token' => aifecToken($web), 'f' => [
        'email' => ' NUEVO@Cliente.test ', 'first_name' => 'ana', 'last_name' => 'ruiz & co', 'company' => 'Ruiz & Hijos', 'vat_id' => '12.345.678-z',
        'phone' => '+34 999 345 678', 'group_id' => '', 'locale' => 'ES', 'active' => '1', 'marketing' => '1', 'notes' => 'cliente de "toda la vida" & amigo',
        'password' => 'clave-larga-1', 'guest' => '1', 'verified' => '1',
    ]]);
    same(302, $r->status, 'se guarda y redirige');
    $row = (array) $app->db()->row("SELECT * FROM {customers} WHERE email = 'nuevo@cliente.test'");
    $id = (int) $row['id'];
    check($id > 0, 'se creó con el correo en minúsculas');
    same('Ana', $row['first_name']);
    same('Ruiz & Co', $row['last_name']);
    same('Ruiz & Hijos', $row['company']);
    same('12345678Z', $row['vat_id']);
    same('999345678', $row['phone']);
    same('es', $row['locale']);
    same(1, (int) $row['group_id'], 'sin elegir grupo entra en el de por defecto');
    same('cliente de "toda la vida" & amigo', $row['notes']);
    same(1, (int) $row['marketing']);
    same(0, (int) $row['guest'], 'el campo «invitado» es de solo lectura: lo mandado se ignora y con contraseña es una cuenta');
    same('admin', $row['source']);
    same('2026-11-12 12:00:00', $row['email_verified_at']);
    check(Passwords::verify('clave-larga-1', (string) $row['password']), 'la contraseña se guarda con su resumen');
    same(1, customersCount($app, 'consents', 'customer_id = ? AND kind = ? AND context = ?', [$id, 'marketing', 'admin']));
    same(1, customersAuditCount($app, 'create', 'customers'));
    contains('Creado', $web->follow()->body);

    // Editar: la contraseña vacía no cambia la que había; quitar «verificado» y «publicidad» queda apuntado.
    $hash = (string) $row['password'];
    $web->get('/admin/r/customers/' . $id);
    $r = $web->post('/admin/r/customers/' . $id . '/save', ['_token' => aifecToken($web), 'f' => [
        'email' => 'nuevo@cliente.test', 'first_name' => 'Beatriz', 'last_name' => 'Ruiz', 'company' => '', 'vat_id' => '', 'phone' => '',
        'group_id' => '', 'locale' => 'es', 'active' => '1', 'marketing' => '0', 'notes' => '', 'password' => '', 'verified' => '0',
    ]]);
    same(302, $r->status);
    $row = customersRow($app, $id);
    same('Beatriz', $row['first_name']);
    same(null, $row['company']);
    same(null, $row['vat_id']);
    same(null, $row['phone']);
    same(null, $row['notes']);
    same($hash, $row['password'], 'sin contraseña nueva se conserva la anterior');
    same(null, $row['email_verified_at']);
    same(0, (int) $row['marketing']);
    same(2, customersCount($app, 'consents', 'customer_id = ? AND kind = ?', [$id, 'marketing']));
    same(1, customersAuditCount($app, 'update', 'customers'));

    // Cambiar el correo de una cuenta verificada la deja sin verificar; la contraseña nueva se guarda con resumen.
    $web->get('/admin/r/customers/' . $id);
    $web->post('/admin/r/customers/' . $id . '/save', ['_token' => aifecToken($web), 'f' => [
        'email' => 'cambiado@cliente.test', 'first_name' => 'Beatriz', 'last_name' => 'Ruiz', 'company' => '', 'vat_id' => '', 'phone' => '',
        'group_id' => '', 'locale' => 'es', 'active' => '1', 'marketing' => '0', 'notes' => '', 'password' => 'otra-clave-larga', 'verified' => '1',
    ]]);
    $row = customersRow($app, $id);
    same('cambiado@cliente.test', $row['email']);
    same('2026-11-12 12:00:00', $row['email_verified_at'], 'se marcó «verificado» a la vez que estaba sin verificar');
    check(Passwords::verify('otra-clave-larga', (string) $row['password']));
    check(!Passwords::verify('clave-larga-1', (string) $row['password']));

    // Sin contraseña es un contacto (invitado); un cliente apagado pierde sus sesiones.
    $web->get('/admin/r/customers/new');
    $web->post('/admin/r/customers/save', ['_token' => aifecToken($web), 'f' => [
        'email' => 'contacto@cliente.test', 'first_name' => 'Contacto', 'last_name' => '', 'company' => '', 'vat_id' => '', 'phone' => '',
        'group_id' => '', 'locale' => '', 'active' => '1', 'marketing' => '0', 'notes' => '', 'password' => '', 'verified' => '0',
    ]]);
    $contact = (array) $app->db()->row("SELECT * FROM {customers} WHERE email = 'contacto@cliente.test'");
    same(1, (int) $contact['guest'], 'sin contraseña es un invitado: si se registra conserva su ficha');
    same(null, $contact['password']);
    customersMakeSession($app, $id);
    $web->get('/admin/r/customers/' . $id);
    $web->post('/admin/r/customers/' . $id . '/save', ['_token' => aifecToken($web), 'f' => [
        'email' => 'cambiado@cliente.test', 'first_name' => 'Beatriz', 'last_name' => 'Ruiz', 'company' => '', 'vat_id' => '', 'phone' => '',
        'group_id' => '', 'locale' => 'es', 'active' => '0', 'marketing' => '0', 'notes' => '', 'password' => '', 'verified' => '1',
    ]]);
    same(0, (int) customersRow($app, $id)['active']);
    same(0, customersCount($app, 'sessions', "guard = 'customer' AND user_id = ?", [$id]));
});

test('un correo repetido, un NIF malo, un teléfono malo y una contraseña corta dan error y no guardan', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = adminClient($app);
    $base = ['email' => 'nuevo@cliente.test', 'first_name' => 'Nuevo', 'last_name' => '', 'company' => '', 'vat_id' => '', 'phone' => '', 'group_id' => '', 'locale' => 'es',
        'active' => '1', 'marketing' => '0', 'notes' => '', 'password' => 'clave-larga-1', 'verified' => '0'];
    $try = static function (array $change) use ($web, $base): int {
        $web->get('/admin/r/customers/new');

        return $web->post('/admin/r/customers/save', ['_token' => aifecToken($web), 'f' => $change + $base])->status;
    };
    same(422, $try(['email' => 'ANA@cliente.test']), 'correo repetido (sin distinguir mayúsculas)');
    same(422, $try(['email' => 'no-es-un-correo']));
    same(422, $try(['vat_id' => '12345678A']));
    same(422, $try(['phone' => 'abc']));
    same(422, $try(['password' => 'corta']));
    same(1, customersCount($app, 'customers'), 'no se creó nada');
    same(302, $try([]), 'con los datos buenos sí');
    same(2, customersCount($app, 'customers'));
});

test('un cliente con pedidos no se borra y uno sin pedidos se borra con todo lo suyo', function (): void {
    [$app] = customersTestApp();
    $app->db()->run('CREATE TABLE {orders} (id INTEGER PRIMARY KEY, customer_id INTEGER)');
    $buyer = customersMakeAccount($app, 'compra@cliente.test');
    $app->db()->insert('orders', ['customer_id' => $buyer]);
    $counted = customersMakeAccount($app, 'contado@cliente.test', 'clave-larga-1', ['orders_count' => 1]);
    $free = customersMakeAccount($app, 'libre@cliente.test');
    $service = $app->service('customers');
    $service->saveAddress($free, customersAddressData());
    $service->consent($free, 'libre@cliente.test', 'privacy', true, 'v1');
    customersMakeSession($app, $free);

    $web = adminClient($app);
    foreach ([$buyer, $counted] as $id) {
        $web->get('/admin/r/customers/' . $id);
        $r = $web->post('/admin/r/customers/' . $id . '/delete', ['_token' => aifecToken($web)]);
        same(302, $r->status);
        same(1, customersCount($app, 'customers', 'id = ?', [$id]), 'sigue ahí');
        contains('tiene pedidos', $web->follow()->body);
        contains('Anonimiza', $web->last->body, 'dice qué hacer en su lugar');
    }

    $web->get('/admin/r/customers/' . $free);
    $r = $web->post('/admin/r/customers/' . $free . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(0, customersCount($app, 'customers', 'id = ?', [$free]));
    same(0, customersCount($app, 'addresses', 'customer_id = ?', [$free]));
    same(0, customersCount($app, 'consents', 'customer_id = ?', [$free]));
    same(0, customersCount($app, 'sessions', "guard = 'customer' AND user_id = ?", [$free]));
    same(1, customersAuditCount($app, 'delete', 'customers'));
});

test('anonimizar desde el panel pide el permiso de privacidad y deja la ficha sin datos', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['phone' => '999345678', 'orders_count' => 3]);
    $app->service('customers')->saveAddress($id, customersAddressData());

    $support = adminClient($app, 'support');
    $support->get('/admin/r/customers/' . $id);
    same(403, $support->post('/admin/customers/' . $id . '/anonymise', ['_token' => aifecToken($support)])->status);
    same('ana@cliente.test', customersRow($app, $id)['email'], 'soporte no puede');

    $manager = adminClient($app, 'manager');
    $manager->get('/admin/r/customers/' . $id);
    same(404, $manager->post('/admin/customers/99999/anonymise', ['_token' => aifecToken($manager)])->status);
    $manager->get('/admin/r/customers/' . $id);
    $r = $manager->post('/admin/customers/' . $id . '/anonymise', ['_token' => aifecToken($manager)]);
    same(302, $r->status);
    $row = customersRow($app, $id);
    same('anonymised-' . $id . '@anonymised.invalid', $row['email']);
    same(null, $row['phone']);
    same(3, (int) $row['orders_count']);
    same(0, customersCount($app, 'addresses', 'customer_id = ?', [$id]));
    same(1, customersAuditCount($app, 'anonymise', 'customer'));
    $page = $manager->follow();
    same(200, $page->status);
    contains('anonymised-' . $id . '@anonymised.invalid', $page->body);
    notContains('ana@cliente.test', $page->body);
});

test('data.json se descarga con el permiso de privacidad, trae lo suyo y apunta la auditoría', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['phone' => '999345678']);
    customersMakeAccount($app, 'otra@cliente.test');
    $app->service('customers')->saveAddress($id, customersAddressData(['line1' => 'Calle Mayor 5']));

    $manager = adminClient($app, 'manager');
    $r = $manager->get('/admin/customers/' . $id . '/data.json');
    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    contains('customer-' . $id . '.json', $r->headers['Content-Disposition']);
    contains('attachment', $r->headers['Content-Disposition']);
    $data = $manager->json();
    same('ana@cliente.test', $data['customer']['email']);
    same('Calle Mayor 5', $data['addresses'][0]['line1']);
    notContains('otra@cliente.test', $r->body);
    notContains('password', $r->body);
    same(1, customersAuditCount($app, 'privacy_export', 'customer'));

    same(403, adminClient($app, 'support')->get('/admin/customers/' . $id . '/data.json')->status);
    same(403, adminClient($app, 'editor')->get('/admin/customers/' . $id . '/data.json')->status);
    same(404, $manager->get('/admin/customers/99999/data.json')->status);
});

test('export.csv solo se da con el permiso de exportar, por las dos direcciones, y apunta quién exporta', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['phone' => '999345678', 'total_spent' => 12345]);
    customersMakeAccount($app, 'peligro@cliente.test', 'clave-larga-1', ['first_name' => '=HYPERLINK("http://malo.test")', 'last_name' => 'Cuidado; con "comillas"']);
    customersMakeGuest($app, 'invitado@cliente.test');

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/customers/export.csv')->status);
    same(403, $support->get('/admin/r/customers/export.csv')->status, 'la ruta genérica del recurso tampoco deja exportar con solo ver');
    same(0, customersAuditCount($app, 'export', 'customers'));

    $manager = adminClient($app, 'manager');
    $r = $manager->get('/admin/customers/export.csv');
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment', $r->headers['Content-Disposition']);
    contains('customers-20261112.csv', $r->headers['Content-Disposition']);
    contains('ana@cliente.test', $r->body);
    contains('123,45', $r->body);
    contains('999345678', $r->body);
    check(strpos($r->body, ';=HYPERLINK') === false && strpos($r->body, '"=HYPERLINK') === false, 'una fórmula no se entrega como fórmula');
    $lines = explode("\n", trim($r->body));
    same(4, count($lines), 'cabecera y tres clientes');
    same(1, customersAuditCount($app, 'export', 'customers'));

    $filtered = $manager->get('/admin/customers/export.csv?f[guest]=1');
    contains('invitado@cliente.test', $filtered->body);
    notContains('ana@cliente.test', $filtered->body, 'respeta los filtros del listado');
    $generic = $manager->get('/admin/r/customers/export.csv');
    same(200, $generic->status);
    contains('ana@cliente.test', $generic->body);
    same(3, customersAuditCount($app, 'export', 'customers'), 'las dos direcciones apuntan en la auditoría');
});

test('las direcciones desde el panel: lista, alta, cambio, borrado y 404 con las de otro cliente', function (): void {
    [$app] = customersTestApp();
    $service = $app->service('customers');
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $other = customersMakeAccount($app, 'otra@cliente.test');
    $mine = $service->saveAddress($id, customersAddressData(['line1' => 'Calle Mayor 5', 'company' => 'Ruiz & Hijos']));
    $foreign = $service->saveAddress($other, customersAddressData(['line1' => 'Calle Ajena 9']));

    $web = adminClient($app);
    $r = $web->get('/admin/customers/' . $id . '/addresses');
    same(200, $r->status);
    contains('Calle Mayor 5', $r->body);
    contains('Ruiz &amp; Hijos', $r->body);
    notContains('Calle Ajena', $r->body);
    same('', (string) inputValue($r->body, 'f[line1]'), 'el formulario de alta va vacío');

    $r = $web->get('/admin/customers/' . $id . '/addresses?address=' . $mine);
    same('Calle Mayor 5', inputValue($r->body, 'f[line1]'), 'con una dirección elegida, el formulario trae sus datos');
    same(404, $web->get('/admin/customers/' . $id . '/addresses?address=' . $foreign)->status, 'la de otro cliente no se abre');
    same(404, $web->get('/admin/customers/99999/addresses')->status);

    $form = ['first_name' => 'Bea', 'last_name' => 'López', 'company' => '', 'vat_id' => '', 'phone' => '', 'line1' => 'Avenida "Sol" & Luna 3', 'line2' => '', 'postcode' => '38001',
        'city' => 'Santa Cruz', 'region' => '', 'country' => 'ES', 'label' => '', 'default_shipping' => '1', 'default_billing' => '0'];
    $web->get('/admin/customers/' . $id . '/addresses');
    $r = $web->post('/admin/customers/' . $id . '/addresses/save', ['_token' => aifecToken($web), 'f' => $form]);
    same(302, $r->status);
    $new = (array) $app->db()->row("SELECT * FROM {addresses} WHERE customer_id = ? AND line1 LIKE 'Avenida%'", [$id]);
    same('Avenida "Sol" & Luna 3', $new['line1']);
    same('38', $new['region'], 'la provincia sale del código postal');
    same(1, (int) $new['default_shipping']);
    same(1, customersCount($app, 'addresses', 'customer_id = ? AND default_shipping = 1', [$id]), 'solo una de envío');
    contains('Dirección guardada', $web->follow()->body);

    // Cambiar una existente.
    $web->get('/admin/customers/' . $id . '/addresses?address=' . $mine);
    $web->post('/admin/customers/' . $id . '/addresses/save', ['_token' => aifecToken($web), 'address_id' => (string) $mine, 'f' => ['line1' => 'Calle Mayor 6', 'city' => 'Telde', 'postcode' => '35200'] + $form]);
    $row = (array) $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$mine]);
    same('Calle Mayor 6', $row['line1']);
    same('Telde', $row['city']);

    // Un código postal malo no guarda y avisa.
    $web->get('/admin/customers/' . $id . '/addresses');
    $r = $web->post('/admin/customers/' . $id . '/addresses/save', ['_token' => aifecToken($web), 'f' => ['postcode' => '9999', 'line1' => 'No se guarda 1'] + $form]);
    same(422, $r->status);
    same(0, customersCount($app, 'addresses', "line1 = 'No se guarda 1'"));
    contains('código postal', $r->body);

    // Tocar la dirección de otro cliente: 404 y no cambia.
    $before = $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$foreign]);
    $web->get('/admin/customers/' . $id . '/addresses');
    same(404, $web->post('/admin/customers/' . $id . '/addresses/save', ['_token' => aifecToken($web), 'address_id' => (string) $foreign, 'f' => ['line1' => 'Robada 1'] + $form])->status);
    $web->get('/admin/customers/' . $id . '/addresses');
    same(404, $web->post('/admin/customers/' . $id . '/addresses/' . $foreign . '/delete', ['_token' => aifecToken($web)])->status);
    $web->get('/admin/customers/' . $id . '/addresses');
    same($before, $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$foreign]));

    // Borrar la propia.
    $r = $web->post('/admin/customers/' . $id . '/addresses/' . $mine . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(null, $service->address($mine, $id));

    // Quien solo puede ver las ve, sin formulario ni borrar.
    $support = adminClient($app, 'support');
    $r = $support->get('/admin/customers/' . $id . '/addresses');
    same(200, $r->status);
    contains('Avenida', $r->body);
    notContains('name="f[line1]"', $r->body);
    same(403, $support->post('/admin/customers/' . $id . '/addresses/save', ['_token' => aifecToken($support), 'f' => $form])->status);
    same(403, adminClient($app, 'editor')->get('/admin/customers/' . $id . '/addresses')->status);
});

test('los grupos: el de por defecto es uno solo, no se borra el de por defecto ni uno con clientes', function (): void {
    [$app] = customersTestApp();
    $web = adminClient($app);
    $r = $web->get('/admin/r/customer_groups');
    same(200, $r->status);
    contains('>Clientes<', $r->body, 'el grupo sembrado en inglés («Customers») se enseña traducido');

    $web->get('/admin/r/customer_groups/new');
    $r = $web->post('/admin/r/customer_groups/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Mayoristas & Co', 'discount' => '15,5', 'tax_exempt' => '1', 'is_default' => '0']]);
    same(302, $r->status);
    $wholesale = (array) $app->db()->row("SELECT * FROM {customer_groups} WHERE name = 'Mayoristas & Co'");
    same(1550, (int) $wholesale['discount'], 'el descuento se guarda en centésimas de punto');
    same(1, (int) $wholesale['tax_exempt']);
    same(0, (int) $wholesale['is_default']);
    $id = (int) $wholesale['id'];
    contains('15,50 %', $web->get('/admin/r/customer_groups')->body);
    contains('Mayoristas &amp; Co', $web->last->body);

    // Un descuento a cero, y uno fuera de rango.
    $web->get('/admin/r/customer_groups/new');
    same(302, $web->post('/admin/r/customer_groups/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Sin descuento', 'discount' => '0', 'tax_exempt' => '0', 'is_default' => '0']])->status);
    $web->get('/admin/r/customer_groups/new');
    same(422, $web->post('/admin/r/customer_groups/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Demasiado', 'discount' => '101', 'tax_exempt' => '0', 'is_default' => '0']])->status);
    same(0, customersCount($app, 'customer_groups', "name = 'Demasiado'"));

    // Marcar otro como el de por defecto deja uno solo.
    $web->get('/admin/r/customer_groups/' . $id);
    same(302, $web->post('/admin/r/customer_groups/' . $id . '/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Mayoristas & Co', 'discount' => '15,5', 'tax_exempt' => '1', 'is_default' => '1']])->status);
    same(1, customersCount($app, 'customer_groups', 'is_default = 1'));
    same(1, (int) $app->db()->row('SELECT * FROM {customer_groups} WHERE id = ?', [$id])['is_default']);
    same(0, (int) $app->db()->row('SELECT * FROM {customer_groups} WHERE id = 1')['is_default']);

    // El de por defecto no se puede dejar sin marcar ni borrar.
    $web->get('/admin/r/customer_groups/' . $id);
    same(422, $web->post('/admin/r/customer_groups/' . $id . '/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Mayoristas & Co', 'discount' => '15,5', 'tax_exempt' => '1', 'is_default' => '0']])->status);
    same(1, (int) $app->db()->row('SELECT * FROM {customer_groups} WHERE id = ?', [$id])['is_default']);
    $web->get('/admin/r/customer_groups/' . $id);
    same(302, $web->post('/admin/r/customer_groups/' . $id . '/delete', ['_token' => aifecToken($web)])->status);
    same(1, customersCount($app, 'customer_groups', 'id = ?', [$id]), 'el de por defecto sigue');
    contains('por defecto', $web->follow()->body);

    // Uno con clientes no se borra; vacío sí.
    $customer = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['group_id' => 1]);
    $web->get('/admin/r/customer_groups/1');
    same(302, $web->post('/admin/r/customer_groups/1/delete', ['_token' => aifecToken($web)])->status);
    same(1, customersCount($app, 'customer_groups', 'id = 1'), 'tiene clientes');
    $empty = (int) $app->db()->value("SELECT id FROM {customer_groups} WHERE name = 'Sin descuento'");
    $web->get('/admin/r/customer_groups/' . $empty);
    same(302, $web->post('/admin/r/customer_groups/' . $empty . '/delete', ['_token' => aifecToken($web)])->status);
    same(0, customersCount($app, 'customer_groups', 'id = ?', [$empty]), 'uno vacío se borra');
    same(1, customersCount($app, 'customers', 'id = ?', [$customer]));
});

test('los ajustes se abren y se guardan, con la clave de cookie como secreto', function (): void {
    [$app] = customersTestApp();
    $web = adminClient($app);
    $r = $web->get('/admin/settings/customers');
    same(200, $r->status);
    contains('cuaccounts', $r->body);
    contains('cumigrated', $r->body);
    same(403, adminClient($app, 'editor')->get('/admin/settings/customers')->status);
    same(403, adminClient($app, 'support')->get('/admin/settings/customers')->status);

    same(true, $app->settings()->bool('customers.allow_register'), 'por defecto se puede registrar');
    same(true, $app->settings()->bool('customers.magic_link'));
    $r = $web->post('/admin/settings/customers', ['_token' => aifecToken($web), 'f' => [
        'customers.allow_register' => '0', 'customers.magic_link' => '0', 'customers.legacy_cookie_key' => 'CLAVE-Secreta-123',
    ]]);
    same(302, $r->status);
    $app->settings()->reload();
    same(false, $app->settings()->bool('customers.allow_register'));
    same(false, $app->settings()->bool('customers.magic_link'));
    same('CLAVE-Secreta-123', $app->secret('customers.legacy_cookie_key'));
    check(strpos($app->settings()->str('customers.legacy_cookie_key'), 'enc:') === 0, 'se guarda cifrada');
    notContains('CLAVE-Secreta-123', $web->get('/admin/settings/customers')->body);

    // Con el registro cerrado, el servicio no da de alta.
    throws(function () use ($app): void {
        $app->service('customers')->register(['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    }, \InvalidArgumentException::class);

    // Dejar la clave vacía conserva la que había.
    $web->post('/admin/settings/customers', ['_token' => aifecToken($web), 'f' => ['customers.allow_register' => '1', 'customers.magic_link' => '1', 'customers.legacy_cookie_key' => '']]);
    $app->settings()->reload();
    same(true, $app->settings()->bool('customers.allow_register'));
    same('CLAVE-Secreta-123', $app->secret('customers.legacy_cookie_key'));
});

test('autocomprobación: el servicio, las rutas, los permisos y las plantillas de correo están conectados', function (): void {
    [$app, $transport] = customersTestApp();
    check($app->has('customers'), 'el servicio customers está publicado');
    check($app->has('mailer'), 'el módulo de correo se carga con el de clientes');
    foreach (['admin.customers.export', 'admin.customers.addresses', 'admin.customers.addresses.save', 'admin.customers.addresses.delete', 'admin.customers.anonymise', 'admin.customers.data'] as $name) {
        check($app->router()->has($name), 'la ruta ' . $name . ' existe');
    }
    foreach (['customers.view', 'customers.edit', 'customers.export', 'customers.privacy'] as $cap) {
        check(isset(\Aifec\Admin\Admin::capabilities($app)[$cap]), 'el permiso ' . $cap . ' está declarado');
    }
    check(\Aifec\Admin\Admin::resource($app, 'customers') !== null && \Aifec\Admin\Admin::resource($app, 'customer_groups') !== null, 'los dos recursos están declarados');

    $templates = $app->service('mailer')->templates();
    foreach (['customer_welcome', 'customer_verify', 'customer_reset', 'customer_magic_link', 'customer_exists'] as $key) {
        check(isset($templates[$key]), 'la plantilla ' . $key . ' está declarada');
        $mail = $app->service('mailer')->render($key, $templates[$key]['sample'], 'es');
        check($mail['subject'] !== '' && $mail['html'] !== '' && $mail['text'] !== '', 'la plantilla ' . $key . ' se pinta entera');
        contains('AIFEC', $mail['html']);
        // Cada una lleva su enlace de muestra.
        contains('https://example.com/mi-cuenta', $mail['html']);
        notContains('{', $mail['subject'], 'el asunto no deja variables sin sustituir');
    }
    same(true, $templates['customer_reset']['system'], 'las que dan acceso salen aunque el correo esté retenido');
    same(true, $templates['customer_magic_link']['system']);
    same(true, $templates['customer_verify']['system']);
    same(0, count($transport->sent));
});

test('las plantillas de correo salen en castellano y escapan un nombre con «&», etiquetas y tildes', function (): void {
    [$app] = customersTestApp();
    $mailer = $app->service('mailer');
    $mail = $mailer->render('customer_reset', ['name' => 'Ana & <b>Núñez</b>', 'url' => 'https://tienda.test/mi-cuenta/restablecer/abc?x=1&y=2', 'minutes' => 60], 'es');
    contains('Hola Ana &amp; &lt;b&gt;Núñez&lt;/b&gt;', $mail['html']);
    notContains('<b>Núñez', $mail['html']);
    contains('x=1&amp;y=2', $mail['html'], 'la dirección también va escapada');
    contains('Elegir una contraseña nueva', $mail['html']);
    contains('60 minutos', $mail['html']);
    notContains('Choose a new password', $mail['html'], 'no queda texto en inglés');
    contains('Elige una contraseña nueva para AIFEC', $mail['subject']);

    $en = $mailer->render('customer_reset', ['name' => 'Ana', 'url' => 'https://tienda.test/x', 'minutes' => 60], 'en');
    contains('Choose a new password', $en['html'], 'sin traducción al inglés se ve el texto fuente');
});
