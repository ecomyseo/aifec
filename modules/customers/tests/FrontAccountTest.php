<?php
/**
 * AIFEC — pruebas de la zona de cliente de la tienda: entrar, registro, recuperar la contraseña, enlace de entrada,
 * direcciones, mis datos, verificación del correo, privacidad y las reglas comunes de todas las páginas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Security\Passwords;

require_once __DIR__ . '/CustomersKit.php';

if (!function_exists('customersFrontLogin')) {
    /** Un navegador de mentira que ya ha entrado con esa cuenta (sale por la pantalla de entrada, como un cliente). */
    function customersFrontLogin(App $app, string $email = 'ana@cliente.test', string $password = 'clave-larga-1'): TestClient
    {
        $web = new TestClient($app);
        $r = $web->post('/mi-cuenta/entrar', ['email' => $email, 'password' => $password]);
        same(303, $r->status, 'entrar con ' . $email);

        return $web;
    }

    /** La ficha de formulario (_token) de la primera página abierta. */
    function customersFrontToken(string $html): string
    {
        check(preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1, 'la página lleva una ficha de formulario');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** Número de filas de una tabla. */
    function customersFrontRows(App $app, string $table, string $where = '1 = 1', array $params = []): int
    {
        return customersCount($app, $table, $where, $params);
    }
}

test('registro: crea la cuenta, apunta la privacidad, manda la bienvenida y deja al cliente dentro', function (): void {
    [$app, $transport] = customersTestApp();
    $web = new TestClient($app);
    same(200, $web->get('/mi-cuenta/registro')->status);

    $r = $web->post('/mi-cuenta/registro', [
        'first_name' => 'ana', 'last_name' => 'ruiz', 'email' => 'Ana@Cliente.test', 'password' => 'clave-larga-1',
        'accept_privacy' => 'on', 'marketing' => 'on',
    ]);
    same(303, $r->status);
    same('/mi-cuenta', $r->headers['Location']);
    $id = (int) $app->db()->value('SELECT id FROM {customers} WHERE email = ?', ['ana@cliente.test']);
    check($id > 0, 'la cuenta existe con el correo en minúsculas');
    same('Ana', customersRow($app, $id)['first_name']);
    same(1, customersFrontRows($app, 'consents', "customer_id = ? AND kind = 'privacy' AND granted = 1", [$id]), 'queda la prueba de la privacidad');
    same(1, customersFrontRows($app, 'consents', "customer_id = ? AND kind = 'marketing' AND granted = 1", [$id]), 'y la de la publicidad');
    same(1, count($transport->sent), 'sale el correo de bienvenida con el enlace de verificación');
    same('Te damos la bienvenida a AIFEC', $transport->sent[0]->getSubject());

    $home = $web->get('/mi-cuenta');
    same(200, $home->status, 'el cliente ya está dentro');
    contains('Hola Ana', $home->body);
    contains('Tu cuenta está creada', $home->body);
    contains('Tu correo todavía no está confirmado', $home->body);
    notContains('Tu cuenta está creada', $web->get('/mi-cuenta')->body, 'el aviso se enseña una sola vez');
});

test('registro: sin aceptar la privacidad, con datos que faltan o con un fallo del servicio no se crea nada', function (): void {
    [$app] = customersTestApp();
    $web = new TestClient($app);
    $base = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@cliente.test', 'password' => 'clave-larga-1'];

    $r = $web->post('/mi-cuenta/registro', $base);
    same(200, $r->status);
    contains('Tienes que aceptar la política de privacidad', $r->body);
    contains('value="ana@cliente.test"', $r->body, 'lo escrito se conserva');
    notContains('clave-larga-1', $r->body, 'la contraseña no vuelve al navegador');

    $r = $web->post('/mi-cuenta/registro', ['first_name' => '', 'accept_privacy' => 'on'] + $base);
    contains('Escribe tu nombre, tu correo y una contraseña', $r->body);
    $r = $web->post('/mi-cuenta/registro', ['password' => 'corta', 'accept_privacy' => 'on'] + $base);
    contains('al menos 8 caracteres', $r->body);
    $r = $web->post('/mi-cuenta/registro', ['email' => 'no-es-un-correo', 'accept_privacy' => 'on'] + $base);
    contains('La dirección de correo no es válida', $r->body);

    same(0, customersFrontRows($app, 'customers'));
    same(0, customersFrontRows($app, 'consents'));
    same(null, $app->auth()->customerId());
});

test('registro apagado: la pantalla y el envío dan 404 y la entrada no ofrece crear cuenta', function (): void {
    [$app] = customersTestApp(['customers.allow_register' => false]);
    $web = new TestClient($app);
    same(404, $web->get('/mi-cuenta/registro')->status);
    same(404, $web->post('/mi-cuenta/registro', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'accept_privacy' => 'on'])->status);
    same(0, customersFrontRows($app, 'customers'));
    $login = $web->get('/mi-cuenta/entrar');
    same(200, $login->status);
    notContains('/mi-cuenta/registro', $login->body);
});

