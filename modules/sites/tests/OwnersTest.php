<?php
/**
 * AIFEC — pruebas de los dueños de la instalación: los administradores del sitio maestro entran en el panel de cada
 * demostración con su misma cuenta y con todos los permisos; la cuenta «demo» sigue recortada; un sitio que no es una
 * demostración no se toca; quien deja de ser administrador pierde el acceso; el segundo paso viaja cifrado con la clave de
 * la demostración.
 *
 * Nace de un fallo (13/11/2026): el dueño entraba en el panel de una demostración con la cuenta «demo» (la única que había),
 * le faltaban menús y había pantallas que contestaban «sin permiso».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Sites\Owners;
use Aifec\Modules\Sites\SitesTasks;
use Aifec\Security\Crypto;
use Aifec\Security\Passwords;

require_once __DIR__ . '/helpers.php';

/**
 * Las personas del panel de un sitio, por correo.
 *
 * @param array<string,mixed> $world
 * @return array<string,array<string,mixed>>
 */
function aifecOwnersUsers(array $world, string $id): array
{
    $app = aifecSitesDemoApp($world, $id);
    $out = [];
    foreach ($app->db()->select('SELECT email, name, password, role, active, totp_secret FROM {users} ORDER BY id') as $row) {
        $out[(string) $row['email']] = $row;
    }
    $world['master']->makeCurrent();

    return $out;
}

test('al crear una demostración, el administrador del maestro queda dentro como administrador, con su misma contraseña', function (): void {
    $w = aifecSitesFresh();
    $master = $w['master']->db()->row("SELECT email, password FROM {users} WHERE role = 'admin' ORDER BY id");
    check($master !== null, 'el maestro tiene su administrador');
    $created = $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);
    same(true, $created['ok']);

    $users = aifecOwnersUsers($w, 'demo-moda');
    check(isset($users[(string) $master['email']]), 'la cuenta del dueño está en la demostración');
    $owner = $users[(string) $master['email']];
    same('admin', (string) $owner['role']);
    same(1, (int) $owner['active']);
    same((string) $master['password'], (string) $owner['password'], 'el mismo resumen de contraseña: entra con la suya');
    check(isset($users['demo@tienda.test']) && (string) $users['demo@tienda.test']['role'] === 'demo', 'la cuenta «demo» sigue, con su papel recortado');

    // Entra de verdad, y ve lo que la cuenta «demo» no puede ver.
    $demo = aifecSitesDemoApp($w, 'demo-moda');
    $caps = json_decode((string) $demo->db()->value("SELECT caps FROM {roles} WHERE code = 'admin'"), true);
    same(['*'], $caps, 'el papel de administrador de la demostración lo permite todo');
    $demoCaps = json_decode((string) $demo->db()->value("SELECT caps FROM {roles} WHERE code = 'demo'"), true);
    check(!in_array('*', (array) $demoCaps, true) && !in_array('settings.edit', (array) $demoCaps, true), 'y el papel «demo» sigue recortado');
    $w['master']->makeCurrent();
});

test('el dueño entra en el panel de la demostración con su correo y su contraseña y abre una pantalla vetada a «demo»', function (): void {
    $w = aifecSitesFresh();
    $w['master']->db()->update('users', ['password' => Passwords::hash('clave-del-dueno-1')], ['role' => 'admin']);
    $email = (string) $w['master']->db()->value("SELECT email FROM {users} WHERE role = 'admin' ORDER BY id");
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);

    $demo = aifecSitesDemoApp($w, 'demo-moda');
    $web = new TestClient($demo);
    aifecLogin($web, $email, 'clave-del-dueno-1');
    same(200, $web->get('/admin')->status, 'está dentro');
    same(200, $web->get('/admin/r/users')->status, 'el equipo, que «demo» no puede abrir');
    same(200, $web->get('/admin/r/roles')->status, 'y los papeles');

    $guest = new TestClient($demo);
    aifecLogin($guest, 'demo@tienda.test', 'demo-aifec-2026');
    same(200, $guest->get('/admin')->status, 'la cuenta «demo» entra');
    same(403, $guest->get('/admin/r/users')->status, 'pero sigue sin poder abrir el equipo');
    same(403, $guest->get('/admin/r/roles')->status, 'ni los papeles');
    $w['master']->makeCurrent();
});

