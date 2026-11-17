<?php
/**
 * AIFEC — pruebas de las órdenes de consola site:create, site:delete y site:config.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Install\Commands;
use Aifec\Kernel\SiteResolver;

require_once __DIR__ . '/support.php';

/**
 * Lanza una orden como lo hace `php aifec …`.
 *
 * @param string[] $args
 *
 * @return array{0:int,1:string} código de salida y salida
 */
function aifecInstConsole(string $root, string $data, array $args): array
{
    $io = new Console(false);
    $code = $io->run(array_merge(['aifec'], $args), $root, $data);

    return [$code, $io->output];
}

test('site:create crea el sitio, enseña la contraseña generada una sola vez y esa contraseña entra', function (): void {
    [$root, $data, $public] = aifecInstEmpty();
    [$code, $out] = aifecInstConsole($root, $data, ['site:create', 'main', '--url=https://tienda.test', '--name=Mi tienda', '--admin-email=Ana@Tienda.test', '--admin-name=Ana Ruiz', '--modules=tax']);
    same(0, $code, $out);
    contains('Site created: main', $out);
    contains('Panel: https://tienda.test/admin', $out);
    contains('Email: ana@tienda.test', $out);
    check(preg_match('/Password: (\S{16}) \(shown only once/', $out, $m) === 1, 'enseña la contraseña generada');

    same([['id' => 'main', 'host' => '*', 'path' => '']], SiteResolver::all($data));
    $config = require $data . '/main/config.php';
    same(false, $config['demo']);
    $db = aifecInstDb($data, 'main');
    same('Mi tienda', json_decode((string) $db->value('SELECT v FROM {settings} WHERE k = ?', ['store.name']), true));
    same(['tax'], json_decode((string) $db->value('SELECT v FROM {settings} WHERE k = ?', ['modules.active']), true));

    $i = [$root, $data, $public];
    same(302, aifecInstPost($i, '/admin/login', ['email' => 'ana@tienda.test', 'password' => $m[1]])->status, 'la contraseña enseñada es la buena');

    // Un segundo sitio de demostración, con contraseña dada (que no se vuelve a enseñar) y subcarpeta.
    [$code, $out] = aifecInstConsole($root, $data, ['site:create', 'moda', '--url=https://tienda.test/demos/moda', '--path=/demos/moda', '--demo',
        '--admin-email=bea@tienda.test', '--admin-password=otra-clave-larga', '--lang=en', '--modules=tax']);
    same(0, $code, $out);
    notContains('otra-clave-larga', $out);
    notContains('Password:', $out);
    same(2, count(SiteResolver::all($data)));
    $moda = require $data . '/moda/config.php';
    same(true, $moda['demo']);
    same('en', $moda['locale']);
    same(true, SiteResolver::load($data, 'moda')->isDemo());
    same(302, aifecInstPost($i, '/demos/moda/admin/login', ['email' => 'bea@tienda.test', 'password' => 'otra-clave-larga'])->status);
});

test('site:create sin lo imprescindible, o con algo mal, no crea nada y dice por qué', function (): void {
    $root = aifecInstRoot();
    $data = tmpDir('aifec-inst-data') . '/vacia';
    foreach ([
        ['site:create'],
        ['site:create', 'main'],
        ['site:create', 'main', '--url=https://tienda.test'],
        ['site:create', 'Mal_Id', '--url=https://tienda.test', '--admin-email=a@b.es'],
        ['site:create', 'main', '--url=https://tienda.test', '--admin-email=a@b.es', '--admin-password=corta'],
        ['site:create', 'main', '--url=https://tienda.test', '--admin-email=a@b.es', '--path=sin-barra'],
    ] as $args) {
        [$code, $out] = aifecInstConsole($root, $data, $args);
        check($code !== 0, 'tendría que fallar: ' . implode(' ', $args));
        contains('ERROR:', $out);
        check(!file_exists($data), 'no escribe nada: ' . implode(' ', $args));
    }
});