test('registro con un correo que ya es de una cuenta: el mismo mensaje neutro y un aviso a su dueño', function (): void {
    [$app, $transport] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = new TestClient($app);
    $r = $web->post('/mi-cuenta/registro', ['first_name' => 'Otra', 'email' => 'ana@cliente.test', 'password' => 'otra-clave-22', 'accept_privacy' => 'on']);
    same(200, $r->status);
    contains('No hemos podido crear la cuenta con esos datos', $r->body);
    contains('Si ya tienes una cuenta con ese correo, te hemos escrito', $r->body, 'dice qué hacer, sin decir si existe');
    notContains('ya existe', $r->body);
    notContains('ya tiene una cuenta', $r->body);
    same(1, customersFrontRows($app, 'customers'), 'no hay una segunda cuenta');
    same(1, customersMailCount($app, 'customer_exists'), 'el dueño recibe el aviso');
    same(null, $app->auth()->customerId());
    same(1, count($transport->sent));
});

test('entrar: la contraseña buena abre la sesión; la mala y el correo que no existe dan el mismo error genérico', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);

    $page = $web->get('/mi-cuenta/entrar');
    same(200, $page->status);
    contains('name="password"', $page->body);

    $bad = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-mala-999']);
    $unknown = $web->post('/mi-cuenta/entrar', ['email' => 'nadie@cliente.test', 'password' => 'clave-mala-999']);
    foreach ([$bad, $unknown] as $r) {
        same(200, $r->status);
        contains('El correo o la contraseña no son correctos', $r->body);
    }
    same(null, $app->auth()->customerId());
    contains('value="nadie@cliente.test"', $unknown->body);

    $empty = $web->post('/mi-cuenta/entrar', ['email' => '', 'password' => '']);
    contains('Escribe tu correo y tu contraseña', $empty->body);

    $ok = $web->post('/mi-cuenta/entrar', ['email' => ' ANA@cliente.test ', 'password' => 'clave-larga-1']);
    same(303, $ok->status);
    same('/mi-cuenta', $ok->headers['Location']);
    same($id, $app->auth()->customerId());
    same(303, $web->get('/mi-cuenta/entrar')->status, 'con la sesión abierta, la pantalla de entrada lleva a la cuenta');
});

test('entrar: cinco fallos bloquean la cuenta con su mensaje y ni la contraseña buena entra', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);
    for ($i = 1; $i <= 4; ++$i) {
        $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'mala-' . $i]);
        same(200, $r->status);
        contains('no son correctos', $r->body);
    }
    $fifth = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'mala-5']);
    same(429, $fifth->status);
    contains('Demasiados intentos fallidos. Inténtalo de nuevo dentro de 15 minutos', $fifth->body);
    $good = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    same(429, $good->status);
    same(null, $app->auth()->customerId());

    $app->clock()->advance(16 * 60);
    same(303, $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1'])->status, 'pasado el bloqueo entra');
});

test('entrar: «next» solo vale si es un camino del propio sitio', function (): void {
    foreach (['https://evil.test/robar', '//evil.test/x', '/\\evil.test', 'javascript:alert(1)', 'evil.test/x'] as $bad) {
        [$app] = customersTestApp();
        customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
        $web = new TestClient($app);
        $page = $web->get('/mi-cuenta/entrar?next=' . rawurlencode($bad));
        notContains('evil.test', $page->body, 'la pantalla no repite un destino ajeno: ' . $bad);
        notContains('name="next"', $page->body, 'ni lo guarda en el formulario: ' . $bad);
        $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'next' => $bad]);
        same(303, $r->status);
        same('/mi-cuenta', $r->headers['Location'], 'va a la cuenta y no a ' . $bad);
    }
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);
    $page = $web->get('/mi-cuenta/entrar?next=' . rawurlencode('/mi-cuenta/datos'));
    contains('name="next" value="/mi-cuenta/datos"', $page->body);
    $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'next' => '/mi-cuenta/datos']);
    same('/mi-cuenta/datos', $r->headers['Location']);
});

