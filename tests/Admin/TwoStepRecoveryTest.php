<?php
/**
 * AIFEC — el segundo paso no deja a nadie fuera: códigos de recuperación, quitárselo a otra persona y la orden de consola.
 *
 * Fallo que cubre: activar la verificación en dos pasos sin ninguna salida. Quien perdía el móvil se quedaba fuera del panel,
 * sin códigos de recuperación y sin nadie que pudiera quitárselo (lo encontró quien escribía el manual, 13/11/2026).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Security\Crypto;
use Aifec\Security\RecoveryCodes;
use Aifec\Security\Totp;

/** Envía un formulario de «Mi cuenta» con la ficha de la última página abierta. */
function tsrPost(TestClient $web, string $path, array $data = []): Aifec\Kernel\Response
{
    $web->get('/admin/profile');

    return $web->post('/admin/profile' . $path, ['_token' => aifecToken($web)] + $data);
}

/**
 * Enciende el segundo paso desde el panel, como lo haría la persona.
 *
 * @return array{0:string,1:string[],2:Aifec\Kernel\Response} la clave de la aplicación, los códigos de recuperación y la respuesta
 */
function tsrEnable(TestClient $web, App $app): array
{
    tsrPost($web, '/two-step/start');
    $page = $web->get('/admin/profile?tab=prtwostep');
    check(preg_match('/<code class="af-code"[^>]*>([A-Z2-7 ]+)<\/code>/', $page->body, $m) === 1, 'la clave se enseña');
    $secret = str_replace(' ', '', $m[1]);
    $r = tsrPost($web, '/two-step/confirm', ['code' => Totp::code($secret, $app->clock()->now())]);
    preg_match_all('/<code class="af-code">([a-z2-9]{4}-[a-z2-9]{4})<\/code>/', $r->body, $c);

    return [$secret, $c[1], $r];
}

/** Una persona con el segundo paso ya puesto (por la base de datos) y diez códigos de recuperación. */
function tsrPerson(App $app, string $email = 'bea@tienda.test', string $role = 'editor'): array
{
    $id = aifecAdmin($app, $role, $email, 'Bea');
    $secret = Totp::generateSecret();
    $app->db()->update('users', ['totp_secret' => (new Crypto($app->site()->secret()))->encrypt($secret)], ['id' => $id]);
    $codes = (new RecoveryCodes($app->db(), $app->site()->secret()))->issue($id, $app->clock()->sql());

    return [$id, $secret, $codes];
}

/** Entra con contraseña y devuelve el navegador, parado en el paso del código. */
function tsrAtCodeStep(App $app, string $email = 'bea@tienda.test'): TestClient
{
    $web = new TestClient($app);
    $r = aifecLogin($web, $email);
    contains('/admin/login/code', (string) $r->headers['Location']);

    return $web;
}

/** Todo lo que hay guardado en las tablas donde un código podría colarse, como un texto. */
function tsrDump(App $app): string
{
    $dump = '';
    foreach (['sessions', 'audit', 'login_attempts', 'tokens', 'jobs', 'users', 'mail_messages', 'recovery_codes'] as $table) {
        $dump .= json_encode($app->db()->select('SELECT * FROM {' . $table . '}')) . "\n";
    }

    return $dump;
}

test('activar el segundo paso enseña diez códigos de recuperación, con «Copiar» y «Descargar .txt», y ya no se vuelven a ver', function (): void {
    $app = testApp();
    $web = adminClient($app);
    [$secret, $codes, $r] = tsrEnable($web, $app);
    same(200, $r->status, 'se enseñan en la propia respuesta, sin redirigir');
    same(10, count($codes));
    same(10, count(array_unique($codes)));
    contains('No se volverán a enseñar', $r->body);
    contains('data-af-copy="', $r->body);
    contains('download="aifec-recovery-codes.txt"', $r->body);
    contains('href="data:text/plain;charset=utf-8,', $r->body);
    contains(rawurlencode($codes[0]), $r->body, 'el .txt lleva los códigos');
    contains('Encendida', $r->body);

    $again = $web->get('/admin/profile?tab=prtwostep');
    foreach ($codes as $code) {
        notContains($code, $again->body, 'una vez enseñados, no vuelven a salir');
    }
    contains('Te quedan 10 códigos de recuperación.', $again->body);
    notContains('data-af-copy=', $again->body);
    $r = $web->get('/admin/profile?tab=prtwostep');
    contains('Generar códigos nuevos', $r->body);
});

