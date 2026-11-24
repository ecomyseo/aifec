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
 * /llms.txt y /llms-full.txt: el resumen de la tienda para modelos de lenguaje.
 */

test('llms.txt empieza por el nombre de la tienda, lleva el resumen en cita y las secciones con sus enlaces', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms'], ['store.name' => 'Muebles Norte', 'semantic.summary' => "Muebles de roble\nhechos a mano."]);
    $sillas = aifecCategory($app, 'Sillas');
    semanticProduct($app, ['categories' => [$sillas]]);
    cmsInsertPost($app, ['title' => 'Cómo cuidar la madera']);
    $web = new TestClient($app);

    $r = $web->get('/llms.txt');
    same(200, $r->status);
    contains('text/plain', $r->contentType());
    check(strpos($r->body, '# Muebles Norte' . "\n") === 0, 'empieza por «# »');
    contains("\n> Muebles de roble hechos a mano.\n", $r->body);
    contains("## Categorías\n\n- [Sillas](https://tienda.test/categoria/sillas.md)", $r->body);
    contains("## Productos\n\n- [Silla de roble](https://tienda.test/producto/silla.md): ", $r->body);
    contains('- [Cómo cuidar la madera](https://tienda.test/blog/como-cuidar-la-madera.md)', $r->body);
    contains('## Cómo consultar esta tienda', $r->body);
    contains('(https://tienda.test/.well-known/aifec.json)', $r->body);
});

test('sin resumen escrito, el resumen sale de los datos de la tienda', function (): void {
    $app = semanticApp(['semantic'], ['store.name' => 'Muebles Norte', 'store.legal_name' => 'Muebles Norte SL', 'store.email' => 'tienda@ejemplo.test']);
    $web = new TestClient($app);

    contains('> Muebles Norte es una tienda en línea. Razón social: Muebles Norte SL. Contacto: tienda@ejemplo.test.', $web->get('/llms.txt')->body);
});

test('los enlaces del llms.txt son versiones en texto que existen: dos que se piden dan 200 y Markdown', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    $sillas = aifecCategory($app, 'Sillas');
    semanticProduct($app, ['categories' => [$sillas]]);
    aifecProduct($app, ['name' => 'Mesa baja', 'categories' => [$sillas]]);
    $web = new TestClient($app);

    preg_match_all('#\]\((https://tienda\.test/[^)]*\.md)\)#', $web->get('/llms.txt')->body, $m);
    check(count($m[1]) >= 3, 'hay enlaces a versiones en texto');
    foreach (array_slice($m[1], 0, 2) as $url) {
        $r = $web->get(substr($url, strlen('https://tienda.test')));
        same(200, $r->status, $url);
        same('text/plain; charset=UTF-8', $r->contentType(), 'la dirección con .md se sirve como texto plano');
    }
    foreach ($m[1] as $url) {
        same(200, $web->get(substr($url, strlen('https://tienda.test')))->status, 'existe ' . $url);
    }
});

test('el llms.txt lleva como mucho los 30 productos más vendidos', function (): void {
    $app = semanticApp();
    for ($i = 1; $i <= 35; $i++) {
        aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'sales_count' => $i]);
    }
    $web = new TestClient($app);

    $body = $web->get('/llms.txt')->body;
    same(30, preg_match_all('#\(https://tienda\.test/producto/[^)]*\.md\)#', $body), 'treinta enlaces de producto');
    contains('[Producto 35](', $body);
    contains('[Producto 6](', $body);
    notContains('[Producto 5](', $body);
    contains("## Productos\n\nLos 30 más vendidos de 35 productos. La lista entera: https://tienda.test/llms-full.txt y la API https://tienda.test/api/v1/products. 0 de los 35 están en los feeds de producto; el resto no tiene imagen, no enseña el precio o es gratuito.\n\n- [Producto 35](", $body, 'dice que es una muestra y dónde está el resto');
});

test('el enlace al servidor MCP dice qué es: no es una página para leer', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'mcp']);
    $body = (new TestClient($app))->get('/llms.txt')->body;
    contains('(https://tienda.test/mcp): un servidor del protocolo MCP (JSON-RPC por POST), no una página para leer', $body);
    // Y lo dice lo primero, tras el resumen, con su ficha: al final de la lista las IA no lo veían (17/11/2026).
    $pos = strpos($body, '**Servidor MCP (sin clave):** https://tienda.test/mcp');
    check($pos !== false && $pos < strpos($body, '## '), 'el MCP, antes de la primera sección: ' . substr($body, 0, 500));
    contains('https://tienda.test/.well-known/mcp/server-card.json', $body);
});

