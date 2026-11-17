<?php
/**
 * AIFEC — pruebas de los lotes: estimación, tandas de diez, pausar, reanudar y cancelar, parada por el tope diario, tres fallos
 * seguidos, que siempre queda cerrado con sus cuentas, el cursor que avanza por lo consumido y el trabajo de la cola.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Ai\BatchJobs;
use Aifec\Modules\Ai\Batches;

require_once __DIR__ . '/helpers.php';

/**
 * Productos sin descripción y un proveedor económico que contesta bien a todo.
 *
 * @return int[]
 */
function aiBatchProducts(App $app, int $n): array
{
    $ids = [];
    for ($i = 1; $i <= $n; ++$i) {
        $ids[] = aiProduct($app, ['name' => 'Producto ' . $i, 'description' => '']);
    }

    return $ids;
}

/** Encola $n respuestas buenas de resumen en el nivel económico. */
function aiBatchScript(App $app, int $n): void
{
    $results = [];
    for ($i = 0; $i < $n; ++$i) {
        $results[] = aiJson(['summary' => 'Resumen ' . $i], 100, 50);
    }
    aiFake($app)->script(...$results);
}

test('la estimación cuenta los productos, las fichas y el coste con los precios de los ajustes, sin lanzar nada', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 7);
    aiProduct($app, ['name' => 'Con descripción', 'description' => '<p>Ya tiene</p>']);
    $estimate = (new Batches($app))->estimate('summary', ['type' => 'no_description']);
    same(7, $estimate['count'], 'solo los que no tienen descripción');
    same(7 * 700, $estimate['tokens_in']);
    same(7 * 120, $estimate['tokens_out']);
    same(7 * 700 * 100 + 7 * 120 * 400, $estimate['cost_micro']);
    same('economy', $estimate['tier']);
    same(200 * 1000000, $estimate['limit_micro']);
    same(0, $estimate['spent_micro']);
    same([], aiRows($app, 'ai_batches'), 'estimar no crea ningún lote');
    same([], aiFake($app)->requests, 'ni llama a la IA');
    same('standard', (new Batches($app))->estimate('description', ['type' => 'no_description'])['tier']);

    throws(function () use ($app): void {
        (new Batches($app))->estimate('alt', ['type' => 'no_description']);
    }, \InvalidArgumentException::class);
    throws(function () use ($app): void {
        (new Batches($app))->estimate('summary', ['type' => 'todo']);
    }, \InvalidArgumentException::class);
});

test('un lote corre por tandas de diez a mano y siempre acaba «done» con sus cuentas: 25 productos son 10 + 10 + 5', function (): void {
    $app = aiApp();
    $ids = aiBatchProducts($app, 25);
    aiBatchScript($app, 25);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $batch = $batches->find($id);
    same('running', $batch['status']);
    same(25, (int) $batch['total']);
    same(0, (int) $batch['done']);

    $batch = $batches->runChunk($id);
    same(10, (int) $batch['done']);
    same('running', $batch['status']);
    same($ids[9], (int) $batch['last_id']);
    $batch = $batches->runChunk($id);
    same(20, (int) $batch['done']);
    same('running', $batch['status']);
    $batch = $batches->runChunk($id);
    same(25, (int) $batch['done']);
    same(0, (int) $batch['failed']);
    same('done', $batch['status'], 'la tercera tanda agota la fuente y lo cierra');
    check($batch['finished_at'] !== null);
    same(25, count(aiRows($app, 'ai_proposals')));
    same(25, count(aiRows($app, 'ai_usage')));
    same(10, count(aiFake($app)->requests) - 15, 'diez llamadas por tanda');

    $again = $batches->runChunk($id);
    same(25, (int) $again['done'], 'un lote terminado no vuelve a correr');
    same(25, count(aiFake($app)->requests));
});

test('un lote cuyo número de productos es múltiplo exacto de la tanda también termina (el fin lo da la fuente, no una tanda corta)', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 10);
    aiBatchScript($app, 10);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    same('done', $batches->runChunk($id)['status']);
});