test('enlace de entrada: contesta igual exista o no la cuenta y vale una sola vez', function (): void {
    [$app, $transport] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $web = new TestClient($app);
    $known = $web->post('/mi-cuenta/entrar/enlace', ['email' => 'ana@cliente.test']);
    $unknown = $web->post('/mi-cuenta/entrar/enlace', ['email' => 'nadie@cliente.test']);
    same(200, $known->status);
    same($known->body, $unknown->body, 'la respuesta no delata si existe');
    contains('Si ese correo es de una cuenta', $known->body);
    same(1, count($transport->sent), 'solo sale el correo de la cuenta que existe');
    $token = customersMailToken($transport);

    $first = new TestClient($app);
    $r = $first->get('/mi-cuenta/entrar/' . $token);
    same(303, $r->status);
    same('/mi-cuenta', $r->headers['Location']);
    same(200, $first->get('/mi-cuenta')->status);
    same($id, $app->auth()->customerId());

    $second = new TestClient($app);
    $again = $second->get('/mi-cuenta/entrar/' . $token);
    same(200, $again->status, 'la segunda vez no entra');
    contains('El enlace no es válido o ha caducado', $again->body);
    same(302, $second->get('/mi-cuenta')->status);
    same(200, (new TestClient($app))->get('/mi-cuenta/entrar/ficha-inventada-que-no-existe-1234')->status);
});

test('enlace de entrada apagado: el envío da 404 y la pantalla no lo ofrece', function (): void {
    [$app] = customersTestApp(['customers.magic_link' => false]);
    customersMakeAccount($app, 'ana@cliente.test');
    $web = new TestClient($app);
    same(404, $web->post('/mi-cuenta/entrar/enlace', ['email' => 'ana@cliente.test'])->status);
    notContains('/mi-cuenta/entrar/enlace', $web->get('/mi-cuenta/entrar')->body);
    same(0, customersMailCount($app, 'customer_magic_link'));
});

test('recuperar: la respuesta es idéntica exista o no el correo y solo sale un mensaje', function (): void {
    [$app, $transport] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = new TestClient($app);
    same(200, $web->get('/mi-cuenta/recuperar')->status);
    $known = $web->post('/mi-cuenta/recuperar', ['email' => 'ana@cliente.test']);
    $unknown = $web->post('/mi-cuenta/recuperar', ['email' => 'nadie@cliente.test']);
    same(200, $known->status);
    same($known->body, $unknown->body);
    contains('Si ese correo es de una cuenta', $known->body);
    notContains('ana@cliente.test', $known->body);
    same(1, count($transport->sent));
});

test('restablecer: una ficha buena cambia la contraseña una vez; mal repetida o corta no la gasta; usada y caducada no valen', function (): void {
    [$app, $transport] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);
    $web->post('/mi-cuenta/recuperar', ['email' => 'ana@cliente.test']);
    $token = customersMailToken($transport);
    $url = '/mi-cuenta/restablecer/' . $token;
    same(200, $web->get($url)->status);

    $r = $web->post($url, ['password' => 'clave-nueva-22', 'password_confirm' => 'otra-distinta-33']);
    contains('Las dos contraseñas no son iguales', $r->body);
    $r = $web->post($url, ['password' => 'corta', 'password_confirm' => 'corta']);
    contains('al menos 8 caracteres', $r->body);
    check(Passwords::verify('clave-larga-1', (string) customersRow($app, $id)['password']), 'por ahora sigue la contraseña de antes');

    $ok = $web->post($url, ['password' => 'clave-nueva-22', 'password_confirm' => 'clave-nueva-22']);
    same(303, $ok->status);
    same('/mi-cuenta/entrar?done=reset', $ok->headers['Location']);
    contains('Tu contraseña ha cambiado', $web->follow()->body);
    check(Passwords::verify('clave-nueva-22', (string) customersRow($app, $id)['password']), 'la contraseña nueva queda guardada');

    $used = $web->post($url, ['password' => 'otra-clave-44', 'password_confirm' => 'otra-clave-44']);
    same(200, $used->status);
    contains('El enlace no es válido o ha caducado', $used->body);
    contains('/mi-cuenta/recuperar', $used->body, 'se ofrece pedir otro enlace');
    check(Passwords::verify('clave-nueva-22', (string) customersRow($app, $id)['password']), 'una ficha usada no cambia nada');

    // Caducada: la ficha vale una hora.
    $web->post('/mi-cuenta/recuperar', ['email' => 'ana@cliente.test']);
    $late = customersMailToken($transport);
    $app->clock()->advance(3601);
    $expired = $web->post('/mi-cuenta/restablecer/' . $late, ['password' => 'clave-tarde-55', 'password_confirm' => 'clave-tarde-55']);
    contains('El enlace no es válido o ha caducado', $expired->body);
    check(Passwords::verify('clave-nueva-22', (string) customersRow($app, $id)['password']));
});

