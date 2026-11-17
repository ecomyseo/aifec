<?php
/**
 * AIFEC — pruebas del núcleo de punta a punta sobre una instalación de mentira (módulo, plugins y temas de prueba).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/** Escribe un fichero creando sus carpetas. */
function aifecPut(string $file, string $content): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, $content);
}

/** Instalación de mentira: un módulo esencial, un plugin sano, uno roto y un tema con hijo. */
function aifecFakeRoot(): string
{
    $r = tmpDir('aifec-root');
    aifecPut($r . '/core/migrations/0001_system.php', (string) file_get_contents(Autoload::root() . '/core/migrations/0001_system.php'));
    aifecPut($r . '/core/views/error.php', '<?php ?>NUCLEO <?= (int) $status ?> <?= e($message) ?>');
    aifecPut($r . '/core/lang/es.php', "<?php\nreturn ['Hello {name}' => 'Hola {name}', '{n} item|{n} items' => '{n} artículo|{n} artículos'];\n");

    aifecPut($r . '/modules/demo/module.json', '{"name":"Demo","version":"1.0.0","essential":true,"contract":{"capabilities":["pricing"]}}');
    aifecPut($r . '/modules/demo/lang/es.php', "<?php\nreturn ['Cart' => 'Carrito'];\n");
    aifecPut($r . '/modules/demo/settings.php', "<?php\nreturn ['tabs' => ['dmgeneral' => ['label' => 'General', 'fields' => ['demo.color' => ['type' => 'text', 'default' => 'verde']]]]];\n");
    aifecPut($r . '/modules/demo/views/hello.php', '<?php ?>MODULO <?= e($who) ?>');
    aifecPut($r . '/modules/demo/src/HelloController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Aifec\Modules\Demo;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
final class HelloController
{
    private $app;
    public function __construct(App $app) { $this->app = $app; }
    public function show(Request $r, array $p): Response
    {
        return $this->app->render('page', ['title' => t('Cart'), 'body' => 'id=' . $p['id'] . ' ' . t('Hello {name}', ['name' => 'Ana <b>']) . ' ' . tn('{n} item', '{n} items', 3)]);
    }
    public function entity(Request $r, array $p): Response
    {
        return Response::html('THING ' . $p['id'])->cacheable(60, ['thing:' . $p['id']]);
    }
}
PHP
    );
    aifecPut($r . '/modules/demo/routes.php', <<<'PHP'
<?php
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Kernel\Router;
return function (Router $r, App $app): void {
    $r->get('/', function (): string { return 'HOME lang=' . app()->lang() . ' url=' . url('/carrito', ['a' => 1]); })->name('home');
    $r->get('/hola/{id:\d+}', ['Aifec\Modules\Demo\HelloController', 'show'])->name('hello');
    $r->get('/modulo', function (Request $q, App $app): Response { return Response::html($app->view()->render('demo::hello', ['who' => 'yo'])); });
    $r->post('/guardar', function (Request $q, App $app): Response {
        $app->session('customer')->set('nota', $q->str('nota'));
        $app->session('customer')->flash('ok', 'guardado');
        return Response::redirect(url('/leer'));
    });
    $r->get('/leer', function (Request $q, App $app): array {
        $s = $app->session('customer');
        return ['nota' => $s->get('nota'), 'flash' => $s->pullFlash(), 'started' => $s->isStarted()];
    });
    $r->get('/salir', function (Request $q, App $app): string { $app->session('customer')->logout(); return 'fuera'; });
    $r->get('/revienta', function (): string { throw new \RuntimeException('secreto interno'); });
    $r->get('/prohibido', function (): string { throw HttpError::forbidden(); });
    $r->group(['prefix' => '/zona', 'middleware' => ['uno', 'dos']], function (Router $r): void {
        $r->get('/x', function (Request $q): string { return 'x:' . implode(',', (array) $q->attr('orden', [])); });
    });
    $r->get('/precio', function (Request $q, App $app): string { return 'precio=' . $app->hooks()->apply('pricing.total', 100) . ' ' . hook('pie'); });
};
PHP
    );
    aifecPut($r . '/modules/demo/hooks.php', <<<'PHP'
<?php
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
return function (App $app): void {
    $app->hooks()->seal('pricing.total', 'pricing');
    $app->hooks()->filter('pricing.total', function (int $v): int { return $v + 1; });
    $app->entity('thing', ['Aifec\Modules\Demo\HelloController', 'entity']);
    foreach (['uno', 'dos'] as $name) {
        $app->middleware($name, function (Request $q, App $app, callable $next) use ($name) {
            $q->setAttr('orden', array_merge((array) $q->attr('orden', []), [$name]));
            return $next($q);
        });
    }
};
PHP
    );

    aifecPut($r . '/plugins/roto/plugin.json', '{"name":"Roto","version":"0.1.0","default":true,"namespace":"Acme\\\\Roto"}');
    aifecPut($r . '/plugins/roto/hooks.php', "<?php\nthrow new \\RuntimeException('plugin roto al cargar');\n");

    aifecPut($r . '/plugins/extra/plugin.json', '{"name":"Extra","version":"0.1.0","default":true,"requires":{"modules":["demo"]},"contract":{"capabilities":["pricing"]}}');
    aifecPut($r . '/plugins/extra/hooks.php', <<<'PHP'
<?php
use Aifec\Kernel\App;
return function (App $app): void {
    $app->hooks()->filter('pricing.total', function (int $v): int { return 0; });   // sin permiso concedido: no entra
    $app->hooks()->slot('pie', function (): string { throw new \LogicException('oyente roto'); });
    $app->hooks()->slot('pie', function (): string { return '[extra]'; }, 20);
};
PHP
    );
    aifecPut($r . '/plugins/apagado/plugin.json', '{"name":"Apagado","version":"0.1.0"}');
    aifecPut($r . '/plugins/apagado/hooks.php', "<?php\nreturn function (\$app): void { \$app->hooks()->slot('pie', function (): string { return '[apagado]'; }); };\n");

    aifecPut($r . '/themes/base/theme.json', '{"name":"Base","settings":{"color":"azul","radius":4}}');
    aifecPut($r . '/themes/base/templates/layout.php', '<?php ?><html><title><?= e($title) ?></title><body><?= $this->section("content") ?><?= $this->stack("fin") ?></body></html>');
    aifecPut($r . '/themes/base/templates/page.php', '<?php $this->layout("layout", ["title" => $title]); $this->push("fin", "<i>pie</i>"); ?><main>BASE <?= e($body) ?></main>');
    aifecPut($r . '/themes/base/templates/error.php', '<?php ?>TEMA <?= (int) $status ?> <?= e($message) ?>');
    aifecPut($r . '/themes/hijo/theme.json', '{"name":"Hijo","parent":"base","settings":{"color":"rojo"}}');
    aifecPut($r . '/themes/hijo/templates/page.php', '<?php $this->layout("layout", ["title" => $title]); ?><main>HIJO <?= e($body) ?></main>');
    aifecPut($r . '/themes/hijo/templates/modules/demo/hello.php', '<?php ?>TEMA-SOBRE-MODULO <?= e($who) ?>');
    aifecPut($r . '/themes/hijo/lang/es.php', "<?php\nreturn ['Cart' => 'Cesta'];\n");

    return $r;
}