test('los códigos de recuperación no dejan rastro en claro: ni en las sesiones, ni en el registro, ni en la base de datos', function (): void {
    $app = testApp();
    $web = adminClient($app);
    [$secret, $codes] = tsrEnable($web, $app);
    same(10, count($codes));
    $dump = tsrDump($app);
    foreach ($codes as $code) {
        notContains($code, $dump, 'el código con guion');
        notContains(str_replace('-', '', $code), $dump, 'el código sin guion');
    }
    same(10, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes}'));
    notContains($secret, $dump, 'tampoco la clave de la aplicación en claro');
    $log = is_file($app->site()->path('logs') . '/app.log') ? (string) file_get_contents($app->site()->path('logs') . '/app.log') : '';
    foreach ($codes as $code) {
        notContains($code, $log);
    }
});

test('la pantalla del código ofrece «Usar un código de recuperación» y esa pantalla pide el código', function (): void {
    $app = testApp();
    [, , $codes] = tsrPerson($app);
    $web = tsrAtCodeStep($app);
    $r = $web->get('/admin/login/code');
    same(200, $r->status);
    contains('Usar un código de recuperación', $r->body);
    contains('mode=recovery', $r->body);
    notContains('name="recovery"', $r->body);

    $r = $web->get('/admin/login/code?mode=recovery');
    same(200, $r->status);
    contains('name="recovery"', $r->body);
    contains('Usar el código de la aplicación', $r->body);
    notContains('name="code"', $r->body);
    check(count($codes) === 10);
});

test('entrar con un código de recuperación vale una vez y la segunda no', function (): void {
    $app = testApp();
    [$id, , $codes] = tsrPerson($app);
    $web = tsrAtCodeStep($app);
    same(302, $web->get('/admin')->status, 'todavía fuera');
    $r = $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => strtoupper($codes[0])]);
    same(302, $r->status);
    same(200, $web->get('/admin')->status, 'dentro');
    same(9, (new RecoveryCodes($app->db(), $app->site()->secret()))->remaining($id));

    $second = tsrAtCodeStep($app);
    $r = $second->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[0]]);
    same(422, $r->status, 'el mismo código, ya gastado');
    contains('ya se usó', $r->body);
    same(302, $second->get('/admin')->status, 'sigue fuera');
    $r = $second->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[1]]);
    same(302, $r->status, 'otro código, sí');
});

test('un código de recuperación falso cuenta como intento y bloquea igual que el del móvil: cinco y a esperar', function (): void {
    $app = testApp();
    [, , $codes] = tsrPerson($app);
    $web = tsrAtCodeStep($app);
    for ($i = 0; $i < 5; $i++) {
        same(422, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => 'zzzz-zzzz'])->status);
    }
    same(5, (int) $app->db()->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin2' AND ok = 0"));
    $r = $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[0]]);
    same(429, $r->status, 'ni con un código bueno');
    contains('Demasiados intentos', $r->body);
    same(302, $web->get('/admin')->status);
    $app->clock()->advance(901);
    same(302, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[0]])->status, 'pasado el plazo, entra');
});

test('los fallos del código del móvil y los de recuperación se suman: son los mismos intentos', function (): void {
    $app = testApp();
    [, $secret, $codes] = tsrPerson($app);
    $web = tsrAtCodeStep($app);
    $wrong = Totp::code($secret, $app->clock()->now()) === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 3; $i++) {
        same(422, $web->post('/admin/login/code', ['code' => $wrong])->status);
    }
    same(422, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => 'zzzz-zzzz'])->status);
    same(422, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => 'mal'])->status, 'algo que ni parece un código también cuenta');
    same(429, $web->post('/admin/login/code', ['code' => Totp::code($secret, $app->clock()->now())])->status, 'con el del móvil bueno, ya bloqueado');
    same(429, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[0]])->status);
});

