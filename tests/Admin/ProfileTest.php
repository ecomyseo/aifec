<?php
/**
 * AIFEC — «Mi cuenta» del panel: datos, contraseña, verificación en dos pasos y sesiones.
 *
 * Fallo que cubre: el menú de la persona enlazaba a /admin/profile y esa pantalla no existía (404). Lo encontró
 * la comprobación del despliegue, que pide cada enlace del menú.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Passwords;
use Aifec\Security\Totp;

/** Envía un formulario de «Mi cuenta» con la ficha de la última página abierta. */
function profilePost(TestClient $web, string $path, array $data = []): Aifec\Kernel\Response
{
    $web->get('/admin/profile');

    return $web->post('/admin/profile' . $path, ['_token' => aifecToken($web)] + $data);
}

test('mi cuenta: cualquier persona del panel la abre, con sus cuatro pestañas, y el menú enlaza a ella', function (): void {
    $app = testApp();
    $web = adminClient($app, 'support');
    contains('href="/admin/profile"', $web->last->body, 'el menú de la persona enlaza a su cuenta');
    $r = $web->get('/admin/profile');
    same(200, $r->status, 'no pide ningún permiso: cada uno gestiona lo suyo');
    foreach (['Mis datos', 'Contraseña', 'Verificación en dos pasos', 'Sesiones'] as $tab) {
        contains('>' . $tab . '</button>', $r->body);
    }
    contains('data-af-panel="prdata"', $r->body);
    same('Support', inputValue($r->body, 'name'));
    same('support@tienda.test', inputValue($r->body, 'email'));
    notContains('$2y$', $r->body, 'el resumen de la contraseña no sale');

    $r = $web->get('/admin/profile?tab=prsessions');
    contains('data-af-active="prsessions"', $r->body);
    $r = $web->get('/admin/profile?tab=no-existe');
    contains('data-af-active="prdata"', $r->body);

    $anon = new TestClient($app);
    same(302, $anon->get('/admin/profile')->status, 'sin entrar, a la pantalla de acceso');
});

test('mis datos: nombre e idioma se guardan; el correo solo con la contraseña actual y si está libre', function (): void {
    $app = testApp();
    $web = adminClient($app);
    aifecAdmin($app, 'editor', 'otra@tienda.test', 'Otra');
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");

    $r = profilePost($web, '/data', ['name' => 'Ana Ñ. & Cía', 'email' => 'admin@tienda.test', 'locale' => 'en']);
    same(303, $r->status);
    contains('/admin/profile?tab=prdata', (string) $r->headers['Location']);
    $row = $app->db()->row('SELECT name, locale, email FROM {users} WHERE id = ?', [$id]);
    same(['name' => 'Ana Ñ. & Cía', 'locale' => 'en', 'email' => 'admin@tienda.test'], $row);
    $r = $web->get('/admin/profile');
    same('Ana Ñ. & Cía', inputValue($r->body, 'name'));
    contains('My details', $r->body, 'el panel pasa a su idioma');
    profilePost($web, '/data', ['name' => 'Ana', 'email' => 'admin@tienda.test', 'locale' => 'es']);

    $r = profilePost($web, '/data', ['name' => '', 'email' => 'no es un correo', 'locale' => 'xx']);
    same(422, $r->status);
    contains('Escribe tu nombre.', $r->body);
    contains('Esa dirección de correo no es válida.', $r->body);

    $r = profilePost($web, '/data', ['name' => 'Ana', 'email' => 'nueva@tienda.test', 'locale' => 'es']);
    same(422, $r->status);
    contains('Para cambiar el correo, escribe tu contraseña actual.', $r->body);
    same('nueva@tienda.test', inputValue($r->body, 'email'), 'no hay que volver a escribirlo');
    same(422, profilePost($web, '/data', ['name' => 'Ana', 'email' => 'nueva@tienda.test', 'locale' => 'es', 'current_password' => 'no-es-esta'])->status);
    $r = profilePost($web, '/data', ['name' => 'Ana', 'email' => 'OTRA@tienda.test', 'locale' => 'es', 'current_password' => 'clave-larga-1234']);
    same(422, $r->status);
    contains('Otra persona ya usa ese correo.', $r->body);
    same('admin@tienda.test', $app->db()->value('SELECT email FROM {users} WHERE id = ?', [$id]), 'nada de lo anterior ha cambiado el correo');

    same(303, profilePost($web, '/data', ['name' => 'Ana', 'email' => 'Nueva@Tienda.test', 'locale' => 'es', 'current_password' => 'clave-larga-1234'])->status);
    same('nueva@tienda.test', $app->db()->value('SELECT email FROM {users} WHERE id = ?', [$id]));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'profile.update'") > 0 ? 1 : 0, 'queda en el registro de actividad');

    // Sin ficha no se guarda nada.
    $web->get('/admin/profile');
    $web->post('/admin/profile/data', ['name' => 'Sin ficha', 'email' => 'nueva@tienda.test', 'locale' => 'es']);
    same('Ana', $app->db()->value('SELECT name FROM {users} WHERE id = ?', [$id]));
});

