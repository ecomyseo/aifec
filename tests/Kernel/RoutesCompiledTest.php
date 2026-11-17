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
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Route;
use Aifec\Kernel\Router;

require_once __DIR__ . '/../Perf/helpers.php';

/** Un valor de ejemplo que cumple el patrón de un parámetro de ruta. */
function routesSample(string $regex): string
{
    foreach (['12', 'abc', 'es', 'page', 'pedido-1', '12-3', 'p12-3', 'a/b'] as $candidate) {
        if (preg_match('#^(?:' . $regex . ')$#u', $candidate) === 1) {
            return $candidate;
        }
    }

    return 'x';
}

test('todas las rutas con nombre de todos los módulos resuelven igual con la tabla compilada y sin ella', function (): void {
    // Todos los módulos que cargan (uno a medio escribir que no carga no deja caché: eso se prueba en BootCacheTest).
    $probe = testApp(['modules' => '*']);
    $probe->boot();
    $app = testApp(['modules' => $probe->modules()->loaded()]);
    perfGet($app, '/');
    same([], $app->modules()->failed(), 'todos cargan');
    $boot = Cache::import($app->site()->path('cache') . '/boot.php');
    check(is_array($boot), 'hay caché de arranque');
    $next = perfNext($app);
    perfGet($next, '/');
    same(0, $next->modules()->scans(), 'la segunda usa la tabla compilada');
    same(perfRoutes($app), perfRoutes($next), 'la misma tabla, ruta a ruta');

    $named = 0;
    foreach ($app->router()->all() as $route) {
        if ($route->name === '') {
            continue;
        }
        ++$named;
        $params = [];
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+(?:\{[^{}]*\}[^{}]*)*))?\}/', $route->pattern, $m, PREG_SET_ORDER);
        foreach ($m as $p) {
            $params[$p[1]] = routesSample(isset($p[2]) && $p[2] !== '' ? $p[2] : '[^/]+');
        }
        $path = $app->router()->path($route->name, $params);
        same($path, $next->router()->path($route->name, $params), 'dirección de ' . $route->name);
        $method = $route->methods[0];
        $clean = (string) parse_url($path, PHP_URL_PATH);
        try {
            $a = $app->router()->match($method, $clean);
        } catch (HttpError $e) {
            $a = 'http ' . $e->status();
        }
        try {
            $b = $next->router()->match($method, $clean);
        } catch (HttpError $e) {
            $b = 'http ' . $e->status();
        }
        $flat = static function ($match): string {
            return is_array($match) ? json_encode([$match['route']->name, $match['route']->handler, $match['route']->middleware, $match['params']]) : (string) json_encode($match);
        };
        same($flat($a), $flat($b), 'resolución de ' . $method . ' ' . $clean);
    }
    check($named > 100, 'se han recorrido todas las rutas con nombre (' . $named . ')');
});

test('cambiar urls.cart rehace la tabla de rutas', function (): void {
    $app = testApp(['root' => perfRoot()]);
    same(200, perfGet($app, '/carrito')->status);
    $next = perfNext($app);
    same(200, perfGet($next, '/carrito')->status, 'con la tabla compilada');
    $next->settings()->set('urls.cart', 'cesta');
    $after = perfNext($next);
    same(200, perfGet($after, '/cesta')->status, 'la dirección nueva');
    same(404, perfGet(perfNext($next), '/carrito')->status, 'la vieja ya no');
    same('/cesta', $after->router()->path('alfa.cart'));
});

test('cambiar urls.cart por la consola (setting:set) también rehace la tabla', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/carrito');
    $cli = perfNext($app, ['sapi' => 'cli']);
    $cli->boot();
    // La instalación de mentira no trae core/cli.php: se usa la orden del núcleo de verdad.
    $core = require \Aifec\Autoload::root() . '/core/cli.php';
    check(is_array($core) && isset($core['setting:set']), 'la orden existe');
    same(0, $core['setting:set']['run']($cli, ['urls.cart', 'mi-cesta'], new \Aifec\Cli\Console(false)));
    same(200, perfGet(perfNext($app), '/mi-cesta')->status);
    same(404, perfGet(perfNext($app), '/carrito')->status);
});

