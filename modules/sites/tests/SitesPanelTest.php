<?php
/**
 * AIFEC — pruebas del panel maestro «Sites and demos»: la tabla, crear y restablecer en segundo plano, borrar, los permisos,
 * el interruptor de la noche, las órdenes de consola y que en un sitio que no es el maestro no exista nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Http\FakeHttpClient;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Sites\Demos;

require_once __DIR__ . '/helpers.php';

/** Los identificadores de los sitios de una instalación, ordenados. */
function aifecSitesIds(array $world): array
{
    $ids = array_column(SiteResolver::all((string) $world['data']), 'id');
    sort($ids);

    return $ids;
}

test('el panel maestro lista los sitios con su tipo, su paquete, su estado y los enlaces para abrir la tienda y su panel', function (): void {
    $w = aifecSitesWorld();
    $web = adminClient($w['master']);
    $r = $web->get('/admin/sites');
    same(200, $r->status);
    contains('Sitios y demostraciones', $r->body);
    contains('demo-minimo', $r->body);
    contains('/demos/minimo', $r->body);
    contains('href="https://tienda.test/demos/minimo/"', $r->body, 'enlace para abrir la tienda');
    contains('/admin/sites/open/demo-minimo"', $r->body, 'enlace para abrir su panel (pasa por la puesta al día de los dueños)');
    contains('Maestro', $r->body);
    contains('Pedidos de hoy', $r->body);
    contains('Último restablecimiento', $r->body);
    contains('data-af-confirm', $r->body, 'restablecer y borrar piden confirmación');
    contains('Taller de ejemplo', $r->body);
    check(substr_count($r->body, '/admin/sites/reset/demo-minimo') === 1 && substr_count($r->body, '/admin/sites/delete/demo-minimo') === 1, 'una acción de cada por demostración');
    check(strpos($r->body, '/admin/sites/reset/main') === false && strpos($r->body, '/admin/sites/delete/main') === false, 'el maestro no tiene botón de restablecer ni de borrar');
    $sites = $w['demos']->sites();
    $ids = array_column($sites, 'id');
    sort($ids);
    same(['demo-minimo', 'main'], $ids);
    $byId = array_column($sites, null, 'id');
    same(0, $byId['demo-minimo']['orders_today'], 'cero pedidos hoy');
    same(0, $byId['main']['orders_today']);
    same('minimo', $byId['demo-minimo']['package']);
    same(true, $byId['main']['master']);
    aifecSitesRelease($web);
});