test('rutas, controladores, traducción y escapado', function (): void {
    $web = new TestClient(testApp(['root' => aifecFakeRoot()]));
    $r = $web->get('/hola/7');
    same(200, $r->status);
    contains('<title>Carrito</title>', $r->body);
    contains('BASE id=7 Hola Ana &lt;b&gt; 3 artículos', $r->body, 'los datos salen escapados y traducidos');
    contains('<i>pie</i></body>', $r->body, 'lo apilado en la plantilla sale en el envoltorio');
    same('nosniff', $r->headers['X-Content-Type-Options']);
    check(isset($r->headers['Server-Timing']), 'toda respuesta lleva su tiempo de servidor');
    same(404, $web->get('/hola/abc')->status, 'el parámetro no cumple su patrón');
    same(200, $web->send('HEAD', '/hola/7')->status);
    same(405, $web->post('/hola/7')->status);
});

test('página no encontrada: plantilla del tema, y JSON si se pide JSON', function (): void {
    $web = new TestClient(testApp(['root' => aifecFakeRoot()]));
    $r = $web->get('/no-existe');
    same(404, $r->status);
    contains('TEMA 404', $r->body);
    $r = $web->get('/no-existe', ['accept' => 'application/json']);
    same(404, $r->status);
    same(404, $web->json()['error']['status']);
    contains('no-store', $r->headers['Cache-Control']);
    same(403, $web->get('/prohibido')->status);
});

