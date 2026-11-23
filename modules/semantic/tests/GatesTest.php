<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * /.well-known/aifec.json (qué puertas hay abiertas), los ajustes del módulo y el módulo solo, sin catálogo ni contenidos.
 */

test('aifec.json lleva versión, nombre, idiomas y moneda, y lista solo las puertas activas con su dirección completa', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'seo'], ['store.name' => 'Muebles Norte', 'i18n.languages' => ['es', 'en'], 'semantic.feeds.openai' => false]);
    semanticProduct($app);
    semanticBuildFeeds($app);
    $web = new TestClient($app);

    $r = $web->get('/.well-known/aifec.json');
    same(200, $r->status);
    contains('application/json', $r->contentType());
    $doc = json_decode($r->body, true);
    check(is_array($doc), 'es JSON válido');
    same('1.0', $doc['version']);
    same('Muebles Norte', $doc['name']);
    same(['es', 'en'], $doc['languages']);
    same('es', $doc['default_language']);
    same('EUR', $doc['currency']);
    same('https://tienda.test/llms.txt', $doc['gates']['llms']);
    same('https://tienda.test/llms-full.txt', $doc['gates']['llms_full']);
    same(['google' => 'https://tienda.test/feeds/google.xml', 'products' => 'https://tienda.test/feeds/products.json'], $doc['gates']['feeds'], 'el feed apagado no sale');
    same('https://tienda.test/sitemap.xml', $doc['gates']['sitemap']);
    check(!isset($doc['gates']['api']), 'sin módulo de API no hay puerta de API');
    check(!isset($doc['gates']['mcp']), 'sin módulo MCP no hay puerta MCP');
});

test('aifec.json no anuncia un feed que todavía no se ha generado ni el sitemap si no hay módulo SEO', function (): void {
    $app = semanticApp();
    $web = new TestClient($app);

    $doc = json_decode($web->get('/.well-known/aifec.json')->body, true);
    same(['llms', 'llms_full'], array_keys($doc['gates']));
});

test('los ajustes del módulo tienen cuatro pestañas con las iniciales del módulo y valores de serie coherentes', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media']);

    $settings = $app->modules()->definition('semantic', 'settings');
    same('Data for machines', $settings['title']);
    same(['smorg', 'smreturns', 'smtext', 'smrobots', 'smfeeds'], array_keys($settings['tabs']));
    same('settings.edit', $settings['cap'], 'sin el módulo SEO, el permiso de ajustes generales');
    same(true, $app->settings()->bool('semantic.feeds.google'));
    same(true, $app->settings()->bool('semantic.feeds.products'));
    same(true, $app->settings()->bool('semantic.feeds.openai'));
    same(true, $app->settings()->bool('semantic.openai.enable_search'));
    same(false, $app->settings()->bool('semantic.openai.enable_checkout'));
    same(500, $app->settings()->int('semantic.llms_full_kb'));
    same(0, $app->settings()->int('semantic.returns.days'), 'sin política de devoluciones por defecto');
});

test('el permiso de los ajustes es el de SEO cuando el módulo SEO existe', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'seo']);

    same('seo.edit', $app->modules()->definition('semantic', 'settings')['cap']);
});

test('sin catálogo ni contenidos el módulo no rompe ninguna página: portada, llms.txt, llms-full.txt y aifec.json responden', function (): void {
    $app = testApp(['modules' => ['semantic']]);
    $web = new TestClient($app);

    same(200, $web->get('/')->status);
    $llms = $web->get('/llms.txt');
    same(200, $llms->status);
    check(strpos($llms->body, '# ') === 0, 'llms.txt empieza por «# »');
    same(200, $web->get('/llms-full.txt')->status);
    same(200, $web->get('/.well-known/aifec.json')->status);
    same(200, $web->get('/index.md')->status);
    same(404, $web->get('/producto/silla.md')->status, 'sin catálogo no hay productos');
    same(404, $web->get('/blog.md')->status, 'sin contenidos no hay blog');
    same(404, $web->get('/feeds/google.xml')->status);
    $org = null;
    foreach (semanticLd($web->get('/')->body) as $object) {
        $org = $object['@type'] === 'Organization' ? $object : $org;
    }
    check($org !== null, 'la portada lleva su organización');
    foreach (semanticLd($web->get('/')->body) as $object) {
        check(!($object['@type'] === 'WebSite' && isset($object['potentialAction'])), 'sin buscador no hay SearchAction');
    }
});

test('solo con catálogo (sin contenidos) las fichas y el llms.txt funcionan y el blog no existe', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media']);
    semanticProduct($app);
    $web = new TestClient($app);

    same(200, $web->get('/producto/silla.md')->status);
    same(200, $web->get('/llms.txt')->status);
    same(404, $web->get('/blog.md')->status);
    notContains('## Blog', $web->get('/llms.txt')->body);
});

test('lo que lanza un plugin en un filtro de datos estructurados no tumba la ficha', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    expectHookErrors();
    $app->hooks()->filter('jsonld', static function ($objects): array {
        throw new \RuntimeException('plugin roto');
    });
    $web = new TestClient($app);

    $r = $web->get('/producto/silla');
    same(200, $r->status);
    check(semanticFind(semanticLd($r->body), 'Product') !== null, 'la ficha conserva su producto');
    check(count(AifecTestEnv::$hookErrors) > 0, 'el error se apunta');
    AifecTestEnv::$hookErrors = [];
});