test('crear desde el panel solo encola el trabajo: la tabla lo enseña en cola y, cuando corre, hecho', function (): void {
    $w = aifecSitesFresh();
    $web = adminClient($w['master']);
    $web->get('/admin/sites');
    $r = $web->post('/admin/sites/create', ['_token' => aifecToken($web), 'package' => 'minimo', 'slug' => 'nuevo', 'name' => 'Nueva & mía']);
    same(302, $r->status);
    contains('La demostración se está construyendo', $web->follow()->body);

    same(1, (int) $w['master']->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'sites.build' AND status = 'pending'"), 'un trabajo pendiente');
    same('queued', $w['demos']->entry('demo-nuevo')['state']);
    same(['main'], aifecSitesIds($w), 'la petición del panel no ha construido nada');
    $list = $web->get('/admin/sites');
    contains('En cola', $list->body);
    contains('demo-nuevo', $list->body);
    contains('Nueva &amp; mía', $list->body, 'el nombre con «&» sale escapado');

    $work = $w['master']->service('jobs')->work(120);
    same(1, $work['done'], 'el trabajo se ejecuta');
    same('done', $w['demos']->entry('demo-nuevo')['state']);
    same(['demo-nuevo', 'main'], aifecSitesIds($w));
    same(200, aifecSitesFront($w, '/demos/nuevo/')->status);
    contains('Lista', $web->get('/admin/sites')->body);
    contains('Nueva &amp; mía', aifecSitesFront($w, '/demos/nuevo/')->body);
    aifecSitesRelease($web);
});

test('si el trabajo en segundo plano falla, la tabla lo enseña fallido con su motivo y no queda sitio a medias', function (): void {
    $packages = aifecSitesPackages();
    $w = aifecSitesFresh($packages);
    $web = adminClient($w['master']);
    $web->get('/admin/sites');
    $web->post('/admin/sites/create', ['_token' => aifecToken($web), 'package' => 'uno', 'slug' => 'caida']);
    file_put_contents($packages . '/uno/media/silla-aldea.svg', 'no es un svg');
    $work = $w['master']->service('jobs')->work(120);
    same(1, $work['failed'], 'el trabajo acaba fallido, sin reintentos');
    same('failed', $w['demos']->entry('demo-caida')['state']);
    same(['main'], aifecSitesIds($w));
    $list = $web->get('/admin/sites');
    contains('Fallida', $list->body);
    check(strpos((string) $w['master']->db()->value("SELECT error FROM {jobs} WHERE type = 'sites.build'"), 'svg') !== false || (string) $w['master']->db()->value("SELECT error FROM {jobs} WHERE type = 'sites.build'") !== '', 'el trabajo guarda su motivo');
    aifecSitesRelease($web);
});

test('una subcarpeta mala desde el panel enseña el error y no encola nada', function (): void {
    $w = aifecSitesFresh();
    $web = adminClient($w['master']);
    $web->get('/admin/sites');
    foreach (['Mal Nombre', '', 'a/b', 'ñandú'] as $slug) {
        $web->post('/admin/sites/create', ['_token' => aifecToken($web), 'package' => 'minimo', 'slug' => $slug]);
        contains('No se ha creado la demostración', $web->follow()->body);
    }
    $web->post('/admin/sites/create', ['_token' => aifecToken($web), 'package' => 'inventado', 'slug' => 'ok']);
    contains('does not exist', $web->follow()->body);
    same(0, (int) $w['master']->db()->value('SELECT COUNT(*) FROM {jobs}'), 'ningún trabajo');
    same([], $w['demos']->registry());
    aifecSitesRelease($web);
});

test('restablecer y borrar desde el panel funcionan, el primero en segundo plano', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'panel']);
    $web = adminClient($w['master']);
    $web->get('/admin/sites');
    $r = $web->post('/admin/sites/reset/demo-panel', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('queued', $w['demos']->entry('demo-panel')['state']);
    $w['master']->clock()->advance(120);
    same(1, $w['master']->service('jobs')->work(120)['done']);
    same('done', $w['demos']->entry('demo-panel')['state']);
    same($w['master']->clock()->sql(), $w['demos']->entry('demo-panel')['last_reset_at']);

    $web->get('/admin/sites');
    $web->post('/admin/sites/reset/main', ['_token' => aifecToken($web)]);
    contains('No se ha restablecido', $web->follow()->body);
    $web->post('/admin/sites/delete/main', ['_token' => aifecToken($web)]);
    contains('No se ha borrado', $web->follow()->body);
    check(is_dir($w['data'] . '/main'), 'el maestro sigue');

    $web->post('/admin/sites/delete/demo-panel', ['_token' => aifecToken($web)]);
    contains('La demostración se ha borrado', $web->follow()->body);
    same(['main'], aifecSitesIds($w));
    same(null, $w['demos']->entry('demo-panel'));
    check((int) $w['master']->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'sites.delete'") === 1, 'queda apuntado en el registro de actividad');
    aifecSitesRelease($web);
});

