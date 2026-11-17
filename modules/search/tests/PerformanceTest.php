<?php
/**
 * AIFEC — rendimiento: con 2.000 productos inventados indexados en la propia prueba, 20 consultas (con erratas, plurales,
 * sinónimos, dos y tres términos, referencias y cosas que no existen) tienen que costar de media menos de 150 ms cada una, y las
 * sugerencias menos de 50 ms. La cifra medida se escribe en la salida de la prueba (línea «RENDIMIENTO»).
 *
 * Si la máquina va cargada, se repite una vez antes de darlo por fallo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Mide 20 consultas distintas y devuelve la media de milisegundos y la peor.
 *
 * @return array{0:float,1:float}
 */
function searchMeasure(App $app): array
{
    $search = searchService($app);
    $queries = [
        'silla', 'mesa roble', 'sillas rojas', 'sillón', 'slla', 'espejo grande', 'lamapra', 'armario nordica de nogal', 'cama plegable',
        'estante moderno pino', 'SKU-1500', 'taburete', 'escritorio ligero', 'cajonera rustica', 'banco extensible', 'mesas azules',
        'zapatillas', 'silla zapatillas', 'modelo 1999', 'sofa',
    ];
    $times = [];
    foreach ($queries as $q) {
        $search->forget();
        $t0 = microtime(true);
        $search->query($q);
        $times[] = (microtime(true) - $t0) * 1000;
    }

    return [array_sum($times) / count($times), max($times)];
}

test('2.000 productos: indexados, y 20 consultas con una media por debajo de 150 ms', function (): void {
    $app = searchApp();
    $ids = searchBulkProducts($app, 2000);
    $search = searchService($app);
    $t0 = microtime(true);
    $read = $search->reindexNow();
    $indexing = microtime(true) - $t0;
    same(2000, $read);
    same(2000, count(searchDocs($app)));
    searchCheckTerms($app);

    $search->query('silla'); // la primera vez calienta la caché de estadísticas, como en una tienda en marcha
    list($mean, $worst) = searchMeasure($app);
    if ($mean >= 150.0) {
        list($mean, $worst) = searchMeasure($app);
    }
    $postings = (int) $app->db()->value('SELECT COUNT(*) FROM {search_postings}');
    $terms = (int) $app->db()->value('SELECT COUNT(*) FROM {search_terms}');
    echo sprintf(
        "RENDIMIENTO: 2000 productos, %d términos, %d apariciones; indexar %.1f s; consulta media %.1f ms, peor %.1f ms\n",
        $terms,
        $postings,
        $indexing,
        $mean,
        $worst
    );
    check($mean < 150.0, 'la media tiene que estar por debajo de 150 ms y es ' . round($mean, 1) . ' ms');
    check($worst < 600.0, 'ninguna consulta pasa de 600 ms y la peor es ' . round($worst, 1) . ' ms');

    // Y lo que se encuentra es lo que tiene que ser.
    $found = searchIds($app, 'modelo 1999');
    check(in_array($ids[1999], $found, true), 'el producto 1999 por su nombre');
    $result = $search->query('SKU-1500', ['explain' => true]);
    same($ids[1500], (int) $result['ids'][0], 'una referencia: el primero');
    same(true, $result['explain'][$ids[1500]]['exact']);
});

test('las sugerencias con 2.000 productos tardan menos de 50 ms de media', function (): void {
    $app = searchApp();
    searchBulkProducts($app, 2000);
    $search = searchService($app);
    $search->reindexNow();
    $search->suggest('sil');
    $times = [];
    foreach (['sil', 'mes', 'est', 'lam', 'arm', 'esc', 'tab', 'caj', 'esp', 'ban'] as $prefix) {
        $t0 = microtime(true);
        $items = $search->suggest($prefix);
        $times[] = (microtime(true) - $t0) * 1000;
        check(count($items) >= 1 && count($items) <= 8, 'sugerencias para «' . $prefix . '»: ' . count($items));
    }
    $mean = array_sum($times) / count($times);
    if ($mean >= 50.0) {
        $times = [];
        foreach (['sil', 'mes', 'est', 'lam', 'arm', 'esc', 'tab', 'caj', 'esp', 'ban'] as $prefix) {
            $t0 = microtime(true);
            $search->suggest($prefix);
            $times[] = (microtime(true) - $t0) * 1000;
        }
        $mean = array_sum($times) / count($times);
    }
    echo sprintf("RENDIMIENTO: sugerencias con 2000 productos, media %.1f ms\n", $mean);
    check($mean < 50.0, 'la media de las sugerencias es ' . round($mean, 1) . ' ms');
});
