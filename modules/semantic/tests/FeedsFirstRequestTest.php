<?php
/**
 * AIFEC — feeds desde el primer minuto: en una tienda recién instalada los feeds daban 404 hasta que corría la tarea programada.
 * Ahora la primera petición a un feed encendido que aún no existe lo genera (hasta 500 productos); con más, contesta 503 con
 * Retry-After y deja el trabajo encolado; y nunca corren dos generaciones a la vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Semantic\Feeds;
use Aifec\Modules\Semantic\SemanticJobs;

require_once __DIR__ . '/helpers.php';

/** Cuenta los elementos que salen en los feeds (el gancho «feed.item» corre una vez por elemento generado). */
function ffrCountItems(App $app): \stdClass
{
    $counter = new \stdClass();
    $counter->items = 0;
    $app->hooks()->filter('feed.item', static function ($item) use ($counter) {
        ++$counter->items;

        return $item;
    });

    return $counter;
}

/** El cerrojo de los feeds, cogido por «otra petición»: un manejador propio sobre el mismo fichero. */
function ffrHoldLock(App $app)
{
    $handle = fopen($app->site()->path('feeds', true) . '/.generating.lock', 'cb');
    check($handle !== false, 'se abre el fichero del cerrojo');
    check(flock($handle, LOCK_EX | LOCK_NB), 'el cerrojo estaba libre');

    return $handle;
}

function ffrFeedFile(App $app, string $name = 'google.xml'): string
{
    return $app->site()->path('feeds', true) . '/' . $name;
}

test('la primera petición a un feed que aún no existe lo genera en ese momento y lo sirve', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    check(!is_file(ffrFeedFile($app)), 'tienda recién instalada: no hay feed');
    $web = new TestClient($app);
    $r = $web->get('/feeds/google.xml');
    same(200, $r->status);
    contains('application/xml', $r->contentType());
    check(is_file(ffrFeedFile($app)), 'ya existe el fichero');
    check(is_string($r->file) && strpos((string) file_get_contents($r->file), 'Silla de roble') !== false, 'se sirve el feed recién hecho, con el producto');
    check(isset($r->headers['ETag']) && $r->headers['ETag'] !== '', 'con su ETag');
});

test('al generar el feed pedido salen también los otros dos encendidos: es una sola pasada', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    same(200, (new TestClient($app))->get('/feeds/products.json')->status);
    foreach (['google.xml', 'products.json', 'openai.json'] as $name) {
        check(is_file(ffrFeedFile($app, $name)), $name . ' ya existe');
    }
});

test('la segunda petición sirve el fichero y no vuelve a generar nada', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $counter = ffrCountItems($app);
    $web = new TestClient($app);
    same(200, $web->get('/feeds/google.xml')->status);
    $after = $counter->items;
    check($after > 0, 'la primera generó elementos');
    $mtime = filemtime(ffrFeedFile($app));
    same(200, $web->get('/feeds/google.xml')->status);
    same(200, $web->get('/feeds/openai.json')->status);
    same($after, $counter->items, 'ninguna generación más');
    same($mtime, filemtime(ffrFeedFile($app)), 'el fichero no se ha tocado');
});

test('un feed apagado sigue siendo 404 y no se genera nada por pedirlo', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.feeds.google' => false]);
    semanticProduct($app);
    $web = new TestClient($app);
    same(404, $web->get('/feeds/google.xml')->status);
    check(!is_file(ffrFeedFile($app)), 'no se ha escrito');
    check(!is_file(ffrFeedFile($app, 'products.json')), 'y pedirlo tampoco genera los demás');
    same(404, $web->get('/feeds/otro.json')->status);
    same(200, $web->get('/feeds/products.json')->status, 'los encendidos, sí');
    check(!is_file(ffrFeedFile($app)), 'el apagado sigue sin existir');
});

test('con más de 500 productos la primera petición contesta 503 con Retry-After y deja el trabajo encolado, sin generar', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    for ($i = 1; $i <= 501; $i++) {
        aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'price' => 1000 + $i]);
    }
    same(501, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    $web = new TestClient($app);
    $r = $web->get('/feeds/google.xml');
    same(503, $r->status);
    check(isset($r->headers['Retry-After']) && (int) $r->headers['Retry-After'] > 0, 'con Retry-After: ' . json_encode($r->headers));
    check(!is_file(ffrFeedFile($app)), 'no se genera en la petición');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.feeds' AND status = 'pending'"), 'el trabajo queda encolado');
    same(503, $web->get('/feeds/products.json')->status);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.feeds' AND status = 'pending'"), 'y no se encola otro por cada petición');

    SemanticJobs::feeds([], $app);
    same(200, $web->get('/feeds/google.xml')->status, 'cuando el trabajo ha corrido, se sirve');
});

