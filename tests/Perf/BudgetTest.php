<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../modules/catalog/tests/helpers.php';
require_once __DIR__ . '/../../modules/cms/tests/helpers.php';

/*
 * Presupuestos de rendimiento que no se pueden romper sin enterarse: consultas por petición de las páginas de la tienda
 * (con las cachés del arranque ya hechas, sin la copia de la caché de página), ficheros que incluye el arranque de una
 * visita y repintado de bloques. Si una prueba de aquí falla, algo ha vuelto a hacer trabajo en cada petición.
 */

/** Tienda de las pruebas de presupuesto: catálogo con categorías, marca y productos, buscador indexado y una página de bloques. */
function perfBudgetShop(): array
{
    $app = testApp(['modules' => ['catalog', 'orders', 'cms', 'search']]);
    $app->boot();
    $oficina = aifecCategory($app, 'Oficina');
    $sillas = aifecCategory($app, 'Sillas de oficina', $oficina);
    $marca = aifecBrand($app, 'Nórdika');
    $ids = [];
    foreach (['Silla Ergo Pro', 'Silla Básica', 'Mesa Elevable', 'Flexo Led', 'Cojín Lumbar', 'Silla Gamer'] as $i => $name) {
        $ids[] = aifecProduct($app, ['name' => $name, 'price' => 1000 + $i * 500, 'brand_id' => $marca, 'categories' => [$i < 3 ? $sillas : $oficina], 'featured' => $i % 2]);
    }
    $app->service('search')->indexer()->reindexNow();
    $page = cmsInsertPage($app, ['title' => 'Nuestra tienda', 'blocks' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'html' => 'Quiénes somos']],
        ['type' => 'paragraph', 'attrs' => ['html' => 'Muebles & más.']],
        ['type' => 'product', 'attrs' => ['id' => $ids[0]]],
        ['type' => 'products', 'attrs' => ['mode' => 'manual', 'ids' => [$ids[1], $ids[2]]]],
    ]]);
    // Una visita de cada página para dejar hechas las cachés del arranque (como en un servidor que ya está en marcha).
    foreach (['/', '/categoria/oficina', '/producto/silla-ergo-pro', '/buscar?q=silla', '/carrito/summary.json', '/nuestra-tienda'] as $uri) {
        perfGet($app, $uri);
    }

    return [$app, $page];
}

/**
 * Consultas de una petición en un proceso nuevo (con las cachés del arranque) y la respuesta.
 *
 * @return array{0:int,1:Response,2:App}
 */
function budgetQueries(App $base, string $uri): array
{
    $app = perfNext($base);
    $response = perfGet($app, $uri);

    return [$app->dbConnected() ? $app->db()->queries : 0, $response, $app];
}

test('presupuesto de consultas: categoría ≤ 10, ficha ≤ 8, buscador ≤ 12 y portada ≤ 17 (la meta es 10)', function (): void {
    [$app] = perfBudgetShop();
    // Medido el 13/11/2026 en este sitio de pruebas: portada 17, categoría 9, ficha 3, buscador 12. Lo que queda de la
    // portada por encima de 10 no es del núcleo: las tres secciones de productos del módulo catalog (HomeSections) piden
    // un listado entero con sus facetas cada una (recuento, ids, marcas, atributos y precios) y la portada no enseña
    // facetas; y MenuItems del catálogo repite su consulta de categorías del menú. Cuando se arregle ahí, se baja a 10.
    $budgets = ['/' => 17, '/categoria/oficina' => 10, '/producto/silla-ergo-pro' => 8, '/buscar?q=silla' => 12];
    $over = [];
    foreach ($budgets as $uri => $max) {
        [$queries, $response] = budgetQueries($app, $uri);
        same(200, $response->status, $uri);
        if ($queries > $max) {
            $over[] = $uri . ': ' . $queries . ' consultas (máximo ' . $max . ')';
        }
    }
    same([], $over, 'por encima del presupuesto');
});

test('presupuesto: el resumen del carrito sin carrito no hace ninguna consulta ni abre la base de datos', function (): void {
    [$app] = perfBudgetShop();
    [$queries, $response, $next] = budgetQueries($app, '/carrito/summary.json');
    same(200, $response->status);
    same(['count' => 0, 'lines' => []], json_decode($response->body, true));
    same(0, $queries);
    same(false, $next->dbConnected(), 'sin conexión a la base de datos');
    contains('db;desc="0 q"', $response->headers['Server-Timing']);
});

