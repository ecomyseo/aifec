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
 * Los feeds de producto: google.xml, products.json y openai.json, generados a ficheros y servidos desde el fichero.
 */

/**
 * @return array<int,array<string,string>> los elementos de un google.xml: nombre de la etiqueta (sin «g:») => texto
 */
function semanticGoogleItems(string $xml): array
{
    $dom = new \DOMDocument();
    check($dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING), 'google.xml es XML válido');
    $items = [];
    foreach ($dom->getElementsByTagName('item') as $node) {
        $fields = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $fields[str_replace('g:', '', $child->tagName)] = $child->textContent;
            }
        }
        $items[] = $fields;
    }

    return $items;
}

test('google.xml es RSS válido con un item por variante activa, con el precio como «19.99 EUR» y el grupo de variantes', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app, ['brand_id' => aifecBrand($app, 'Maderas Norte'), 'mpn' => 'MN-1', 'variants' => [
        ['sku' => 'S-R', 'label' => 'Rojo', 'price' => 1999, 'stock' => 3, 'values' => ['color' => 'rojo']],
        ['sku' => 'S-A', 'label' => 'Azul', 'price' => 2199, 'stock' => 0, 'values' => ['color' => 'azul']],
        ['sku' => 'S-V', 'label' => 'Verde', 'price' => 1899, 'stock' => 5, 'active' => 0, 'values' => ['color' => 'verde']],
    ]]);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete', 'price' => 999]);

    semanticBuildFeeds($app);
    $xml = semanticFeed($app, 'google.xml');
    contains('xmlns:g="http://base.google.com/ns/1.0"', $xml);
    $items = semanticGoogleItems($xml);
    same(3, count($items), 'dos variantes activas y un producto simple');

    $byId = [];
    foreach ($items as $item) {
        $byId[$item['id']] = $item;
    }
    $red = $byId[$id . '-' . (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['S-R'])];
    same('19.99 EUR', $red['price']);
    same('in_stock', $red['availability']);
    same('Silla de roble - Rojo', $red['title']);
    same('https://tienda.test/producto/silla', $red['link']);
    same('Maderas Norte', $red['brand']);
    same('MN-1', $red['mpn']);
    same('new', $red['condition']);
    same((string) $id, $red['item_group_id']);
    same('Rojo', $red['color']);
    check(strpos($red['image_link'], 'https://tienda.test/media/') === 0, 'la imagen lleva la dirección completa');
    $blue = $byId[$id . '-' . (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['S-A'])];
    same('out_of_stock', $blue['availability']);
    same('21.99 EUR', $blue['price']);
    $stool = $byId[(string) ((int) $app->db()->value('SELECT id FROM {products} WHERE id <> ? ORDER BY id DESC', [$id]))];
    same('9.99 EUR', $stool['price']);
    same('no', $stool['identifier_exists'], 'sin código de barras ni referencia, Google lo pide así');
    check(!isset($stool['item_group_id']), 'un producto simple no lleva grupo');
});

test('google.xml no incluye borradores, ocultos, productos sin precio ni productos sin imagen', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => 'Bueno', 'slug' => 'bueno']);
    semanticProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    semanticProduct($app, ['name' => 'Oculto', 'slug' => 'oculto', 'visibility' => 'hidden']);
    semanticProduct($app, ['name' => 'Sin imagen', 'slug' => 'sin-imagen', 'images' => 0]);
    semanticProduct($app, ['name' => 'Sin precio', 'slug' => 'sin-precio', 'price' => 0]);

    semanticBuildFeeds($app);
    $titles = array_column(semanticGoogleItems(semanticFeed($app, 'google.xml')), 'title');
    same(['Bueno'], $titles);
    // products.json sí lleva el que no tiene imagen: solo google.xml la exige.
    $names = array_column(json_decode(semanticFeed($app, 'products.json'), true)['products'], 'name');
    sort($names);
    same(['Bueno', 'Sin imagen', 'Sin precio'], $names);
});

test('una oferta sale como precio normal y precio de oferta, y el de antes se calcula con impuestos', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['price' => 2000, 'sale_price' => 1500, 'sale_from' => '2026-11-01 00:00:00', 'sale_to' => '2026-12-24 22:59:59']);

    semanticBuildFeeds($app);
    $item = semanticGoogleItems(semanticFeed($app, 'google.xml'))[0];
    same('20.00 EUR', $item['price']);
    same('15.00 EUR', $item['sale_price']);
    $openai = json_decode(semanticFeed($app, 'openai.json'), true)['products'][0];
    same('20.00 EUR', $openai['price']);
    same('15.00 EUR', $openai['sale_price']);
});