test('sin sesión, cada página privada manda a entrar y, tras entrar, vuelve a la página pedida', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $web = new TestClient($app);
    foreach (['/mi-cuenta', '/mi-cuenta/datos', '/mi-cuenta/direcciones', '/mi-cuenta/direcciones/nueva', '/mi-cuenta/privacidad', '/mi-cuenta/privacidad/datos.json', '/mi-cuenta/pedidos', '/mi-cuenta/descargas'] as $path) {
        $r = $web->get($path);
        same(302, $r->status, $path);
        same('/mi-cuenta/entrar?next=' . rawurlencode($path), $r->headers['Location'], $path);
    }
    $page = $web->get('/mi-cuenta/entrar?next=' . rawurlencode('/mi-cuenta/datos'));
    $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'next' => '/mi-cuenta/datos']);
    same('/mi-cuenta/datos', $r->headers['Location']);
    same(200, $web->follow()->status);
    contains('name="action" value="password"', $web->last->body);
});

test('direcciones: alta, cambio, dirección por defecto y borrado', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $web = customersFrontLogin($app);
    contains('Todavía no hay direcciones', $web->get('/mi-cuenta/direcciones')->body);
    contains('Guardar dirección', $web->get('/mi-cuenta/direcciones/nueva')->body);

    $r = $web->post('/mi-cuenta/direcciones/nueva', customersAddressData(['label' => 'Casa', 'country' => 'ES']));
    same(303, $r->status);
    same('/mi-cuenta/direcciones', $r->headers['Location']);
    $rows = $app->db()->select('SELECT * FROM {addresses} WHERE customer_id = ? ORDER BY id', [$id]);
    same(1, count($rows));
    same([1, 1], [(int) $rows[0]['default_shipping'], (int) $rows[0]['default_billing']], 'la primera es de las dos clases por defecto');
    same('35', $rows[0]['region'], 'la provincia sale del código postal');
    contains('Dirección guardada', $web->follow()->body);

    $web->post('/mi-cuenta/direcciones/nueva', customersAddressData(['label' => 'Oficina', 'line1' => 'Calle Larga 2', 'default_shipping' => '1']));
    $rows = $app->db()->select('SELECT * FROM {addresses} WHERE customer_id = ? ORDER BY id', [$id]);
    same(2, count($rows));
    same([0, 1], [(int) $rows[0]['default_shipping'], (int) $rows[1]['default_shipping']], 'la nueva pasa a ser la de envío');
    same([1, 0], [(int) $rows[0]['default_billing'], (int) $rows[1]['default_billing']], 'la de facturación no cambia');

    $first = (int) $rows[0]['id'];
    $second = (int) $rows[1]['id'];
    $edit = $web->get('/mi-cuenta/direcciones/' . $first);
    same(200, $edit->status);
    contains('value="Calle Mayor 5"', $edit->body);
    $r = $web->post('/mi-cuenta/direcciones/' . $first, customersAddressData(['line1' => 'Calle Nueva 9', 'company' => 'Ruiz & Hijos']));
    same(303, $r->status);
    $row = $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$first]);
    same(['Calle Nueva 9', 'Ruiz & Hijos'], [$row['line1'], $row['company']]);
    same(1, (int) $row['default_billing'], 'cambiar la calle no quita lo de por defecto');
    $list = $web->get('/mi-cuenta/direcciones')->body;
    contains('Calle Nueva 9', $list);
    contains('Ruiz &amp; Hijos', $list);

    $r = $web->post('/mi-cuenta/direcciones/' . $second . '/borrar');
    same(303, $r->status);
    same(1, customersFrontRows($app, 'addresses', 'customer_id = ?', [$id]));
    same(1, (int) $app->db()->value('SELECT default_shipping FROM {addresses} WHERE id = ?', [$first]), 'la que queda recoge lo de por defecto');
    contains('Dirección borrada', $web->follow()->body);
});

test('direcciones: una dirección mal escrita no se guarda y se vuelve a enseñar lo escrito', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = customersFrontLogin($app);
    $r = $web->post('/mi-cuenta/direcciones/nueva', customersAddressData(['postcode' => 'XX99']));
    same(200, $r->status);
    contains('El código postal no es válido para ese país', $r->body);
    contains('value="Calle Mayor 5"', $r->body);
    $r = $web->post('/mi-cuenta/direcciones/nueva', customersAddressData(['city' => '']));
    contains('La ciudad es obligatoria', $r->body);
    same(0, customersFrontRows($app, 'addresses'));
});

test('direcciones: la de otro cliente es un 404 al verla, cambiarla y borrarla', function (): void {
    [$app] = customersTestApp();
    $ana = customersMakeAccount($app, 'ana@cliente.test');
    customersMakeAccount($app, 'luis@cliente.test');
    $address = customersService($app)->saveAddress($ana, customersAddressData(['line1' => 'Calle de Ana 1']));
    $web = customersFrontLogin($app, 'luis@cliente.test');

    same(404, $web->get('/mi-cuenta/direcciones/' . $address)->status);
    same(404, $web->post('/mi-cuenta/direcciones/' . $address, customersAddressData(['line1' => 'Robada 66']))->status);
    same(404, $web->post('/mi-cuenta/direcciones/' . $address . '/borrar')->status);
    same(404, $web->get('/mi-cuenta/direcciones/999999')->status);
    $row = $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$address]);
    same('Calle de Ana 1', $row['line1'], 'no se ha tocado');
    notContains('Calle de Ana 1', $web->get('/mi-cuenta/direcciones')->body);
});

