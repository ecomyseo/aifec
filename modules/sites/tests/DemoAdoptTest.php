<?php
/**
 * AIFEC — pruebas de las tiendas de muestra apuntadas a mano (demo:adopt): un sitio de muestra que ya existe y que no salió de
 * un paquete sale en la página pública de demostraciones, sin acceso público a su panel, y la tarea de cada noche no lo toca.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Sites\SitesTasks;

require_once __DIR__ . '/helpers.php';

/**
 * Un sitio creado sin paquete en /demos/<carpeta> (como las tiendas de muestra con un catálogo cargado a mano).
 *
 * @param array<string,mixed> $w
 */
function aifecSitesLoose(array $w, string $id, bool $demo = true): void
{
    SiteFactory::create((string) $w['root'], (string) $w['data'], aifecInstOptions([
        'id' => $id, 'host' => '*', 'path' => '/demos/' . $id, 'url' => 'https://tienda.test/demos/' . $id,
        'db' => ['driver' => 'sqlite'], 'demo' => $demo, 'shop_name' => 'Suelta ' . $id,
        'admin' => ['name' => 'Privada', 'email' => 'privada@tienda.test', 'password' => 'clave-privada-9876'],
        'public_dir' => (string) $w['public'], 'modules' => [],
    ]));
    SiteResolver::forget();
    $w['master']->makeCurrent();
}

test('una tienda de muestra apuntada a mano sale en /demos con su enlace, su sector y su descripción, y sin acceso al panel', function (): void {
    $w = aifecSitesFresh();
    aifecSitesLoose($w, 'bricolaje');
    check(strpos(aifecSitesFront($w, '/demos')->body, '/demos/bricolaje/') === false, 'sin apuntarla no sale');

    $result = $w['demos']->adopt(['id' => 'bricolaje', 'name' => 'Bricolaje & Taller', 'sector' => 'bricolaje', 'description' => 'Herrajes y abrasivos de verdad']);
    same(true, $result['ok']);
    same('https://tienda.test/demos/bricolaje', $result['url']);

    $body = aifecSitesFront($w, '/demos')->body;
    contains('Bricolaje &amp; Taller', $body, 'el nombre, escapado');
    contains('href="https://tienda.test/demos/bricolaje/"', $body, '«Ver la tienda»');
    contains('Herrajes y abrasivos de verdad', $body);
    contains('bricolaje', $body);
    check(strpos($body, 'https://tienda.test/demos/bricolaje/admin') === false, 'no enlaza su panel');
    check(strpos($body, 'clave-privada-9876') === false && strpos($body, 'privada@tienda.test') === false, 'ni enseña su acceso');
    check(strpos($body, 'demo-aifec-2026') === false, 'ni la contraseña de serie de las demostraciones de paquete');

    $entry = $w['demos']->entry('bricolaje');
    same(true, $entry['adopted']);
    same('', $entry['password']);
    same('done', $entry['state']);
});

test('apuntar otra vez cambia el nombre; junto a una de paquete, cada tarjeta conserva lo suyo', function (): void {
    $w = aifecSitesFresh();
    // Con el acceso al panel anunciado (de serie la página no lo enseña): la de paquete lo lleva, la apuntada nunca.
    $w['master']->settings()->set('sites.demos_show_panel', true);
    $w['demos']->create(['package' => 'minimo', 'slug' => 'hogar', 'name' => 'Hogar', 'password' => 'clave-publica-1']);
    aifecSitesLoose($w, 'deporte');
    same(true, $w['demos']->adopt(['id' => 'deporte', 'name' => 'Deporte'])['ok']);
    same(true, $w['demos']->adopt(['id' => 'deporte', 'name' => 'Deporte y aire libre', 'sector' => 'deporte'])['ok']);

    $body = aifecSitesFront($w, '/demos')->body;
    contains('Deporte y aire libre', $body);
    contains('href="https://tienda.test/demos/hogar/admin"', $body, 'la de paquete sigue enlazando su panel');
    contains('clave-publica-1', $body, 'y enseñando su contraseña pública');
    check(strpos($body, 'https://tienda.test/demos/deporte/admin') === false, 'la apuntada, no');
    same(2, substr_count($body, 'data-demo="'), 'dos tarjetas');
});

