<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de la pantalla de ajustes de SEO: se abre con permiso, guarda lo que debe (releyendo la base de datos), rechaza los
 * códigos de verificación con comillas o «<», y lo guardado llega a la tienda.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Lo que manda el formulario de ajustes de SEO tal como está, con lo que se quiera cambiar. @return array<string,mixed> */
function seoForm(array $change = []): array
{
    return $change + [
        'seo.noindex_site' => '0', 'seo.max_indexed_page' => '0',
        'seo.title.home' => '{shop}', 'seo.description.home' => '',
        'seo.title.product' => '{name} | {shop}', 'seo.description.product' => '',
        'seo.title.category' => '{name} | {shop}', 'seo.description.category' => '',
        'seo.title.brand' => '{name} | {shop}', 'seo.description.brand' => '',
        'seo.title.page' => '{name} | {shop}', 'seo.description.page' => '',
        'seo.title.post' => '{name} | {shop}', 'seo.description.post' => '',
        'seo.title.blog' => '{name} | {shop}', 'seo.description.blog' => '',
        'seo.bots.search' => 'allow', 'seo.bots.assistant' => 'allow', 'seo.bots.training' => 'allow',
        'seo.robots_extra' => '', 'seo.verify_google' => '', 'seo.verify_bing' => '',
    ];
}

test('la pantalla de ajustes se abre con permiso, trae sus cuatro pestañas y a los demás les da 403', function (): void {
    $app = seoApp(['seo']);
    $web = adminClient($app);
    $r = $web->get('/admin/settings/seo');
    same(200, $r->status);
    foreach (['seogeneral', 'seotitles', 'seobots', 'seoverify'] as $tab) {
        contains('data-af-tab="' . $tab . '"', $r->body, 'pestaña ' . $tab);
    }
    contains('Ocultar todo el sitio a los buscadores', $r->body, 'en castellano');
    same('{name} | {shop}', inputValue($r->body, 'f[seo.title.product]'), 'la plantilla de serie');
    same(403, adminClient(seoApp(['seo']), 'support')->get('/admin/settings/seo')->status);
});

test('guardar los ajustes deja los valores en la base de datos y vuelve a la misma pestaña', function (): void {
    $app = seoApp(['seo']);
    $web = adminClient($app);
    $web->get('/admin/settings/seo');
    $r = $web->post('/admin/settings/seo', ['_token' => aifecToken($web), '_tab' => 'seotitles', 'f' => seoForm([
        'seo.title.product' => '{name} & {brand} | {shop}', 'seo.max_indexed_page' => '3', 'seo.bots.training' => 'disallow',
        'seo.robots_extra' => "Disallow: /privado/\nCrawl-delay: 5",
    ])]);
    same(302, $r->status);
    same('/admin/settings/seo#tab=seotitles', $r->headers['Location']);
    $app->settings()->reload();
    same('{name} & {brand} | {shop}', $app->settings()->str('seo.title.product'), 'el «&» se guarda tal cual, no como «&amp;»');
    same(3, $app->settings()->int('seo.max_indexed_page'));
    same('disallow', $app->settings()->str('seo.bots.training'));
    same("Disallow: /privado/\nCrawl-delay: 5", $app->settings()->str('seo.robots_extra'));
    $robots = $web->get('/robots.txt')->body;
    contains("User-agent: GPTBot\n", $robots);
    same(['Disallow: /'], seoGroupOf($robots, 'GPTBot'), 'lo guardado llega al robots.txt');
    contains("Disallow: /privado/\n", $robots);
});

test('un código de verificación con comillas o «<» no se guarda', function (): void {
    $app = seoApp(['seo']);
    $web = adminClient($app);
    foreach (['abc"def12345', 'abcdef<12345', '"><script>alert(1)</script>', "abc'def12345", 'corto', '<meta name="google-site-verification" content="abcdefgh12">'] as $bad) {
        $web->get('/admin/settings/seo');
        $r = $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.verify_google' => $bad])]);
        same(422, $r->status, 'se rechaza: ' . $bad);
        contains('El formato no es válido', $r->body);
        $app->settings()->reload();
        same('', $app->settings()->str('seo.verify_google'), 'y no se guarda: ' . $bad);
    }
    $web->get('/admin/settings/seo');
    $r = $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.verify_bing' => "abc\"def12345"])]);
    same(422, $r->status, 'Bing igual');
});

test('un código válido se guarda y sale en la tienda; borrarlo lo quita', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    $web = adminClient($app);
    $web->get('/admin/settings/seo');
    $r = $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.verify_google' => 'abcDEF123_-xyz', 'seo.verify_bing' => '0123456789ABCDEF0123456789ABCDEF'])]);
    same(302, $r->status);
    $app->settings()->reload();
    same('abcDEF123_-xyz', $app->settings()->str('seo.verify_google'));
    $shop = new TestClient($app);
    $body = $shop->get('/')->body;
    contains('<meta name="google-site-verification" content="abcDEF123_-xyz">', $body);
    contains('<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">', $body);

    $web->get('/admin/settings/seo');
    $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm()]);
    notContains('google-site-verification', $shop->get('/')->body, 'al vaciar el campo desaparece');
});

test('el interruptor «no indexar» se puede encender y apagar desde la pantalla', function (): void {
    $app = seoApp(['seo']);
    $web = adminClient($app);
    $web->get('/admin/settings/seo');
    $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.noindex_site' => '1'])]);
    $app->settings()->reload();
    same(true, $app->settings()->bool('seo.noindex_site'), 'encendido');
    check(strpos($web->get('/robots.txt')->body, "User-agent: *\nDisallow: /\n") === 0, 'cerrado a todos los robots (menos a quien lee una página para una persona)');
    $web->get('/admin/settings/seo');
    $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.noindex_site' => '0'])]);
    $app->settings()->reload();
    same(false, $app->settings()->bool('seo.noindex_site'), 'apagado: el interruptor viaja siempre, con 0 o con 1');
    contains('Sitemap: https://tienda.test/sitemap.xml', $web->get('/robots.txt')->body);
});

test('una plantilla vacía en los ajustes deja el título como estaba', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $web = adminClient($app);
    $web->get('/admin/settings/seo');
    $web->post('/admin/settings/seo', ['_token' => aifecToken($web), 'f' => seoForm(['seo.title.product' => ''])]);
    same('Silla · Muebles Ana', seoTitle((new TestClient($app))->get('/producto/silla')->body), 'sin plantilla, el título de siempre');
});