test('una excepción da un 500 sin tumbar nada, y sin detalles si no hay depuración', function (): void {
    $root = aifecFakeRoot();
    $web = new TestClient(testApp(['root' => $root]));
    $r = $web->get('/revienta');
    same(500, $r->status);
    contains('secreto interno', $r->body, 'en depuración se ve el motivo');
    check(count($web->app->log()->records) > 0, 'queda apuntado');
    $web2 = new TestClient(testApp(['root' => $root, 'config' => ['debug' => false]]));
    $r = $web2->get('/revienta');
    same(500, $r->status);
    notContains('secreto interno', $r->body, 'sin depuración no se filtra el mensaje');
    same(200, $web2->get('/hola/1')->status, 'la petición siguiente funciona');
});

test('intermediarios en orden y grupos de rutas', function (): void {
    $web = new TestClient(testApp(['root' => aifecFakeRoot()]));
    same('x:uno,dos', $web->get('/zona/x')->body);
});

test('un plugin roto no tumba el sitio; un oyente roto no corta a los demás', function (): void {
    expectHookErrors(); // el plugin «extra» de este sitio de mentira trae un oyente que lanza a propósito
    $app = testApp(['root' => aifecFakeRoot()]);
    $web = new TestClient($app);
    $r = $web->get('/precio');
    same(200, $r->status);
    contains('[extra]', $r->body, 'el oyente sano pinta aunque el anterior lance');
    notContains('[apagado]', $r->body, 'un plugin sin activar no carga');
    check(isset($app->modules()->failed()['roto']), 'el plugin roto queda señalado');
    same(['demo', 'extra'], $app->modules()->loaded());
    $errors = array_map(static function (array $e): string {
        return $e['module'] . '|' . $e['hook'];
    }, $app->hooks()->errors());
    check(in_array('extra|pie', $errors, true), 'el fallo del oyente queda apuntado con su módulo');
});

test('gancho sellado: sin la capacidad concedida, un plugin no puede tocar el total', function (): void {
    expectHookErrors(); // el plugin «extra» de este sitio de mentira trae un oyente que lanza a propósito
    $root = aifecFakeRoot();
    $web = new TestClient(testApp(['root' => $root]));
    contains('precio=101', $web->get('/precio')->body, 'solo actúa el filtro del módulo; el del plugin no entra');
    // Concedida la capacidad por el comerciante, sí entra.
    $web = new TestClient(testApp(['root' => $root, 'settings' => ['plugins.granted' => ['extra:pricing']]]));
    contains('precio=0', $web->get('/precio')->body);
});

test('temas: el hijo sustituye plantillas, hereda el resto y puede sustituir las de un módulo', function (): void {
    $root = aifecFakeRoot();
    $base = new TestClient(testApp(['root' => $root]));
    contains('MODULO yo', $base->get('/modulo')->body);
    $app = testApp(['root' => $root, 'settings' => ['theme.active' => 'hijo']]);
    $web = new TestClient($app);
    $r = $web->get('/hola/1');
    contains('HIJO id=1', $r->body);
    contains('<title>Cesta</title>', $r->body, 'el catálogo del tema manda sobre el del módulo');
    contains('TEMA-SOBRE-MODULO yo', $web->get('/modulo')->body);
    same(['hijo', 'base'], $app->theme()['chain']);
    same('rojo', $app->theme()['settings']['color'], 'lo del hijo manda');
    same(4, $app->theme()['settings']['radius'], 'lo que no declara se hereda');
    same('verde', $app->settings()->get('demo.color'), 'valor por defecto declarado por el módulo');
});

test('idiomas y subcarpeta: las direcciones llevan su prefijo', function (): void {
    $root = aifecFakeRoot();
    $web = new TestClient(testApp(['root' => $root, 'base_path' => '/demos/moda', 'settings' => ['i18n.languages' => ['es', 'en']]]));
    same('HOME lang=es url=/demos/moda/carrito?a=1', $web->get('/')->body);
    same('HOME lang=en url=/demos/moda/en/carrito?a=1', $web->get('/en')->body);
    contains('Hello Ana', $web->get('/en/hola/2')->body, 'en inglés se enseña el texto fuente');
    same('/demos/moda/hola/9', $web->app->url()->route('hello', ['id' => 9], 'es'));
    same('https://tienda.test/demos/moda/x', $web->app->url()->abs('/x', [], 'es'));
});