test('un fallo en un producto cuenta como fallido, no se vuelve a leer y el lote sigue; las cuentas cuadran con el total', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 6);
    aiFake($app)->script(
        aiJson(['summary' => 'uno']),
        aiJson(['summary' => 'dos']),
        aiJson(['sin' => 'esquema']),
        aiJson(['summary' => 'cuatro']),
        aiJson(['summary' => 'cinco']),
        aiJson(['summary' => 'seis'])
    );
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $batch = $batches->runChunk($id);
    same('done', $batch['status']);
    same(5, (int) $batch['done']);
    same(1, (int) $batch['failed']);
    same((int) $batch['total'], (int) $batch['done'] + (int) $batch['failed'], 'hechos + fallidos = total');
    same(0, (int) $batch['streak'], 'un éxito después del fallo reinicia la racha');
    same(6, count(aiFake($app)->requests), 'el producto que falló no se reintentó en el mismo recorrido');
});

test('tres fallos seguidos pausan el lote con su motivo; al reanudarlo sigue por donde iba y no relee lo consumido', function (): void {
    $app = aiApp();
    $ids = aiBatchProducts($app, 8);
    $bad = aiJson(['sin' => 'esquema']);
    aiFake($app)->script(aiJson(['summary' => 'uno']), $bad, $bad, $bad);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $batch = $batches->runChunk($id);
    same('paused', $batch['status']);
    same('Three failures in a row', $batch['reason']);
    same(1, (int) $batch['done']);
    same(3, (int) $batch['failed']);
    same($ids[3], (int) $batch['last_id'], 'los tres fallidos se consumieron');
    same(4, count(aiFake($app)->requests));

    same('paused', $batches->runChunk($id)['status'], 'en pausa no corre');
    same(4, count(aiFake($app)->requests));

    check($batches->resume($id));
    $batch = $batches->find($id);
    same('running', $batch['status']);
    same(0, (int) $batch['streak']);
    same(null, $batch['reason']);
    aiBatchScript($app, 4);
    $batch = $batches->runChunk($id);
    same('done', $batch['status']);
    same(5, (int) $batch['done']);
    same(3, (int) $batch['failed']);
    same(8, (int) $batch['done'] + (int) $batch['failed']);
    same(8, count(aiFake($app)->requests), 'cada producto se pidió una sola vez');
});

test('pausar, reanudar y cancelar: solo en el estado que toca, y cancelar deja las propuestas ya hechas', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 12);
    aiBatchScript($app, 12);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $batches->runChunk($id);

    check($batches->pause($id, 'Paused by a person'));
    same('paused', $batches->find($id)['status']);
    same('Paused by a person', $batches->find($id)['reason']);
    check(!$batches->pause($id), 'ya estaba en pausa');
    $batches->runChunk($id);
    same(10, (int) $batches->find($id)['done'], 'en pausa no avanza');

    check($batches->resume($id));
    check(!$batches->resume($id), 'ya corre');
    check($batches->cancel($id));
    $batch = $batches->find($id);
    same('cancelled', $batch['status']);
    check($batch['finished_at'] !== null);
    same(10, (int) $batch['done']);
    same(10, count(aiRows($app, 'ai_proposals')), 'lo ya propuesto se queda');
    $batches->runChunk($id);
    same(10, (int) $batches->find($id)['done'], 'cancelado no corre');
    check(!$batches->cancel($id), 'no se cancela dos veces');
    check(!$batches->resume($id), 'ni se reanuda uno cancelado');
});