test('no se apunta el sitio maestro, ni un sitio que no es de muestra, ni uno que no existe, ni una demostración de paquete', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'hogar']);
    aifecSitesLoose($w, 'tienda-real', false);

    same(false, $w['demos']->adopt(['id' => 'main'])['ok'], 'el maestro');
    same(false, $w['demos']->adopt(['id' => 'tienda-real'])['ok'], 'una tienda que no es de muestra');
    same(false, $w['demos']->adopt(['id' => 'no-existe'])['ok']);
    same(false, $w['demos']->adopt(['id' => '../main'])['ok'], 'un identificador raro');
    $package = $w['demos']->adopt(['id' => 'demo-hogar', 'name' => 'Otra cosa']);
    same(false, $package['ok'], 'la de paquete ya está en la lista');
    same('minimo', $w['demos']->entry('demo-hogar')['package'], 'y su ficha no se toca');
    same(null, $w['demos']->entry('tienda-real'));
    check(strpos(aifecSitesFront($w, '/demos')->body, 'tienda-real') === false, 'la tienda de verdad no sale en la página');
});

test('la tarea de cada noche restablece las de paquete y deja en paz la apuntada a mano, sin darla por fallida', function (): void {
    $w = aifecSitesFresh();
    same(true, $w['demos']->create(['package' => 'minimo', 'slug' => 'hogar'])['ok']);
    aifecSitesLoose($w, 'calzado');
    same(true, $w['demos']->adopt(['id' => 'calzado', 'name' => 'Calzado'])['ok']);
    $before = $w['demos']->entry('calzado');
    $w['master']->clock()->advance(7200);

    SitesTasks::resetDemos($w['master']);
    check(true, 'la tarea no lanza: la apuntada no cuenta como fallida');

    same('done', $w['demos']->entry('demo-hogar')['state']);
    same($w['master']->clock()->sql(), $w['demos']->entry('demo-hogar')['last_reset_at'], 'la de paquete se ha restablecido');
    same($before, $w['demos']->entry('calzado'), 'la apuntada sigue igual');
    same(200, aifecSitesFront($w, '/demos/calzado/')->status, 'y su tienda sigue respondiendo');

    $result = $w['demos']->reset('calzado');
    same(false, $result['ok'], 'tampoco se puede restablecer a mano: no hay paquete del que rehacerla');
});

test('la orden demo:adopt apunta la tienda y dice su dirección; fuera del maestro no funciona', function (): void {
    $w = aifecSitesFresh();
    aifecSitesLoose($w, 'gourmet');
    $io = new Console(false);
    $commands = $io->commands($w['master']);
    check(isset($commands['demo:adopt']) && $commands['demo:adopt']['help'] !== '', 'la orden existe, con su ayuda');

    same(0, $commands['demo:adopt']['run']($w['master'], [0 => 'gourmet', 'name' => 'Gourmet', 'sector' => 'alimentación'], $io), $io->output);
    contains('https://tienda.test/demos/gourmet/', $io->output);
    same('alimentación', $w['demos']->entry('gourmet')['sector']);

    $io = new Console(false);
    same(2, $commands['demo:adopt']['run']($w['master'], [], $io), 'sin sitio, el modo de uso');
    contains('Usage: demo:adopt', $io->output);
    $io = new Console(false);
    same(1, $commands['demo:adopt']['run']($w['master'], [0 => 'no-existe'], $io));
    contains('not a demo', $io->output);

    // En un sitio que no es el maestro, la orden se niega.
    $demo = aifecSitesDemoApp($w, 'gourmet');
    $io = new Console(false);
    $run = require dirname(__DIR__) . '/cli.php';
    same(2, $run['demo:adopt']['run']($demo, [0 => 'gourmet'], $io), 'fuera del maestro no funciona');
    aifecSitesRelease($demo);
    $w['master']->makeCurrent();
});
