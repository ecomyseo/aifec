<?php
/**
 * AIFEC — pruebas del modo mantenimiento: 503 para las visitas, paso para el panel y su pase, avisos de pago, API y caché de página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Tools\Maintenance;

require_once __DIR__ . '/helpers.php';

/** Una tienda con algunas rutas de pruebas y el mantenimiento puesto o quitado. */
function toolsShop(bool $maintenance = true, array $settings = []): App
{
    $app = toolsApp(['tools'], $settings + ['tools.maintenance' => $maintenance, 'i18n.languages' => ['es', 'en'],
        'tools.maintenance_text_es' => 'Volvemos a las 18:00 & seguimos', 'tools.maintenance_text_en' => 'Back at 6 pm']);
    $app->router()->get('/hola', static function ($request, App $a): Response {
        return Response::html('<!doctype html><html><head><title>Hola</title></head><body class="home"><p>Hola desde la tienda</p></body></html>')->cacheable(600);
    });
    $app->router()->post('/pago/aviso', static function ($request, App $a): Response {
        return Response::json(['ok' => true]);
    });
    $app->router()->get('/cron/clave', static function ($request, App $a): Response {
        return Response::json(['cron' => true]);
    });

    return $app;
}

test('con el mantenimiento puesto la tienda da 503 con Retry-After, sin indexar y con el mensaje escapado', function (): void {
    $app = toolsShop();
    $web = new TestClient($app);
    $r = $web->get('/hola');
    same(503, $r->status);
    same('3600', $r->headers['Retry-After']);
    contains('noindex', $r->headers['X-Robots-Tag']);
    contains('no-store', $r->headers['Cache-Control']);
    contains('<meta name="robots" content="noindex, nofollow">', $r->body);
    contains('Volvemos a las 18:00 &amp; seguimos', $r->body);
    notContains('Hola desde la tienda', $r->body);
    contains('Volvemos enseguida', $r->body);
    same(503, $web->get('/una/pagina/que/no/existe')->status, 'también lo que no existe');
    same(503, $web->post('/hola', ['a' => 'b'])->status, 'y los envíos');
    check(!isset($r->headers['X-Cache']), 'una página de mantenimiento nunca se guarda');
});

test('el mensaje sale en el idioma de la visita y, si no hay, en el de serie', function (): void {
    $app = toolsShop();
    $web = new TestClient($app);
    contains('Back at 6 pm', $web->get('/en/hola')->body);
    contains('Volvemos a las 18:00', $web->get('/hola')->body);

    $plain = toolsShop(true, ['tools.maintenance_text_es' => '', 'tools.maintenance_text_en' => '']);
    $body = (new TestClient($plain))->get('/hola')->body;
    contains('Estamos haciendo tareas de mantenimiento', $body);
    $app->makeCurrent();
});

test('el panel entra con el mantenimiento puesto y quien tiene sesión del panel ve la tienda con una banda de aviso', function (): void {
    $app = toolsShop();
    $web = adminClient($app);
    same(200, $web->get('/admin')->status);

    $r = $web->get('/hola');
    same(200, $r->status);
    contains('Hola desde la tienda', $r->body);
    contains('role="status"', $r->body);
    contains('El modo mantenimiento está puesto', $r->body);
    check(strpos($r->body, '<body class="home"><div role="status"') !== false, 'la banda va justo después de abrir el cuerpo');
    check(!isset($r->headers['X-Cache']), 'esa página no se guarda en la caché: la vería todo el mundo');
    same(0, $r->cacheTtl);

    // Y un visitante, después, sigue viendo el 503 y no la banda.
    $visitor = (new TestClient($app))->get('/hola');
    same(503, $visitor->status);
    notContains('role="status"', $visitor->body);
});

