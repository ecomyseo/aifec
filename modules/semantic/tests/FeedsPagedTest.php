<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Los feeds y llms-full.txt en páginas: un lector de páginas corta hacia los 100.000 caracteres, así que cada página cabe de sobra,
 * lleva el enlace a la siguiente y el índice las lista. Con un catálogo de 5.000 productos.
 */

declare(strict_types=1);

use Aifec\Modules\Semantic\Feeds;
use Aifec\Modules\Semantic\FeedParts;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../orders/tests/helpers.php';

/** El cuerpo de una respuesta, o el fichero que se sirve (los feeds se sirven desde su fichero). */
function fpBody(\Aifec\Kernel\Response $r): string
{
    return is_string($r->file) && is_file($r->file) ? (string) file_get_contents($r->file) : $r->body;
}

/** Una tienda con $n productos con imagen: se clona un producto modelo directamente en las tablas (por el catálogo serían minutos). */
function fpApp(int $n): \Aifec\Kernel\App
{
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs'], ['semantic.llms_full_kb' => 5000]);
    $model = semanticProduct($app, ['name' => 'Producto modelo', 'slug' => 'modelo']);
    $db = $app->db();
    $now = $app->clock()->sql();
    $product = (array) $db->find('products', ['id' => $model]);
    $i18n = $db->select('SELECT * FROM {product_i18n} WHERE product_id = ?', [$model]);
    $slugs = $db->select("SELECT * FROM {slugs} WHERE type = 'product' AND entity_id = ?", [$model]);
    $media = $db->select('SELECT * FROM {product_media} WHERE product_id = ?', [$model]);
    check($i18n !== [] && $slugs !== [] && $media !== [], 'el producto modelo tiene textos, dirección e imágenes');
    $db->transaction(static function ($db) use ($n, $product, $i18n, $slugs, $media, $now): void {
        for ($i = 1; $i <= $n; $i++) {
            $row = $product;
            unset($row['id']);
            foreach (['created_at', 'updated_at'] as $c) {
                if (array_key_exists($c, $row)) {
                    $row[$c] = $now;
                }
            }
            if (array_key_exists('sku', $row)) {
                $row['sku'] = 'SKU-' . $i;
            }
            $id = $db->insert('products', $row);
            foreach ($i18n as $t) {
                unset($t['id']);
                $t['product_id'] = $id;
                $t['name'] = 'Silla modelo ' . $i;
                $db->insert('product_i18n', $t);
            }
            foreach ($slugs as $sl) {
                unset($sl['id']);
                $sl['entity_id'] = $id;
                $sl['path'] = 'producto/silla-modelo-' . $i;
                $db->insert('slugs', $sl);
            }
            foreach ($media as $m) {
                unset($m['id']);
                $m['product_id'] = $id;
                $db->insert('product_media', $m);
            }
        }
    });

    return $app;
}