test('entrar con un código de recuperación queda en el registro sin el código, avisa de los que quedan y suelta los intentos fallidos', function (): void {
    $app = testApp();
    [$id, , $codes] = tsrPerson($app);
    $web = tsrAtCodeStep($app);
    $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => 'zzzz-zzzz']);
    $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[4]]);
    $row = $app->db()->row("SELECT * FROM {audit} WHERE action = 'login.recovery_code'");
    check($row !== null, 'queda apuntado');
    same((string) $id, (string) $row['entity_id']);
    same('left: 9', $row['summary']);
    foreach ($codes as $code) {
        notContains($code, tsrDump($app));
    }
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin2' AND ok = 0"), 'tras entrar bien, los fallos dejan de contar');
    contains('Has entrado con un código de recuperación', $web->get('/admin')->body);
});

test('con pocos códigos de recuperación, entrar avisa de que quedan pocos', function (): void {
    $app = testApp();
    [$id, , $codes] = tsrPerson($app);
    $service = new RecoveryCodes($app->db(), $app->site()->secret());
    for ($i = 1; $i <= 8; $i++) {
        $service->consume($id, $codes[$i], $app->clock()->sql());
    }
    $web = tsrAtCodeStep($app);
    same(302, $web->post('/admin/login/code', ['mode' => 'recovery', 'recovery' => $codes[0]])->status);
    $page = $web->get('/admin');
    contains('Queda 1: genera códigos nuevos en Mi cuenta', $page->body);
    contains('af-alert-warn', $page->body, 'con uno o dos, el aviso es de los que llaman la atención');
    same(1, $service->remaining($id), 'queda el décimo sin gastar');
});

test('generar códigos nuevos pide la contraseña, invalida los anteriores y enseña diez distintos', function (): void {
    $app = testApp();
    $web = adminClient($app);
    [, $old] = tsrEnable($web, $app);
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");

    $r = tsrPost($web, '/two-step/codes', ['current_password' => 'no-es-esta']);
    same(422, $r->status);
    contains('Esa no es tu contraseña actual.', $r->body);
    same(10, (new RecoveryCodes($app->db(), $app->site()->secret()))->remaining($id));
    $same = $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$id]);
    same(10, (int) $same, 'con la contraseña mala no cambia nada');

    $r = tsrPost($web, '/two-step/codes', ['current_password' => 'clave-larga-1234']);
    same(200, $r->status);
    preg_match_all('/<code class="af-code">([a-z2-9]{4}-[a-z2-9]{4})<\/code>/', $r->body, $c);
    same(10, count($c[1]));
    same([], array_values(array_intersect($old, $c[1])), 'son otros');
    contains('Códigos de recuperación nuevos generados', $r->body);
    $service = new RecoveryCodes($app->db(), $app->site()->secret());
    check(!$service->consume($id, $old[0], $app->clock()->sql()), 'uno viejo ya no vale');
    check($service->consume($id, $c[1][0], $app->clock()->sql()), 'uno nuevo sí');
    check($app->db()->row("SELECT id FROM {audit} WHERE action = 'profile.recovery_codes'") !== null, 'queda en el registro');
});

test('sin el segundo paso encendido no se generan códigos de recuperación', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $r = tsrPost($web, '/two-step/codes', ['current_password' => 'clave-larga-1234']);
    same(303, $r->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes}'));
});

test('apagar el segundo paso borra sus códigos de recuperación, y volver a encenderlo da otros', function (): void {
    $app = testApp();
    $web = adminClient($app);
    [, $first] = tsrEnable($web, $app);
    same(10, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes}'));
    same(303, tsrPost($web, '/two-step/disable', ['current_password' => 'clave-larga-1234'])->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes}'), 'no quedan códigos de una verificación que ya no existe');
    [, $second] = tsrEnable($web, $app);
    same(10, count($second));
    same([], array_values(array_intersect($first, $second)));
});