test('contraseña: pide la actual, exige diez caracteres, y al cambiarla cierra las otras sesiones', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $other = new TestClient($app);
    aifecLogin($other, 'admin@tienda.test');
    same(200, $other->get('/admin')->status, 'la misma persona en otro navegador');
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");

    $bad = [
        [['current_password' => 'no-es-esta', 'password' => 'una-nueva-larga-9', 'password2' => 'una-nueva-larga-9'], 'Esa no es tu contraseña actual.'],
        [['current_password' => 'clave-larga-1234', 'password' => 'corta', 'password2' => 'corta'], 'La contraseña necesita al menos 10 caracteres.'],
        [['current_password' => 'clave-larga-1234', 'password' => 'una-nueva-larga-9', 'password2' => 'otra-distinta-99'], 'Las dos contraseñas no son iguales.'],
        [['current_password' => 'clave-larga-1234', 'password' => 'clave-larga-1234', 'password2' => 'clave-larga-1234'], 'La contraseña nueva tiene que ser distinta de la actual.'],
    ];
    foreach ($bad as $case) {
        $r = profilePost($web, '/password', $case[0]);
        same(422, $r->status, $case[1]);
        contains($case[1], $r->body);
        contains('data-af-active="prpassword"', $r->body, 'vuelve a su pestaña');
        notContains('una-nueva-larga-9', $r->body, 'una contraseña escrita no vuelve a la pantalla');
    }
    check(Passwords::verify('clave-larga-1234', (string) $app->db()->value('SELECT password FROM {users} WHERE id = ?', [$id])), 'sigue la de antes');

    $r = profilePost($web, '/password', ['current_password' => 'clave-larga-1234', 'password' => 'una-nueva-larga-9', 'password2' => 'una-nueva-larga-9']);
    same(303, $r->status);
    $hash = (string) $app->db()->value('SELECT password FROM {users} WHERE id = ?', [$id]);
    check(Passwords::verify('una-nueva-larga-9', $hash) && !Passwords::verify('clave-larga-1234', $hash), 'la contraseña es la nueva');
    notContains('una-nueva-larga-9', $hash, 'y va resumida');
    same(200, $web->get('/admin/profile')->status, 'quien la cambia sigue dentro');
    contains('Contraseña cambiada', $web->last->body);
    same(302, $other->get('/admin')->status, 'el otro navegador se queda fuera');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {sessions} WHERE guard = 'admin' AND user_id = ?", [$id]));

    $again = new TestClient($app);
    same(422, aifecLogin($again, 'admin@tienda.test', 'clave-larga-1234')->status, 'con la vieja ya no se entra');
    check(in_array(aifecLogin($again, 'admin@tienda.test', 'una-nueva-larga-9')->status, [302, 303], true), 'con la nueva sí');
});