test('con 5.000 productos cada página de los feeds JSON cabe de sobra en un lector de páginas y entre todas están todos los productos', function (): void {
    $app = fpApp(4999);
    $t0 = microtime(true);
    semanticBuildFeeds($app);
    $built = microtime(true) - $t0;
    $web = new TestClient($app);

    $index = json_decode(fpBody($web->get('/feeds/index.json')), true);
    check(isset($index['feeds']['openai'], $index['feeds']['products'], $index['feeds']['google']), 'el índice lista los tres feeds');
    foreach (['openai', 'products'] as $key) {
        $entry = $index['feeds'][$key];
        check($entry['bytes'] > Feeds::SINGLE_MAX_BYTES, $key . ': el feed entero (' . $entry['bytes'] . ' bytes) no cabe en un lector');
        check($entry['pages'] > 20, $key . ': hay muchas páginas (' . $entry['pages'] . ')');
        same(true, $entry['paged_by_default'], $key . ' se sirve por páginas si no se pide otra cosa');
        $seen = 0;
        $ids = [];
        foreach ($entry['parts'] as $part) {
            check($part['bytes'] < 80000, $key . ' página ' . $part['page'] . ' con ' . $part['bytes'] . ' bytes, menos de 80 KB');
            $seen += $part['items'];
        }
        same($entry['items'], $seen, $key . ': las páginas suman todos los productos del feed');
        check($entry['items'] >= 4990, $key . ' lleva los productos (' . $entry['items'] . ')');
        // Seguir «next» desde la primera página hasta el final: sin saltos, sin repetidos.
        $url = '/feeds/' . $entry['file'];
        $guard = 0;
        $pages = 0;
        while ($url !== '' && $guard++ < $entry['pages'] + 2) {
            $r = $web->get($url);
            same(200, $r->status, $key . ' ' . $url);
            $body = fpBody($r);
            check(strlen($body) < 80000, $key . ' ' . $url . ' pesa ' . strlen($body));
            $doc = json_decode($body, true);
            check(is_array($doc) && isset($doc['pagination']), 'la página es JSON con su paginación');
            $pages++;
            foreach ($doc['products'] as $p) {
                $id = isset($p['id']) ? (string) $p['id'] : (isset($p['url']) ? (string) $p['url'] : json_encode($p));
                check(!isset($ids[$id]), $key . ': producto repetido ' . $id);
                $ids[$id] = true;
            }
            same($pages, $doc['pagination']['page']);
            same($entry['pages'], $doc['pagination']['pages']);
            if (isset($doc['pagination']['next'])) {
                contains('rel="next"', (string) $r->headers['Link'], 'la cabecera Link lleva la siguiente');
                same((string) $entry['pages'], (string) $r->headers['X-Total-Pages']);
                $url = (string) preg_replace('#^https?://[^/]+#', '', $doc['pagination']['next']);
            } else {
                notContains('rel="next"', (string) ($r->headers['Link'] ?? ''), 'la última no tiene siguiente');
                $url = '';
            }
        }
        same($entry['pages'], $pages, $key . ': se recorren todas las páginas siguiendo «next»');
        same($entry['items'], count($ids), $key . ': y están todos los productos, sin repetir');
    }
    // El feed entero sigue disponible con ?all=1, y el de Google, que Merchant Center necesita entero, no se trocea.
    $all = $web->get('/feeds/openai.json?all=1');
    same(200, $all->status);
    check(strlen(fpBody($all)) > 1000000, 'todo, en un solo fichero (' . strlen(fpBody($all)) . ' bytes)');
    same(false, isset($index['feeds']['google']['pages']), 'google.xml no se trocea');
    check(strlen(fpBody($web->get('/feeds/google.xml'))) > 1000000, 'google.xml entero');
    // Páginas que no existen
    same(404, $web->get('/feeds/openai.json?page=' . ($index['feeds']['openai']['pages'] + 1))->status);
    same(404, $web->get('/feeds/openai.json?page=0')->status);
    same(404, $web->get('/feeds/openai.json?page=abc')->status);
});

test('un catálogo pequeño no cambia: el feed entero cabe en un lector y se sirve tal cual, con su página 1 igual', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media']);
    for ($i = 1; $i <= 5; $i++) {
        semanticProduct($app, ['name' => 'Silla ' . $i, 'slug' => 'silla-' . $i]);
    }
    semanticBuildFeeds($app);
    $web = new TestClient($app);
    $full = $web->get('/feeds/openai.json');
    same(200, $full->status);
    check(strlen(fpBody($full)) <= Feeds::SINGLE_MAX_BYTES, 'cabe en un lector');
    $doc = json_decode(fpBody($full), true);
    same(false, isset($doc['pagination']), 'el feed entero no lleva paginación');
    $first = json_decode(fpBody($web->get('/feeds/openai.json?page=1')), true);
    same(5, count($first['products']), 'la página 1 lleva los mismos cinco');
    same(1, $first['pagination']['pages']);
    same(false, isset($first['pagination']['next']));
    $index = json_decode(fpBody($web->get('/feeds/index.json')), true);
    same(false, $index['feeds']['openai']['paged_by_default']);
});

