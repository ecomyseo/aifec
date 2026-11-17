<?php
/**
 * AIFEC — pruebas de restablecer y borrar demostraciones: vuelven a su estado, el maestro es intocable, un fallo a mitad no
 * deja nada, y la tarea de cada noche sigue con las demás cuando una falla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Install\SiteFactory;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Sites\SitesTasks;

require_once __DIR__ . '/helpers.php';

test('restablecer una demostración tras un pedido y un precio cambiado la deja con el catálogo original y cero pedidos', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    check(!empty($demos->create(['package' => 'minimo', 'slug' => 'tienda'])['ok']), 'la demostración se crea');
    $demo = aifecSitesDemoApp($w, 'demo-tienda');
    $placed = aifecSitesPlaceOrder($demo);
    check(!empty($placed['ok']), 'el pedido con el pago simulado se crea: ' . (isset($placed['error']) ? (string) $placed['error'] : ''));
    $demo->db()->update('products', ['price' => 1], ['sku' => 'DEMO-MESA-02']);
    $demo->db()->update('categories', ['active' => 0], ['id' => 1]);
    same(1, (int) $demo->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(1, (int) $demo->db()->value('SELECT price FROM {products} WHERE sku = ?', ['DEMO-MESA-02']));
    $oldSecret = (string) $demo->site()->secret();
    aifecSitesRelease($demo);

    $result = $demos->reset('demo-tienda');
    same(true, $result['ok'], (string) ($result['error'] ?? ''));

    $demo = aifecSitesDemoApp($w, 'demo-tienda');
    same(0, (int) $demo->db()->value('SELECT COUNT(*) FROM {orders}'), 'cero pedidos');
    same(0, (int) $demo->db()->value('SELECT COUNT(*) FROM {customers}'), 'y ningún cliente de la visita anterior');
    same(8900, (int) $demo->db()->value('SELECT price FROM {products} WHERE sku = ?', ['DEMO-MESA-02']), 'el precio original');
    same(2, (int) $demo->db()->value('SELECT COUNT(*) FROM {categories} WHERE active = 1'), 'las dos categorías activas');
    same(6, (int) $demo->db()->value('SELECT COUNT(*) FROM {products}'));
    check($demo->site()->secret() !== $oldSecret, 'es un sitio nuevo, con su propia clave');
    same(11, (int) $demo->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['DEMO-MESA-02']), 'las existencias también vuelven');
    aifecSitesRelease($demo);
});

test('restablecer conserva la subcarpeta, el paquete y la contraseña, anota cuándo y la demostración sigue respondiendo', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    $demos->create(['package' => 'minimo', 'slug' => 'fija', 'password' => 'clave-fija-2026', 'name' => 'Tienda fija']);
    $before = $demos->entry('demo-fija');
    same(null, $before['last_reset_at']);
    $w['master']->clock()->advance(3600);

    same(true, $demos->reset('demo-fija')['ok']);
    $after = $demos->entry('demo-fija');
    same('done', $after['state']);
    same('minimo', $after['package']);
    same('fija', $after['slug']);
    same('clave-fija-2026', $after['password']);
    same('Tienda fija', $after['name']);
    same($w['master']->clock()->sql(), $after['last_reset_at'], 'anota cuándo se restableció');
    same(200, aifecSitesFront($w, '/demos/fija/')->status);
    contains('Tienda fija', aifecSitesFront($w, '/demos/fija/')->body);
    $demo = aifecSitesDemoApp($w, 'demo-fija');
    $web = new TestClient($demo);
    same(302, aifecLogin($web, 'demo@tienda.test', 'clave-fija-2026')->status, 'la misma contraseña entra');
    aifecSitesRelease($web);
    aifecSitesRelease($demo);
    same(1, count(array_filter(SiteResolver::all((string) $w['data']), static function (array $r): bool {
        return $r['id'] === 'demo-fija';
    })), 'una sola entrada en sites.php');
});

test('no se puede restablecer ni borrar el sitio maestro ni un sitio que no es una demostración', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    $sitesBefore = (string) file_get_contents($w['data'] . '/sites.php');
    $dbBefore = md5_file($w['data'] . '/main/db.sqlite');
    foreach (['main', '../main', 'demo-', 'demo-x'] as $id) {
        same(false, $demos->reset($id)['ok'], 'reset(' . $id . ') se rechaza');
        same(false, $demos->delete($id)['ok'], 'delete(' . $id . ') se rechaza');
    }
    check(is_dir($w['data'] . '/main') && is_file($w['data'] . '/main/db.sqlite'), 'el maestro sigue en su sitio');
    same($sitesBefore, (string) file_get_contents($w['data'] . '/sites.php'));

    // Un sitio que se llama «demo-…» pero NO es una demostración, y que alguien apuntó en el registro.
    SiteFactory::create($w['root'], $w['data'], aifecInstOptions(['id' => 'demo-falsa', 'path' => '/otra', 'public_dir' => $w['public'], 'admin' => ['email' => 'otra@tienda.test']]));
    $w['master']->settings()->set('sites.demos', ['demo-falsa' => ['slug' => 'falsa', 'package' => 'minimo', 'name' => 'Falsa', 'password' => 'clave-larga-1234', 'state' => 'done']]);
    $r = $demos->reset('demo-falsa');
    same(false, $r['ok']);
    contains('not a demo', (string) $r['error']);
    same(false, $demos->delete('demo-falsa')['ok']);
    check(is_file($w['data'] . '/demo-falsa/db.sqlite'), 'el sitio que no es demostración sigue intacto');
    same(2, count(SiteResolver::all((string) $w['data'])));
    same($dbBefore, md5_file($w['data'] . '/main/db.sqlite'), 'la base del maestro no se ha tocado');
});

test('si el paquete deja de valer, restablecer no destruye la demostración que funcionaba', function (): void {
    $packages = aifecSitesPackages();
    $w = aifecSitesFresh($packages);
    $demos = $w['demos'];
    same(true, $demos->create(['package' => 'uno', 'slug' => 'viva'])['ok']);
    file_put_contents($packages . '/uno/products.json', '[{"sku": ');

    $r = $demos->reset('demo-viva');
    same(false, $r['ok']);
    contains('products.json', (string) $r['error']);
    same('failed', $demos->entry('demo-viva')['state']);
    same(200, aifecSitesFront($w, '/demos/viva/')->status, 'la demostración sigue ahí');
    $demo = aifecSitesDemoApp($w, 'demo-viva');
    same(6, (int) $demo->db()->value('SELECT COUNT(*) FROM {products}'), 'con sus productos');
    aifecSitesRelease($demo);
});

test('un create que falla (paquete que no existe, o que falla a mitad) no deja el sitio, ni su carpeta, ni sus medios', function (): void {
    $packages = aifecSitesPackages();
    $w = aifecSitesFresh($packages);
    $demos = $w['demos'];

    $r = $demos->create(['package' => 'no-existe', 'slug' => 'nada']);
    same(false, $r['ok']);
    check(!is_dir($w['data'] . '/demo-nada') && !is_dir($w['public'] . '/media/demo-nada'), 'no queda nada de un paquete que no existe');
    same(['main'], array_column(SiteResolver::all((string) $w['data']), 'id'), 'sites.php solo tiene el maestro');

    // Un paquete que pasa la comprobación pero falla al guardar una imagen: el sitio ya está creado y con datos a medias.
    file_put_contents($packages . '/dos/media/mesa-trabajo.svg', 'esto ya no es un svg');
    $r = $demos->create(['package' => 'dos', 'slug' => 'rota']);
    same(false, $r['ok'], 'el create falla a mitad');
    check($r['error'] !== '', 'y dice por qué');
    check(!is_dir($w['data'] . '/demo-rota'), 'se ha borrado la carpeta del sitio a medio crear');
    check(!is_dir($w['public'] . '/media/demo-rota'), 'y sus medios');
    same(['main'], array_column(SiteResolver::all((string) $w['data']), 'id'), 'sites.php solo tiene el maestro');
    same('failed', $demos->entry('demo-rota')['state']);
    check($demos->entry('demo-rota')['error'] !== '', 'la ficha dice el motivo');
    same(200, aifecSitesFront($w, '/')->status, 'y el maestro sigue respondiendo');

    // Arreglado el paquete, se puede volver a crear con el mismo nombre.
    aifecSitesCopy(\Aifec\Autoload::root() . '/demos/minimo/media', $packages . '/dos/media_copia');
    copy($packages . '/dos/media_copia/mesa-trabajo.svg', $packages . '/dos/media/mesa-trabajo.svg');
    same(true, $demos->create(['package' => 'dos', 'slug' => 'rota'])['ok'], 'tras arreglarlo se crea');
});

test('la tarea de cada noche restablece las demostraciones y, si la primera falla, la segunda se hace igual', function (): void {
    $packages = aifecSitesPackages();
    $w = aifecSitesFresh($packages);
    $demos = $w['demos'];
    same(true, $demos->create(['package' => 'uno', 'slug' => 'a'])['ok']);
    same(true, $demos->create(['package' => 'dos', 'slug' => 'b'])['ok']);
    $demo = aifecSitesDemoApp($w, 'demo-b');
    $demo->db()->update('products', ['price' => 5], ['sku' => 'DEMO-MESA-01']);
    aifecSitesRelease($demo);
    file_put_contents($packages . '/uno/package.json', 'roto');
    $w['master']->clock()->advance(7200);

    $failure = '';
    try {
        SitesTasks::resetDemos($w['master']);
    } catch (\RuntimeException $e) {
        $failure = $e->getMessage();
    }
    contains('failed 1', $failure);
    contains('demo-a', $failure);
    check(strpos($failure, 'demo-b') === false, 'la segunda no figura como fallida');
    same('failed', $demos->entry('demo-a')['state'], 'la primera queda fallida con su motivo');
    check(strpos((string) $demos->entry('demo-a')['error'], 'package.json') !== false, 'dice por qué');
    same('done', $demos->entry('demo-b')['state'], 'la segunda se ha hecho');
    same($w['master']->clock()->sql(), $demos->entry('demo-b')['last_reset_at']);
    $demo = aifecSitesDemoApp($w, 'demo-b');
    same(24900, (int) $demo->db()->value('SELECT price FROM {products} WHERE sku = ?', ['DEMO-MESA-01']), 'y volvió a su precio');
    aifecSitesRelease($demo);
    same(200, aifecSitesFront($w, '/demos/a/')->status, 'la primera sigue en pie como estaba');
});

test('la tarea de cada noche respeta el ajuste que la apaga y no hace nada sin demostraciones', function (): void {
    $w = aifecSitesFresh();
    same(null, SitesTasks::resetDemos($w['master']), 'sin demostraciones, nada que hacer');
    same(true, $w['demos']->create(['package' => 'minimo', 'slug' => 'x'])['ok']);
    $w['master']->settings()->set('sites.reset_nightly', false);
    $before = $w['demos']->entry('demo-x');
    contains('turned off', (string) SitesTasks::resetDemos($w['master']));
    same($before['last_reset_at'], $w['demos']->entry('demo-x')['last_reset_at'], 'apagada, no restablece');
    $w['master']->settings()->set('sites.reset_nightly', true);
    $w['master']->clock()->advance(60);
    same('Reset 1 demo(s)', SitesTasks::resetDemos($w['master']));
    check($w['demos']->entry('demo-x')['last_reset_at'] !== null, 'encendida, restablece');
    $tasks = array_column($w['master']->service('jobs')->tasks(), null, 'task');
    check(isset($tasks['sites.reset_demos']), 'la tarea está en la lista de tareas');
    same('daily@04:30', $tasks['sites.reset_demos']['every']);
});

test('una demostración que se quedó en curso sin nadie detrás se cierra como fallida con su motivo', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    $old = $w['master']->clock()->sql($w['master']->clock()->now() - 3600);
    $fresh = $w['master']->clock()->sql();
    $w['master']->settings()->set('sites.demos', [
        'demo-vieja' => ['slug' => 'vieja', 'package' => 'minimo', 'name' => 'V', 'password' => 'clave-larga-1234', 'state' => 'running', 'updated_at' => $old],
        'demo-nueva' => ['slug' => 'nueva', 'package' => 'minimo', 'name' => 'N', 'password' => 'clave-larga-1234', 'state' => 'running', 'updated_at' => $fresh],
        'demo-lista' => ['slug' => 'lista', 'package' => 'minimo', 'name' => 'L', 'password' => 'clave-larga-1234', 'state' => 'done', 'updated_at' => $old],
    ]);
    same(1, $demos->sweep());
    same('failed', $demos->entry('demo-vieja')['state']);
    contains('Interrupted', (string) $demos->entry('demo-vieja')['error']);
    same('running', $demos->entry('demo-nueva')['state'], 'la reciente sigue en curso');
    same('done', $demos->entry('demo-lista')['state'], 'la hecha no se toca por antigua que sea');
    same(0, $demos->sweep(), 'una segunda pasada no cierra nada más');
});

test('borrar una demostración quita su sitio, sus medios y su ficha, y deja en paz las demás', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    $demos->create(['package' => 'minimo', 'slug' => 'uno']);
    $demos->create(['package' => 'minimo', 'slug' => 'dos']);
    check(is_dir($w['public'] . '/media/demo-uno') && is_dir($w['public'] . '/media/demo-dos'), 'cada demostración tiene sus medios');
    same(true, $demos->delete('demo-uno')['ok']);
    check(!is_dir($w['data'] . '/demo-uno') && !is_dir($w['public'] . '/media/demo-uno'), 'sin sitio y sin medios');
    check(is_dir($w['data'] . '/demo-dos') && is_dir($w['public'] . '/media/demo-dos'), 'la otra sigue con lo suyo');
    same(null, $demos->entry('demo-uno'));
    $ids = array_column(SiteResolver::all((string) $w['data']), 'id');
    sort($ids);
    same(['demo-dos', 'main'], $ids);
    same(404, aifecSitesFront($w, '/demos/uno/')->status, 'la dirección ya no es de esa demostración');
    same(200, aifecSitesFront($w, '/demos/dos/')->status);
});