test('verificación en dos pasos: se activa demostrando un código, se pide al entrar y se apaga con la contraseña', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Muebles Ñandú']]);
    $web = adminClient($app);
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");

    $r = $web->get('/admin/profile?tab=prtwostep');
    contains('Configurar la verificación en dos pasos', $r->body);
    same(303, profilePost($web, '/two-step/start')->status);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]), 'todavía no se guarda nada');
    $r = $web->get('/admin/profile?tab=prtwostep');
    check(preg_match('/<code class="af-code"[^>]*>([A-Z2-7 ]+)<\/code>/', $r->body, $m) === 1, 'enseña la clave para escribirla a mano');
    $secret = str_replace(' ', '', $m[1]);
    check(strlen($secret) >= 16, 'la clave tiene al menos 16 caracteres');
    contains('otpauth://totp/', $r->body);
    contains('Mi cuenta', $r->body);

    $now = $app->clock()->now();
    $wrong = Totp::code($secret, $now) === '000000' ? '111111' : '000000';
    $r = profilePost($web, '/two-step/confirm', ['code' => $wrong]);
    same(422, $r->status);
    contains('Ese código no vale', $r->body);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]));

    $r = profilePost($web, '/two-step/confirm', ['code' => substr(Totp::code($secret, $now), 0, 3) . ' ' . substr(Totp::code($secret, $now), 3)]);
    same(200, $r->status, 'el código vale también con un espacio en medio y la respuesta enseña los códigos de recuperación, sin redirigir');
    same(10, preg_match_all('/<code class="af-code">([a-z2-9]{4}-[a-z2-9]{4})<\/code>/', $r->body), 'diez códigos de recuperación');
    $stored = (string) $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]);
    check($stored !== '' && strpos($stored, $secret) === false, 'el secreto se guarda cifrado, no tal cual');
    $r = $web->get('/admin/profile?tab=prtwostep');
    contains('Encendida', $r->body);
    notContains($secret, $r->body, 'una vez activada, la clave no se vuelve a enseñar');
    notContains(trim(chunk_split($secret, 4, ' ')), $r->body);

    // Al entrar ahora se pide el código.
    $fresh = new TestClient($app);
    $r = aifecLogin($fresh, 'admin@tienda.test');
    contains('/admin/login/code', (string) $r->headers['Location']);
    same(302, $fresh->get('/admin')->status, 'sin el código no se entra');
    $r = $fresh->post('/admin/login/code', ['code' => Totp::code($secret, $app->clock()->now())]);
    check(in_array($r->status, [302, 303], true));
    same(200, $fresh->get('/admin')->status);

    // Apagarla pide la contraseña.
    $r = profilePost($web, '/two-step/disable', ['current_password' => 'no-es-esta']);
    same(422, $r->status);
    contains('Esa no es tu contraseña actual.', $r->body);
    check((string) $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]) !== '', 'sigue encendida');
    same(303, profilePost($web, '/two-step/disable', ['current_password' => 'clave-larga-1234'])->status);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]));
});

test('verificación en dos pasos: la clave pendiente caduca a los quince minutos y se puede cancelar', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");

    profilePost($web, '/two-step/start');
    $r = $web->get('/admin/profile?tab=prtwostep');
    check(preg_match('/<code class="af-code"[^>]*>([A-Z2-7 ]+)<\/code>/', $r->body, $m) === 1);
    $secret = str_replace(' ', '', $m[1]);
    same(303, profilePost($web, '/two-step/cancel')->status);
    notContains('af-code', $web->get('/admin/profile?tab=prtwostep')->body, 'cancelada, vuelve al principio');

    profilePost($web, '/two-step/start');
    $r = $web->get('/admin/profile?tab=prtwostep');
    check(preg_match('/<code class="af-code"[^>]*>([A-Z2-7 ]+)<\/code>/', $r->body, $m2) === 1);
    $second = str_replace(' ', '', $m2[1]);
    check($second !== $secret, 'cada intento genera una clave nueva');
    $app->clock()->advance(16 * 60);
    $r = profilePost($web, '/two-step/confirm', ['code' => Totp::code($second, $app->clock()->now())]);
    same(303, $r->status);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]), 'con la clave caducada no se activa aunque el código sea bueno');
    contains('Ha pasado demasiado tiempo', $web->get('/admin/profile?tab=prtwostep')->body);
});

test('sesiones: se ven las abiertas y «cerrar las otras» deja solo la de este navegador', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    $a = new TestClient($app);
    aifecLogin($a, 'admin@tienda.test');
    $a->get('/admin');
    $b = new TestClient($app);
    aifecLogin($b, 'admin@tienda.test');
    $b->get('/admin');
    aifecAdmin($app, 'editor', 'otra@tienda.test', 'Otra');
    $stranger = new TestClient($app);
    aifecLogin($stranger, 'otra@tienda.test');
    $stranger->get('/admin');

    $r = $web->get('/admin/profile?tab=prsessions');
    same(3, substr_count($r->body, '<tr>') - 1, 'tres sesiones suyas (más la cabecera de la tabla)');

    $r = profilePost($web, '/sessions/close');
    same(303, $r->status);
    contains('Se han cerrado 2 sesiones más.', $web->get('/admin/profile?tab=prsessions')->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {sessions} WHERE guard = 'admin' AND user_id = ?", [$id]));
    same(302, $a->get('/admin')->status);
    same(302, $b->get('/admin')->status);
    same(200, $web->get('/admin')->status, 'quien las cierra sigue dentro');
    same(200, $stranger->get('/admin')->status, 'las sesiones de otra persona no se tocan');
});