test('el límite de la primera petición es de 500 productos y cabe cambiarlo para probar: justo el límite se genera, uno más se encola', function (): void {
    same(500, Feeds::FIRST_REQUEST_MAX_PRODUCTS);
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    semanticProduct($app);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete']);
    /** @var \Aifec\Modules\Semantic\Semantic $semantic */
    $semantic = $app->service('semantic');
    same('queued', Feeds::ensure($semantic, 'google', 1), 'con dos productos y límite uno');
    check(!is_file(ffrFeedFile($app)), 'no se ha generado');
    same('ready', Feeds::ensure($semantic, 'google', 2), 'con dos productos y límite dos, se genera');
    check(is_file(ffrFeedFile($app)));
    same('ready', Feeds::ensure($semantic, 'google', 0), 'si ya existe, no importa el límite');
});

test('si otra generación tiene el cerrojo, la petición contesta 503 y no genera una segunda; al soltarse, se genera', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $counter = ffrCountItems($app);
    $web = new TestClient($app);
    $lock = ffrHoldLock($app);
    $r = $web->get('/feeds/google.xml');
    same(503, $r->status);
    check(isset($r->headers['Retry-After']), 'con Retry-After');
    same(0, $counter->items, 'no ha generado nada');
    check(!is_file(ffrFeedFile($app)));
    same([], Feeds::generate($app->service('semantic')), 'la tarea periódica tampoco genera encima');
    same(null, Feeds::tryGenerate($app->service('semantic')), 'y tryGenerate dice que está ocupado');
    flock($lock, LOCK_UN);
    fclose($lock);
    same(200, $web->get('/feeds/google.xml')->status);
    check($counter->items > 0, 'soltado el cerrojo, genera');
});

test('dos peticiones a la vez no generan dos veces: la que llega mientras se genera recibe un 503', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete']);
    $inner = [];
    $items = 0;
    $app->hooks()->filter('feed.item', static function ($item) use ($app, &$inner, &$items) {
        ++$items;
        if ($items === 1) {
            // Mientras la primera petición está generando, entra la segunda.
            $inner[] = (new TestClient($app))->get('/feeds/google.xml')->status;
            $inner[] = Feeds::ensure($app->service('semantic'), 'products');
        }

        return $item;
    });
    $web = new TestClient($app);
    same(200, $web->get('/feeds/google.xml')->status);
    same([503, 'busy'], $inner, 'la segunda, a la vez, no genera: 503 y «busy»');
    same(3 * 2, $items, 'dos productos en tres feeds: una sola generación, no dos');
});

test('si la generación falla, la petición no cae con un 500: contesta 503, no deja un fichero a medias y suelta el cerrojo', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    // Una carpeta con el nombre del temporal: abrirlo para escribir falla, como un disco lleno o sin permisos.
    mkdir(ffrFeedFile($app) . '.tmp');
    $web = new TestClient($app);
    $r = $web->get('/feeds/google.xml');
    same(503, $r->status);
    check(!is_file(ffrFeedFile($app)), 'ni un feed a medias');
    check(!is_file(ffrFeedFile($app, 'products.json')), 'ni otro a medias');
    rmdir(ffrFeedFile($app) . '.tmp');
    $lock = ffrHoldLock($app);
    check(is_resource($lock), 'el cerrojo se soltó al fallar: está libre');
    flock($lock, LOCK_UN);
    fclose($lock);
    same(200, $web->get('/feeds/google.xml')->status, 'y la siguiente petición genera');
});

test('la auditoría AIFEC no dice «feeds bien» si el fichero no existe, y sí cuando la primera petición lo ha generado', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $checks = static function (App $app): array {
        $out = [];
        foreach ($app->service('semantic')->audit()['checks'] as $check) {
            $out[$check['id']] = $check;
        }

        return $out;
    };
    same(false, $checks($app)['feeds']['ok'], 'sin fichero, no');
    (new TestClient($app))->get('/feeds/google.xml');
    same(true, $checks($app)['feeds']['ok'], 'con los tres ficheros recién hechos, sí');
    unlink(ffrFeedFile($app, 'openai.json'));
    same(false, $checks($app)['feeds']['ok'], 'si falta uno de los encendidos, no');
});

test('las puertas de /.well-known/aifec.json no anuncian un feed que no existe', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $web = new TestClient($app);
    $before = json_decode($web->get('/.well-known/aifec.json')->body, true);
    check(!isset($before['gates']['feeds']), 'sin feeds hechos, la puerta no se anuncia');
    $web->get('/feeds/google.xml');
    $app->cache()->flush();
    $after = json_decode($web->get('/.well-known/aifec.json')->body, true);
    check(isset($after['gates']['feeds']['google']), 'con el feed hecho, sí');
});
