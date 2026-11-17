<?php
/**
 * AIFEC — pruebas de las sugerencias: por prefijo, con imagen y precio, hasta ocho, sin lo que no se ve y sin comodines de SQL.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * @param array<int,array<string,mixed>> $items
 *
 * @return string[]
 */
function suggestLabels(array $items): array
{
    return array_map(static function (array $item): string {
        return (string) $item['label'];
    }, $items);
}

test('sugiere por prefijo productos y categorías, y lo que hay en existencias va antes', function (): void {
    $app = searchApp();
    searchShop($app);
    $items = searchService($app)->suggest('sil');
    $labels = suggestLabels($items);
    check(in_array('Silla roja', $labels, true) && in_array('Sillón orejero', $labels, true) && in_array('Silla azul', $labels, true), 'las sillas y el sillón');
    check(in_array('Sillas', $labels, true), 'y la categoría');
    $types = array_column($items, 'type', 'label');
    same('category', $types['Sillas']);
    same('product', $types['Silla roja']);
    $position = array_flip($labels);
    check($position['Silla roja'] < $position['Silla azul'], 'la agotada, detrás');
    check(!in_array('Mesa de roble', $labels, true), 'lo que no empieza por lo escrito, no');
});

test('también sugiere por el comienzo de cualquier palabra del nombre, y con varias palabras', function (): void {
    $app = searchApp();
    searchShop($app);
    $search = searchService($app);
    $labels = suggestLabels($search->suggest('rob'));
    check(in_array('Mesa de roble', $labels, true), '«rob» está en medio del nombre');
    same(['Mesa de comedor extensible'], array_values(array_filter(suggestLabels($search->suggest('mesa ext')), static function (string $l): bool {
        return strpos($l, 'Mesa') === 0;
    })), 'dos palabras: cada una es el comienzo de una palabra del nombre');
    same(suggestLabels($search->suggest('SILLÓN')), suggestLabels($search->suggest('sillon')), 'sin mirar mayúsculas ni tildes');
});

test('los productos sugeridos llevan imagen y precio con su formato, y la dirección de su ficha', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $items = searchService($app)->suggest('silla r');
    $roja = null;
    foreach ($items as $item) {
        if ($item['label'] === 'Silla roja') {
            $roja = $item;
        }
    }
    check($roja !== null, 'sale la silla roja');
    same('product', $roja['type']);
    same('/producto/silla-roja', $roja['url']);
    check(is_string($roja['image']) && $roja['image'] !== '', 'con imagen');
    check(is_string($roja['price']) && strpos($roja['price'], '€') !== false, 'con precio: ' . json_encode($roja['price']));
    unset($ids);
});

test('como mucho ocho, y nada con menos de dos letras', function (): void {
    $app = searchApp();
    for ($i = 1; $i <= 12; ++$i) {
        aifecProduct($app, ['name' => 'Lámpara modelo ' . $i]);
    }
    searchService($app)->reindexNow();
    same(8, count(searchService($app)->suggest('lamp')), 'tope de ocho');
    same([], searchService($app)->suggest('l'), 'una letra no basta');
    same([], searchService($app)->suggest(''));
    same([], searchService($app)->suggest('  '));
});

test('un producto que no se ve no se sugiere, y el que solo está en el buscador sí', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $db = $app->db();
    $search = searchService($app);
    $db->update('products', ['status' => 'draft'], ['id' => $ids['banco']]);
    $db->update('products', ['visibility' => 'search'], ['id' => $ids['lampara']]);
    $app->invalidate(['catalog']);
    $search->index('product', $ids['banco']);
    $search->index('product', $ids['lampara']);
    same([], $search->suggest('banc'), 'un borrador, no');
    same(['Lámpara de pie'], suggestLabels($search->suggest('lamp')), 'solo buscador: sí');
    // Lo programado para más tarde está indexado, pero el catálogo no lo da: no se sugiere todavía.
    $later = aifecProduct($app, ['name' => 'Taburete futuro', 'published_at' => $app->clock()->sql($app->clock()->now() + 86400)]);
    $search->index('product', $later);
    same([], $search->suggest('tabur'));
});

test('los comodines de SQL no son comodines: «%» y «_» no sugieren todo', function (): void {
    $app = searchApp();
    searchShop($app);
    $search = searchService($app);
    same([], $search->suggest('%'));
    same([], $search->suggest('_'));
    same([], $search->suggest('%%'));
    same([], $search->suggest("'; DROP TABLE af_search_docs; --"), 'ni se inyecta nada (la consulta va con parámetros)');
    check(count($app->db()->select('SELECT id FROM {search_docs}')) > 0, 'el índice sigue ahí');
});

test('las consultas frecuentes que encontraron algo salen primero, y las que no, nunca', function (): void {
    $app = searchApp();
    searchShop($app);
    $search = searchService($app);
    $log = $search->log();
    $log->record('silla roja', 'es', 1, false);
    $log->record('silla roja', 'es', 1, false);
    $log->record('silla reclinable', 'es', 0, true);
    $items = $search->suggest('silla r');
    $queries = array_values(array_filter($items, static function (array $item): bool {
        return $item['type'] === 'query';
    }));
    same(['silla roja'], suggestLabels($queries), 'solo la que encontró algo');
    same('query', $items[0]['type'], 'las consultas, primero');
    check(strpos((string) $queries[0]['url'], '/buscar?q=silla+roja') === 0, 'con su dirección de búsqueda: ' . $queries[0]['url']);
    same([], suggestLabels(array_filter($search->suggest('silla recl'), static function (array $item): bool {
        return $item['type'] === 'query';
    })), 'una consulta sin resultado no se sugiere');
});
