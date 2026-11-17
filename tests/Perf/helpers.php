<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * Ayudantes de las pruebas de rendimiento del núcleo (caché de arranque, ajustes, idiomas, rutas, caché de página y
 * bloques). Los cargan con require_once las pruebas de tests/Kernel y tests/Perf.
 */

use Aifec\Autoload;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

if (!function_exists('perfNext')) {
    /**
     * La «petición siguiente» del mismo sitio en otro proceso: una aplicación nueva sobre los mismos datos. Por defecto
     * como una página de la tienda servida por PHP-FPM (se fía de las cachés del arranque); con 'sapi' => 'cli', como la
     * consola o el cron (las comprueba).
     *
     * @param array<string,mixed> $options se funden con las de la aplicación
     */
    function perfNext(App $base, array $options = []): App
    {
        $only = $base->modules()->onlyIds();
        $defaults = [
            'clock' => $base->clock(),
            'http' => $base->http(),
            'log_files' => false,
            'public_dir' => $base->publicDir(),
            'sapi' => 'fpm-fcgi',
        ];
        if ($only !== null) {
            $defaults['modules'] = $only;
        }
        $app = new App($base->site(), $base->root(), $options + $defaults);
        $app->hooks()->onError(static function (string $module, string $hook, \Throwable $e) use ($app): void {
            AifecTestEnv::$hookErrors[] = 'gancho «' . $hook . '» (' . $module . '): ' . get_class($e) . ': ' . $e->getMessage();
            $app->log()->exception($e, 'hook ' . $hook . ' (' . $module . ')');
        });
        $app->makeCurrent();

        return $app;
    }

    /**
     * Una petición GET a una aplicación, con cookies y cabeceras, como las manda un navegador.
     *
     * @param array<string,string> $cookies
     * @param array<string,string> $headers
     */
    function perfGet(App $app, string $uri, array $cookies = [], array $headers = []): Response
    {
        $request = Request::create('GET', $uri, [], $cookies, $headers + ['sec-fetch-site' => 'same-origin', 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0) AifecTest/1.0']);
        $request->host = 'tienda.test';
        $request->secure = true;

        return $app->handle($request);
    }

    /** Escribe un fichero creando sus carpetas. */
    function perfPut(string $file, string $content): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        clearstatcache();
    }

    /**
     * Instalación de mentira con lo justo: el núcleo (sus migraciones), un módulo «alfa» con ajustes, idioma, rutas
     * compiladas y una con función anónima, un módulo «beta» apagado de serie y un tema.
     */
    function perfRoot(): string
    {
        $r = tmpDir('aifec-perf-root');
        perfPut($r . '/core/migrations/0001_system.php', (string) file_get_contents(Autoload::root() . '/core/migrations/0001_system.php'));
        perfPut($r . '/core/lang/es.php', "<?php\nreturn ['Hello' => 'Hola'];\n");
        perfPut($r . '/core/views/error.php', '<?php ?>ERROR <?= (int) $status ?>');

        perfPut($r . '/modules/alfa/module.json', '{"name":"Alfa","version":"1.0.0","essential":true}');
        perfPut($r . '/modules/alfa/lang/es.php', "<?php\nreturn ['Cart' => 'Carrito', 'Ñandú & cía' => 'Ñandú y compañía'];\n");
        perfPut($r . '/modules/alfa/settings.php', "<?php\nreturn ['tabs' => ['algeneral' => ['label' => 'General', 'fields' => ['alfa.color' => ['type' => 'text', 'default' => 'verde'], 'alfa.tope' => ['type' => 'number', 'default' => 7]]]]];\n");
        perfPut($r . '/modules/alfa/src/AlfaController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Aifec\Modules\Alfa;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
final class AlfaController
{
    private $app;
    public function __construct(App $app) { $this->app = $app; }
    public function show(Request $r, array $p): Response
    {
        return Response::html('ALFA ' . (isset($p['id']) ? $p['id'] : '-') . ' ' . t('Cart') . ' ' . $this->app->settings()->str('alfa.color'));
    }
    public function page(Request $r, array $p): Response
    {
        return Response::html('PAGINA')->cacheable(60, ['alfa']);
    }
}
PHP
        );
        perfPut($r . '/modules/alfa/routes.php', <<<'PHP'
<?php
use Aifec\Kernel\App;
use Aifec\Kernel\Router;
return static function (Router $r, App $app): void {
    $cart = '/' . trim($app->settings()->str('urls.cart', 'carrito'), '/');
    $r->get($cart, ['Aifec\Modules\Alfa\AlfaController', 'show'])->name('alfa.cart');
    $r->get('/alfa/{id:\d+}', ['Aifec\Modules\Alfa\AlfaController', 'show'])->name('alfa.show');
    $r->get('/alfa/pagina', ['Aifec\Modules\Alfa\AlfaController', 'page'])->name('alfa.page');
    $r->group(['prefix' => '/zona', 'middleware' => ['csrf']], static function (Router $r): void {
        $r->post('/enviar', ['Aifec\Modules\Alfa\AlfaController', 'show'])->name('alfa.send');
    });
};
PHP
        );
        perfPut($r . '/modules/alfa/hooks.php', "<?php\nreturn static function (\\Aifec\\Kernel\\App \$app): void { \$app->bind('alfa', static function () { return new \\ArrayObject(['ok' => true]); }); };\n");

        perfPut($r . '/modules/beta/module.json', '{"name":"Beta","version":"1.0.0","default":false}');
        perfPut($r . '/modules/beta/routes.php', <<<'PHP'
<?php
use Aifec\Kernel\App;
use Aifec\Kernel\Router;
return static function (Router $r, App $app): void {
    $r->get('/beta', static function (): string { return 'BETA anónima'; })->name('beta.home');
    $r->get('/beta/flash', static function ($request, App $app): string {
        $app->session('customer')->flash('ok', 'Guardado');
        return 'flash puesto';
    });
    $r->get('/beta/leer', static function ($request, App $app): string {
        return 'flash=' . (string) json_encode($app->session('customer')->pullFlash());
    });
};
PHP
        );

        perfPut($r . '/themes/base/theme.json', '{"name":"Base","version":"1.2.3","settings":{"color":"azul"}}');
        perfPut($r . '/themes/base/templates/error.php', '<?php ?>TEMA <?= (int) $status ?>');
        perfPut($r . '/themes/base/lang/es.php', "<?php\nreturn ['Hello' => 'Hola, tema'];\n");

        return $r;
    }

    /**
     * Las rutas de un enrutador como datos comparables: [métodos, patrón, manejador, nombre, intermediarios, módulo, regex].
     *
     * @return array<int,array<int,mixed>>
     */
    function perfRoutes(App $app): array
    {
        $out = [];
        foreach ($app->router()->all() as $route) {
            $out[] = [$route->methods, $route->pattern, is_array($route->handler) ? $route->handler : 'closure', $route->name, $route->middleware, $route->module, $route->regex];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) json_encode($a), (string) json_encode($b));
        });

        return $out;
    }

    /** Cookie de un sitio por su nombre corto: perfCookie($app, 'nc') → «af_nc_…». */
    function perfCookie(App $app, string $short): string
    {
        return $app->cookieName($short);
    }
}