test('una ruta con función anónima no se compila y sigue funcionando con la caché', function (): void {
    $app = testApp(['root' => perfRoot(), 'settings' => ['modules.active' => ['alfa', 'beta']]]);
    same('BETA anónima', perfGet($app, '/beta')->body);
    $boot = Cache::import($app->site()->path('cache') . '/boot.php');
    same('live', $boot['modules']['beta']['routes'], 'beta se vuelve a registrar ejecutando su routes.php');
    check(is_array($boot['modules']['alfa']['routes']), 'alfa sí está compilado');
    $next = perfNext($app);
    same('BETA anónima', perfGet($next, '/beta')->body, 'con la caché, la ruta anónima funciona');
    same('/beta', $next->router()->path('beta.home'));
    same(perfRoutes($app), perfRoutes($next));
});

test('una ruta exportada y vuelta a importar es la misma: patrón, expresión, nombre, intermediarios y módulo', function (): void {
    $router = new Router();
    $router->setModule('demo');
    $router->group(['prefix' => '/zona', 'middleware' => ['csrf']], static function (Router $r): void {
        $r->get('/ficha/{id:\d+}/{slug}', ['Demo\\Ficha', 'show'])->name('demo.ficha')->middleware('api');
    });
    $route = $router->all()[0];
    check($route->compilable(), 'un manejador [Clase, método] se compila');
    $copy = Route::import($route->export());
    same($route->methods, $copy->methods);
    same($route->pattern, $copy->pattern);
    same($route->regex, $copy->regex);
    same($route->name, $copy->name);
    same(['csrf', 'api'], $copy->middleware);
    same('demo', $copy->module);
    $other = new Router();
    $other->import([$route->export()]);
    same(['id' => '12', 'slug' => 'silla-ñandú'], $other->match('GET', '/zona/ficha/12/silla-ñandú')['params']);
    same('/zona/ficha/5/a%20b', $other->path('demo.ficha', ['id' => 5, 'slug' => 'a b']));
    $closure = new Route(['GET'], '/x', static function (): string {
        return 'x';
    });
    check(!$closure->compilable(), 'una función anónima no');
    throws(static function () use ($closure): void {
        $closure->export();
    }, \LogicException::class);
});

test('grabar e importar respeta el orden: una ruta fija repetida la gana la última, como al ejecutar routes.php', function (): void {
    $live = new Router();
    $live->record();
    $live->get('/a', ['X', 'uno'])->name('a1');
    $live->get('/a', ['X', 'dos'])->name('a2');
    $live->get('/p/{id}', ['X', 'tres']);
    $live->get('/p/{id:\d+}', ['X', 'cuatro']);
    $rows = array_map(static function (Route $r): array {
        return $r->export();
    }, $live->recorded());
    same(4, count($rows));
    same([], $live->recorded(), 'recorded() deja de grabar');
    $compiled = new Router();
    $compiled->import($rows);
    same(['X', 'dos'], $compiled->match('GET', '/a')['route']->handler, 'la última gana');
    same(['X', 'tres'], $compiled->match('GET', '/p/9')['route']->handler, 'las de patrón, por orden de alta');
    same($live->path('a2'), $compiled->path('a2'));
    same($live->has('a1'), $compiled->has('a1'), 'la que quedó tapada tampoco tiene nombre en ninguna de las dos');
    throws(static function () use ($compiled): void {
        $compiled->match('POST', '/a');
    }, HttpError::class, 'otro método sigue dando 405');
});

test('la tabla compilada no se lleva lo que un módulo registra al vuelo después del arranque', function (): void {
    $app = testApp(['root' => perfRoot()]);
    perfGet($app, '/alfa/1');
    $app->router()->get('/solo-en-esta', static function (): string {
        return 'efímera';
    });
    same('efímera', perfGet($app, '/solo-en-esta')->body);
    same(404, perfGet(perfNext($app), '/solo-en-esta')->status, 'la siguiente petición no la hereda');
});
