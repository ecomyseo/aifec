<?php
/**
 * AIFEC — pruebas de la ruta propia del panel: con «security.admin_path» el panel vive en esa dirección, «/admin» deja de existir,
 * todos los enlaces y formularios salen con la ruta nueva y entrar, navegar y salir funciona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const AA_PATH = 'panel-4k7x';

test('sin ruta propia el panel sigue en /admin', function (): void {
    $app = securityApp();
    $web = new TestClient($app);
    same(200, $web->get('/admin/login')->status);
    contains('action="/admin/login"', $web->last->body);
    same('', $app->adminAlias());
});

test('con ruta propia, /admin responde 404 y la ruta nueva abre el acceso', function (): void {
    $app = securityApp(['settings' => ['security.admin_path' => AA_PATH]]);
    $web = new TestClient($app);
    same(404, $web->get('/admin')->status);
    same(404, $web->get('/admin/login')->status);
    same(404, $web->get('/admin/settings')->status);
    same(200, $web->get('/' . AA_PATH . '/login')->status);
    contains('action="/' . AA_PATH . '/login"', $web->last->body);
    $left = preg_match('#(?:href|action|src|data-url)="[^"]*/admin(?:/|")[^"]*"#', preg_replace('#/assets/[^"]*#', '/A/x', $web->last->body), $m) === 1 ? $m[0] : '';
    same('', $left, 'queda un enlace a /admin en el acceso');
});

test('con ruta propia se entra, se navega con los enlaces nuevos y se sale', function (): void {
    $app = securityApp(['settings' => ['security.admin_path' => AA_PATH]]);
    aifecAdmin($app);
    $web = new TestClient($app);
    $res = $web->post('/' . AA_PATH . '/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234']);
    same(302, $res->status);
    same('/' . AA_PATH, $res->headers['Location']);
    $home = $web->get('/' . AA_PATH);
    same(200, $home->status);
    contains('href="/' . AA_PATH . '/profile"', $home->body);
    notContains('href="/admin', $home->body);
    notContains('action="/admin', $home->body);
    same(200, $web->get('/' . AA_PATH . '/security')->status);
    // Sin sesión, la redirección al acceso también va a la ruta nueva.
    $anon = new TestClient($app);
    $go = $anon->get('/' . AA_PATH . '/security');
    same(302, $go->status);
    contains('/' . AA_PATH . '/login', $go->headers['Location']);
    notContains('/admin/login', $go->headers['Location']);
    // Salir.
    $web->get('/' . AA_PATH);
    $out = $web->post('/' . AA_PATH . '/logout', ['_token' => aifecToken($web)]);
    same(302, $out->status);
    same(302, $web->get('/' . AA_PATH)->status);
});

test('la cookie de la sesión del panel se limita a la ruta propia', function (): void {
    $app = securityApp(['settings' => ['security.admin_path' => AA_PATH]]);
    aifecAdmin($app);
    $web = new TestClient($app);
    $res = $web->post('/' . AA_PATH . '/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234']);
    $paths = [];
    foreach ($res->cookies as $cookie) {
        $paths[] = (string) ($cookie['options']['path'] ?? '');
    }
    same(true, in_array('/' . AA_PATH, $paths, true), 'rutas de las cookies: ' . implode(',', $paths));
});

test('una ruta mal escrita o reservada se ignora y el panel queda en /admin', function (): void {
    foreach (['admin', 'api', 'Ab', 'con espacios', 'x', 'mal/ruta', '-empieza', 'acaba-', 'webmcp', 'assets', 'checkout'] as $bad) {
        $app = securityApp(['settings' => ['security.admin_path' => $bad]]);
        same('', $app->adminAlias(), $bad);
        same(200, (new TestClient($app))->get('/admin/login')->status, $bad);
    }
});

test('el resto de la web no cambia con la ruta propia', function (): void {
    $app = securityApp(['settings' => ['security.admin_path' => AA_PATH]]);
    $web = new TestClient($app);
    same(200, $web->get('/')->status);
    $web->get('/' . AA_PATH . '/login');
    notContains(AA_PATH, (new TestClient($app))->get('/robots.txt')->body);
});
