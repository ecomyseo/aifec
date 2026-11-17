<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * IndexNow (17/11/2026): la clave en /indexnow.txt y el aviso a Bing de las fichas de producto, para que ChatGPT (que se
 * apoya en parte en el índice de Bing) encuentre la tienda.
 */

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Seo\IndexNow;

require_once __DIR__ . '/helpers.php';

test('/indexnow.txt da la clave en texto plano, 404 sin clave; el robots.txt de la tienda cerrada la deja leer', function (): void {
    $none = seoApp(['seo', 'catalog', 'media']);
    same(404, (new TestClient($none))->get('/indexnow.txt')->status, 'sin clave, no hay fichero');

    $app = seoApp(['seo', 'catalog', 'media'], ['seo.indexnow_key' => 'abcdef0123456789abcdef0123456789', 'seo.noindex_site' => true]);
    $client = new TestClient($app);
    $r = $client->get('/indexnow.txt');
    same(200, $r->status);
    same('abcdef0123456789abcdef0123456789', trim($r->body));
    contains('text/plain', (string) $r->headers['Content-Type']);
    contains("Allow: /indexnow.txt\n", $client->get('/robots.txt')->body, 'la tienda cerrada deja leer la clave');
});

test('seo:indexnow manda a api.indexnow.org las direcciones de las fichas, con la clave y dónde está', function (): void {
    $http = new FakeHttpClient();
    $http->queue(new HttpResponse(202, [], ''));
    $app = testApp(['modules' => ['seo', 'catalog', 'media'], 'http' => $http, 'settings' => ['seo.indexnow_key' => 'abcdef0123456789abcdef0123456789']]);
    $app->boot();
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $urls = IndexNow::productUrls($app);
    contains('https://tienda.test/producto/silla-ergo', implode(' ', $urls), 'las fichas del mapa del sitio');
    $result = IndexNow::submit($app, $urls);
    same(202, $result['status']);
    same(1, count($http->requests));
    same('https://api.indexnow.org/indexnow', $http->requests[0]->url);
    $body = json_decode((string) $http->requests[0]->body, true);
    same('tienda.test', $body['host']);
    same('abcdef0123456789abcdef0123456789', $body['key']);
    same('https://tienda.test/indexnow.txt', $body['keyLocation'], 'la clave en la raíz: vale para todas las direcciones');
    same($urls, $body['urlList']);

    // seo:indexnow --dry-run no manda nada: la consola entrega la opción como «dry-run» => true (17/11/2026: se mandó de verdad).
    $dry = testApp(['modules' => ['seo', 'catalog', 'media'], 'http' => ($fake = new FakeHttpClient()), 'settings' => ['seo.indexnow_key' => 'abcdef0123456789abcdef0123456789']]);
    $dry->boot();
    aifecProduct($dry, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $commands = require dirname(__DIR__) . '/cli.php';
    same(0, $commands['seo:indexnow']['run']($dry, ['dry-run' => true], new \Aifec\Cli\Console(false)));
    same(0, count($fake->requests), 'con --dry-run no sale ninguna petición');

    // Sin clave, se crea la primera vez y se guarda.
    $fresh = testApp(['modules' => ['seo', 'catalog', 'media'], 'http' => (new FakeHttpClient())->queue(new HttpResponse(200, [], ''))]);
    $fresh->boot();
    IndexNow::submit($fresh, ['https://tienda.test/producto/x']);
    check(preg_match('/^[a-f0-9]{32}$/', IndexNow::key($fresh)) === 1, 'clave creada: ' . IndexNow::key($fresh));
});