test('site:delete sin --yes no borra nada y lo dice; con --yes borra el sitio', function (): void {
    [$root, $data] = aifecInstEmpty();
    aifecInstConsole($root, $data, ['site:create', 'main', '--url=https://tienda.test', '--admin-email=ana@tienda.test', '--modules=tax']);
    aifecInstConsole($root, $data, ['site:create', 'moda', '--url=https://tienda.test/demos/moda', '--path=/demos/moda', '--admin-email=bea@tienda.test', '--modules=tax']);

    [$code, $out] = aifecInstConsole($root, $data, ['site:delete', 'moda']);
    same(1, $code);
    contains('Nothing was deleted', $out);
    same(2, count(SiteResolver::all($data)));
    check(is_file($data . '/moda/db.sqlite'));

    [$code, $out] = aifecInstConsole($root, $data, ['site:delete', 'moda', '--yes']);
    same(0, $code, $out);
    contains('Site deleted: moda', $out);
    same([['id' => 'main', 'host' => '*', 'path' => '']], SiteResolver::all($data));
    check(!is_dir($data . '/moda'));
    check(is_file($data . '/main/db.sqlite'));

    [$code, $out] = aifecInstConsole($root, $data, ['site:delete', 'moda', '--yes']);
    same(2, $code, 'un sitio que no existe');
    contains('Unknown site', $out);
    same(2, aifecInstConsole($root, $data, ['site:delete'])[0]);
});

test('site:config cambia las claves permitidas y se niega con secret, db y lo desconocido sin tocar nada', function (): void {
    [$root, $data] = aifecInstEmpty();
    aifecInstConsole($root, $data, ['site:create', 'main', '--url=https://tienda.test', '--admin-email=ana@tienda.test', '--modules=tax']);
    $original = (string) file_get_contents($data . '/main/config.php');
    $secret = (require $data . '/main/config.php')['secret'];

    // El primer sitio es el maestro, y un maestro no puede ser demo: hay que quitarle la marca en la misma orden.
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'main', 'debug=1', 'demo=true', 'timezone=Atlantic/Canary', 'locale=en', 'url=https://nueva.test/', 'modules_only=tax']);
    same(2, $code, $out);
    contains('A demo site cannot be the master site', $out);
    same($original, (string) file_get_contents($data . '/main/config.php'), 'rechazado: no se ha escrito nada');

    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'main', 'debug=1', 'demo=true', 'master=0', 'timezone=Atlantic/Canary', 'locale=en', 'url=https://nueva.test/', 'modules_only=tax']);
    same(0, $code, $out);
    contains('Updated: debug = true', $out);
    contains('Updated: master = false', $out);
    $config = require $data . '/main/config.php';
    same(true, $config['debug']);
    same(true, $config['demo']);
    check(!array_key_exists('master', $config), 'sin la marca de maestro');
    same('Atlantic/Canary', $config['timezone']);
    same('en', $config['locale']);
    same('https://nueva.test', $config['url']);
    same(['tax'], $config['modules_only']);
    same($secret, $config['secret'], 'la clave del sitio no cambia');
    same('sqlite', $config['db']['driver']);

    aifecInstConsole($root, $data, ['site:config', 'main', 'debug=0', 'modules_only=']);
    $config = require $data . '/main/config.php';
    same(false, $config['debug']);
    check(!array_key_exists('modules_only', $config), 'un valor vacío quita modules_only');

    $before = (string) file_get_contents($data . '/main/config.php');
    foreach ([
        ['site:config', 'main', 'secret=hola'],
        ['site:config', 'main', 'db=otra'],
        ['site:config', 'main', 'nombre=raro'],
        ['site:config', 'main', 'debug=1', 'secret=hola'],
        ['site:config', 'main', 'debug=quizas'],
        ['site:config', 'main', 'timezone=Marte/Olimpo'],
        ['site:config', 'main', 'url=sin-protocolo'],
        ['site:config', 'main', 'locale=castellano'],
        ['site:config', 'main', 'sin-igual'],
        ['site:config', 'fantasma', 'debug=1'],
        ['site:config', 'main'],
        ['site:config'],
    ] as $args) {
        [$code, $out] = aifecInstConsole($root, $data, $args);
        check($code === 2, 'tendría que rechazarlo: ' . implode(' ', $args) . ' → ' . $code . ' ' . $out);
        contains('ERROR:', $out);
    }
    contains('cannot be changed', aifecInstConsole($root, $data, ['site:config', 'main', 'secret=hola'])[1]);
    same($before, (string) file_get_contents($data . '/main/config.php'), 'ninguna petición rechazada toca config.php, ni las que traen una clave buena delante');
    unset($original);
});