test('mis datos: se guardan con los escapes en su sitio, la publicidad se apunta y los errores no guardan nada', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $web = customersFrontLogin($app);
    $data = ['action' => 'profile', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999345678', 'company' => 'Ruiz & Hijos <b>', 'vat_id' => '12345678Z', 'marketing' => 'on'];

    $r = $web->post('/mi-cuenta/datos', $data);
    same(303, $r->status);
    $row = customersRow($app, $id);
    same(['Ruiz & Hijos <b>', '999345678', '12345678Z', 1], [$row['company'], $row['phone'], $row['vat_id'], (int) $row['marketing']]);
    same(1, customersFrontRows($app, 'consents', "customer_id = ? AND kind = 'marketing' AND granted = 1", [$id]));
    $page = $web->follow();
    contains('Tus datos se han guardado', $page->body);
    contains('value="Ruiz &amp; Hijos &lt;b&gt;"', $page->body);
    notContains('<b>', $page->body);
    contains('name="marketing" value="1" checked', $page->body);

    $web->post('/mi-cuenta/datos', ['marketing' => ''] + $data);
    same(0, (int) customersRow($app, $id)['marketing'], 'sin la casilla, se retira la publicidad');
    same(1, customersFrontRows($app, 'consents', "customer_id = ? AND kind = 'marketing' AND granted = 0", [$id]));

    $r = $web->post('/mi-cuenta/datos', ['first_name' => '', 'company' => 'Cambiada'] + $data);
    contains('El nombre es obligatorio', $r->body);
    $r = $web->post('/mi-cuenta/datos', ['vat_id' => 'XXXX', 'company' => 'Cambiada'] + $data);
    contains('El NIF o número de IVA no es válido', $r->body);
    same('Ruiz & Hijos <b>', customersRow($app, $id)['company'], 'los envíos con error no guardan');
    same(400, $web->post('/mi-cuenta/datos', ['action' => 'otra-cosa'])->status);
});

test('cambiar la contraseña: pide la actual, cierra las otras sesiones y deja abierta la tuya', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $mine = customersFrontLogin($app);
    $other = customersFrontLogin($app);
    $form = ['action' => 'password', 'current_password' => 'clave-larga-1', 'new_password' => 'clave-nueva-22', 'new_password_confirm' => 'clave-nueva-22'];

    $r = $mine->post('/mi-cuenta/datos', ['current_password' => 'mala-mala'] + $form);
    same(200, $r->status);
    contains('La contraseña no es correcta', $r->body);
    $r = $mine->post('/mi-cuenta/datos', ['new_password_confirm' => 'otra-distinta'] + $form);
    contains('Las dos contraseñas no son iguales', $r->body);
    $r = $mine->post('/mi-cuenta/datos', ['new_password' => 'corta', 'new_password_confirm' => 'corta'] + $form);
    contains('al menos 8 caracteres', $r->body);
    check(Passwords::verify('clave-larga-1', (string) customersRow($app, $id)['password']), 'los errores no cambian nada');
    same(200, $other->get('/mi-cuenta')->status, 'la otra sesión sigue viva mientras no cambie la contraseña');

    $r = $mine->post('/mi-cuenta/datos', $form);
    same(303, $r->status);
    contains('Tu contraseña ha cambiado', $mine->follow()->body);
    check(Passwords::verify('clave-nueva-22', (string) customersRow($app, $id)['password']));
    same(200, $mine->get('/mi-cuenta')->status, 'la sesión desde la que se cambia sigue abierta');
    same(302, $other->get('/mi-cuenta')->status, 'la otra deja de valer');
    customersFrontLogin($app, 'ana@cliente.test', 'clave-nueva-22');
});