test('direcciones amigables: canónica, antigua que redirige, repetida y barra final', function (): void {
    $app = testApp(['root' => aifecFakeRoot()]);
    $web = new TestClient($app);
    $app->boot();
    same('cosa/silla', $app->slugs()->set('thing', 5, 'es', '/cosa/silla/'));
    same('cosa/silla-2', $app->slugs()->set('thing', 6, 'es', 'cosa/silla'), 'otra entidad con la misma ruta recibe sufijo');
    same('THING 5', $web->get('/cosa/silla')->body);
    same('cosa/silla-ergo', $app->slugs()->set('thing', 5, 'es', 'cosa/silla-ergo'));
    $r = $web->get('/cosa/silla?utm=1');
    same(301, $r->status, 'la ruta antigua redirige');
    same('/cosa/silla-ergo?utm=1', $r->headers['Location']);
    same('THING 5', $web->follow()->body);
    same('/cosa/silla-ergo', $app->url()->entity('thing', 5));
    $app->settings()->set('urls.trailing_slash', true);
    same('/cosa/silla-ergo/', $app->url()->entity('thing', 5));
    same(301, $web->get('/cosa/silla-ergo')->status, 'con barra final activada, sin barra redirige');
    same(200, $web->get('/cosa/silla-ergo/')->status);
    $app->slugs()->remove('thing', 5);
    same(404, $web->get('/cosa/silla-ergo/')->status);
});

test('sesión: no nace sola, persiste con su cookie, lleva el sitio en el nombre y se cierra', function (): void {
    $root = aifecFakeRoot();
    $app = testApp(['root' => $root, 'base_path' => '/demos/moda']);
    $web = new TestClient($app);
    $web->get('/leer');
    same([], $web->cookies, 'solo leer no crea sesión ni cookie');
    same(false, $web->json()['started']);
    $r = $web->post('/guardar', ['nota' => 'Año & ñ']);
    same(302, $r->status);
    $name = $app->cookieName('c');
    check(isset($web->cookies[$name]), 'al escribir nace la cookie');
    same('/demos/moda/', $r->cookies[$name]['options']['path']);
    same(true, $r->cookies[$name]['options']['httponly']);
    $web->follow();
    same('Año & ñ', $web->json()['nota']);
    same(['ok' => 'guardado'], $web->json()['flash']);
    $web->get('/leer');
    same([], $web->json()['flash'], 'el aviso de un solo uso no se repite');
    $token = $web->cookies[$name];
    same(null, $app->db()->value('SELECT id FROM {sessions} WHERE id = ?', [$token]), 'en la base no está la ficha en claro');
    $web->get('/salir');
    check(!isset($web->cookies[$name]), 'al salir se borra la cookie');
    $web->cookies[$name] = $token;
    $web->get('/leer');
    same(null, $web->json()['nota'], 'la ficha vieja ya no vale');

    $otro = testApp(['root' => $root, 'config' => ['url' => 'https://tienda.test']]);
    check($otro->cookieName('c') === $app->cookieName('c'), 'mismo identificador de sitio, misma cookie');
});

test('las migraciones se aplican una sola vez', function (): void {
    $app = testApp(['root' => aifecFakeRoot()]);
    $app->boot();
    same([], $app->migrate(false), 'ya está al día: la huella coincide');
    same([], $app->migrate(true), 'forzando tampoco se repite ninguna');
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {roles}'));
});

test('las pruebas ven lo que lanza un oyente: en producción solo se apunta, aquí se guarda para hacer fallar la prueba', function (): void {
    expectHookErrors();
    $app = testApp();
    $app->boot();
    $ran = 0;
    $app->hooks()->on('prueba.accion', static function (): void {
        throw new \RuntimeException('oyente roto de prueba');
    });
    $app->hooks()->on('prueba.accion', static function () use (&$ran): void {
        ++$ran;
    });
    $app->hooks()->filter('prueba.filtro', static function ($value) {
        throw new \LogicException('filtro roto de prueba');
    });
    $app->hooks()->fire('prueba.accion');
    same(1, $ran, 'el oyente roto no corta a los demás');
    same('intacto', $app->hooks()->apply('prueba.filtro', 'intacto'), 'un filtro que lanza deja el valor como estaba');
    same(2, count(AifecTestEnv::$hookErrors), 'los dos errores quedan a la vista del corredor de pruebas');
    contains('prueba.accion', AifecTestEnv::$hookErrors[0]);
    contains('oyente roto de prueba', AifecTestEnv::$hookErrors[0]);
    contains('filtro roto de prueba', AifecTestEnv::$hookErrors[1]);
});

