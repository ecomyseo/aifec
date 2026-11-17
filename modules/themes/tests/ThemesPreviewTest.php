<?php
/**
 * AIFEC — pruebas de la vista previa del personalizador: los valores sin publicar solo los ve quien tiene el borrador, la
 * respuesta no se guarda en caché ni se indexa, y nada de lo previsualizado llega a la base de datos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Themes\Preview;

require_once __DIR__ . '/ThemesKit.php';

/** Manda el formulario del personalizador con la ficha de la última página del panel. */
function themesCustomise(TestClient $web, string $slug, string $action, array $values): \Aifec\Kernel\Response
{
    // La ficha sale de la última página con marco del panel: si la última respuesta fue de la tienda, se abre el inicio.
    if ($web->last === null || strpos($web->last->body, 'name="af-token"') === false) {
        $web->get('/admin');
    }

    return $web->post('/admin/themes/customise/' . $slug, ['_token' => aifecToken($web), 'do' => $action, 'f' => $values]);
}

themesTest('vista previa: con la sesión del panel y af_preview=1 se ven los valores sin publicar, y no se guarda nada', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    $r = themesCustomise($web, 'base', 'preview', ['accent' => '#123456', 'radius' => '20']);
    same(302, $r->status);
    check(isset($r->cookies[$app->cookieName('pv')]), 'el panel da la cookie de vista previa');

    $page = $web->get('/?af_preview=1');
    same(200, $page->status);
    contains('--accent:#123456', $page->body);
    contains('--radius:20px', $page->body);
    same(null, themesSetting($app, 'theme.options'), 'nada se publica');
    same(null, themesSetting($app, 'theme.active'));

    $published = $web->get('/');
    contains('--accent:#b94a17', $published->body, 'sin el parámetro se ve lo publicado');
    notContains('#123456', $published->body);
});

themesTest('vista previa: la respuesta nunca es cacheable, lleva noindex y su canónica no lleva el parámetro', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    themesCustomise($web, 'base', 'preview', ['accent' => '#123456']);
    $page = $web->get('/?af_preview=1');
    check(!isset($page->headers['X-Cache']), 'no entra en la caché de página');
    same(0, $page->cacheTtl);
    contains('no-store', $page->headers['Cache-Control']);
    contains('noindex', $page->headers['X-Robots-Tag']);
    contains('<meta name="robots" content="noindex, nofollow">', $page->body);
    same(1, preg_match('/<link rel="canonical" href="([^"]*)"/', $page->body, $m));
    notContains('af_preview', $m[1]);
    $both = $web->get('/?utm_source=x&af_preview=1');
    same(1, preg_match('/<link rel="canonical" href="([^"]*)"/', $both->body, $m2));
    notContains('af_preview', $m2[1]);
    // Y la portada normal sí se guarda, para que se vea la diferencia.
    $plain = (new TestClient($app))->get('/');
    same('MISS', isset($plain->headers['X-Cache']) ? $plain->headers['X-Cache'] : '');
});

themesTest('vista previa: otro visitante no ve los valores, ni con el parámetro, y no se queda pegada a la petición siguiente', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    themesCustomise($web, 'base', 'preview', ['accent' => '#123456']);
    same(200, $web->get('/?af_preview=1')->status);

    $other = new TestClient($app);
    $r = $other->get('/?af_preview=1');
    contains('--accent:#b94a17', $r->body, 'sin cookie no hay borrador');
    notContains('#123456', $r->body);
    check(!isset($r->headers['X-Cache']), 'ni siquiera la de un visitante con el parámetro se guarda');
    contains('noindex', $r->headers['X-Robots-Tag']);

    // La misma aplicación atiende a otro visitante después: los ajustes volvieron a como estaban.
    $after = (new TestClient($app))->get('/');
    contains('--accent:#b94a17', $after->body);

    // Una cookie inventada tampoco vale.
    $forged = new TestClient($app);
    $forged->cookies[$app->cookieName('pv')] = str_repeat('a', 32);
    contains('--accent:#b94a17', $forged->get('/?af_preview=1')->body);
    $odd = new TestClient($app);
    $odd->cookies[$app->cookieName('pv')] = '../../etc/passwd';
    contains('--accent:#b94a17', $odd->get('/?af_preview=1')->body);
});

themesTest('vista previa: el borrador sigue en el formulario al recargar, publicar lo sustituye y restablecer lo borra', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    themesCustomise($web, 'base', 'preview', ['accent' => '#123456']);
    $screen = $web->get('/admin/themes/customise/base');
    same(200, $screen->status);
    contains('#123456', $screen->body);
    contains('Estás viendo un borrador', $screen->body);

    $r = themesCustomise($web, 'base', 'publish', ['accent' => '#654321']);
    same(302, $r->status);
    same(['base' => ['accent' => '#654321']], themesSetting($app, 'theme.options'));
    $page = $web->get('/?af_preview=1');
    contains('--accent:#654321', $page->body, 'ya no hay borrador: se ve lo publicado');
    notContains('#123456', $page->body);

    themesCustomise($web, 'base', 'preview', ['accent' => '#aa0000']);
    contains('--accent:#aa0000', $web->get('/?af_preview=1')->body);
    $web->get('/admin/themes/customise/base');
    $r = themesCustomise($web, 'base', 'reset', []);
    same(302, $r->status);
    same([], themesSetting($app, 'theme.options'));
    contains('--accent:#b94a17', $web->get('/?af_preview=1')->body);
});

themesTest('vista previa: un valor no válido se deja fuera, y el borrador de un tema que no es el activo no se aplica', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo');
    $app = themesApp($root);
    $web = adminClient($app);
    themesCustomise($web, 'base', 'preview', ['accent' => 'url(javascript:1)', 'radius' => '12']);
    $body = $web->get('/?af_preview=1')->body;
    contains('--accent:#b94a17', $body);
    contains('--radius:12px', $body);
    notContains('javascript', $body);

    $web->get('/admin');
    themesCustomise($web, 'hijo', 'preview', ['accent' => '#123456']);
    contains('--accent:#b94a17', $web->get('/?af_preview=1')->body, 'el activo es base');
    $screen = $web->get('/admin/themes/customise/hijo');
    contains('Este tema no es el activo', $screen->body);
    notContains('data-th-frame', $screen->body);
});

themesTest('stripParam quita el parámetro de vista previa de una dirección y deja lo demás', function (): void {
    same('https://tienda.test/', Preview::stripParam('https://tienda.test/?af_preview=1'));
    same('https://tienda.test/?a=1', Preview::stripParam('https://tienda.test/?a=1&af_preview=1'));
    same('https://tienda.test/?a=2', Preview::stripParam('https://tienda.test/?af_preview=1&a=2'));
    same('https://tienda.test/?a=1&b=2', Preview::stripParam('https://tienda.test/?a=1&af_preview=1&b=2'));
    same('https://tienda.test/x', Preview::stripParam('https://tienda.test/x'));
});