test('cambiar la contraseña en el maestro llega a las demostraciones al ponerlas al día, y quien deja de ser administrador pierde el acceso', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);
    $db = $w['master']->db();
    $first = (string) $db->value("SELECT email FROM {users} WHERE role = 'admin' ORDER BY id");
    $now = $w['master']->clock()->sql();
    $db->insert('users', ['email' => 'segunda@tienda.test', 'name' => 'Segunda', 'password' => Passwords::hash('otra-clave-22'), 'role' => 'admin', 'locale' => 'es', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $db->update('users', ['password' => Passwords::hash('clave-nueva-33')], ['email' => $first]);

    $result = (new Owners($w['master']))->syncAll();
    same(['demo-moda'], array_keys($result));
    same(['ok' => true, 'owners' => 2, 'error' => ''], $result['demo-moda']);
    $users = aifecOwnersUsers($w, 'demo-moda');
    check(Passwords::verify('clave-nueva-33', (string) $users[$first]['password']), 'la contraseña nueva ya vale en la demostración');
    same('admin', (string) $users['segunda@tienda.test']['role']);

    // La segunda deja de ser administradora del maestro: en la demostración se desactiva su copia.
    $db->update('users', ['role' => 'editor'], ['email' => 'segunda@tienda.test']);
    (new Owners($w['master']))->syncAll();
    $users = aifecOwnersUsers($w, 'demo-moda');
    same(0, (int) $users['segunda@tienda.test']['active'], 'pierde el acceso');
    same(1, (int) $users[$first]['active']);
    same(1, (int) $users['demo@tienda.test']['active'], 'la cuenta «demo» no se toca');
});

test('una cuenta de administrador que la demostración ya tenía por su cuenta no se desactiva nunca', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);
    $demo = aifecSitesDemoApp($w, 'demo-moda');
    $now = $demo->clock()->sql();
    $demo->db()->insert('users', ['email' => 'propia@moda.test', 'name' => 'Propia', 'password' => Passwords::hash('clave-propia-44'), 'role' => 'admin', 'locale' => 'es', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $w['master']->makeCurrent();

    (new Owners($w['master']))->syncAll();
    (new Owners($w['master']))->syncAll();
    same(1, (int) aifecOwnersUsers($w, 'demo-moda')['propia@moda.test']['active'], 'no la puso este mecanismo: no la quita');
});

