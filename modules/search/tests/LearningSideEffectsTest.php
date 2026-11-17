<?php
/**
 * AIFEC — las comparaciones de aprendizaje no encargan trabajo de IA fuera de su presupuesto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Search\Vector;

require_once __DIR__ . '/helpers.php';

test('el ensayo usa el buscador real sin crear embeddings pendientes y restaura la búsqueda normal', function (): void {
    [$app, $fake] = searchAiApp(['search.vectors' => true, 'search.ai_expand' => true]);
    searchShop($app);
    $search = searchService($app);
    $beforeJobs = searchJobCount($app, 'search.embed');
    $beforeCalls = [count($fake->asked), count($fake->embedded)];
    $result = $search->withoutAiSideEffects(static function () use ($search): array {
        same(false, $search->allowsAiSideEffects());
        same(true, $search->vectorsOn(), 'el ajuste de vectores sigue encendido');
        same(false, $search->expandOn(), 'no se encargan expansiones nuevas');
        return $search->ask('silla', ['ai' => false, 'log' => false]);
    });
    check($result['products'] !== [], 'son resultados del catálogo real de prueba');
    same($beforeJobs, searchJobCount($app, 'search.embed'));
    same($beforeCalls, [count($fake->asked), count($fake->embedded)]);
    same(true, $search->allowsAiSideEffects());
    same(true, $search->expandOn());
    $search->query('silla');
    same($beforeJobs + 1, searchJobCount($app, 'search.embed'), 'la memoización del ensayo no desactiva los trabajos de búsquedas posteriores');
});

test('los vectores ya calculados siguen dando el mismo orden durante el ensayo', function (): void {
    [$app, $fake] = searchAiApp(['search.vectors' => true]);
    $first = aifecProduct($app, ['name' => 'Banqueta alta']);
    $second = aifecProduct($app, ['name' => 'Banqueta baja']);
    $search = searchService($app);
    $search->reindexNow();
    $row = $app->db()->row('SELECT vector FROM {search_docs} WHERE entity_id = ?', [$second]);
    $search->storeQueryVector('banqueta', 'es', Vector::unpack((string) base64_decode((string) $row['vector'], true)));
    $before = $search->query('banqueta');
    same([$second, $first], $before['ids'], 'el vector existente altera el orden literal');
    $calls = count($fake->embedded);
    $after = $search->withoutAiSideEffects(static function () use ($search): array {
        return $search->query('banqueta');
    });
    same($before, $after, 'la evaluación conserva el algoritmo configurado');
    same($calls, count($fake->embedded));
});

test('un ámbito anidado y una excepción restauran siempre la política anterior', function (): void {
    $app = searchApp();
    $search = searchService($app);
    $search->withoutAiSideEffects(static function () use ($search): void {
        $failed = false;
        try {
            $search->withoutAiSideEffects(static function () use ($search): void {
                same(false, $search->allowsAiSideEffects());
                throw new RuntimeException('Fallo ficticio de la comprobación');
            });
        } catch (RuntimeException $error) {
            $failed = true;
        }
        check($failed);
        same(false, $search->allowsAiSideEffects(), 'sigue vigente el ámbito exterior');
    });
    same(true, $search->allowsAiSideEffects(), 'la excepción no deja la tienda sin IA');
});

test('una lectura en modo protegido tampoco llama a la IA si el llamador pide ayuda en directo', function (): void {
    [$app, $fake] = searchAiApp(['search.ai_understand' => true]);
    askShop($app);
    $search = searchService($app);
    $query = 'busco algo para sentarme en casa de color negro';
    $search->withoutAiSideEffects(static function () use ($search, $query): void {
        $search->ask($query, ['ai' => true, 'log' => false]);
    });
    same([], $fake->asked);
    same([], $fake->embedded);
    $search->ask($query, ['ai' => true, 'log' => false]);
    check(count($fake->asked) > 0, 'el contexto normal vuelve a permitir la ayuda configurada');
});

test('indexar dentro del ámbito no genera vectores ni expansiones diferidas para un documento nuevo', function (): void {
    [$app, $fake] = searchAiApp(['search.vectors' => true, 'search.ai_expand' => true]);
    $id = aifecProduct($app, ['name' => 'Taburete de prueba']);
    $search = searchService($app);
    $before = [count($fake->asked), count($fake->embedded), searchJobCount($app, 'search.expand')];
    $search->withoutAiSideEffects(static function () use ($search, $id): void {
        $search->index('product', $id);
    });
    same($before, [count($fake->asked), count($fake->embedded), searchJobCount($app, 'search.expand')]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {search_docs} WHERE entity_id = ?', [$id]));
});