test('products.json es JSON válido con generated_at y la lista pública de productos, sin datos internos', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['sku' => 'SILLA-1', 'gtin' => '8412345678905', 'cost' => 700, 'description' => '<p>Roble <b>macizo</b></p>', 'faqs' => [['question' => '¿Q?', 'answer' => 'R']],
        'extra' => ['coste' => ['label' => 'Coste', 'value' => '7', 'type' => 'text', 'public' => false], 'garantia' => ['label' => 'Garantía', 'value' => '2 años', 'type' => 'text', 'public' => true]]]);

    semanticBuildFeeds($app);
    $data = json_decode(semanticFeed($app, 'products.json'), true);
    check(is_array($data), 'products.json es JSON válido');
    same('2026-11-12T12:00:00Z', $data['generated_at']);
    same(1, $data['count']);
    same(1, count($data['products']));
    $p = $data['products'][0];
    same('Silla de roble', $p['name']);
    same('Roble macizo', $p['description']);
    same('https://tienda.test/producto/silla', $p['url']);
    same(1999, $p['price']['amount']);
    same('19.99', $p['price']['decimal']);
    same('in_stock', $p['availability']['status']);
    same('SILLA-1', $p['sku']);
    same('¿Q?', $p['faqs'][0]['question']);
    same(['garantia' => ['label' => 'Garantía', 'value' => '2 años']], $p['extra'], 'solo los datos extra públicos');
    notContains('cost', json_encode($p), 'nada de precios de coste');
});

test('openai.json es JSON válido con los campos del feed de compra, las variantes, las devoluciones, las valoraciones y los indicadores', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.returns.days' => 14, 'semantic.openai.enable_checkout' => true]);
    semanticProduct($app, ['gtin' => '8412345678905', 'rating_sum' => 46, 'rating_count' => 10, 'variants' => [
        ['sku' => 'S-R', 'label' => 'Rojo', 'price' => 1999, 'stock' => 3, 'values' => ['color' => 'rojo']],
        ['sku' => 'S-A', 'label' => 'Azul', 'price' => 2199, 'stock' => 7, 'values' => ['color' => 'azul']],
    ]]);

    semanticBuildFeeds($app);
    $data = json_decode(semanticFeed($app, 'openai.json'), true);
    check(is_array($data), 'openai.json es JSON válido');
    $p = $data['products'][0];
    same('Silla de roble', $p['title']);
    same('https://tienda.test/producto/silla', $p['link']);
    same('19.99 EUR', $p['price']);
    same('8412345678905', $p['gtin']);
    same(4.6, $p['star_rating']);
    same(10, $p['review_count']);
    same(true, $p['enable_search']);
    same(false, $p['enable_checkout'], 'el ajuste por sí solo no anuncia un checkout que no está activo');
    same(14, $p['return_policy']['days']);
    same(2, count($p['variants']));
    same('Rojo', $p['variants'][0]['options']['color']);
    same(3, $p['variants'][0]['inventory_quantity']);
    same('19.99 EUR', $p['variants'][0]['price']);
});

test('un nombre con «&», «<» y comillas sale bien escapado en XML y en JSON', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => 'Mesa <grande> & "roja" \'2\'', 'slug' => 'mesa', 'description' => '<p>A &amp; B &lt;C&gt;</p>']);

    semanticBuildFeeds($app);
    $xml = semanticFeed($app, 'google.xml');
    contains('<title>Mesa &lt;grande&gt; &amp; &quot;roja&quot; &apos;2&apos;</title>', $xml);
    same('Mesa <grande> & "roja" \'2\'', semanticGoogleItems($xml)[0]['title'], 'el XML se lee de vuelta igual');
    same('A & B <C>', semanticGoogleItems($xml)[0]['description']);
    same('Mesa <grande> & "roja" \'2\'', json_decode(semanticFeed($app, 'products.json'), true)['products'][0]['name']);
    same('Mesa <grande> & "roja" \'2\'', json_decode(semanticFeed($app, 'openai.json'), true)['products'][0]['title']);
});

test('los caracteres de control no rompen el XML', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => "Silla" . chr(1) . chr(8) . " con control", 'slug' => 'silla']);

    semanticBuildFeeds($app);
    same('Silla con control', semanticGoogleItems(semanticFeed($app, 'google.xml'))[0]['title']);
});

test('cambiar un precio encola un solo trabajo aunque se guarden tres productos seguidos', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    $ids = [semanticProduct($app), semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete']), semanticProduct($app, ['name' => 'Mesa', 'slug' => 'mesa'])];
    $count = static function () use ($app): int {
        return (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.feeds' AND status = 'pending'");
    };
    same(0, $count());

    foreach ($ids as $id) {
        $app->hooks()->fire('admin.resource.saved', 'products', $id);
    }
    $app->hooks()->fire('stock.changed', $ids[0], 0, 4);
    $app->hooks()->fire('admin.resource.saved', 'categories', 1);
    same(1, $count(), 'un solo trabajo pendiente');
    $job = $app->db()->row("SELECT run_at, unique_key FROM {jobs} WHERE type = 'semantic.feeds'");
    same('semantic.feeds', $job['unique_key']);
    same($app->clock()->sql($app->clock()->now() + 300), $job['run_at'], 'con cinco minutos de retraso');

    $app->hooks()->fire('admin.resource.saved', 'pages', 1);
    same(1, $count(), 'lo que no es del catálogo no encola nada más');
});

test('el trabajo encolado rehace los feeds al ejecutarse y la tarea periódica los rehace cada hora', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    semanticProduct($app);
    $queue = $app->service('jobs');
    $queue->push('semantic.feeds', [], ['unique' => 'semantic.feeds']);

    $done = $queue->work(5, 10);
    same(1, $done['done']);
    same('Feeds built: google.xml: 1, products.json: 1, openai.json: 1', $app->db()->value("SELECT result FROM {jobs} WHERE type = 'semantic.feeds'"));
    check(is_file($app->site()->path('feeds') . '/google.xml'), 'el fichero existe');

    $tasks = $queue->tasks();
    $found = false;
    foreach ($tasks as $task) {
        if (isset($task['task']) && $task['task'] === 'semantic.feeds') {
            $found = true;
            same('hourly', $task['every']);
        }
    }
    check($found, 'la tarea periódica semantic.feeds está registrada');
});