test('cambiar el correo: pide la contraseña, deja la cuenta sin verificar y manda el enlace al correo nuevo', function (): void {
    [$app, $transport] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersMakeAccount($app, 'luis@cliente.test');
    $web = customersFrontLogin($app);
    $form = ['action' => 'email', 'new_email' => 'ana.nueva@cliente.test', 'current_password' => 'clave-larga-1'];

    $r = $web->post('/mi-cuenta/datos', ['current_password' => 'mala-mala'] + $form);
    contains('La contraseña no es correcta', $r->body);
    same('ana@cliente.test', customersRow($app, $id)['email']);
    $r = $web->post('/mi-cuenta/datos', ['new_email' => 'luis@cliente.test'] + $form);
    contains('No hemos podido cambiar la dirección de correo', $r->body);
    $r = $web->post('/mi-cuenta/datos', ['new_email' => 'ANA@cliente.test'] + $form);
    contains('Ese ya es tu correo', $r->body);
    same(0, count($transport->sent));

    $r = $web->post('/mi-cuenta/datos', $form);
    same(303, $r->status);
    $row = customersRow($app, $id);
    same('ana.nueva@cliente.test', $row['email']);
    same(null, $row['email_verified_at'], 'el correo nuevo hay que confirmarlo');
    same(1, count($transport->sent));
    same(['ana.nueva@cliente.test'], $transport->sent[0]->recipients());
    contains('Tu correo ha cambiado', $web->follow()->body);
    contains('Tu correo todavía no está confirmado', $web->get('/mi-cuenta')->body);
});

test('verificar el correo: el aviso, el reenvío y el enlace (que vale una sola vez)', function (): void {
    [$app, $transport] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['email_verified_at' => null]);
    $web = customersFrontLogin($app);
    contains('Tu correo todavía no está confirmado', $web->get('/mi-cuenta')->body);

    $r = $web->post('/mi-cuenta/verificacion/reenviar');
    same(303, $r->status);
    same(1, count($transport->sent));
    contains('Te hemos mandado un correo para confirmar', $web->follow()->body);
    $token = customersMailToken($transport);

    $r = $web->get('/mi-cuenta/verificar/' . $token);
    same(303, $r->status);
    check(customersRow($app, $id)['email_verified_at'] !== null, 'el correo queda verificado');
    $home = $web->follow();
    contains('Tu correo está confirmado', $home->body);
    notContains('Tu correo todavía no está confirmado', $home->body);

    $again = $web->get('/mi-cuenta/verificar/' . $token);
    same(200, $again->status);
    contains('El enlace no es válido o ha caducado', $again->body);
    $web->post('/mi-cuenta/verificacion/reenviar');
    same(1, count($transport->sent), 'ya verificado, no se manda otro');
    contains('ya está confirmado', $web->follow()->body);

    // Sin sesión, el enlace verifica y lleva a la entrada.
    $other = customersMakeAccount($app, 'luis@cliente.test', 'clave-larga-1', ['email_verified_at' => null]);
    customersService($app)->sendVerification($other);
    $anon = new TestClient($app);
    $r = $anon->get('/mi-cuenta/verificar/' . customersMailToken($transport));
    same(303, $r->status);
    same('/mi-cuenta/entrar?done=verified', $r->headers['Location']);
    contains('Tu correo está confirmado', $anon->follow()->body);
});

test('privacidad: el JSON trae los datos de quien lo pide y de nadie más', function (): void {
    [$app] = customersTestApp();
    $ana = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['phone' => '999345678']);
    customersMakeAccount($app, 'luis@cliente.test');
    customersService($app)->saveAddress($ana, customersAddressData(['line1' => 'Calle de Ana 1']));
    customersService($app)->consent($ana, 'ana@cliente.test', 'marketing', true, '', 'prueba');
    $web = customersFrontLogin($app);

    contains('Descargar mis datos', $web->get('/mi-cuenta/privacidad')->body);
    $r = $web->get('/mi-cuenta/privacidad/datos.json');
    same(200, $r->status);
    contains('attachment', $r->headers['Content-Disposition']);
    contains('application/json', $r->headers['Content-Type']);
    contains('no-store', $r->headers['Cache-Control']);
    $data = json_decode($r->body, true);
    check(is_array($data), 'es JSON');
    same('ana@cliente.test', $data['customer']['email']);
    same('999345678', $data['customer']['phone']);
    same('Calle de Ana 1', $data['addresses'][0]['line1']);
    same(1, count($data['consents']));
    check(!array_key_exists('password', $data['customer']), 'sin la contraseña');
    notContains('luis@cliente.test', $r->body);
    notContains('$argon', $r->body);
});

