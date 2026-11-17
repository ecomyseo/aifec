<?php
/**
 * AIFEC — pruebas de la cola del buscador: los ganchos de guardado encolan un trabajo único por entidad (nunca indexan dentro
 * de la petición del panel), el reindexado por tandas se encola a sí mismo hasta terminar, y sin el módulo de tareas se indexa en
 * el momento.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('guardar un producto en el panel encola un trabajo único por producto: nunca se indexa dentro de la petición', function (): void {
    $app = searchApp([], ['jobs']);
    $a = aifecProduct($app, ['name' => 'Taburete grande']);
    $b = aifecProduct($app, ['name' => 'Taburete pequeño']);
    for ($i = 0; $i < 3; ++$i) {
        $app->hooks()->fire('admin.resource.saved', 'products', $a, [], null);
    }
    same(1, searchJobCount($app, 'search.index', 'pending'), 'tres guardados seguidos del mismo producto, un trabajo');
    $app->hooks()->fire('stock.changed', $a, 0, 4);
    same(1, searchJobCount($app, 'search.index', 'pending'), 'y un cambio de existencias tampoco añade otro');
    $app->hooks()->fire('admin.resource.saved', 'products', $b, [], null);
    same(2, searchJobCount($app, 'search.index', 'pending'), 'otro producto, otro trabajo');
    $payload = json_decode((string) $app->db()->value("SELECT payload FROM {jobs} WHERE type = 'search.index' ORDER BY id LIMIT 1"), true);
    same(['type' => 'product', 'id' => $a], $payload);
    same([], searchDocs($app), 'todavía no se ha indexado nada: lo hará la cola');

    searchWork($app);
    same(2, searchJobCount($app, 'search.index', 'done'));
    same([$a], searchIds($app, 'grande'));
    same([$b], searchIds($app, 'pequeño'));
    searchCheckTerms($app);
});

test('borrar un producto, o cambiar su visibilidad, también encola, y el trabajo lo saca del índice', function (): void {
    $app = searchApp([], ['jobs']);
    $id = aifecProduct($app, ['name' => 'Perchero']);
    $search = searchService($app);
    $search->index('product', $id);
    same([$id], searchIds($app, 'perchero'));
    $app->db()->delete('products', ['id' => $id]);
    $app->hooks()->fire('admin.resource.deleted', 'products', $id, ['id' => $id]);
    same(1, searchJobCount($app, 'search.index', 'pending'));
    searchWork($app);
    same([], searchIds($app, 'perchero'));
    same([], searchDocs($app));
});

test('los guardados de otros recursos que no cambian el texto de un producto no encolan nada', function (): void {
    $app = searchApp([], ['jobs']);
    $app->hooks()->fire('admin.resource.saved', 'coupons', 4, [], null);
    $app->hooks()->fire('admin.resource.saved', 'orders', 4, [], null);
    $app->hooks()->fire('content.saved', 'term', 0);
    same(0, searchJobCount($app, 'search.index'));
    same(0, searchJobCount($app, 'search.reindex'), 'a no ser que sea el blog con el módulo de contenidos');
});

test('cambiar una categoría o una marca reindexa los productos, y una sola vez aunque se guarde dos veces', function (): void {
    $app = searchApp([], ['jobs']);
    $category = aifecCategory($app, 'Sillas');
    $id = aifecProduct($app, ['name' => 'Silla de despacho', 'categories' => [$category]]);
    searchService($app)->reindexNow();
    same([$id], searchIds($app, 'sillas'), 'por su categoría');
    same([], searchIds($app, 'asientos'));

    $app->db()->update('category_i18n', ['name' => 'Asientos'], ['category_id' => $category]);
    $app->invalidate(['catalog']);
    $app->hooks()->fire('admin.resource.saved', 'categories', $category, [], null);
    $app->hooks()->fire('admin.resource.saved', 'brands', 3, [], null);
    same(1, searchJobCount($app, 'search.reindex', 'pending'), 'un solo reindexado');
    searchWork($app);
    same([$id], searchIds($app, 'asientos'), 'con el nombre nuevo de la categoría');
    searchCheckTerms($app);
});

test('el reindexado por tandas se encola a sí mismo hasta terminar: 100 + 100 + 30', function (): void {
    $app = searchApp([], ['jobs']);
    searchBulkProducts($app, 230);
    $search = searchService($app);
    $job = $search->reindex();
    same($job, $search->reindex(), 'mientras hay uno en marcha, pedirlo otra vez devuelve el mismo');
    same(1, searchJobCount($app, 'search.reindex'));

    $first = $app->service('jobs')->work(5, 1);
    same(1, $first['done'], 'una tanda por trabajo');
    same(100, count(searchDocs($app)));
    same(1, searchJobCount($app, 'search.reindex', 'pending'), 'y ya hay otra esperando');
    searchWork($app);
    same(230, count(searchDocs($app)));
    same(3, searchJobCount($app, 'search.reindex', 'done'));
    same(0, searchJobCount($app, 'search.reindex', 'pending'));
    searchCheckTerms($app);
    check($search->reindex() !== $job, 'terminado, un reindexado nuevo es otro trabajo');
});

test('sin el módulo de tareas, los ganchos indexan en el momento y reindexar lo hace todo ya', function (): void {
    $app = searchApp();
    $id = aifecProduct($app, ['name' => 'Cómoda vintage']);
    $app->hooks()->fire('admin.resource.saved', 'products', $id, [], null);
    same([$id], searchIds($app, 'comoda'));
    $other = aifecProduct($app, ['name' => 'Cómoda moderna']);
    same(2, searchService($app)->reindex(), 'devuelve cuántas entidades leyó');
    same([$id, $other], searchIds($app, 'comoda'));
});

test('los trabajos y la tarea diaria están registrados en el módulo de tareas', function (): void {
    $app = searchApp([], ['jobs']);
    $tasks = array_column($app->service('jobs')->tasks(), 'every', 'task');
    check(isset($tasks['search.purge']), 'la limpieza del registro');
    same('daily@04:10', $tasks['search.purge']);
    $app->service('jobs')->push('search.index', ['type' => 'nada', 'id' => 5], ['max_attempts' => 3]);
    searchWork($app);
    $row = $app->db()->row("SELECT status, attempts FROM {jobs} WHERE type = 'search.index'");
    same('failed', $row['status'], 'un trabajo con datos malos falla sin reintentos');
    same(1, (int) $row['attempts']);
    $note = \Aifec\Modules\Search\SearchJobs::purge($app);
    same('Removed 0 old rows', $note);
});

test('la orden de consola search:reindex reindexa ahora y rechaza un tipo que no existe', function (): void {
    $app = searchApp([], ['jobs']);
    aifecProduct($app, ['name' => 'Perchero de pared']);
    $commands = require dirname(__DIR__) . '/cli.php';
    $run = $commands['search:reindex']['run'];
    $io = new \Aifec\Cli\Console(false);
    same(0, $run($app, [], $io));
    contains('Reindexed 1 items', $io->output);
    same(1, count(searchDocs($app)));
    same(2, $run($app, ['zzz'], $io), 'un tipo que no existe');
    contains('Unknown type: zzz', $io->output);
    same(0, $run($app, ['product', 'queue' => true], $io));
    contains('Reindex queued', $io->output);
    same(1, searchJobCount($app, 'search.reindex', 'pending'));
});