test('cancelar a mitad de una tanda deja contadas las propuestas que sí se hicieron', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 10);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $count = 0;
    // A la tercera llamada alguien cancela el lote desde el panel.
    $app->bind('ai.provider.economy', static function () use ($app, $batches, $id, &$count): \Aifec\Ai\AiProvider {
        return new class($app, $batches, $id, $count) implements \Aifec\Ai\AiProvider {
            /** @var App */
            private $app;
            /** @var Batches */
            private $batches;
            /** @var int */
            private $id;
            /** @var int */
            private $count;

            public function __construct(App $app, Batches $batches, int $id, int &$count)
            {
                $this->app = $app;
                $this->batches = $batches;
                $this->id = $id;
                $this->count = &$count;
            }

            public function name(): string
            {
                return 'cancelling';
            }

            public function generate(\Aifec\Ai\AiRequest $r): \Aifec\Ai\AiResult
            {
                ++$this->count;
                if ($this->count === 3) {
                    $this->batches->cancel($this->id);
                }

                return aiJson(['summary' => 'Resumen ' . $this->count]);
            }

            public function embed(array $texts, string $model = ''): array
            {
                return [];
            }
        };
    });
    $batch = $batches->runChunk($id);
    same('cancelled', $batch['status']);
    same(3, (int) $batch['done'], 'las tres llamadas hechas quedan contadas');
    same(3, count(aiRows($app, 'ai_proposals')));
    same(3, $count, 'no se pidió nada más tras cancelar');
});

test('el tope diario pausa el lote con su motivo sin consumir el producto que no se pudo hacer; otro día se reanuda y termina', function (): void {
    $app = aiApp(['ai.daily_limit_cents' => 1]);
    $ids = aiBatchProducts($app, 6);
    // Cada respuesta gasta medio céntimo: la tercera llamada encuentra el tope alcanzado.
    $half = static function (string $n): \Aifec\Ai\AiResult {
        return new \Aifec\Ai\AiResult((string) json_encode(['summary' => $n]), [], ['summary' => $n], 5000, 0, 'stop', 'fake-model');
    };
    aiFake($app)->script($half('uno'), $half('dos'), $half('tres'), $half('cuatro'), $half('cinco'), $half('seis'));
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $batch = $batches->runChunk($id);
    same('paused', $batch['status']);
    same('Daily AI limit reached', $batch['reason']);
    same(2, (int) $batch['done']);
    same(0, (int) $batch['failed'], 'el tope no es un fallo del producto');
    same($ids[1], (int) $batch['last_id'], 'el tercero no se consumió');
    same(2, count(aiFake($app)->requests), 'y no se llamó por él');

    $app->clock()->advance(86400);
    check($batches->resume($id));
    $batch = $batches->runChunk($id);
    same('paused', $batch['status'], 'el día nuevo da para dos más y se vuelve a parar');
    same(4, (int) $batch['done']);
    $app->clock()->advance(86400);
    $batches->resume($id);
    $batch = $batches->runChunk($id);
    same('done', $batch['status']);
    same(6, (int) $batch['done']);
    same(6, count(aiRows($app, 'ai_proposals')), 'cada producto tiene su propuesta una sola vez');
});

test('con la IA apagada a mitad de un lote se pausa en vez de dar cada producto por fallido', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 4);
    aiBatchScript($app, 4);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $app->settings()->set('ai.enabled', false);
    $batch = $batches->runChunk($id);
    same('paused', $batch['status']);
    same('AI is off', $batch['reason']);
    same(0, (int) $batch['failed']);
    same(0, (int) $batch['done']);
});