test('presupuesto: las cachés del arranque no se rehacen en cada visita (ni carpetas, ni ajustes, ni idiomas)', function (): void {
    [$app] = perfBudgetShop();
    $cache = $app->site()->path('cache');
    clearstatcache();
    $before = [];
    foreach (['boot.php', 'settings.php', 'lang-es.php'] as $file) {
        check(is_file($cache . '/' . $file), $file . ' existe');
        $before[$file] = (string) md5_file($cache . '/' . $file);
    }
    [, $response, $next] = budgetQueries($app, '/producto/silla-ergo-pro');
    same(200, $response->status);
    same(0, $next->modules()->scans(), 'no recorre carpetas');
    clearstatcache();
    foreach ($before as $file => $hash) {
        same($hash, (string) md5_file($cache . '/' . $file), $file . ' no se reescribe');
    }
});

test('presupuesto de ficheros incluidos por el arranque de una visita (proceso nuevo, con las cachés hechas)', function (): void {
    [$app] = perfBudgetShop();
    if (!function_exists('proc_open')) {
        check(true, 'sin proc_open no se puede medir en otro proceso');

        return;
    }
    $dir = tmpDir('aifec-budget');
    $site = $app->site();
    file_put_contents($dir . '/site.json', (string) json_encode(['id' => $site->id, 'dir' => $site->dataDir, 'config' => $site->config, 'modules' => $app->modules()->onlyIds()]));
    $script = <<<'PHP'
<?php
declare(strict_types=1);
$root = $argv[1];
$data = json_decode((string) file_get_contents($argv[2]), true);
require $root . '/core/bootstrap.php';
$before = count(get_included_files());
$site = new \Aifec\Kernel\Site($data['id'], $data['dir'], $data['config'], '', 'tienda.test');
$app = new \Aifec\Kernel\App($site, $root, ['sapi' => 'fpm-fcgi', 'log_files' => false, 'modules' => $data['modules'], 'public_dir' => $data['dir'] . '/public']);
$request = \Aifec\Kernel\Request::create('GET', $argv[3], [], [], ['user-agent' => 'Mozilla/5.0', 'sec-fetch-site' => 'none']);
$request->host = 'tienda.test';
$request->secure = true;
$app->boot();
$boot = count(get_included_files()) - $before;
$response = $app->handle($request);
echo json_encode(['status' => $response->status, 'boot' => $boot, 'total' => count(get_included_files()) - $before]);
PHP;
    file_put_contents($dir . '/child.php', $script);
    // Medido + 10 %: [ficheros del arranque, ficheros de la petición entera].
    // Medido el 13/11/2026 (igual en PHP 7.4, 8.3 y 8.4): el arranque 35 ficheros; el resumen del carrito 38 en total y la
    // ficha 85 (con sus clases y plantillas). El tope es lo medido + 10 %.
    $budgets = ['/carrito/summary.json' => [39, 42], '/producto/silla-ergo-pro' => [39, 94]];
    $over = [];
    foreach ($budgets as $uri => $max) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/child.php') . ' ' . escapeshellarg($app->root()) . ' ' . escapeshellarg($dir . '/site.json') . ' ' . escapeshellarg($uri);
        $out = (string) shell_exec($cmd);
        $result = json_decode(trim($out), true);
        check(is_array($result) && isset($result['status'], $result['boot'], $result['total']), 'el proceso hijo contesta (' . substr($out, 0, 300) . ')');
        same(200, $result['status'], $uri);
        if ($result['boot'] > $max[0] || $result['total'] > $max[1]) {
            $over[] = $uri . ': arranque ' . $result['boot'] . ' ficheros (máximo ' . $max[0] . '), petición entera ' . $result['total'] . ' (máximo ' . $max[1] . ')';
        }
    }
    same([], $over, 'por encima del presupuesto de ficheros');
});

test('presupuesto: la segunda visita a una página de bloques no repinta ninguno', function (): void {
    [$app, $page] = perfBudgetShop();
    $app->invalidate(['pages', 'cms']);
    $next = perfNext($app);
    same(200, perfGet($next, '/nuestra-tienda')->status);
    $cms = $next->service('cms');
    same(0, $cms->blockCache()->painted(), 'ningún bloque repintado aunque la página no estuviera en caché');
    same(0, $cms->blockCache()->skeletons());
});
