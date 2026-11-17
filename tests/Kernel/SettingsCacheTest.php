<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Cache;
use Aifec\Kernel\Settings;

require_once __DIR__ . '/../Perf/helpers.php';

/** La copia en fichero de los ajustes de un sitio. */
function settingsCopy(App $app): ?array
{
    $data = Cache::import($app->site()->path('cache') . '/settings.php');

    return is_array($data) ? $data : null;
}

test('los ajustes valen lo mismo leídos de la copia en fichero que de la tabla, también con «&», comillas, tildes y emojis', function (): void {
    $values = ['store.name' => 'Muebles Ñandú & Cía "la buena"', 'shop.flags' => ['a' => true, 'b' => null, 'c' => 1.5], 'shop.emoji' => 'Hola 🙂', 'shop.zero' => 0];
    $app = testApp(['settings' => $values]);
    $app->boot();
    $next = perfNext($app);
    $next->boot();
    foreach ($values as $key => $value) {
        same($value, $next->settings()->get($key), $key);
    }
    same($app->settings()->all('shop.'), $next->settings()->all('shop.'));
    same(false, $next->dbConnected(), 'leer los ajustes no abre la base de datos');
});

test('guardar un ajuste se ve en la petición siguiente de la tienda', function (): void {
    $app = testApp();
    $app->boot();
    $shop = perfNext($app);
    same('', $shop->settings()->str('store.slogan'));
    $app->settings()->set('store.slogan', 'Lo nuevo & lo bueno');
    same('Lo nuevo & lo bueno', perfNext($app)->settings()->str('store.slogan'));
    $app->settings()->delete('store.slogan');
    same('', perfNext($app)->settings()->str('store.slogan'), 'borrarlo también');
    $app->settings()->setMany(['a.uno' => 1, 'a.dos' => 2]);
    same(['a.dos' => 2, 'a.uno' => 1], (function (array $all): array { ksort($all); return $all; })(perfNext($app)->settings()->all('a.')));
});

test('setting:set por la consola se ve en la petición siguiente de la tienda', function (): void {
    $app = testApp();
    $app->boot();
    perfNext($app)->settings()->str('store.name');
    $cli = perfNext($app, ['sapi' => 'cli']);
    $cli->boot();
    $core = require \Aifec\Autoload::root() . '/core/cli.php';
    same(0, $core['setting:set']['run']($cli, ['store.name', 'Tienda de consola'], new \Aifec\Cli\Console(false)));
    same('Tienda de consola', perfNext($app)->settings()->str('store.name'));
});

test('lo cambiado solo en memoria (vista previa) nunca llega a la copia', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Original']]);
    $app->boot();
    $saved = $app->settings()->override(['store.name' => 'Vista previa', 'tema.raro' => 'x']);
    check($app->settings()->overridden(), 'se sabe que hay algo en memoria');
    $app->settings()->set('otra.clave', 'guardada');
    $copy = settingsCopy($app);
    same('Original', $copy['values']['store.name'], 'la copia lleva lo guardado');
    check(!isset($copy['values']['tema.raro']), 'y no lo de memoria');
    same('guardada', $copy['values']['otra.clave']);
    $app->settings()->restore($saved);
    check(!$app->settings()->overridden());
    same('Original', perfNext($app)->settings()->str('store.name'));
});

test('una copia dañada o de otra versión se ignora y se repone desde la tabla', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Buena']]);
    $app->boot();
    $file = $app->site()->path('cache') . '/settings.php';
    foreach (['<?php return [', "<?php\nreturn ['version' => '0.0.1', 'values' => ['store.name' => 'Vieja']];\n", '', "<?php\nreturn 5;\n"] as $i => $junk) {
        file_put_contents($file, $junk);
        clearstatcache();
        same('Buena', perfNext($app)->settings()->str('store.name'), 'caso ' . $i);
        same('Buena', settingsCopy($app)['values']['store.name'], 'repuesta (caso ' . $i . ')');
    }
});

test('el panel y la consola leen la tabla y reponen una copia que se quedó vieja', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Buena']]);
    $app->boot();
    Cache::export($app->site()->path('cache') . '/settings.php', ['version' => App::VERSION, 'values' => ['store.name' => 'Vieja por una carrera']]);
    same('Vieja por una carrera', perfNext($app)->settings()->str('store.name'), 'la tienda se fía de la copia');
    $panel = perfNext($app);
    $panel->handle(\Aifec\Kernel\Request::create('GET', '/admin/login'));
    same('Buena', $panel->settings()->str('store.name'), 'el panel lee la tabla');
    same('Buena', perfNext($app)->settings()->str('store.name'), 'y deja la copia bien para la tienda');
    Cache::export($app->site()->path('cache') . '/settings.php', ['version' => App::VERSION, 'values' => []]);
    perfNext($app, ['sapi' => 'cli'])->settings()->str('store.name');
    same('Buena', perfNext($app)->settings()->str('store.name'), 'la consola (y el cron) también');
});

test('Settings sigue funcionando con una conexión directa y sin copia', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Directa']]);
    $plain = new Settings($app->db());
    same('Directa', $plain->str('store.name'));
    $plain->set('store.name', 'Otra');
    same('Otra', (new Settings($app->db()))->str('store.name'));
    $lazy = new Settings(static function () use ($app) {
        return $app->db();
    });
    same('Otra', $lazy->str('store.name'));
    throws(static function (): void {
        new Settings('no es una conexión');
    }, \InvalidArgumentException::class);
});

test('reload() vuelve a la tabla aunque haya copia', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Primera']]);
    $app->boot();
    $shop = perfNext($app);
    same('Primera', $shop->settings()->str('store.name'));
    $app->db()->upsert('settings', ['k' => 'store.name', 'v' => json_encode('Escrita a mano')], ['k']);
    same('Primera', $shop->settings()->str('store.name'), 'en memoria, lo leído');
    $shop->settings()->reload();
    same('Escrita a mano', $shop->settings()->str('store.name'), 'reload lee la tabla');
});