test('sin el permiso sites.manage el panel da 403 y no deja crear ni borrar', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'ajena']);
    foreach (['manager', 'support', 'demo'] as $role) {
        $web = adminClient($w['master'], $role);
        same(403, $web->get('/admin/sites')->status, $role . ' no abre la pantalla');
        $web->get('/admin');
        $token = aifecToken($web);
        same(403, $web->post('/admin/sites/create', ['_token' => $token, 'package' => 'minimo', 'slug' => 'x'])->status, $role . ' no crea');
        same(403, $web->post('/admin/sites/reset/demo-ajena', ['_token' => $token])->status, $role . ' no restablece');
        same(403, $web->post('/admin/sites/delete/demo-ajena', ['_token' => $token])->status, $role . ' no borra');
        same(403, $web->post('/admin/sites/settings', ['_token' => $token, 'nightly' => '0'])->status, $role . ' no cambia la noche');
        aifecSitesRelease($web);
    }
    same(['demo-ajena', 'main'], aifecSitesIds($w), 'nada ha cambiado');
    $anon = new TestClient($w['master']);
    same(302, $anon->get('/admin/sites')->status, 'sin sesión, a la pantalla de acceso');
});

test('el interruptor del restablecimiento de la noche se guarda y se enseña', function (): void {
    $w = aifecSitesFresh();
    $web = adminClient($w['master']);
    $r = $web->get('/admin/sites');
    check(preg_match('/<input type="checkbox" name="nightly" value="1" checked>/', $r->body) === 1, 'de serie está encendido');
    $web->post('/admin/sites/settings', ['_token' => aifecToken($web), 'nightly' => '0']);
    same(false, $w['master']->settings()->bool(Demos::NIGHTLY, true));
    contains('no se restablecerán', $web->follow()->body);
    check(preg_match('/name="nightly" value="1" checked/', $web->get('/admin/sites')->body) === 0, 'ahora sale apagado');
    $web->post('/admin/sites/settings', ['_token' => aifecToken($web), 'nightly' => '1']);
    same(true, $w['master']->settings()->bool(Demos::NIGHTLY, false));
    aifecSitesRelease($web);
});

test('en un sitio que no es el maestro el módulo no registra ni el menú, ni las rutas, ni el servicio', function (): void {
    // El módulo está ENCENDIDO (no viene encendido de serie), pero el sitio no es el maestro.
    $app = testApp(['modules' => ['sites'], 'settings' => ['modules.active' => ['sites']]]);
    $app->boot();
    check($app->modules()->isLoaded('sites'), 'el módulo está cargado');
    same(false, Demos::isMaster($app));
    check(!isset($app->adminDefinitions()['sites']), 'sin definición de panel');
    same(false, $app->router()->has('admin.sites'));
    same(false, $app->router()->has('demos'));
    same(false, $app->has('sites'));
    $web = adminClient($app);
    same(404, $web->get('/admin/sites')->status);
    check(strpos($web->get('/admin')->body, '/admin/sites') === false, 'el menú no enseña la entrada');
    same(404, $web->get('/demos')->status, 'ni la página pública');
    $r = (new Demos($app))->create(['package' => 'minimo', 'slug' => 'x']);
    same(false, $r['ok']);
    contains('master site', (string) $r['error']);
    $console = new Console(false);
    foreach (['demo:create', 'demo:reset', 'demo:list'] as $command) {
        same(2, $console->commands($app)[$command]['run']($app, ['minimo', 'x'], $console), $command . ' avisa de que no es el maestro');
    }
    contains('master', $console->output);
});