test('el pase firmado deja ver la tienda aunque la cookie de sesión del panel no viaje a ella, y caduca', function (): void {
    $app = toolsShop();
    $admin = adminClient($app);
    $name = $app->cookieName('m');
    check(isset($admin->cookies[$name]), 'entrar al panel con el mantenimiento puesto da el pase');

    $shop = new TestClient($app);
    $shop->cookies = [$name => $admin->cookies[$name]];
    same(200, $shop->get('/hola')->status, 'solo con el pase');
    contains('role="status"', $shop->get('/hola')->body);

    $forged = new TestClient($app);
    $forged->cookies = [$name => 'eyJlIjo5OTk5OTk5OTk5fQ.firma-falsa'];
    same(503, $forged->get('/hola')->status);
    $garbage = new TestClient($app);
    $garbage->cookies = [$name => 'basura'];
    same(503, $garbage->get('/hola')->status);

    $app->clock()->advance(43200 + 1);
    same(503, $shop->get('/hola')->status, 'a las 12 horas el pase caduca');
});

test('los avisos de las pasarelas de pago y la dirección web de cron siguen entrando', function (): void {
    $app = toolsShop();
    $web = new TestClient($app);
    $r = $web->post('/pago/aviso', ['x' => 'y']);
    same(200, $r->status);
    same(['ok' => true], $web->json());
    same(200, $web->get('/cron/clave')->status);
    same(503, $web->get('/pagos-falsos/aviso')->status, 'solo la ruta de pago de los ajustes');

    $app->settings()->set('urls.payment', 'cobro');
    $app->router()->post('/cobro/aviso', static function ($request, App $a): Response {
        return Response::json(['ok' => 'cobro']);
    });
    same(200, $web->post('/cobro/aviso')->status, 'la ruta de pago se lee de los ajustes');
    same(503, $web->post('/pago/aviso')->status);
});

test('la API y el MCP contestan 503 en JSON, y también cualquier petición que pide JSON', function (): void {
    $app = toolsShop();
    $web = new TestClient($app);
    foreach (['/api/v1/products', '/api/openapi.json', '/mcp'] as $path) {
        $r = $path === '/mcp' ? $web->postJson($path, ['jsonrpc' => '2.0', 'method' => 'initialize']) : $web->get($path);
        same(503, $r->status, $path);
        contains('application/json', $r->contentType());
        same('3600', $r->headers['Retry-After']);
        $data = $web->json();
        same(503, $data['error']['status']);
        contains('Volvemos a las 18:00 & seguimos', $data['error']['message']);
    }
    $r = $web->get('/hola', ['accept' => 'application/json']);
    same(503, $r->status);
    contains('application/json', $r->contentType());
});

test('al apagar el mantenimiento la tienda vuelve a dar 200', function (): void {
    $app = toolsShop();
    $web = new TestClient($app);
    same(503, $web->get('/hola')->status);
    $app->settings()->set('tools.maintenance', false);
    same(200, $web->get('/hola')->status);
    contains('Hola desde la tienda', $web->last->body);
    notContains('role="status"', $web->last->body);

    $off = toolsShop(false);
    same(200, (new TestClient($off))->get('/hola')->status, 'apagado desde el principio');
    $app->makeCurrent();
});

test('el botón del panel enciende y apaga el mantenimiento, lo audita y tira la caché de páginas', function (): void {
    $app = toolsShop(false);
    $web = adminClient($app);
    $site = $app->site();

    // Una página guardada en la caché de páginas se sirve sin arrancar la aplicación: es lo que se salta el mantenimiento.
    $request = Request::create('GET', '/hola');
    $key = PageCache::key($site, $request);
    check($key !== null, 'la petición se puede guardar');
    $app->cache()->set($key, ['b' => '<html><body>copia vieja</body></html>', 'h' => ['Content-Type' => 'text/html; charset=UTF-8']], 600, ['pages']);
    check(PageCache::serve($site, $request, $app->clock()) !== null, 'la copia se sirve');
    $version = $app->cache()->tagVersion('pages');

    $r = $web->post('/admin/tools/cache/maintenance', ['state' => 'on', '_token' => aifecToken($web)]);
    same(302, $r->status);
    same(true, $app->settings()->bool('tools.maintenance'));
    check($app->cache()->tagVersion('pages') > $version, 'la etiqueta de las páginas sube');
    same(null, PageCache::serve($site, $request, $app->clock()), 'la copia vieja ya no se sirve');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.maintenance'"));

    $web->get('/admin/tools/cache');
    $r = $web->post('/admin/tools/cache/maintenance', ['state' => 'off', '_token' => aifecToken($web)]);
    same(302, $r->status);
    same(false, $app->settings()->bool('tools.maintenance'));
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.maintenance'"));
});

