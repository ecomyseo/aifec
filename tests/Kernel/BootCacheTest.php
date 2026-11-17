<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Kernel\Cache;

require_once __DIR__ . '/../Perf/helpers.php';

/** Lo que guardó la caché de arranque de un sitio (o null si no hay). */
function bootCacheData(App $app): ?array
{
    $data = Cache::import($app->site()->path('cache') . '/boot.php');

    return is_array($data) ? $data : null;
}

test('la caché de arranque se crea en la primera petición y la segunda no recorre ninguna carpeta', function (): void {
    $app = testApp(['root' => perfRoot()]);
    same(200, perfGet($app, '/alfa/7')->status);
    $boot = bootCacheData($app);
    check($boot !== null, 'existe boot.php');
    same(['alfa'], $boot['ids'], 'los módulos activos, ya resueltos (beta está apagado de serie)');
    check(isset($boot['modules']['alfa']['routes']) && is_array($boot['modules']['alfa']['routes']), 'las rutas de alfa están compiladas');
    same('verde', $boot['defaults']['alfa.color'], 'los valores por defecto, ya fundidos');

    $next = perfNext($app);
    $r = perfGet($next, '/alfa/7');
    same(200, $r->status);
    same('ALFA 7 Carrito verde', $r->body, 'misma página con la caché');
    same(0, $next->modules()->scans(), 'no recorre las carpetas de módulos');
    same(['alfa'], $next->modules()->loaded());
    same(perfRoutes($app), perfRoutes($next), 'las mismas rutas con la tabla compilada y sin ella');
});

test('una petición de la tienda con la caché no lee ningún settings.php ni pregunta por los ficheros de cada módulo', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/alfa/1');
    $next = perfNext($app);
    perfGet($next, '/alfa/1');
    same(7, $next->settings()->int('alfa.tope'), 'el valor por defecto sale de la caché');
    // La definición de un fichero que no existe se contesta sin ir al disco: el módulo no tiene jobs.php.
    same([], $next->modules()->definition('alfa', 'jobs'));
    same(0, $next->modules()->scans());
    // Y la de uno que sí existe se sigue leyendo cuando alguien la pide (el panel).
    check(isset($next->modules()->definition('alfa', 'settings')['tabs']), 'settings.php se lee si se pide');
});

test('activar un módulo rehace la caché de arranque en la petición siguiente', function (): void {
    $app = testApp(['root' => perfRoot()]);
    same(404, perfGet($app, '/beta')->status, 'beta está apagado');
    $app->settings()->set('modules.active', ['alfa', 'beta']);
    $next = perfNext($app);
    $r = perfGet($next, '/beta');
    same(200, $r->status, 'la siguiente petición ya ve beta');
    same('BETA anónima', $r->body);
    same(['alfa', 'beta'], bootCacheData($next)['ids'], 'y la caché nueva lo lleva');
    $off = perfNext($next);
    $off->settings()->set('modules.active', ['alfa']);
    same(404, perfGet(perfNext($off), '/beta')->status, 'desactivarlo también la rehace');
});

test('cambiar un module.json se ve desde el panel y la consola; una página de la tienda no recorre el disco para saberlo', function (): void {
    $root = perfRoot();
    $app = testApp(['root' => $root]);
    perfGet($app, '/alfa/1');
    $before = bootCacheData($app)['fp'];
    perfPut($root . '/modules/beta/module.json', '{"name":"Beta","version":"1.0.1","default":true}');

    // La tienda se fía de la caché: no recorre carpetas (es lo que cuesta) y sigue con lo de antes.
    $shop = perfNext($app);
    same(404, perfGet($shop, '/beta')->status, 'la tienda no ha mirado el disco');
    same(0, $shop->modules()->scans());

    // El panel recalcula la huella y la rehace.
    $panel = perfNext($app);
    perfGet($panel, '/admin/cualquier-cosa');
    check(bootCacheData($panel)['fp'] !== $before, 'el panel ha rehecho la caché con la huella nueva');
    same(['alfa', 'beta'], bootCacheData($panel)['ids']);
    same(200, perfGet(perfNext($app), '/beta')->status, 'y a partir de ahí la tienda lo ve');

    // Y la consola también.
    perfPut($root . '/modules/beta/module.json', '{"name":"Beta","version":"1.0.2","default":false}');
    $cli = perfNext($app, ['sapi' => 'cli']);
    $cli->boot();
    same(['alfa'], bootCacheData($cli)['ids'], 'la consola recalcula la huella');
});

