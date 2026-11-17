<?php
/**
 * AIFEC — pruebas de la página pública /demos del sitio maestro: la lista de demostraciones con sus enlaces y sus datos de
 * acceso, que sale en la caché de página, y la captura de portada de cada paquete.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Sites\Demos;

require_once __DIR__ . '/helpers.php';

/** Un PNG de 1 × 1 píxel, el más pequeño posible. */
function aifecSitesTinyPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}

test('/demos, de serie, no anuncia el panel: ni el botón, ni el correo, ni la contraseña (la tienda sí)', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda & más', 'password' => 'clave-publica-1']);
    $w['demos']->create(['package' => 'minimo', 'slug' => 'hogar', 'name' => 'Hogar']);
    $r = aifecSitesFront($w, '/demos');
    same(200, $r->status);
    contains('href="https://tienda.test/demos/moda/"', $r->body, '«Ver la tienda» sigue');
    contains('href="https://tienda.test/demos/hogar/"', $r->body);
    contains('aria-label="Ver la tienda: Moda &amp; más"', $r->body, 'cada «Ver la tienda» dice de qué tienda es a quien navega por la lista de enlaces');
    contains('aria-label="Ver la tienda: Hogar"', $r->body);
    foreach (['/admin"', 'Entrar al panel', 'demo@tienda.test', 'clave-publica-1', 'demo-aifec-2026', 'Contraseña del panel', 'Correo del panel', 'abre su panel'] as $hidden) {
        check(strpos($r->body, $hidden) === false, 'no sale «' . $hidden . '»');
    }
    contains('Navega por ellas y compra con el pago de prueba.', $r->body, 'la frase de arriba no promete el panel');
    contains('navega por ellas y compra con un pago de prueba.', $r->body, 'ni la descripción para buscadores');
});

test('el interruptor «enseñar el panel en la página de demos» se guarda, se enseña y renueva la página guardada', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda', 'password' => 'clave-publica-1']);
    $web = adminClient($w['master']);
    $screen = $web->get('/admin/sites')->body;
    contains('name="show_panel" value="1"', $screen, 'el interruptor está en la pantalla');
    check(preg_match('/name="show_panel" value="1" checked/', $screen) === 0, 'de serie, apagado');
    check(strpos(aifecSitesFront($w, '/demos')->body, 'clave-publica-1') === false, 'apagado: la página no enseña la contraseña');

    $web->get('/admin/sites');
    $web->post('/admin/sites/settings', ['_token' => aifecToken($web), 'nightly' => '1', 'show_panel' => '1']);
    same(true, $w['master']->settings()->bool(Demos::SHOW_PANEL, false));
    check(preg_match('/name="show_panel" value="1" checked/', $web->get('/admin/sites')->body) === 1, 'ahora sale marcado');
    $on = aifecSitesFront($w, '/demos')->body;
    contains('clave-publica-1', $on, 'encendido: la página guardada se renueva y enseña el acceso');
    contains('href="https://tienda.test/demos/moda/admin"', $on);

    $web->get('/admin/sites');
    $web->post('/admin/sites/settings', ['_token' => aifecToken($web), 'nightly' => '1', 'show_panel' => '0']);
    same(false, $w['master']->settings()->bool(Demos::SHOW_PANEL, true));
    check(strpos(aifecSitesFront($w, '/demos')->body, 'clave-publica-1') === false, 'apagado otra vez: desaparece');

    // Un formulario antiguo, sin el campo, no cambia el ajuste.
    $w['master']->settings()->set(Demos::SHOW_PANEL, true);
    $web->get('/admin/sites');
    $web->post('/admin/sites/settings', ['_token' => aifecToken($web), 'nightly' => '1']);
    same(true, $w['master']->settings()->bool(Demos::SHOW_PANEL, false), 'sin el campo no se toca');
    aifecSitesRelease($web);
});