test('privacidad: eliminar la cuenta pide palabra y contraseña; con las buenas anonimiza y cierra la sesión', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersService($app)->saveAddress($id, customersAddressData());
    $web = customersFrontLogin($app);
    $page = $web->get('/mi-cuenta/privacidad');
    contains('Escribe ELIMINAR para confirmar', $page->body);

    $r = $web->post('/mi-cuenta/privacidad/eliminar', ['password' => 'clave-larga-1', 'confirm' => 'borrar']);
    same(200, $r->status);
    contains('Escribe la palabra de confirmación', $r->body);
    $r = $web->post('/mi-cuenta/privacidad/eliminar', ['password' => 'mala-mala', 'confirm' => 'ELIMINAR']);
    same(200, $r->status);
    contains('La contraseña no es correcta', $r->body);
    $row = customersRow($app, $id);
    same(['ana@cliente.test', 1], [$row['email'], (int) $row['active']], 'con datos malos no se toca nada');
    same(1, customersFrontRows($app, 'addresses', 'customer_id = ?', [$id]));
    same(200, $web->get('/mi-cuenta')->status);

    $r = $web->post('/mi-cuenta/privacidad/eliminar', ['password' => 'clave-larga-1', 'confirm' => 'eliminar']);
    same(303, $r->status);
    same('/mi-cuenta/entrar?done=deleted', $r->headers['Location']);
    $row = customersRow($app, $id);
    same('anonymised-' . $id . '@anonymised.invalid', $row['email']);
    same(0, (int) $row['active']);
    same(0, customersFrontRows($app, 'addresses', 'customer_id = ?', [$id]));
    contains('Tu cuenta se ha eliminado', $web->follow()->body);
    same(302, $web->get('/mi-cuenta')->status, 'la sesión está cerrada');
    same(null, $app->auth()->customerId());
});

test('todas las páginas: noindex, no-store, un solo h1 y los datos con «&» y «<» escapados', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['first_name' => 'Ana & <i>Luis</i>', 'company' => 'Ruiz & Hijos', 'email_verified_at' => null]);
    $address = customersService($app)->saveAddress($id, customersAddressData(['company' => 'Ruiz & <u>Hijos</u>']));
    $web = customersFrontLogin($app);
    $private = ['/mi-cuenta', '/mi-cuenta/datos', '/mi-cuenta/direcciones', '/mi-cuenta/direcciones/nueva', '/mi-cuenta/direcciones/' . $address, '/mi-cuenta/privacidad'];
    foreach ($private as $path) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        contains('<meta name="robots" content="noindex, nofollow">', $r->body, $path);
        contains('no-store', $r->headers['Cache-Control'], $path);
        same(1, substr_count($r->body, '<h1'), 'un solo h1 en ' . $path);
        notContains('<i>Luis', $r->body, $path);
        notContains('<u>Hijos', $r->body, $path);
    }
    contains('Hola Ana &amp; &lt;i&gt;Luis&lt;/i&gt;', $web->get('/mi-cuenta')->body);
    contains('value="Ana &amp; &lt;i&gt;Luis&lt;/i&gt;"', $web->get('/mi-cuenta/datos')->body);
    contains('Ruiz &amp; &lt;u&gt;Hijos&lt;/u&gt;', $web->get('/mi-cuenta/direcciones')->body);
    contains('value="Ruiz &amp; &lt;u&gt;Hijos&lt;/u&gt;"', $web->get('/mi-cuenta/direcciones/' . $address)->body);

    $anon = new TestClient($app);
    foreach (['/mi-cuenta/entrar', '/mi-cuenta/registro', '/mi-cuenta/recuperar', '/mi-cuenta/restablecer/ficha-cualquiera-de-prueba-1'] as $path) {
        $r = $anon->get($path);
        same(200, $r->status, $path);
        contains('noindex', $r->body, $path);
        contains('no-store', $r->headers['Cache-Control'], $path);
        same(1, substr_count($r->body, '<h1'), 'un solo h1 en ' . $path);
    }
});

test('todos los envíos desde otro origen dan 403 y no cambian nada', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $address = customersService($app)->saveAddress($id, customersAddressData());
    $web = customersFrontLogin($app);
    $foreign = ['sec-fetch-site' => 'cross-site'];

    $posts = [
        ['/mi-cuenta/salir', []],
        ['/mi-cuenta/verificacion/reenviar', []],
        ['/mi-cuenta/datos', ['action' => 'profile', 'first_name' => 'Robada']],
        ['/mi-cuenta/direcciones/nueva', customersAddressData()],
        ['/mi-cuenta/direcciones/' . $address . '/borrar', []],
        ['/mi-cuenta/privacidad/eliminar', ['password' => 'clave-larga-1', 'confirm' => 'ELIMINAR']],
    ];
    foreach ($posts as [$path, $data]) {
        same(403, $web->post($path, $data, $foreign)->status, $path);
    }
    $anon = new TestClient($app);
    foreach ([['/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']], ['/mi-cuenta/registro', []], ['/mi-cuenta/recuperar', ['email' => 'ana@cliente.test']], ['/mi-cuenta/entrar/enlace', ['email' => 'ana@cliente.test']]] as [$path, $data]) {
        same(403, $anon->post($path, $data, $foreign)->status, $path);
    }
    same(null, $app->auth()->customerId(), 'la entrada desde otro origen no abre sesión');
    $row = customersRow($app, $id);
    same(['Ana', 1], [$row['first_name'], (int) $row['active']]);
    same(1, customersFrontRows($app, 'addresses', 'customer_id = ?', [$id]));
    same(200, $web->get('/mi-cuenta')->status, 'la sesión sigue abierta');
});

