<?php
/**
 * AIFEC — pruebas del panel: acceso, permisos, fichas de formulario, equipo, papeles, ajustes y registro de actividad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Security\Crypto;
use Aifec\Security\Passwords;
use Aifec\Security\Totp;

test('sin sesión, el panel manda a la entrada y recuerda adónde se iba', function (): void {
    $web = new TestClient(testApp());
    $r = $web->get('/admin/r/users?q=ana');
    same(302, $r->status);
    contains('/admin/login?next=', $r->headers['Location']);
    contains(rawurlencode('/admin/r/users?q=ana'), $r->headers['Location']);
    $r = $web->follow();
    same(200, $r->status);
    contains('name="password"', $r->body);
    contains('noindex', $r->headers['X-Robots-Tag']);
    same(401, $web->send('GET', '/admin/palette.json', [], ['accept' => 'application/json'])->status, 'a quien pide JSON se le contesta 401, no una redirección');
});

test('entrar: contraseña mala no entra; buena entra, vuelve adonde iba y queda apuntado', function (): void {
    $app = testApp();
    $id = aifecAdmin($app);
    $web = new TestClient($app);
    $r = $web->post('/admin/login', ['email' => 'ana@tienda.test', 'password' => 'otra-cosa-9999']);
    same(422, $r->status);
    contains('af-alert-bad', $r->body);
    same(302, $web->get('/admin')->status, 'sigue fuera');

    $r = $web->post('/admin/login', ['email' => 'ANA@tienda.test ', 'password' => 'clave-larga-1234', 'next' => '/admin/settings']);
    same(302, $r->status);
    same('/admin/settings', $r->headers['Location']);
    $r = $web->get('/admin');
    same(200, $r->status);
    contains('Ana Ruiz', $r->body);
    contains('Inicio', $r->body, 'el panel sale en el idioma de la persona');
    same('no-store, private', $r->headers['Cache-Control']);
    check($app->db()->value('SELECT last_login_at FROM {users} WHERE id = ?', [$id]) !== null);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'login' AND entity_id = ?", [(string) $id]));

    $r = $web->post('/admin/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234', 'next' => 'https://malo.example/admin']);
    same('/admin', $r->headers['Location'], 'nunca se redirige fuera del panel');
});

test('entrar: cinco fallos seguidos bloquean la cuenta un cuarto de hora', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    for ($i = 0; $i < 5; $i++) {
        same(422, $web->post('/admin/login', ['email' => 'ana@tienda.test', 'password' => 'mala-' . $i])->status);
    }
    same(429, aifecLogin($web)->status, 'ni con la contraseña buena');
    $app->clock()->advance(901);
    same(302, aifecLogin($web)->status, 'pasado el plazo, entra');
});

test('un envío sin ficha o con ficha ajena no hace nada', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $web->get('/admin/settings/core');
    $token = aifecToken($web);

    $r = $web->post('/admin/settings/core', ['f' => ['store.name' => 'Pirata']]);
    same(302, $r->status);
    same('AIFEC', $app->settings()->str('store.name'), 'sin ficha no se guarda');
    contains('caducado', $web->follow()->body);

    $r = $web->post('/admin/settings/core', ['_token' => $token, 'f' => ['store.name' => 'Pirata', 'shop.currency' => 'EUR', 'shop.currency_symbol' => '€']], ['sec-fetch-site' => 'cross-site']);
    same(302, $r->status);
    same('AIFEC', $app->settings()->str('store.name'), 'con ficha buena pero enviado desde otra web, tampoco');

    $r = $web->send('POST', '/admin/settings/core', ['f' => ['store.name' => 'Pirata']], ['accept' => 'application/json']);
    same(419, $r->status);
});

test('ajustes: un formulario con pestañas que guarda, valida y vuelve a su pestaña', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $r = $web->get('/admin/settings');
    same(200, $r->status);
    contains('/admin/settings/core', $r->body);
    $r = $web->get('/admin/settings/core');
    same(200, $r->status);
    contains('data-af-tab="coidentity"', $r->body);
    contains('data-af-panel="coregion"', $r->body);
    contains('name="f[store.name]"', $r->body);
    same(1, substr_count($r->body, 'type="submit" class="af-btn af-btn-primary"'), 'un solo botón de guardar');
    $token = aifecToken($web);

    $good = ['store.name' => 'Sillas & Mesas', 'store.email' => 'hola@tienda.test', 'i18n.languages' => 'es, en', 'shop.currency' => 'EUR', 'shop.currency_symbol' => '€', 'urls.trailing_slash' => '1', 'cache.pages' => '0', 'api.rate_per_minute' => '60'];
    $r = $web->post('/admin/settings/core', ['_token' => $token, '_tab' => 'coregion', 'f' => $good]);
    same(302, $r->status);
    same('/admin/settings/core#tab=coregion', $r->headers['Location']);
    $app->settings()->reload();
    same('Sillas & Mesas', $app->settings()->str('store.name'), 'el «&» se guarda tal cual');
    same(['es', 'en'], $app->settings()->arr('i18n.languages'));
    same(true, $app->settings()->bool('urls.trailing_slash'));
    same(false, $app->settings()->bool('cache.pages'), 'un interruptor apagado llega como 0 y se guarda apagado');
    same(60, $app->settings()->int('api.rate_per_minute'));
    $r = $web->follow();
    contains('Ajustes guardados', $r->body);
    contains('value="Sillas &amp; Mesas"', $r->body, 'y vuelve a pintarse bien escapado');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'settings'"));

    $token = aifecToken($web);
    $r = $web->post('/admin/settings/core', ['_token' => $token, 'f' => ['store.name' => '', 'store.email' => 'no-es-correo', 'shop.currency' => 'XXX', 'shop.currency_symbol' => '€']]);
    same(422, $r->status);
    contains('Este campo es obligatorio', $r->body);
    contains('Escribe un correo electrónico válido', $r->body);
    contains('Elige una de las opciones', $r->body);
    same('Sillas & Mesas', $app->settings()->str('store.name'), 'con errores no se guarda nada');
});

test('equipo: alta con contraseña cifrada, correo único y reglas que protegen el acceso', function (): void {
    $app = testApp();
    $me = aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $r = $web->get('/admin/r/users');
    same(200, $r->status);
    contains('ana@tienda.test', $r->body);
    contains('/admin/r/users/new', $r->body);
    $web->get('/admin/r/users/new');
    $token = aifecToken($web);

    $r = $web->post('/admin/r/users/save', ['_token' => $token, 'f' => ['name' => 'Luis', 'email' => 'luis@tienda.test', 'role' => 'editor', 'locale' => 'es', 'password' => '', 'active' => '1']]);
    same(422, $r->status, 'en un alta la contraseña es obligatoria');
    $r = $web->post('/admin/r/users/save', ['_token' => $token, 'f' => ['name' => 'Luis', 'email' => 'luis@tienda.test', 'role' => 'editor', 'locale' => 'es', 'password' => 'corta', 'active' => '1']]);
    same(422, $r->status);
    $r = $web->post('/admin/r/users/save', ['_token' => $token, 'f' => ['name' => 'Luis', 'email' => 'luis@tienda.test', 'role' => 'editor', 'locale' => 'es', 'password' => 'una-clave-buena', 'active' => '1']]);
    same(302, $r->status);
    $luis = $app->db()->row('SELECT * FROM {users} WHERE email = ?', ['luis@tienda.test']);
    check($luis !== null && $luis['password'] !== 'una-clave-buena' && Passwords::verify('una-clave-buena', (string) $luis['password']), 'la contraseña se guarda cifrada');
    same('/admin/r/users/' . $luis['id'], $r->headers['Location']);
    contains('Creado', $web->follow()->body);
    notContains((string) $luis['password'], $web->last->body, 'el resumen de la contraseña nunca vuelve a la pantalla');

    $r = $web->post('/admin/r/users/save', ['_token' => $token, 'f' => ['name' => 'Otro', 'email' => 'luis@tienda.test', 'role' => 'editor', 'locale' => 'es', 'password' => 'una-clave-buena', 'active' => '1']]);
    same(422, $r->status);
    contains('Este valor ya está en uso', $r->body);

    // Editar sin tocar la contraseña la conserva, y el mismo correo no choca consigo mismo.
    $r = $web->post('/admin/r/users/' . $luis['id'] . '/save', ['_token' => $token, '_then' => 'list', 'f' => ['name' => 'Luis Gil', 'email' => 'luis@tienda.test', 'role' => 'support', 'locale' => 'es', 'password' => '', 'active' => '1']]);
    same(302, $r->status);
    same('/admin/r/users', $r->headers['Location']);
    $after = $app->db()->row('SELECT * FROM {users} WHERE id = ?', [(int) $luis['id']]);
    same('Luis Gil', $after['name']);
    same('support', $after['role']);
    same($luis['password'], $after['password']);
    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'update' AND entity = 'users' ORDER BY id DESC");
    contains('"role":["editor","support"]', (string) $audit['changes']);
    notContains('password', (string) $audit['changes']);

    // No puedo quitarme el acceso ni borrarme, ni dejar la tienda sin administradores.
    $r = $web->post('/admin/r/users/' . $me . '/save', ['_token' => $token, 'f' => ['name' => 'Ana Ruiz', 'email' => 'ana@tienda.test', 'role' => 'editor', 'locale' => 'es', 'active' => '1']]);
    same(422, $r->status);
    same('admin', $app->db()->value('SELECT role FROM {users} WHERE id = ?', [$me]));
    $r = $web->post('/admin/r/users/' . $me . '/delete', ['_token' => $token]);
    same(302, $r->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {users} WHERE id = ?', [$me]), 'sigo existiendo');
    $r = $web->post('/admin/r/users/bulk', ['_token' => $token, 'action' => 'delete', 'ids' => [$me, (int) $luis['id']]]);
    same(302, $r->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {users}'), 'en bloque se borra a Luis y a mí no');
    contains('1 borrado', $web->follow()->body);
});

test('permisos: cada papel ve lo suyo y nada más', function (): void {
    $app = testApp();
    aifecAdmin($app);
    aifecAdmin($app, 'support', 'sara@tienda.test', 'Sara');
    $web = new TestClient($app);
    aifecLogin($web, 'sara@tienda.test');
    $r = $web->get('/admin');
    same(200, $r->status);
    notContains('/admin/r/users', $r->body, 'el menú no enseña lo que no puede abrir');
    notContains('/admin/settings', $r->body);
    same(403, $web->get('/admin/r/users')->status);
    same(403, $web->get('/admin/settings/core')->status);
    same(403, $web->get('/admin/r/audit')->status);
    same(404, $web->get('/admin/r/no-existe')->status);
    $web->get('/admin');
    $token = aifecToken($web);
    same(403, $web->post('/admin/r/users/save', ['_token' => $token, 'f' => ['name' => 'X', 'email' => 'x@tienda.test', 'role' => 'admin', 'password' => 'una-clave-buena']])->status);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {users} WHERE role = 'admin'"));
});

test('papeles: los de serie no se borran, el de administrador no pierde el «todo» y no se pierden permisos ocultos', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $r = $web->get('/admin/r/roles');
    contains('Administrador', $r->body, 'los nombres sembrados en inglés se enseñan traducidos');
    contains('Responsable de tienda', $r->body);
    $token = aifecToken($web);
    $admin = $app->db()->row("SELECT * FROM {roles} WHERE code = 'admin'");
    $manager = $app->db()->row("SELECT * FROM {roles} WHERE code = 'manager'");

    $web->post('/admin/r/roles/' . $admin['id'] . '/delete', ['_token' => $token]);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {roles} WHERE code = 'admin'"));
    $r = $web->post('/admin/r/roles/' . $admin['id'] . '/save', ['_token' => $token, 'f' => ['name' => 'Jefa', 'code' => 'otro', 'caps' => ['team.view']]]);
    same(302, $r->status);
    $after = $app->db()->row('SELECT * FROM {roles} WHERE id = ?', [(int) $admin['id']]);
    same('admin', $after['code'], 'el código de un papel de serie no cambia');
    check(in_array('*', (array) json_decode((string) $after['caps'], true), true), 'el administrador conserva el «todo»');

    $r = $web->post('/admin/r/roles/' . $manager['id'] . '/save', ['_token' => $token, 'f' => ['name' => 'Store manager', 'code' => 'manager', 'caps' => ['team.view', 'settings.edit']]]);
    same(302, $r->status);
    $caps = (array) json_decode((string) $app->db()->value('SELECT caps FROM {roles} WHERE id = ?', [(int) $manager['id']]), true);
    check(in_array('team.view', $caps, true) && in_array('settings.edit', $caps, true));
    check(in_array('orders.*', $caps, true), 'los permisos de módulos que ahora no están cargados no se pierden al guardar');

    $r = $web->post('/admin/r/roles/save', ['_token' => $token, 'f' => ['name' => 'Almacén', 'code' => 'almacen', 'caps' => ['dashboard.view']]]);
    same(302, $r->status);
    $new = $app->db()->row("SELECT * FROM {roles} WHERE code = 'almacen'");
    same('["dashboard.view"]', $new['caps']);
    $web->post('/admin/r/roles/' . $new['id'] . '/delete', ['_token' => $token]);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {roles} WHERE code = 'almacen'"), 'un papel propio y sin uso sí se borra');
});

test('registro de actividad: se lista, se abre y se exporta, pero no se toca', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $r = $web->get('/admin/r/audit');
    same(200, $r->status);
    contains('login', $r->body);
    notContains('/admin/r/audit/new', $r->body);
    $id = (int) $app->db()->value('SELECT id FROM {audit} ORDER BY id DESC');
    $r = $web->get('/admin/r/audit/' . $id);
    same(200, $r->status);
    contains('ana@tienda.test', $r->body);
    $token = aifecToken($web);
    same(403, $web->post('/admin/r/audit/' . $id . '/delete', ['_token' => $token])->status);
    same(403, $web->post('/admin/r/audit/save', ['_token' => $token, 'f' => ['action' => 'x']])->status);
    $r = $web->get('/admin/r/audit/export.csv');
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment; filename="audit-', $r->headers['Content-Disposition']);
    contains('login', $r->body);
});

test('buscador de órdenes y salida', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $web = new TestClient($app);
    aifecLogin($web);
    $web->get('/admin/palette.json?q=equip');
    $items = $web->json()['items'];
    same(1, count($items));
    same('Equipo', $items[0]['label']);
    same('/admin/r/users', $items[0]['url']);
    $web->get('/admin');
    $r = $web->post('/admin/logout', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(302, $web->get('/admin')->status, 'tras salir, fuera');
});

test('segundo paso: con código activado, la contraseña sola no basta', function (): void {
    $app = testApp();
    $id = aifecAdmin($app);
    $secret = Totp::generateSecret();
    $app->db()->update('users', ['totp_secret' => (new Crypto($app->site()->secret()))->encrypt($secret)], ['id' => $id]);
    $web = new TestClient($app);
    $r = aifecLogin($web);
    same(302, $r->status);
    contains('/admin/login/code', $r->headers['Location']);
    same(302, $web->get('/admin')->status, 'todavía fuera');
    same(200, $web->get('/admin/login/code')->status);
    same(422, $web->post('/admin/login/code', ['code' => '000000'])->status);
    $r = $web->post('/admin/login/code', ['code' => Totp::code($secret, $app->clock()->now())]);
    same(302, $r->status);
    same(200, $web->get('/admin')->status);
});

test('restablecer la contraseña: enlace de un solo uso que caduca y no delata quién tiene cuenta', function (): void {
    $app = testApp();
    $id = aifecAdmin($app);
    $sent = [];
    $app->bind('mailer', function () use (&$sent) {
        return new class($sent) {
            /** @var array<int,array<string,mixed>> */
            private $sent;

            /** @param array<int,array<string,mixed>> $sent */
            public function __construct(array &$sent)
            {
                $this->sent = &$sent;
            }

            /**
             * @param array<string,mixed> $data
             * @param array<string,mixed> $options
             */
            public function queue(string $template, string $to, array $data = [], array $options = []): int
            {
                $this->sent[] = ['template' => $template, 'to' => $to, 'data' => $data];

                return count($this->sent);
            }
        };
    });
    $web = new TestClient($app);
    $r = $web->post('/admin/forgot', ['email' => 'nadie@tienda.test']);
    same(200, $r->status);
    same(0, count($sent));
    $other = $web->post('/admin/forgot', ['email' => 'ana@tienda.test']);
    same($r->body, $other->body, 'la respuesta es idéntica exista o no la cuenta');
    same(1, count($sent));
    same('admin_reset', $sent[0]['template']);
    check(preg_match('#/admin/reset/([A-Za-z0-9_-]+)$#', (string) $sent[0]['data']['url'], $m) === 1);
    $token = $m[1];
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {tokens} WHERE hash = ?', [$token]), 'en la base se guarda el resumen, no la ficha');

    same(200, $web->get('/admin/reset/' . $token)->status);
    same(410, $web->get('/admin/reset/' . str_repeat('a', 43))->status);
    same(422, $web->post('/admin/reset/' . $token, ['password' => 'corta', 'password2' => 'corta'])->status);
    same(422, $web->post('/admin/reset/' . $token, ['password' => 'nueva-clave-5678', 'password2' => 'distinta-clave-5678'])->status);
    $r = $web->post('/admin/reset/' . $token, ['password' => 'nueva-clave-5678', 'password2' => 'nueva-clave-5678']);
    same(302, $r->status);
    check(Passwords::verify('nueva-clave-5678', (string) $app->db()->value('SELECT password FROM {users} WHERE id = ?', [$id])));
    same(410, $web->post('/admin/reset/' . $token, ['password' => 'otra-clave-más-9999', 'password2' => 'otra-clave-más-9999'])->status, 'la ficha no vale dos veces');
    same(302, aifecLogin($web, 'ana@tienda.test', 'nueva-clave-5678')->status);

    $web->post('/admin/forgot', ['email' => 'ana@tienda.test']);
    preg_match('#/admin/reset/([A-Za-z0-9_-]+)$#', (string) $sent[1]['data']['url'], $m);
    $app->clock()->advance(3601);
    same(410, $web->get('/admin/reset/' . $m[1])->status, 'pasada una hora ya no vale');
});

test('consola: alta de una persona y cambio de contraseña', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require \Aifec\Autoload::root() . '/core/cli.php';
    $io = new \Aifec\Cli\Console(false);
    same(0, $commands['user:create']['run']($app, ['eva@tienda.test', 'Eva', 'password' => 'clave-de-eva-123'], $io));
    $eva = $app->db()->row('SELECT * FROM {users} WHERE email = ?', ['eva@tienda.test']);
    check($eva !== null && $eva['role'] === 'admin' && Passwords::verify('clave-de-eva-123', (string) $eva['password']));
    same(1, $commands['user:create']['run']($app, ['eva@tienda.test', 'Eva'], $io), 'un correo repetido se rechaza');
    same(2, $commands['user:create']['run']($app, ['eva2@tienda.test', 'Eva', 'role' => 'no-existe'], $io));
    $io = new \Aifec\Cli\Console(false);
    same(0, $commands['user:password']['run']($app, ['eva@tienda.test'], $io));
    check(preg_match('/password: (\S{16})/', $io->output, $m) === 1, 'sin --password se genera una y se enseña');
    check(Passwords::verify($m[1], (string) $app->db()->value('SELECT password FROM {users} WHERE email = ?', ['eva@tienda.test'])));
});
