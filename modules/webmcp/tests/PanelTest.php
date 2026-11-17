<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El panel de WebMCP: permisos y menú, herramientas publicadas, formularios marcados con su estado y el ajuste que lo
 * enciende y lo apaga (y con él, las páginas guardadas en la caché).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el panel declara sus permisos y su entrada en el grupo IA; se abre con permiso y recibe un 403 sin él', function (): void {
    $app = webmcpApp();
    $web = adminClient($app);

    $home = $web->get('/admin');
    contains('/admin/webmcp', $home->body, 'el menú lleva al panel');
    // Desde P102, los ajustes son una pestaña de «WebMCP»: se ven en la barra de su pantalla.
    contains('role="tab" href="/admin/settings/webmcp"', $web->get('/admin/webmcp')->body, 'y a los ajustes');
    $caps = \Aifec\Admin\Admin::capabilities($app);
    check(isset($caps['webmcp.view']) && isset($caps['webmcp.edit']), 'los permisos están declarados');

    same(200, $web->get('/admin/webmcp')->status);
    $support = adminClient($app, 'support');
    foreach (['/admin/webmcp', '/admin/settings/webmcp'] as $path) {
        same(403, $support->get($path)->status, $path);
    }
    check((new TestClient($app))->get('/admin/webmcp')->status !== 200, 'sin sesión no se ve');
});

test('el panel lista las herramientas publicadas, con su tipo, y no enseña checkout_place', function (): void {
    $app = webmcpApp();
    $web = adminClient($app);
    $page = $web->get('/admin/webmcp');

    same(200, $page->status);
    contains('Herramientas publicadas', $page->body);
    foreach (array_keys(webmcpList(new TestClient($app))) as $name) {
        contains('<code>' . $name . '</code>', $page->body, $name);
    }
    notContains('<code>checkout_place</code>', $page->body);
    contains('WebMCP está encendido', $page->body);
    contains('/webmcp/tools.json', $page->body, 'enlaza la lista');
    contains('Lee', $page->body);
    contains('Cambia algo', $page->body);
});

test('el panel lista los formularios marcados con su estado: los que están en una plantilla y los que aún no', function (): void {
    $app = webmcpApp();
    $web = adminClient($app);
    $page = $web->get('/admin/webmcp');

    contains('Formularios marcados', $page->body);
    foreach (\Aifec\Modules\Webmcp\Webmcp::FORMS as $form) {
        contains('<code>' . $form . '</code>', $page->body, $form);
    }
    contains('Todavía en ninguna plantilla', $page->body, 'el boletín y el contacto aún no están en una plantilla');
    check(preg_match('/En \d+ plantillas/', $page->body) === 1, 'los demás dicen en cuántas están');
});

test('el panel avisa cuando WebMCP está apagado', function (): void {
    $app = webmcpApp(['webmcp.enabled' => false]);
    $web = adminClient($app);
    $page = $web->get('/admin/webmcp');

    same(200, $page->status);
    contains('WebMCP está apagado', $page->body);
    notContains('WebMCP está encendido', $page->body);
});

test('el ajuste enciende y apaga WebMCP y vacía las páginas guardadas: sin él, ni guion ni marcas ni lista', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = adminClient($app);
    $path = $app->url()->entity('product', $ids['silla_roja']);

    $settings = $web->get('/admin/settings/webmcp');
    same(200, $settings->status);
    contains('wmgeneral', $settings->body);
    contains('Ofrecer las herramientas de la tienda a los agentes de IA en el navegador', $settings->body);

    $visitor = new TestClient($app);
    contains('toolname="add_to_cart"', $visitor->get($path)->body);
    $web->post('/admin/settings/webmcp', ['_token' => aifecToken($web), 'f' => ['webmcp.enabled' => '0']]);
    $app->settings()->reload();
    same(false, $app->settings()->bool('webmcp.enabled', true));
    $body = (new TestClient($app))->get($path)->body;
    notContains('toolname', $body);
    notContains('webmcp.js', $body);
    same(404, (new TestClient($app))->get('/webmcp/tools.json')->status);

    $web->get('/admin/settings/webmcp');
    $web->post('/admin/settings/webmcp', ['_token' => aifecToken($web), 'f' => ['webmcp.enabled' => '1']]);
    $app->settings()->reload();
    contains('toolname="add_to_cart"', (new TestClient($app))->get($path)->body);
    same(200, (new TestClient($app))->get('/webmcp/tools.json')->status);
});