test('el segundo paso viaja: el secreto se vuelve a cifrar con la clave de la demostración; si no se puede leer, esa persona no se copia', function (): void {
    $w = aifecSitesFresh();
    $db = $w['master']->db();
    $email = (string) $db->value("SELECT email FROM {users} WHERE role = 'admin' ORDER BY id");
    $db->update('users', ['totp_secret' => (new Crypto($w['master']->site()->secret()))->encrypt('JBSWY3DPEHPK3PXP')], ['email' => $email]);
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);

    $users = aifecOwnersUsers($w, 'demo-moda');
    $stored = (string) $users[$email]['totp_secret'];
    check($stored !== '', 'la demostración también le pide el segundo paso');
    $demoSite = SiteResolver::load((string) $w['data'], 'demo-moda');
    same('JBSWY3DPEHPK3PXP', (new Crypto($demoSite->secret()))->decrypt($stored), 'cifrado con la clave de la demostración');
    check((new Crypto($w['master']->site()->secret()))->decrypt($stored) !== 'JBSWY3DPEHPK3PXP' || $demoSite->secret() === $w['master']->site()->secret(), 'y no con la del maestro');

    // Un secreto que el maestro no sabe descifrar: esa persona no entra con solo contraseña.
    $now = $w['master']->clock()->sql();
    $db->insert('users', ['email' => 'rara@tienda.test', 'name' => 'Rara', 'password' => Passwords::hash('clave-rara-55'), 'role' => 'admin', 'locale' => 'es', 'totp_secret' => 'esto-no-es-un-cifrado', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    (new Owners($w['master']))->syncAll();
    check(!isset(aifecOwnersUsers($w, 'demo-moda')['rara@tienda.test']), 'no se copia');
});

test('un sitio que no es una demostración no se toca, ni el propio maestro', function (): void {
    $w = aifecSitesFresh();
    SiteFactory::create((string) $w['root'], (string) $w['data'], aifecInstOptions([
        'id' => 'tienda-real', 'path' => '/real', 'url' => 'https://tienda.test/real', 'public_dir' => (string) $w['public'], 'modules' => [],
        'admin' => ['name' => 'Cliente', 'email' => 'cliente@real.test', 'password' => 'clave-del-cliente-66'],
    ]));
    SiteResolver::forget();
    $w['master']->makeCurrent();

    same([], (new Owners($w['master']))->syncAll(), 'no hay demostraciones: nada que hacer');
    same(false, (new Owners($w['master']))->syncSite('tienda-real')['ok']);
    same(false, (new Owners($w['master']))->syncSite('main')['ok']);
    same(false, (new Owners($w['master']))->syncSite('../main')['ok']);
    same(['cliente@real.test'], array_keys(aifecOwnersUsers($w, 'tienda-real')), 'la tienda del cliente sigue con su persona y nadie más');
});

test('«Abrir panel» pone al día la demostración y lleva a su panel; la orden sites:owners y la tarea de la noche, también', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda']);
    $db = $w['master']->db();
    $email = (string) $db->value("SELECT email FROM {users} WHERE role = 'admin' ORDER BY id");
    $web = adminClient($w['master']);
    contains('/admin/sites/open/demo-moda"', $web->get('/admin/sites')->body, 'el botón «Abrir panel» pasa por la puesta al día');
    contains('Entras en el panel de cada demostración con tu mismo correo', $web->get('/admin/sites')->body);

    $db->update('users', ['password' => Passwords::hash('clave-por-boton-77')], ['email' => $email]);
    $r = $web->get('/admin/sites/open/demo-moda');
    same(302, $r->status);
    same('https://tienda.test/demos/moda/admin', (string) $r->headers['Location']);
    check(Passwords::verify('clave-por-boton-77', (string) aifecOwnersUsers($w, 'demo-moda')[$email]['password']), 'la contraseña recién cambiada ya vale allí');
    same(302, $web->get('/admin/sites/open/no-existe')->status);
    contains('Ese sitio no existe', $web->follow()->body);
    aifecSitesRelease($web);

    $db->update('users', ['password' => Passwords::hash('clave-por-consola-88')], ['email' => $email]);
    $io = new Console(false);
    $commands = $io->commands($w['master']);
    check(isset($commands['sites:owners']) && $commands['sites:owners']['help'] !== '', 'la orden existe, con su ayuda');
    same(0, $commands['sites:owners']['run']($w['master'], [], $io), $io->output);
    contains('OWNERS: 1 demo(s), 0 failed', $io->output);
    check(Passwords::verify('clave-por-consola-88', (string) aifecOwnersUsers($w, 'demo-moda')[$email]['password']), 'por consola');

    $db->update('users', ['password' => Passwords::hash('clave-de-noche-99')], ['email' => $email]);
    $w['master']->settings()->set('sites.reset_nightly', false);
    SitesTasks::resetDemos($w['master']);
    check(Passwords::verify('clave-de-noche-99', (string) aifecOwnersUsers($w, 'demo-moda')[$email]['password']), 'la tarea de la noche, aunque no restablezca');
});