test('el sitio maestro: lo es el primero que no es demo, solo puede haber uno y nunca es una demo', function (): void {
    [$root, $data] = aifecInstEmpty();
    aifecInstConsole($root, $data, ['site:create', 'main', '--url=https://tienda.test', '--admin-email=ana@tienda.test', '--modules=tax']);
    same(true, (require $data . '/main/config.php')['master'] ?? null, 'el primer sitio de la instalación es el maestro');

    // El segundo no lo es; ni una demo, ni aunque se pida con --master.
    aifecInstConsole($root, $data, ['site:create', 'otra', '--url=https://tienda.test/otra', '--path=/otra', '--admin-email=ana@tienda.test', '--modules=tax']);
    check(!array_key_exists('master', require $data . '/otra/config.php'), 'el segundo sitio no es maestro');
    [$code, $out] = aifecInstConsole($root, $data, ['site:create', 'tercera', '--url=https://tienda.test/tercera', '--path=/tercera', '--admin-email=ana@tienda.test', '--modules=tax', '--master']);
    same(2, $code, $out);
    contains('The master site is already "main"', $out);
    check(!is_dir($data . '/tercera'), 'rechazado: no se ha creado nada');

    // Con site:config: se niega mientras haya otro maestro, y dice cuál.
    $before = (string) file_get_contents($data . '/otra/config.php');
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'otra', 'master=1']);
    same(2, $code, $out);
    contains('The master site is already "main"', $out);
    same($before, (string) file_get_contents($data . '/otra/config.php'), 'rechazado: no se ha escrito nada');

    // Se le quita al primero y entonces sí.
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'main', 'master=0']);
    same(0, $code, $out);
    check(!array_key_exists('master', require $data . '/main/config.php'), 'master=0 quita la clave');
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'otra', 'master=1']);
    same(0, $code, $out);
    contains('Updated: master = true', $out);
    same(true, (require $data . '/otra/config.php')['master']);
    // Repetirlo sobre el que ya lo es no es un error (no se compara consigo mismo).
    same(0, aifecInstConsole($root, $data, ['site:config', 'otra', 'master=1'])[0]);

    // Una demo no puede ser maestra, ni al crearla ni después; y el maestro no se puede marcar como demo.
    aifecInstConsole($root, $data, ['site:config', 'otra', 'master=0']);
    [$code, $out] = aifecInstConsole($root, $data, ['site:create', 'muestra', '--url=https://tienda.test/demos/muestra', '--path=/demos/muestra', '--admin-email=ana@tienda.test', '--modules=tax', '--demo', '--master']);
    same(2, $code, $out);
    contains('A demo site cannot be the master site', $out);
    aifecInstConsole($root, $data, ['site:create', 'muestra', '--url=https://tienda.test/demos/muestra', '--path=/demos/muestra', '--admin-email=ana@tienda.test', '--modules=tax', '--demo']);
    check(!array_key_exists('master', require $data . '/muestra/config.php'), 'una demo no es maestra aunque no haya ninguno');
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'muestra', 'master=1']);
    same(2, $code, $out);
    contains('A demo site cannot be the master site', $out);
    same(0, aifecInstConsole($root, $data, ['site:config', 'main', 'master=1'])[0]);
    [$code, $out] = aifecInstConsole($root, $data, ['site:config', 'main', 'demo=1']);
    same(2, $code, $out);
    contains('A demo site cannot be the master site', $out);
    same(false, (require $data . '/main/config.php')['demo']);
});

test('una instalación cuyo primer sitio es una demo no tiene maestro hasta que se crea un sitio de verdad con --master', function (): void {
    [$root, $data] = aifecInstEmpty();
    aifecInstConsole($root, $data, ['site:create', 'muestra', '--url=https://tienda.test/demos/muestra', '--path=/demos/muestra', '--admin-email=ana@tienda.test', '--modules=tax', '--demo']);
    check(!array_key_exists('master', require $data . '/muestra/config.php'), 'la demo no es maestra');
    same(null, Aifec\Install\SiteFactory::masterId($data));
    [$code, $out] = aifecInstConsole($root, $data, ['site:create', 'main', '--url=https://tienda.test', '--admin-email=ana@tienda.test', '--modules=tax', '--master']);
    same(0, $code, $out);
    same('main', Aifec\Install\SiteFactory::masterId($data));
    same(null, Aifec\Install\SiteFactory::masterId($data, 'main'), 'sin contar el que se está cambiando');
});

test('las órdenes son globales: salen en la ayuda aunque no haya ningún sitio, cada una con su help', function (): void {
    $root = aifecInstRoot();
    $data = tmpDir('aifec-inst-data');
    [$code, $out] = aifecInstConsole($root, $data, ['help']);
    same(0, $code);
    foreach (['site:create', 'site:delete', 'site:config'] as $name) {
        contains($name, $out);
    }
    $all = Commands::all();
    same(['site:create', 'site:delete', 'site:config'], array_keys($all));
    foreach ($all as $name => $command) {
        check($command['help'] !== '' && is_callable($command['run']), $name);
    }
});