test('cambiar la configuración del sitio (config.php) rehace la caché de arranque: hay rutas que dependen de ella', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/alfa/1');
    $before = bootCacheData($app);
    check($before !== null, 'existe boot.php');
    $same = perfNext($app);
    perfGet($same, '/alfa/1');
    same(0, $same->modules()->scans(), 'con la misma configuración, la caché vale');

    // Lo que hace «site:config main master=1»: el mismo sitio con otra configuración.
    $app->site()->config['master'] = true;
    $next = perfNext($app);
    same(200, perfGet($next, '/alfa/1')->status);
    check($next->modules()->scans() > 0, 'la caché de antes ya no vale: se vuelve a leer el disco');
    $after = bootCacheData($next);
    check($after !== null && $after['sig'] !== $before['sig'], 'y queda una caché nueva, con otra firma');
    $again = perfNext($app);
    perfGet($again, '/alfa/1');
    same(0, $again->modules()->scans(), 'que vale para las peticiones siguientes');
    notContains((string) $app->site()->secret(), (string) file_get_contents($app->site()->path('cache') . '/boot.php'), 'la firma no deja la clave del sitio en el fichero');
});

test('cache:clear borra la caché de arranque y la petición siguiente la vuelve a crear', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/alfa/1');
    $file = $app->site()->path('cache') . '/boot.php';
    check(is_file($file), 'existe antes');
    $io = new Console(false);
    $commands = $io->commands($app);
    same(0, $commands['cache:clear']['run']($app, [], $io));
    clearstatcache();
    check(!is_file($file), 'cache:clear la borra');
    check(!is_file($app->site()->path('cache') . '/settings.php'), 'y la copia de los ajustes');
    $next = perfNext($app);
    same('ALFA 2 Carrito verde', perfGet($next, '/alfa/2')->body);
    check(is_file($file), 'la siguiente la vuelve a crear');
});

test('una caché de arranque dañada, vacía o de otro formato se rehace sola y la tienda sigue', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/alfa/1');
    $file = $app->site()->path('cache') . '/boot.php';
    foreach (['', '<?php return [', "<?php\nreturn 'basura';\n", "<?php\nreturn ['v' => 999];\n", 'texto sin php'] as $i => $junk) {
        file_put_contents($file, $junk);
        clearstatcache();
        $next = perfNext($app);
        $r = perfGet($next, '/alfa/' . $i);
        same(200, $r->status, 'caso ' . $i);
        same('ALFA ' . $i . ' Carrito verde', $r->body, 'caso ' . $i);
        check(bootCacheData($next) !== null && bootCacheData($next)['v'] === 1, 'se ha rehecho (caso ' . $i . ')');
    }
});

test('dos sitios de la misma instalación no comparten la caché de arranque', function (): void {
    $root = perfRoot();
    $one = testApp(['root' => $root]);
    $two = testApp(['root' => $root, 'settings' => ['modules.active' => ['alfa', 'beta'], 'urls.cart' => 'cesta']]);
    perfGet($one, '/alfa/1');
    perfGet($two, '/alfa/1');
    check($one->site()->path('cache') !== $two->site()->path('cache'), 'cada sitio tiene su carpeta');
    same(['alfa'], bootCacheData($one)['ids']);
    same(['alfa', 'beta'], bootCacheData($two)['ids']);
    same(200, perfGet(perfNext($one), '/carrito')->status);
    same(404, perfGet(perfNext($one), '/cesta')->status);
    same(200, perfGet(perfNext($two), '/cesta')->status, 'cada uno con sus direcciones');
});

