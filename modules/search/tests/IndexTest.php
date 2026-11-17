<?php
/**
 * AIFEC — pruebas del índice del buscador: qué se indexa, que las cuentas de los términos cuadran, que borrar o despublicar saca
 * un producto y que reindexar por tandas recorre todo sin repetir ni dejarse nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('indexa un documento por producto e idioma, con su nombre para las sugerencias y sus longitudes', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $docs = searchDocs($app);
    same(7, count($docs), 'los siete productos publicados');
    $roja = $docs[$ids['silla_roja']];
    same('es', $roja['lang']);
    same('Silla roja', $roja['label']);
    same('silla roja', $roja['name_norm']);
    same(2, (int) $roja['len_name'], 'dos palabras en el nombre');
    same(5, (int) $roja['sales']);
    same(1, (int) $roja['in_stock']);
    same(0, (int) $docs[$ids['silla_azul']]['in_stock'], 'la azul está agotada');
    check((int) $roja['len_ref'] > 0, 'tiene referencias');
    check((int) $roja['len_brand'] === 1 && (int) $roja['len_cats'] === 1, 'marca y categoría');
    searchCheckTerms($app);
});

test('encuentra por nombre, por marca, por categoría, por etiqueta, por atributo, por dato clave, por resumen y por descripción', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $expected = [$ids['silla_roja'], $ids['silla_azul']];
    sort($expected);

    $byName = searchIds($app, 'silla');
    same(3, count($byName), 'las dos sillas y el sillón, que solo sale por su categoría («Sillas»)');
    $position = array_flip($byName);
    same($ids['silla_roja'], $byName[0], 'por nombre, la que hay en existencias primero');
    check($position[$ids['sillon']] > $position[$ids['silla_roja']], 'el sillón, por su categoría, detrás de la silla de nombre');
    sort($byName);
    same(array_merge($expected, [$ids['sillon']]), $byName);
    $byBrand = searchIds($app, 'nordika');
    sort($byBrand);
    same($expected, $byBrand, 'por marca');
    same([$ids['banco']], searchIds($app, 'exterior'), 'por categoría');
    check(in_array($ids['sillon'], searchIds($app, 'lectura'), true), 'por etiqueta');
    same([$ids['silla_roja']], searchIds($app, 'rojo'), 'por atributo («Rojo»; el nombre dice «roja»)');
    same([$ids['lampara']], searchIds($app, 'latón'), 'por dato clave');
    same([$ids['mesa']], searchIds($app, 'robusta'), 'por descripción');
    same([$ids['banco']], searchIds($app, 'terraza'), 'por descripción, con el HTML fuera');
    same([], searchIds($app, 'zzzzzzzz'), 'una palabra que no está no saca nada');
});

test('lo que está en el nombre pesa más que lo que está en el resumen y en la descripción', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $found = searchIds($app, 'comedor');
    same([$ids['mesa_comedor'], $ids['silla_roja']], $found, 'el nombre antes que el resumen, y nada más');
});

test('una referencia o un código de barras que coincide entero va el primero', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    same($ids['silla_azul'], searchIds($app, 'SIL-002')[0], 'la referencia, aunque la silla esté agotada');
    same($ids['silla_azul'], searchIds($app, 'sil-002')[0], 'sin mirar mayúsculas');
    same([$ids['silla_roja']], searchIds($app, '8412345678905'), 'el código de barras');
    $result = searchService($app)->query('SIL-001', ['explain' => true]);
    same($ids['silla_roja'], (int) $result['ids'][0]);
    same(true, $result['explain'][$ids['silla_roja']]['exact'], 'marcada como coincidencia exacta');
    same(true, $result['confident']);
    // «sil» sola no es la referencia entera: no es una coincidencia exacta.
    $partial = searchService($app)->query('sil', ['explain' => true]);
    foreach ($partial['explain'] as $info) {
        same(false, $info['exact']);
    }
});

test('reindexar dos veces no duplica nada y las cuentas de los términos cuadran', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $db = $app->db();
    $terms = $db->select('SELECT term, df FROM {search_terms} ORDER BY term');
    $postings = (int) $db->value('SELECT COUNT(*) FROM {search_postings}');
    $docs = (int) $db->value('SELECT COUNT(*) FROM {search_docs}');
    $search = searchService($app);
    $search->index('product', $ids['silla_roja']);
    $search->index('product', $ids['silla_roja']);
    $search->reindexNow();
    same($terms, $db->select('SELECT term, df FROM {search_terms} ORDER BY term'), 'el vocabulario queda igual');
    same($postings, (int) $db->value('SELECT COUNT(*) FROM {search_postings}'));
    same($docs, (int) $db->value('SELECT COUNT(*) FROM {search_docs}'));
    searchCheckTerms($app);
});

test('cambiar el texto de un producto cambia lo que se encuentra, y las cuentas siguen cuadrando', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    same([$ids['mesa']], searchIds($app, 'robusta'));
    $app->db()->update('product_i18n', ['description' => 'Mesa elegante de nogal'], ['product_id' => $ids['mesa'], 'lang' => 'es']);
    $app->invalidate(['catalog']);
    searchService($app)->index('product', $ids['mesa']);
    same([], searchIds($app, 'robusta'), 'lo que ya no está, no sale');
    same([$ids['mesa']], searchIds($app, 'nogal'));
    searchCheckTerms($app);
});

test('despublicar, ocultar del buscador o borrar un producto lo saca del índice', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $db = $app->db();
    $search = searchService($app);
    check(in_array($ids['banco'], searchIds($app, 'banco'), true));

    $db->update('products', ['status' => 'draft'], ['id' => $ids['banco']]);
    $app->invalidate(['catalog']);
    $search->index('product', $ids['banco']);
    same([], searchIds($app, 'banco'), 'un borrador no sale');
    same(6, count(searchDocs($app)));

    $db->update('products', ['status' => 'published', 'visibility' => 'catalog'], ['id' => $ids['banco']]);
    $app->invalidate(['catalog']);
    $search->index('product', $ids['banco']);
    same([], searchIds($app, 'banco'), 'visible solo en el catálogo: no sale en el buscador');

    $db->update('products', ['visibility' => 'search'], ['id' => $ids['banco']]);
    $app->invalidate(['catalog']);
    $search->index('product', $ids['banco']);
    same([$ids['banco']], searchIds($app, 'banco'), 'solo buscador: sí sale');

    $db->delete('products', ['id' => $ids['banco']]);
    $app->invalidate(['catalog']);
    $search->index('product', $ids['banco']);
    same([], searchIds($app, 'banco'), 'borrado');
    same(6, count(searchDocs($app)));
    searchCheckTerms($app);
});

test('un producto programado para más tarde ya está en el índice: el catálogo es quien decide cuándo se ve', function (): void {
    $app = searchApp();
    $id = aifecProduct($app, ['name' => 'Taburete futuro', 'published_at' => $app->clock()->sql($app->clock()->now() + 86400)]);
    searchService($app)->index('product', $id);
    same([$id], searchIds($app, 'taburete'));
    same(1, (int) searchService($app)->query('taburete')['total'], 'sale en el índice');
});

test('reindexar por tandas: el cursor avanza por lo leído, recorre todo una vez y quita lo huérfano', function (): void {
    $app = searchApp();
    $created = [];
    for ($i = 1; $i <= 7; ++$i) {
        $created[] = aifecProduct($app, ['name' => 'Producto número ' . $i]);
    }
    $search = searchService($app);
    $indexer = $search->indexer();
    // Un borrador entre medias: se lee pero no se indexa. El cursor no puede quedarse atrás ni acabar antes de tiempo.
    $app->db()->update('products', ['status' => 'draft'], ['id' => $created[1]]);

    $types = ['product'];
    $after = 0;
    $reads = [];
    $guard = 0;
    while ($types !== [] && $guard++ < 10) {
        $step = $indexer->reindexBatch($types, $after, 3);
        $reads[] = $step['read'];
        $types = $step['types'];
        $after = $step['after'];
    }
    same([3, 3, 1], $reads, 'tandas de 3: 3 + 3 + 1, sin releer lo ya leído');
    same(6, count(searchDocs($app)), 'seis indexados y el borrador fuera');
    searchCheckTerms($app);

    // Un documento de un producto que ya no existe: la tanda final lo quita.
    $app->db()->insert('search_docs', [
        'type' => 'product', 'entity_id' => 9999, 'lang' => 'es', 'label' => 'Fantasma', 'name_norm' => 'fantasma', 'indexed_at' => $app->clock()->sql(),
    ]);
    same(7, $search->reindexNow('product'), 'lee los siete productos que hay');
    check(!isset(searchDocs($app)[9999]), 'el huérfano se ha quitado');
});

test('con un número de productos exacto en la tanda, la siguiente lee cero y cierra el tipo', function (): void {
    $app = searchApp();
    for ($i = 1; $i <= 4; ++$i) {
        aifecProduct($app, ['name' => 'Cuatro ' . $i]);
    }
    $indexer = searchService($app)->indexer();
    $first = $indexer->reindexBatch(['product'], 0, 4);
    same(4, $first['read']);
    same(['product'], $first['types'], 'una tanda llena no da el tipo por acabado');
    $second = $indexer->reindexBatch($first['types'], $first['after'], 4);
    same(0, $second['read']);
    same([], $second['types'], 'una tanda vacía sí');
});

test('indexa cada idioma de la tienda con su texto', function (): void {
    $app = searchApp(['i18n.languages' => ['es', 'en']]);
    $id = aifecProduct($app, ['name' => 'Silla roja', 'i18n' => ['en' => ['name' => 'Red chair']]]);
    $search = searchService($app);
    $search->index('product', $id);
    same(2, count($app->db()->select('SELECT id FROM {search_docs} WHERE entity_id = ?', [$id])), 'un documento por idioma');
    same([$id], searchIds($app, 'silla', ['lang' => 'es']));
    same([], searchIds($app, 'silla', ['lang' => 'en']), 'en inglés no se llama así');
    same([$id], searchIds($app, 'chairs', ['lang' => 'en']), 'el plural inglés');
    searchCheckTerms($app);
});