test('«Mi cuenta» cuenta los códigos que quedan', function (): void {
    $app = testApp();
    $web = adminClient($app);
    [, $codes] = tsrEnable($web, $app);
    $id = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    $service = new RecoveryCodes($app->db(), $app->site()->secret());
    $service->consume($id, $codes[0], $app->clock()->sql());
    contains('Te quedan 9 códigos de recuperación.', $web->get('/admin/profile?tab=prtwostep')->body);
    for ($i = 1; $i < 9; $i++) {
        $service->consume($id, $codes[$i], $app->clock()->sql());
    }
    contains('Te queda 1 código de recuperación.', $web->get('/admin/profile?tab=prtwostep')->body);
});

test('quitarle el segundo paso a otra persona: lo apaga, borra sus códigos, cierra sus sesiones, lo apunta y después entra con la contraseña sola', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $adminId = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    $beaId = aifecAdmin($app, 'editor', 'bea@tienda.test', 'Bea');
    $bea = new TestClient($app);
    aifecLogin($bea, 'bea@tienda.test');
    same(200, $bea->get('/admin')->status, 'Bea tiene una sesión abierta');
    $app->db()->update('users', ['totp_secret' => (new Crypto($app->site()->secret()))->encrypt(Totp::generateSecret())], ['id' => $beaId]);
    (new RecoveryCodes($app->db(), $app->site()->secret()))->issue($beaId, $app->clock()->sql());

    $web->get('/admin/r/users/' . $beaId);
    $r = $web->post('/admin/team/' . $beaId . '/two-step/remove', ['_token' => aifecToken($web)]);
    same(303, $r->status);
    contains('/admin/r/users/' . $beaId, (string) $r->headers['Location']);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$beaId]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$beaId]));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {sessions} WHERE guard = 'admin' AND user_id = ?", [$beaId]));
    same(302, $bea->get('/admin')->status, 'su sesión se cerró');
    contains('Verificación en dos pasos apagada para Bea', $web->follow()->body);

    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'team.two_step_removed'");
    check($audit !== null, 'queda en el registro de actividad');
    same($adminId, (int) $audit['user_id'], 'quién');
    same('Admin', $audit['user_name']);
    same((string) $beaId, (string) $audit['entity_id'], 'a quién');
    contains('bea@tienda.test', (string) $audit['summary']);
    contains('admin@tienda.test', (string) $audit['summary']);

    $again = new TestClient($app);
    $r = aifecLogin($again, 'bea@tienda.test');
    notContains('/admin/login/code', (string) $r->headers['Location'], 'ya no se le pide código');
    same(200, $again->get('/admin')->status);
});

test('en la ficha de una persona, el botón de quitarle el segundo paso sale solo si lo tiene y no es una misma', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $adminId = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    [$beaId] = tsrPerson($app);
    $caId = aifecAdmin($app, 'editor', 'carla@tienda.test', 'Carla');
    $app->db()->update('users', ['totp_secret' => (new Crypto($app->site()->secret()))->encrypt(Totp::generateSecret())], ['id' => $adminId]);

    $r = $web->get('/admin/r/users/' . $beaId);
    same(200, $r->status);
    contains('formaction="/admin/team/' . $beaId . '/two-step/remove"', $r->body);
    contains('data-af-confirm="', $r->body);
    contains('¿Apagar la verificación en dos pasos de esta persona?', $r->body);
    $r = $web->get('/admin/r/users/' . $caId);
    notContains('/two-step/remove', $r->body, 'Carla no lo tiene encendido');
    contains('Apagada', $r->body);
    $r = $web->get('/admin/r/users/' . $adminId);
    notContains('/two-step/remove', $r->body, 'a una misma no');
    contains('La tuya se gestiona en Mi cuenta', $r->body);
});

test('quitar el segundo paso a otra persona pide el permiso de gestionar el equipo', function (): void {
    $app = testApp();
    [$beaId, $secret] = tsrPerson($app);
    $app->db()->insert('roles', ['code' => 'lector', 'name' => 'Lector del equipo', 'caps' => json_encode(['team.view']), 'system' => 0]);
    foreach (['manager', 'support', 'lector'] as $role) {
        $web = adminClient($app, $role);
        $web->get('/admin/profile'); // «Mi cuenta» no pide ningún permiso: de ahí sale una ficha válida para cualquier papel
        $token = aifecToken($web);
        $r = $web->post('/admin/team/' . $beaId . '/two-step/remove', ['_token' => $token]);
        same(403, $r->status, 'el papel «' . $role . '» no puede');
    }
    check((string) $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$beaId]) !== '', 'sigue puesto');
    same(10, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$beaId]));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'team.two_step_removed'"));
    check($secret !== '');
});