test('un feed de una versión anterior (sin páginas ni índice) se sigue sirviendo entero y encola su regeneración', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    semanticProduct($app);
    semanticBuildFeeds($app);
    $dir = $app->site()->path('feeds');
    foreach (glob($dir . '/*.p*.json') ?: [] as $file) {
        unlink($file);
    }
    unlink($dir . '/index.json');
    // Hacerlo grande sin páginas: engordar el fichero entero.
    file_put_contents($dir . '/openai.json', json_encode(['products' => [], 'pad' => str_repeat('x', Feeds::SINGLE_MAX_BYTES + 10)]));
    $web = new TestClient($app);
    $r = $web->get('/feeds/openai.json');
    same(200, $r->status, 'se sirve el fichero que hay');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.feeds' AND status = 'pending'"), 'y se encola rehacerlo');
    $i = $web->get('/feeds/index.json');
    same(503, $i->status, 'sin índice: espera');
    check(isset($i->headers['Retry-After']), 'con Retry-After');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.feeds' AND status = 'pending'"), 'sin encolar otro por cada petición');
    semanticBuildFeeds($app);
    same(200, $web->get('/feeds/index.json')->status, 'regenerados, el índice existe');
});

test('llms-full.txt en páginas: cada una cabe de sobra, con la siguiente enlazada, y entre todas está el texto de cada página enlazada', function (): void {
    // El llms.txt enlaza los 30 más vendidos: con textos largos, el llms-full.txt entero pasa de 100.000 caracteres.
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.llms_full_kb' => 5000]);
    for ($i = 1; $i <= 35; $i++) {
        semanticProduct($app, ['name' => 'Silla modelo ' . $i, 'slug' => 'silla-modelo-' . $i, 'description' => 'Texto largo de la silla ' . $i . '. ' . str_repeat('Madera maciza, acabado a mano y garantía de por vida. ', 100)]);
    }
    $web = new TestClient($app);
    $all = $web->get('/llms-full.txt?all=1');
    same(200, $all->status);
    check(strlen($all->body) > 120000, 'el llms-full.txt entero no cabe en un lector (' . strlen($all->body) . ' bytes)');
    $first = $web->get('/llms-full.txt');
    same(200, $first->status);
    check(strlen($first->body) < 80000, 'la primera pesa ' . strlen($first->body));
    $pages = (int) ($first->headers['X-Total-Pages'] ?? 1);
    check($pages >= 3, 'hay varias páginas (' . $pages . ')');
    contains('rel="next"', (string) $first->headers['Link']);
    contains('Sigue en la página 2 de ' . $pages . ': https://tienda.test/llms-full.txt?page=2', $first->body);
    $text = $first->body;
    for ($n = 2; $n <= $pages; $n++) {
        $r = $web->get('/llms-full.txt?page=' . $n);
        same(200, $r->status);
        check(strlen($r->body) < 80000, 'la página ' . $n . ' pesa ' . strlen($r->body));
        contains('(llms-full.txt, página ' . $n . ' de ' . $pages . '.', $r->body);
        $text .= $r->body;
    }
    notContains('Sigue en la página', $web->get('/llms-full.txt?page=' . $pages)->body, 'la última no continúa');
    for ($i = 1; $i <= 30; $i += 7) {
        contains('Texto largo de la silla ' . $i . '.', $text, 'el texto de la silla ' . $i . ' sale en alguna página');
    }
    same(substr_count($all->body, 'Madera maciza'), substr_count($text, 'Madera maciza'), 'entre todas las páginas está lo mismo que en el fichero entero');
    same(404, $web->get('/llms-full.txt?page=' . ($pages + 1))->status);
    same(404, $web->get('/llms-full.txt?page=0')->status);
});