test('la ficha de los formularios: una falsa da 403 y la de la página vale', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = customersFrontLogin($app);
    $token = customersFrontToken($web->get('/mi-cuenta')->body);

    same(403, $web->post('/mi-cuenta/salir', ['_token' => 'falsa'])->status);
    same(403, $web->post('/mi-cuenta/salir', ['_token' => '1.' . str_repeat('A', 43)])->status);
    same(200, $web->get('/mi-cuenta')->status, 'seguía dentro');
    same(303, $web->post('/mi-cuenta/salir', ['_token' => $token])->status);
    same('/mi-cuenta/entrar?done=closed', $web->last->headers['Location']);
    same(302, $web->get('/mi-cuenta')->status, 'salir cierra la sesión');
    contains('Has cerrado la sesión', $web->get('/mi-cuenta/entrar?done=closed')->body);
});

test('estado.json: dice quién ha entrado, sin caché y solo con el nombre de pila', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $web = new TestClient($app);
    $r = $web->get('/mi-cuenta/estado.json');
    same(200, $r->status);
    same(['logged' => false, 'name' => ''], $web->json());
    contains('no-store', $r->headers['Cache-Control']);
    contains('json', $r->headers['Content-Type']);

    $web = customersFrontLogin($app);
    $r = $web->get('/mi-cuenta/estado.json');
    same(['logged' => true, 'name' => 'Ana'], $web->json());
    contains('no-store', $r->headers['Cache-Control']);
    notContains('ana@cliente.test', $r->body);
    notContains('Ruiz', $r->body);
});

test('los nombres de ruta que usan los correos existen y dan las direcciones de la zona', function (): void {
    [$app, $transport] = customersTestApp();
    $router = $app->router();
    foreach (['account', 'account.login', 'account.register', 'account.logout', 'account.recover', 'account.reset', 'account.magic', 'account.verify', 'account.orders', 'account.order', 'account.addresses', 'account.profile', 'account.downloads'] as $name) {
        check($router->has($name), 'la ruta «' . $name . '» está registrada');
    }
    same('/mi-cuenta/restablecer/abc', $app->url()->route('account.reset', ['token' => 'abc']));
    same('/mi-cuenta/entrar/abc', $app->url()->route('account.magic', ['token' => 'abc']));
    same('/mi-cuenta/verificar/abc', $app->url()->route('account.verify', ['token' => 'abc']));

    // El enlace del correo lleva a una página que existe.
    customersMakeAccount($app, 'ana@cliente.test');
    customersService($app)->requestReset('ana@cliente.test');
    $token = customersMailToken($transport);
    contains('https://tienda.test/mi-cuenta/restablecer/' . $token, customersMailText($transport));
    same(200, (new TestClient($app))->get('/mi-cuenta/restablecer/' . $token)->status);
});

test('sin el módulo de pedidos, pedidos y descargas dan 404 y el menú no los enseña', function (): void {
    [$app] = customersTestApp();
    customersMakeAccount($app, 'ana@cliente.test');
    $web = customersFrontLogin($app);
    same(404, $web->get('/mi-cuenta/pedidos')->status);
    same(404, $web->get('/mi-cuenta/pedidos/2026-000001')->status);
    same(404, $web->get('/mi-cuenta/descargas')->status);
    same(404, $web->post('/mi-cuenta/descargas/1/enlace')->status);
    $home = $web->get('/mi-cuenta');
    notContains('/mi-cuenta/pedidos', $home->body);
    notContains('/mi-cuenta/descargas', $home->body);
    contains('/mi-cuenta/direcciones', $home->body);
});

test('la dirección base sale del ajuste urls.account y el hueco account.dashboard se pinta', function (): void {
    [$app] = customersTestApp(['urls.account' => 'cuenta']);
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $app->hooks()->slot('account.dashboard', static function (array $customer): string {
        return '<p id="mi-hueco">Hueco de ' . e($customer['email']) . '</p>';
    });
    $web = new TestClient($app);
    same(200, $web->get('/cuenta/entrar')->status);
    same(404, $web->get('/mi-cuenta/entrar')->status);
    $r = $web->post('/cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    same(303, $r->status);
    same('/cuenta', $r->headers['Location']);
    $home = $web->get('/cuenta');
    same(200, $home->status);
    contains('<p id="mi-hueco">Hueco de ana@cliente.test</p>', $home->body);
    contains('href="/cuenta/direcciones"', $home->body);
    same($id, $app->auth()->customerId());
});