test('guardar el ajuste desde la pantalla de ajustes también tira la caché de páginas', function (): void {
    $app = toolsShop(false);
    $version = $app->cache()->tagVersion('pages');
    $app->hooks()->fire('settings.saved', 'tools', ['tools.maintenance'], $app);
    check($app->cache()->tagVersion('pages') > $version);
    $version = $app->cache()->tagVersion('pages');
    $app->hooks()->fire('settings.saved', 'tools', ['tools.keep_logs_days'], $app);
    same($version, $app->cache()->tagVersion('pages'), 'otro ajuste no toca la caché');

    $web = adminClient($app);
    $r = $web->get('/admin/settings/tools');
    same(200, $r->status);
    contains('Modo mantenimiento', $r->body);
    contains('tools.maintenance_text_es', $r->body);
    contains('tools.maintenance_text_en', $r->body);
});

test('el inicio del panel avisa con el mantenimiento puesto, con una banda abajo y con el botón para quitarlo', function (): void {
    $app = toolsShop();
    $web = adminClient($app);
    $r = $web->get('/admin');
    contains('El modo mantenimiento está puesto: las visitas ven una página de mantenimiento', $r->body);
    contains('Quitarlo', $r->body);
    contains('/admin/tools/cache', $r->body);
    contains('position:fixed;left:0;right:0;bottom:0', $r->body, 'la banda fija de abajo');

    $cache = $web->get('/admin/tools/cache');
    contains('Quitar el modo mantenimiento', $cache->body);
    notContains('Poner el modo mantenimiento', $cache->body);

    $app->settings()->set('tools.maintenance', false);
    $r = $web->get('/admin');
    notContains('El modo mantenimiento está puesto', $r->body);
    notContains('position:fixed;left:0;right:0;bottom:0', $r->body);
    contains('Poner el modo mantenimiento', $web->get('/admin/tools/cache')->body);
});

test('encender o apagar el mantenimiento exige tools.edit', function (): void {
    $app = toolsShop(false);
    toolsRole($app, 'mirador', ['dashboard.view', 'tools.view']);
    aifecAdmin($app, 'mirador', 'mirador@tienda.test', 'Mirador');
    $viewer = new TestClient($app);
    aifecLogin($viewer, 'mirador@tienda.test');
    $viewer->get('/admin/tools/cache');
    same(200, $viewer->last->status);
    notContains('Poner el modo mantenimiento', $viewer->last->body, 'sin permiso no sale el botón');

    $token = aifecToken($viewer);
    $r = $viewer->post('/admin/tools/cache/maintenance', ['state' => 'on', '_token' => $token]);
    same(403, $r->status);
    same(false, $app->settings()->bool('tools.maintenance'));
    same(403, $viewer->post('/admin/tools/cache/clear-data', ['_token' => $token])->status);
    same(403, $viewer->post('/admin/tools/cache/clear-pages', ['_token' => $token])->status);
    same(403, $viewer->post('/admin/tools/cache/publish-assets', ['_token' => $token])->status);
    same(403, adminClient($app, 'support')->get('/admin/tools/cache')->status);
});

test('el mensaje de mantenimiento se puede dar en cada idioma y Maintenance::message respeta el orden', function (): void {
    $app = toolsShop(false, ['tools.maintenance_text_en' => '']);
    same('Volvemos a las 18:00 & seguimos', Maintenance::message($app, 'es'));
    same('Volvemos a las 18:00 & seguimos', Maintenance::message($app, 'en'), 'sin texto en inglés, el del idioma principal');
    same('Volvemos a las 18:00 & seguimos', Maintenance::message($app, '../../x'), 'un idioma raro no sirve para colarse');
    $app->settings()->set('tools.maintenance_text_en', 'Back soon');
    same('Back soon', Maintenance::message($app, 'en'));
});