test('el feed de ACP, con ?page=N, da una tanda de productos con Link y total de páginas; sin ?page sigue siendo entero', function (): void {
    $app = aifecOrderApp(['payment' => false, 'modules' => ['agentic', 'semantic'], 'settings' => [
        'modules.active' => ['orders', 'mail', 'media', 'jobs', 'agentic', 'semantic'], 'agentic.acp_enabled' => true,
    ]]);
    for ($i = 1; $i <= 45; $i++) {
        aifecProduct($app, ['name' => 'Silla ' . $i, 'slug' => 'silla-' . $i, 'price' => 1000 + $i, 'stock' => 5, 'images' => 1]);
    }
    $web = new TestClient($app);
    $whole = trim($web->get('/acp/feed.jsonl')->body);
    $total = count(array_filter(explode("\n", $whole)));
    check($total >= 40, 'el feed entero lleva los productos (' . $total . ')');
    $r = $web->get('/acp/feed.jsonl?page=1&per_page=20');
    same(200, $r->status);
    same('3', (string) $r->headers['X-Total-Pages']);
    contains('rel="next"', (string) $r->headers['Link']);
    same(20, count(array_filter(explode("\n", trim($r->body)))), 'veinte productos en la primera tanda');
    same(20, count(array_filter(explode("
", trim($web->get('/acp/feed.jsonl?page=1')->body)))), 'sin per_page, veinte por tanda');
    $last = $web->get('/acp/feed.jsonl?page=3&per_page=20');
    notContains('rel="next"', (string) $last->headers['Link']);
    contains('rel="prev"', (string) $last->headers['Link']);
    $sum = 0;
    foreach ([1, 2, 3] as $n) {
        $sum += count(array_filter(explode("\n", trim($web->get('/acp/feed.jsonl?page=' . $n . '&per_page=20')->body))));
    }
    same($total, $sum, 'las tandas suman el feed entero');
    same(404, $web->get('/acp/feed.jsonl?page=4&per_page=20')->status);
    same(404, $web->get('/acp/feed.jsonl?page=0')->status);
});

test('lo troceado se anuncia: llms.txt, aifec.json y el catálogo de IA apuntan al índice de feeds', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'mcp']);
    semanticProduct($app);
    semanticBuildFeeds($app);
    $web = new TestClient($app);
    $llms = $web->get('/llms.txt')->body;
    contains('https://tienda.test/feeds/index.json', $llms, 'llms.txt enlaza el índice');
    contains('pagination.next', $llms, 'y explica cómo seguir');
    $doc = json_decode($web->get('/.well-known/aifec.json')->body, true);
    same('https://tienda.test/feeds/index.json', $doc['gates']['feeds_index']);
    $ard = json_decode($web->get('/.well-known/ard.json')->body, true);
    $urls = array_column($ard['entries'], 'url');
    check(in_array('https://tienda.test/feeds/index.json', $urls, true), 'ard.json lista el índice: ' . json_encode($urls));
});

test('FeedParts: reparte por tamaño, cada página lleva su cabecera y sus enlaces, y borra las páginas de una generación anterior', function (): void {
    $dir = sys_get_temp_dir() . '/aifec-feedparts-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $old = $dir . '/demo.p9.json';
    file_put_contents($old, 'viejo');
    $parts = new FeedParts($dir, 'demo');
    $item = json_encode(['id' => 1, 'pad' => str_repeat('a', 30000)]);
    for ($i = 0; $i < 7; $i++) {
        $parts->add($item);
    }
    $meta = $parts->finish('"shop":"X"', static function (int $n): string {
        return 'https://x.test/f?page=' . $n;
    }, 'https://x.test/f?all=1', 'https://x.test/i');
    same(4, count($meta), 'siete productos de 30 KB, dos por página, cuatro páginas');
    same(2, $meta[0]['items']);
    same(1, $meta[3]['items']);
    check(!is_file($old), 'la página de una generación anterior se borra');
    $second = json_decode((string) file_get_contents($dir . '/demo.p2.json'), true);
    same(2, $second['pagination']['page']);
    same('https://x.test/f?page=1', $second['pagination']['prev']);
    same('https://x.test/f?page=3', $second['pagination']['next']);
    same('https://x.test/i', $second['pagination']['index']);
    same(2, $second['count']);
    foreach (glob($dir . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($dir);
});