test('los conjuntos: por categoría y por traducción que falta; un lote sin productos no se lanza', function (): void {
    $app = aiApp([], ['es', 'en']);
    $chairs = aifecCategory($app, 'Sillas');
    $a = aiProduct($app, ['name' => 'Silla A', 'categories' => [$chairs], 'description' => '<p>x</p>']);
    $b = aiProduct($app, ['name' => 'Silla B', 'categories' => [$chairs], 'description' => '<p>x</p>', 'i18n' => ['en' => ['name' => 'Chair B']]]);
    aiProduct($app, ['name' => 'Mesa', 'description' => '<p>x</p>']);
    $batches = new Batches($app);

    same(2, $batches->estimate('summary', ['type' => 'category', 'category_id' => $chairs])['count']);
    same(2, $batches->estimate('translate', ['type' => 'missing_lang', 'lang' => 'en'], ['to' => 'en'])['count'], 'sin fila en inglés: Silla A y la Mesa');
    same(3, $batches->estimate('translate', ['type' => 'missing_lang', 'lang' => 'en', 'field' => 'description'], ['to' => 'en'])['count'], 'con el campo, también la que tiene fila pero sin descripción');
    same(0, $batches->estimate('summary', ['type' => 'no_description'])['count']);
    throws(function () use ($batches): void {
        $batches->start('summary', ['type' => 'no_description']);
    }, \InvalidArgumentException::class);
    throws(function () use ($batches): void {
        $batches->start('summary', ['type' => 'category', 'category_id' => 0]);
    }, \InvalidArgumentException::class);
    throws(function () use ($batches): void {
        $batches->start('translate', ['type' => 'missing_lang', 'lang' => 'en'], ['to' => 'es']);
    }, \InvalidArgumentException::class);
    unset($a, $b);
});

test('un lote de traducción trabaja sobre los que no tienen el idioma y escribe cada propuesta en ese idioma', function (): void {
    $app = aiApp([], ['es', 'en']);
    aiProduct($app, ['name' => 'Silla A', 'meta_title' => 'Meta A']);
    aiProduct($app, ['name' => 'Silla B', 'meta_title' => 'Meta B']);
    aiProduct($app, ['name' => 'Silla C', 'meta_title' => 'Meta C', 'i18n' => ['en' => ['name' => 'Chair C', 'meta_title' => 'Meta C en']]]);
    aiFake($app)->script(aiJson(['text' => 'Meta A en']), aiJson(['text' => 'Meta B en']));
    $batches = new Batches($app);
    $id = $batches->start('translate', ['type' => 'missing_lang', 'lang' => 'en', 'field' => 'meta_title'], ['field' => 'meta_title', 'to' => 'en']);
    $batch = $batches->runChunk($id);
    same('done', $batch['status']);
    same(2, (int) $batch['done']);
    $rows = aiRows($app, 'ai_proposals');
    same(['en', 'en'], array_column($rows, 'lang'));
    same(['meta_title', 'meta_title'], array_column($rows, 'field'));
    same(['Meta A en', 'Meta B en'], array_column($rows, 'proposed_text'));
});

test('el trabajo «ai.batch» de la cola corre una tanda y se encola a sí mismo hasta cerrar el lote, con el tope sin pasarse', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 23);
    aiBatchScript($app, 23);
    $batches = new Batches($app);
    $id = $batches->start('summary', ['type' => 'no_description']);
    $jobs = $app->service('jobs');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'ai.batch' AND status = 'pending'"), 'la primera tanda ya está en la cola');

    $rounds = 0;
    while ($rounds < 10 && (string) $batches->find($id)['status'] === 'running') {
        $jobs->work(20, 1);
        ++$rounds;
    }
    $batch = $batches->find($id);
    same('done', $batch['status']);
    same(23, (int) $batch['done']);
    same(3, $rounds, 'tres tandas, tres trabajos');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'ai.batch' AND status IN ('pending', 'running')"), 'no queda ningún trabajo pendiente');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'ai.batch' AND status = 'failed'"));
});

test('el vigía «ai.batches» vuelve a encolar un lote que corre sin ningún trabajo detrás, y no toca los pausados ni los terminados', function (): void {
    $app = aiApp();
    aiBatchProducts($app, 3);
    aiBatchProducts($app, 3);
    $batches = new Batches($app);
    $running = $batches->start('summary', ['type' => 'no_description']);
    $paused = $batches->start('summary', ['type' => 'no_description']);
    $batches->pause($paused);
    $app->db()->run("DELETE FROM {jobs} WHERE type = 'ai.batch'");

    same('Queued 1 batches again', BatchJobs::nudge($app));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'ai.batch' AND status = 'pending'"));
    same(null, BatchJobs::nudge($app), 'ya hay uno detrás: no se duplica');
    unset($running);
});