test('/demos con el panel encendido lista las demostraciones con sus enlaces y los datos de acceso, y avisa de que se restablecen cada noche', function (): void {
    $w = aifecSitesFresh();
    $w['master']->settings()->set(Demos::SHOW_PANEL, true);
    $w['demos']->create(['package' => 'minimo', 'slug' => 'moda', 'name' => 'Moda & más', 'password' => 'clave-publica-1']);
    $w['demos']->create(['package' => 'minimo', 'slug' => 'hogar', 'name' => 'Hogar']);
    $r = aifecSitesFront($w, '/demos');
    same(200, $r->status);
    contains('Tiendas de demostración', $r->body);
    contains('Moda &amp; más', $r->body, 'el nombre sale escapado');
    contains('href="https://tienda.test/demos/moda/"', $r->body, '«Ver la tienda»');
    contains('href="https://tienda.test/demos/moda/admin"', $r->body, '«Entrar al panel»');
    contains('href="https://tienda.test/demos/hogar/"', $r->body);
    contains('demo@tienda.test', $r->body, 'el correo de acceso');
    contains('clave-publica-1', $r->body, 'la contraseña de esa demostración');
    contains('demo-aifec-2026', $r->body, 'y la de la otra');
    contains('cada noche', $r->body, 'avisa de que se restablecen');
    contains('No se cobra nada', $r->body);
    contains('Sector', $r->body);
    contains('muebles', $r->body);
    contains('Una tienda de sillas y mesas de madera', $r->body, 'la descripción del paquete');
    check(strpos($r->body, 'demo-minimo') === false, 'no enseña lo que no está');
    contains('<meta name="robots" content="index', $r->body, 'la página del maestro sí se indexa');
});

test('/demos sin ninguna demostración enseña su estado vacío, y no lista las que no están hechas', function (): void {
    $w = aifecSitesFresh();
    contains('Todavía no hay demostraciones', aifecSitesFront($w, '/demos')->body);
    $w['master']->settings()->set('sites.demos', [
        'demo-encola' => ['slug' => 'encola', 'package' => 'minimo', 'name' => 'En cola', 'password' => 'secreta-12345', 'state' => 'queued', 'url' => 'https://tienda.test/demos/encola'],
        'demo-falla' => ['slug' => 'falla', 'package' => 'minimo', 'name' => 'Fallida', 'password' => 'secreta-12345', 'state' => 'failed', 'url' => 'https://tienda.test/demos/falla'],
    ]);
    $w['master']->invalidate(['sites']);
    $body = aifecSitesFront($w, '/demos')->body;
    contains('Todavía no hay demostraciones', $body);
    check(strpos($body, 'secreta-12345') === false && strpos($body, 'encola') === false, 'ni una ficha ni su contraseña');
});

test('/demos es cacheable, sale de la caché la segunda vez y se renueva al crear otra demostración', function (): void {
    $w = aifecSitesFresh();
    $w['demos']->create(['package' => 'minimo', 'slug' => 'primera']);
    $web = new TestClient($w['master']);
    $r = $web->get('/demos');
    same(200, $r->status);
    check($r->cacheTtl > 0, 'la respuesta se marca cacheable');
    check(in_array('sites', $r->cacheTags, true), 'con la etiqueta «sites»');
    check(!isset($r->headers['Set-Cookie']) && $r->cookies === [], 'sin cookies: no depende del visitante');

    $first = aifecSitesFront($w, '/demos');
    $second = aifecSitesFront($w, '/demos');
    same('HIT', $second->headers['X-Cache'] ?? '', 'la segunda sale de la caché de página');
    contains('/demos/primera/', $second->body);
    check(strpos($second->body, '/demos/segunda/') === false);

    $w['demos']->create(['package' => 'minimo', 'slug' => 'segunda']);
    $third = aifecSitesFront($w, '/demos');
    contains('/demos/segunda/', $third->body, 'crear otra demostración invalida la página guardada');
    check($first->status === 200);
});

test('la captura de portada del paquete se sirve si existe y sale en la tarjeta', function (): void {
    $packages = aifecSitesPackages();
    file_put_contents($packages . '/uno/preview.png', aifecSitesTinyPng());
    $w = aifecSitesFresh($packages);
    $w['demos']->create(['package' => 'uno', 'slug' => 'con-foto']);
    $w['demos']->create(['package' => 'dos', 'slug' => 'sin-foto']);
    $page = aifecSitesFront($w, '/demos')->body;
    contains('/demo-preview/uno', $page);
    check(strpos($page, '/demo-preview/dos') === false, 'el paquete sin captura no lleva imagen');
    $img = aifecSitesFront($w, '/demo-preview/uno');
    same(200, $img->status);
    same('image/png', $img->headers['Content-Type'] ?? '');
    same(aifecSitesTinyPng(), (string) file_get_contents((string) $img->file));
    same(404, aifecSitesFront($w, '/demo-preview/dos')->status, 'sin captura, 404');
    same(404, aifecSitesFront($w, '/demo-preview/no-existe')->status);
    same(404, aifecSitesFront($w, '/demo-preview/..%2Fdos')->status, 'un nombre raro no sale de la carpeta de paquetes');
});