test('los feeds se sirven desde el fichero con ETag, un 304 si no ha cambiado y un 404 si está apagado o no hay catálogo', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $web = new TestClient($app);

    same(200, $web->get('/feeds/google.xml')->status, 'sin generar, la primera petición lo genera (FeedsFirstRequestTest)');
    semanticBuildFeeds($app);

    $noCatalog = semanticApp(['semantic']);
    same(404, (new TestClient($noCatalog))->get('/feeds/google.xml')->status, 'sin catálogo no hay nada que generar');

    $r = $web->get('/feeds/google.xml');
    same(200, $r->status);
    contains('application/xml', $r->contentType());
    check(is_string($r->file) && strpos((string) file_get_contents($r->file), '<rss') !== false, 'se sirve el fichero');
    $etag = $r->headers['ETag'];
    check($etag !== '', 'lleva ETag');
    $again = $web->get('/feeds/google.xml', ['if-none-match' => $etag]);
    same(304, $again->status);
    same('', $again->body);
    same(200, $web->get('/feeds/google.xml', ['if-none-match' => '"otro"'])->status);
    contains('application/json', $web->get('/feeds/products.json')->contentType());
    same(200, $web->get('/feeds/openai.json')->status);

    $app->settings()->set('semantic.feeds.openai', false);
    same(404, $web->get('/feeds/openai.json')->status, 'un feed apagado no se sirve');
    same(404, $web->get('/feeds/otro.json')->status);
});

test('un feed apagado no se genera y, con todos apagados, no se escribe nada', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.feeds.google' => false, 'semantic.feeds.products' => true, 'semantic.feeds.openai' => false]);
    semanticProduct($app);

    semanticBuildFeeds($app);
    check(!is_file($app->site()->path('feeds') . '/google.xml'), 'google.xml apagado');
    check(is_file($app->site()->path('feeds') . '/products.json'), 'products.json encendido');

    $other = semanticApp(['semantic', 'catalog', 'media'], ['semantic.feeds.google' => false, 'semantic.feeds.products' => false, 'semantic.feeds.openai' => false]);
    semanticProduct($other);
    semanticBuildFeeds($other);
    same([], glob($other->site()->path('feeds') . '/*') ?: [], 'no se escribe nada');
});

test('el filtro «feed.item» cambia un elemento o lo quita, y no queda ningún fichero temporal', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete']);
    $formats = [];
    $app->hooks()->filter('feed.item', static function ($item, $product, $format) use (&$formats) {
        $formats[$format] = ($formats[$format] ?? 0) + 1;
        if ($product['name'] === 'Taburete') {
            return [];
        }
        if ($format === 'google') {
            $item['custom_label_0'] = 'novedad';
        } else {
            $item['etiqueta'] = 'novedad';
        }

        return $item;
    });

    semanticBuildFeeds($app);
    same(['google' => 2, 'products' => 2, 'openai' => 2], $formats);
    $items = semanticGoogleItems(semanticFeed($app, 'google.xml'));
    same(1, count($items));
    same('novedad', $items[0]['custom_label_0']);
    same(['Silla'], array_column(json_decode(semanticFeed($app, 'products.json'), true)['products'], 'name'));
    same('novedad', json_decode(semanticFeed($app, 'openai.json'), true)['products'][0]['etiqueta']);
    same([], glob($app->site()->path('feeds') . '/*.tmp') ?: [], 'sin temporales');
});

test('un catálogo de más de 200 productos sale entero, por tandas, sin repetir ni perder ninguno', function (): void {
    $app = semanticApp();
    for ($i = 1; $i <= 205; $i++) {
        semanticProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'images' => 1]);
    }

    semanticBuildFeeds($app);
    $items = semanticGoogleItems(semanticFeed($app, 'google.xml'));
    same(205, count($items));
    same(205, count(array_unique(array_column($items, 'id'))), 'ninguno repetido');
    $data = json_decode(semanticFeed($app, 'products.json'), true);
    same(205, $data['count']);
    same(205, count($data['products']));
    same(205, json_decode(semanticFeed($app, 'openai.json'), true)['count']);
});
