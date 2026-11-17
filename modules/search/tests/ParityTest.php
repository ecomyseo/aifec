<?php
/**
 * AIFEC — la puntuación del buscador es la de Aifec\Search\Bm25: el módulo la calcula con las estadísticas del índice guardado en
 * la base de datos (no puede tener un índice en memoria), y esta prueba demuestra que da los mismos números que la clase del
 * núcleo sobre el mismo corpus.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Catalog\Texts;
use Aifec\Modules\Search\Fields;
use Aifec\Search\Bm25;

require_once __DIR__ . '/helpers.php';

test('las puntuaciones coinciden con las de Bm25 sobre el mismo corpus, campo a campo', function (): void {
    $app = searchApp();
    $search = searchService($app);
    $analyzer = $search->analyzer('es');
    $products = [
        ['name' => 'Silla roja de comedor', 'sku' => 'SIL-001', 'summary' => 'Una silla cómoda para el comedor', 'description' => '<p>Silla de madera con respaldo alto, ideal para comedor y cocina. Fácil de limpiar.</p>'],
        ['name' => 'Silla azul', 'sku' => 'SIL-002', 'summary' => 'Silla azul de oficina', 'description' => ''],
        ['name' => 'Mesa de roble', 'sku' => 'MES-001', 'summary' => '', 'description' => '<p>Mesa robusta de roble macizo para comedor.</p>'],
        ['name' => 'Mesa de comedor extensible', 'sku' => 'MES-002', 'summary' => 'Mesa con alas', 'description' => '<p>Se extiende hasta dos metros.</p>'],
        ['name' => 'Sillón orejero', 'sku' => 'SLL-010', 'summary' => 'Sillón relax para leer', 'description' => '<p>El sillón más cómodo de la casa, con orejas altas y tapizado suave.</p>'],
        ['name' => 'Lámpara de pie', 'sku' => 'LAM-001', 'summary' => 'Luz cálida', 'description' => ''],
        ['name' => 'Banco de jardín', 'sku' => 'BAN-001', 'summary' => '', 'description' => '<p>Banco para la terraza y el comedor de verano.</p>'],
        ['name' => 'Estantería alta', 'sku' => 'EST-001', 'summary' => 'Estantería con cinco baldas', 'description' => '<p>Cinco baldas de madera para libros.</p>'],
    ];
    $bm25 = new Bm25();
    $bm25->setWeights(Fields::weights($app));
    foreach ($products as $p) {
        $id = aifecProduct($app, ['name' => $p['name'], 'sku' => $p['sku'], 'summary' => $p['summary'], 'description' => $p['description']]);
        $ref = $analyzer->tokens($p['sku']);
        $ref[] = '#' . $analyzer->fold($p['sku']);
        $bm25->add((string) $id, [
            'name' => $analyzer->tokens($p['name']), 'ref' => $ref, 'brand' => [], 'cats' => [], 'tags' => [], 'attrs' => [],
            'summary' => $analyzer->tokens($p['summary']), 'desc' => $analyzer->tokens(Texts::plain($p['description'])), 'extra' => [],
        ]);
    }
    $search->reindexNow();

    foreach (['silla', 'comedor', 'mesa roble', 'silla comedor', 'sillón', 'madera', 'SIL-001', 'baldas cinco', 'jardín terraza'] as $q) {
        $mine = $search->query($q, ['explain' => true, 'limit' => 100]);
        $expected = [];
        foreach ($bm25->search($analyzer->tokens($q), 100) as $hit) {
            $expected[(int) $hit['id']] = $hit['score'];
        }
        check($mine['explain'] !== [], 'hay resultados para «' . $q . '»');
        foreach ($mine['explain'] as $id => $info) {
            check(isset($expected[$id]), 'Bm25 también encuentra el producto ' . $id . ' con «' . $q . '»');
            near($expected[$id], $info['score'], 1e-9, 'la puntuación de «' . $q . '» en el producto ' . $id);
        }
    }
});

test('con los pesos de los campos cambiados, sigue coincidiendo (los pesos de los ajustes se aplican al buscar)', function (): void {
    $app = searchApp(['search.w_name' => 1, 'search.w_desc' => 5]);
    $search = searchService($app);
    $analyzer = $search->analyzer('es');
    $a = aifecProduct($app, ['name' => 'Taburete comedor', 'description' => '<p>Taburete bajo</p>']);
    $b = aifecProduct($app, ['name' => 'Banco bajo', 'description' => '<p>Banco de comedor para el comedor</p>']);
    $search->reindexNow();
    $bm25 = new Bm25();
    $bm25->setWeights(Fields::weights($app));
    same(1.0, Fields::weights($app)['name']);
    same(5.0, Fields::weights($app)['desc']);
    $bm25->add((string) $a, ['name' => $analyzer->tokens('Taburete comedor'), 'desc' => $analyzer->tokens('Taburete bajo')]);
    $bm25->add((string) $b, ['name' => $analyzer->tokens('Banco bajo'), 'desc' => $analyzer->tokens('Banco de comedor para el comedor')]);
    $mine = $search->query('comedor', ['explain' => true]);
    foreach ($bm25->search($analyzer->tokens('comedor'), 10) as $hit) {
        near($hit['score'], $mine['explain'][(int) $hit['id']]['score'], 1e-9);
    }
    same($b, (int) $mine['ids'][0], 'con la descripción pesando más que el nombre, gana la que lo repite en la descripción');
});