test('un lector que solo sigue enlaces llega al llms.txt, al MCP y a la compra con enlaces desde la portada y el índice en texto', function (): void {
    // Revisión externa del 17/11/2026: el llms.txt existía, pero ninguna página lo enlazaba.
    $app = semanticApp(['semantic', 'catalog', 'media', 'mcp', 'api', 'cms']);
    $client = new TestClient($app);
    $home = $client->get('/', ['accept' => 'text/html'])->body;
    contains('<link rel="alternate" type="text/plain" title="llms.txt" href="https://tienda.test/llms.txt">', $home, 'en la cabecera');
    contains('<a class="af-nav-link" href="https://tienda.test/llms.txt">', $home, 'y en el pie, como enlace que se puede seguir');
    contains('href="https://tienda.test/mcp"', $home);
    contains('href="https://tienda.test/buy-with-links"', $home);
    $index = $client->get('/index.md')->body;
    contains('(https://tienda.test/llms.txt)', $index, 'el índice en texto lo enlaza');
    contains('(https://tienda.test/mcp)', $index);
});

test('los enlaces del llms.txt se pueden cambiar con el filtro «llms.sections»', function (): void {
    $app = semanticApp();
    $app->hooks()->filter('llms.sections', static function ($sections): array {
        $sections[] = ['id' => 'extra', 'title' => 'Guías', 'links' => [['title' => 'Guía de compra', 'url' => 'https://tienda.test/guia.md', 'note' => 'Cómo elegir', 'ref' => null]]];

        return $sections;
    });
    $web = new TestClient($app);

    contains("## Guías\n\n- [Guía de compra](https://tienda.test/guia.md): Cómo elegir", $web->get('/llms.txt')->body);
});

test('las páginas legales publicadas salen en «Políticas y páginas legales» y las borradores no', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    $aviso = cmsInsertPage($app, ['title' => 'Aviso legal']);
    $privacidad = cmsInsertPage($app, ['title' => 'Privacidad', 'status' => 'draft']);
    $app->settings()->set('cms.legal', ['legal-notice' => $aviso, 'privacy' => $privacidad]);
    $web = new TestClient($app);

    $body = $web->get('/llms.txt')->body;
    contains("## Políticas y páginas legales\n\n- [Aviso legal](https://tienda.test/aviso-legal.md)", $body);
    notContains('Privacidad', $body);
});

test('llms-full.txt lleva el texto de cada página enlazada y respeta el tope de tamaño de los ajustes', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.llms_full_kb' => 500]);
    for ($i = 1; $i <= 6; $i++) {
        aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'description' => '<p>' . str_repeat('Texto largo del producto ' . $i . '. ', 40) . '</p>']);
    }
    $web = new TestClient($app);

    $full = $web->get('/llms-full.txt');
    same(200, $full->status);
    contains('## Producto 3', $full->body, 'cada página enlazada baja un nivel de encabezado');
    contains('Texto largo del producto 3.', $full->body);
    check(strlen($full->body) > 3000, 'lleva el contenido de las páginas');

    $app->settings()->set('semantic.llms_full_kb', 1);
    $app->invalidate(['semantic']);
    $small = (new TestClient($app))->get('/llms-full.txt');
    check(strlen($small->body) <= 1024, 'con un tope de 1 KB no pasa de 1024 bytes: ' . strlen($small->body));
    check(strlen($small->body) > 0, 'algo sale');
    check(preg_match('//u', $small->body) === 1, 'el corte no parte un carácter');
    $app->settings()->set('semantic.llms_full_kb', 3);
    $app->invalidate(['semantic']);
    $medium = (new TestClient($app))->get('/llms-full.txt');
    check(strlen($medium->body) <= 3072, 'con un tope de 3 KB no pasa de 3072 bytes: ' . strlen($medium->body));
    contains('(La lista se corta aquí: llms-full.txt tiene un tamaño máximo.)', $medium->body);
});