test('nadie se quita a sí mismo el segundo paso por el camino del equipo', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $adminId = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    $app->db()->update('users', ['totp_secret' => (new Crypto($app->site()->secret()))->encrypt(Totp::generateSecret())], ['id' => $adminId]);
    (new RecoveryCodes($app->db(), $app->site()->secret()))->issue($adminId, $app->clock()->sql());
    $web->get('/admin/r/users/' . $adminId);
    $r = $web->post('/admin/team/' . $adminId . '/two-step/remove', ['_token' => aifecToken($web)]);
    same(303, $r->status);
    check((string) $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$adminId]) !== '', 'sigue encendido');
    same(10, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$adminId]));
    contains('No puedes apagar aquí tu propia verificación', $web->follow()->body);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'team.two_step_removed'"));
});

test('quitar el segundo paso a quien no lo tiene avisa y no apunta nada; a quien no existe, 404; sin ficha, nada', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $caId = aifecAdmin($app, 'editor', 'carla@tienda.test', 'Carla');
    $web->get('/admin/r/users/' . $caId);
    $token = aifecToken($web);
    $r = $web->post('/admin/team/' . $caId . '/two-step/remove', ['_token' => $token]);
    same(303, $r->status);
    contains('no tiene la verificación en dos pasos encendida', $web->follow()->body);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'team.two_step_removed'"));
    same(404, $web->post('/admin/team/99999/two-step/remove', ['_token' => $token])->status);

    [$beaId] = tsrPerson($app);
    $web->get('/admin');
    $r = $web->post('/admin/team/' . $beaId . '/two-step/remove', []);
    same(302, $r->status, 'sin ficha, a la pantalla de antes');
    check((string) $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$beaId]) !== '', 'sin ficha no se quita nada');
});

test('consola: user:2fa-reset quita el segundo paso, los códigos y las sesiones, lo dice y lo apunta', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require \Aifec\Autoload::root() . '/core/cli.php';
    [$beaId] = tsrPerson($app);
    $bea = new TestClient($app);
    $app->db()->insert('sessions', ['id' => str_repeat('a', 64), 'guard' => 'admin', 'user_id' => $beaId, 'last_at' => $app->clock()->sql(), 'expires_at' => $app->clock()->sql($app->clock()->now() + 3600)]);

    $io = new \Aifec\Cli\Console(false);
    same(0, $commands['user:2fa-reset']['run']($app, ['BEA@tienda.test'], $io));
    contains('Two-step verification turned off for bea@tienda.test', $io->output);
    contains('10 recovery codes deleted', $io->output);
    contains('1 sessions closed', $io->output);
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$beaId]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ?', [$beaId]));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {sessions} WHERE user_id = ?", [$beaId]));
    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'team.two_step_removed'");
    check($audit !== null && $audit['user_name'] === 'console' && strpos((string) $audit['summary'], 'bea@tienda.test') !== false, 'queda apuntado desde la consola');
    $r = aifecLogin($bea, 'bea@tienda.test');
    notContains('/admin/login/code', (string) $r->headers['Location'], 'entra con la contraseña sola');

    $io = new \Aifec\Cli\Console(false);
    same(0, $commands['user:2fa-reset']['run']($app, ['bea@tienda.test'], $io), 'repetirla no es un error');
    contains('was already off', $io->output);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'team.two_step_removed'"), 'y no apunta una segunda vez');
});

test('consola: user:2fa-reset de una persona que no existe sale con 1 y de un correo vacío también', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require \Aifec\Autoload::root() . '/core/cli.php';
    $io = new \Aifec\Cli\Console(false);
    same(1, $commands['user:2fa-reset']['run']($app, ['nadie@tienda.test'], $io));
    contains('No such user', $io->output);
    same(1, $commands['user:2fa-reset']['run']($app, [], new \Aifec\Cli\Console(false)));
    check(isset($commands['user:2fa-reset']['help']) && $commands['user:2fa-reset']['help'] !== '', 'tiene su ayuda');
});