test('una vista previa (ajustes solo en memoria) no escribe la caché de arranque', function (): void {
    $app = testApp(['root' => perfRoot()]);
    $app->migrate(false);
    $preview = perfNext($app);
    $preview->settings()->override(['urls.cart' => 'cesta-de-prueba']);
    same(200, perfGet($preview, '/cesta-de-prueba')->status, 'la vista previa ve su ajuste');
    check(bootCacheData($preview) === null, 'pero no lo guarda para los demás');
    same(404, perfGet(perfNext($app), '/cesta-de-prueba')->status, 'la siguiente petición no lo hereda');
});

test('un módulo que falla al cargar no deja caché: la petición siguiente lo vuelve a intentar', function (): void {
    $root = perfRoot();
    perfPut($root . '/plugins/roto/plugin.json', '{"name":"Roto","version":"0.1.0","default":true}');
    perfPut($root . '/plugins/roto/hooks.php', "<?php\nthrow new \\RuntimeException('plugin roto al cargar');\n");
    $app = testApp(['root' => $root]);
    same(200, perfGet($app, '/alfa/1')->status, 'el sitio sigue en pie');
    check(isset($app->modules()->failed()['roto']), 'el plugin ha fallado');
    check(bootCacheData($app) === null, 'no se guarda un arranque con un fallo');
    perfPut($root . '/plugins/roto/hooks.php', "<?php\nreturn static function (\$app): void {};\n");
    $next = perfNext($app);
    perfGet($next, '/alfa/1');
    check(in_array('roto', $next->modules()->loaded(), true), 'arreglado, carga');
    check(bootCacheData($next) !== null, 'y ya se guarda');
});

test('el tema sale de la caché igual que leyendo sus theme.json, y cambiar de tema la rehace', function (): void {
    $root = perfRoot();
    perfPut($root . '/themes/hijo/theme.json', '{"name":"Hijo","parent":"base","version":"2.0.0","settings":{"color":"rojo"}}');
    $app = testApp(['root' => $root]);
    perfGet($app, '/alfa/1');
    $fresh = $app->theme();
    $next = perfNext($app);
    perfGet($next, '/alfa/1');
    same($fresh, $next->theme(), 'el mismo tema fundido');
    same('base', $next->theme()['slug']);
    $next->settings()->set('theme.active', 'hijo');
    $third = perfNext($next);
    perfGet($third, '/alfa/1');
    same(['hijo', 'base'], $third->theme()['chain'], 'el tema nuevo, con su padre');
    same('rojo', $third->theme()['settings']['color']);
    same('2.0.0', bootCacheData($third)['theme']['version']);
});

test('la huella cambia al cambiar un catálogo de idioma, al añadir un módulo y al tocar un routes.php', function (): void {
    $root = perfRoot();
    $app = testApp(['root' => $root]);
    $mm = $app->modules();
    $a = $mm->fingerprint();
    same($a, $mm->fingerprint(), 'estable si nada cambia');
    perfPut($root . '/modules/alfa/lang/es.php', "<?php\nreturn ['Cart' => 'Cesta de la compra'];\n");
    $b = $mm->fingerprint();
    check($a !== $b, 'catálogo de idioma');
    perfPut($root . '/modules/gamma/module.json', '{"name":"Gamma"}');
    $c = $mm->fingerprint();
    check($b !== $c, 'módulo nuevo');
    perfPut($root . '/modules/alfa/routes.php', (string) file_get_contents($root . '/modules/alfa/routes.php') . "\n// cambio\n");
    check($c !== $mm->fingerprint(), 'routes.php');
});