test('una página de la tienda no recorre las migraciones si el esquema ya se comprobó: lo hacen el panel, la consola y el cron', function (): void {
    $root = aifecFakeRoot();
    $first = testApp(['root' => $root]);
    // Sin la marca (sitio recién creado), toca siempre: también en una visita a la tienda.
    $web = new \Aifec\Kernel\App($first->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false, 'sapi' => 'fpm-fcgi']);
    same(true, $web->schemaCheckDue(false), 'sin marca, siempre');
    $web->handle(Request::create('GET', '/'));
    check(in_array('core/0001_system', $web->bootMigrations(), true), 'la primera visita de un sitio nuevo aplica las migraciones');
    check(is_file($web->site()->path('cache') . '/schema.txt'), 'y deja la marca');

    // Con la marca: la tienda no mira; el panel y la consola, sí.
    $shop = new \Aifec\Kernel\App($first->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false, 'sapi' => 'fpm-fcgi']);
    same(false, $shop->schemaCheckDue(true), 'una página de la tienda, no');
    $shop->handle(Request::create('GET', '/producto/silla'));
    same(false, $shop->schemaCheckDue(true));
    $shop->handle(Request::create('GET', '/admin/login'));
    same(true, $shop->schemaCheckDue(true), 'el panel, sí');
    $shop->handle(Request::create('GET', '/admin'));
    same(true, $shop->schemaCheckDue(true));
    $shop->handle(Request::create('GET', '/administracion-de-fincas'));
    same(false, $shop->schemaCheckDue(true), 'una dirección que solo empieza igual no es el panel');
    $cli = new \Aifec\Kernel\App($first->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false]);
    same(true, $cli->schemaCheckDue(true), 'la consola (y el cron, y las pruebas), siempre');

    // Y de verdad no mira: con la marca manipulada, la tienda la da por buena y el panel la corrige.
    $stamp = $shop->site()->path('cache') . '/schema.txt';
    file_put_contents($stamp, 'huella-vieja');
    $visit = new \Aifec\Kernel\App($first->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false, 'sapi' => 'fpm-fcgi']);
    $visit->handle(Request::create('GET', '/'));
    same('huella-vieja', (string) file_get_contents($stamp), 'la tienda no ha recalculado la huella');
    $panel = new \Aifec\Kernel\App($first->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false, 'sapi' => 'fpm-fcgi']);
    $panel->handle(Request::create('GET', '/admin/login'));
    check((string) file_get_contents($stamp) !== 'huella-vieja', 'el panel sí, y deja la marca al día');
    // Forzando (la orden migrate, activar un módulo) se mira siempre.
    file_put_contents($stamp, 'huella-vieja');
    $visit->migrate(true);
    check((string) file_get_contents($stamp) !== 'huella-vieja', 'migrate(true) no se salta nunca');
});

test('la orden migrate cuenta lo que aplicó el arranque, y cuando no hay nada lo dice', function (): void {
    $root = aifecFakeRoot();
    $app = testApp(['root' => $root]);
    $app->boot();
    check(in_array('core/0001_system', $app->bootMigrations(), true), 'el arranque de un sitio nuevo aplica las del núcleo');
    $io = new \Aifec\Cli\Console(false);
    $commands = $io->commands($app);
    same(0, $commands['migrate']['run']($app, [], $io));
    contains('Applied: ', $io->output);
    contains('core/0001_system', $io->output, 'no dice «nada que migrar» justo después de aplicarlas al arrancar');
    notContains('Nothing to migrate', $io->output);

    // Un arranque posterior sobre el mismo sitio ya no aplica nada, y la orden lo dice.
    $again = new \Aifec\Kernel\App($app->site(), $root, ['clock' => new \Aifec\Kernel\Clock(testNow()), 'log_files' => false]);
    $again->boot();
    same([], $again->bootMigrations());
    $io = new \Aifec\Cli\Console(false);
    same(0, $io->commands($again)['migrate']['run']($again, [], $io));
    contains('Nothing to migrate.', $io->output);
});

test('permisos: comodines por área y sin sesión nunca', function (): void {
    $app = testApp(['root' => aifecFakeRoot()]);
    $app->boot();
    same(false, $app->auth()->can('catalog.edit'));
    $id = $app->db()->insert('users', ['email' => 'ana@x.es', 'name' => 'Ana', 'password' => 'x', 'role' => 'editor', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $app->handle(Request::create('GET', '/'));
    $app->session('admin')->login($id);
    $app->auth()->reset();
    same(true, $app->auth()->can('content.pages'), 'content.* cubre content.pages');
    same(true, $app->auth()->can('dashboard.view'));
    same(false, $app->auth()->can('orders.view'));
    same(false, $app->auth()->can('settings.edit'));
    throws(function () use ($app): void {
        $app->auth()->requireCap('orders.view');
    }, \Aifec\Kernel\HttpError::class);
});