test('demo:check valida un paquete sin construir nada: cuenta lo que trae, o dice cada problema, y vale fuera del maestro', function (): void {
    $packages = aifecSitesPackages();
    $w = aifecSitesFresh($packages);
    $app = $w['master'];
    $io = new Console(false);
    $commands = $io->commands($app);
    check(isset($commands['demo:check']) && $commands['demo:check']['help'] !== '', 'existe demo:check, con su ayuda');

    same(2, $commands['demo:check']['run']($app, [], $io), 'sin argumentos, uso');
    contains('Usage: demo:check', $io->output);

    $io = new Console(false);
    same(0, $commands['demo:check']['run']($app, ['uno'], $io));
    contains('Products: 6', $io->output);
    check(preg_match('/Images:\s+(\d+)/', $io->output, $m) === 1 && (int) $m[1] >= 6, 'cuenta las imágenes: ' . $io->output);
    check(preg_match('/Size:\s+\d+\.\d\d MB/', $io->output) === 1, 'dice el tamaño');
    contains('DEMO uno: 0 problems', $io->output);
    same([], glob($w['data'] . '/demo-*'), 'no ha construido ningún sitio');

    // Roto: un producto sin precio y una imagen que no está.
    aifecSitesEditJson($packages . '/dos/products.json', static function (array $products): array {
        unset($products[0]['price']);
        $products[1]['images'] = ['no-esta.jpg'];

        return $products;
    });
    $io = new Console(false);
    same(1, $commands['demo:check']['run']($app, ['dos'], $io));
    check(substr_count($io->output, 'PROBLEM: ') >= 2, 'un PROBLEM por cada cosa: ' . $io->output);
    contains('products.json', $io->output);
    contains('no-esta.jpg', $io->output);
    check(preg_match('/DEMO dos: (\d+) problems/', $io->output, $m) === 1 && (int) $m[1] === substr_count($io->output, 'PROBLEM: '), 'el recuento cuadra con lo enseñado');
    notContains('Products:', $io->output);

    $io = new Console(false);
    same(1, $commands['demo:check']['run']($app, ['no-existe'], $io));
    contains('does not exist', $io->output);
    contains('DEMO no-existe: 1 problems', $io->output);

    // En un sitio que no es el maestro también vale (solo lee).
    $main = SiteResolver::load($w['data'], 'main');
    $config = $main->config;
    unset($config['master']);
    SiteFactory::writeConfig($w['data'] . '/main', $config);
    SiteResolver::forget();
    $plain = new App(SiteResolver::load($w['data'], 'main'), $w['root'], ['public_dir' => $w['public'], 'clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false]);
    $plain->makeCurrent();
    $plain->boot();
    same(false, Demos::isMaster($plain));
    $io = new Console(false);
    same(0, $io->commands($plain)['demo:check']['run']($plain, ['uno'], $io), $io->output);
    same(2, $io->commands($plain)['demo:list']['run']($plain, [], $io), 'y las demás siguen pidiendo el maestro');
});

test('las órdenes de consola demo:create, demo:list y demo:reset hacen lo mismo que el panel', function (): void {
    $w = aifecSitesFresh();
    $app = $w['master'];
    $io = new Console(false);
    $commands = $io->commands($app);
    foreach (['demo:create', 'demo:reset', 'demo:list'] as $name) {
        check(isset($commands[$name]), 'existe ' . $name);
    }
    same(2, $commands['demo:create']['run']($app, [], $io), 'sin argumentos, uso');
    same(0, $commands['demo:create']['run']($app, ['minimo', 'consola', 'name' => 'Desde la consola', 'password' => 'clave-consola-1'], $io));
    contains('Demo demo-consola is ready', $io->output);
    contains('https://tienda.test/demos/consola/', $io->output);
    contains('Password: clave-consola-1', $io->output);
    same(1, $commands['demo:create']['run']($app, ['minimo', 'consola'], $io), 'repetida: error');

    $io = new Console(false);
    same(0, $commands['demo:list']['run']($app, [], $io));
    contains('demo-consola', $io->output);
    contains('/demos/consola', $io->output);
    contains('demo', $io->output);
    contains('main', $io->output);

    $io = new Console(false);
    same(1, $commands['demo:reset']['run']($app, ['main'], $io));
    contains('not a demo', $io->output);
    $io = new Console(false);
    same(0, $commands['demo:reset']['run']($app, ['demo-consola'], $io));
    contains('was reset', $io->output);
    same(200, aifecSitesFront($w, '/demos/consola/')->status);
});
