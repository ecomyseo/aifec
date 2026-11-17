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
use Aifec\Modules\Jobs\PermanentFailure;

require_once __DIR__ . '/helpers.php';

test('un trabajo encolado se ejecuta, guarda su resultado y su hora de fin', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.ok', function (array $payload, App $a): string {
        return 'hola ' . $payload['name'];
    });
    $id = $q->push('demo.ok', ['name' => 'Mía & Ñu <b> 😀']);
    same('pending', jobsRow($app, $id)['status']);

    same(['done' => 1, 'failed' => 0, 'retried' => 0, 'reclaimed' => 0], $q->work());

    $row = jobsRow($app, $id);
    same('done', $row['status']);
    same('hola Mía & Ñu <b> 😀', $row['result']);
    same($app->clock()->sql(), $row['finished_at']);
    same(1, (int) $row['attempts']);
    same(null, $row['locked_at']);
    same(null, $row['error']);
});

test('un manejador que devuelve un array guarda el resultado como JSON, y sin devolver nada queda vacío', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.array', function (): array {
        return ['n' => 3, 'ñ' => 'é'];
    });
    $q->register('demo.nothing', function (): void {
    });
    $a = $q->push('demo.array');
    $b = $q->push('demo.nothing');
    $q->work();
    same('{"n":3,"ñ":"é"}', jobsRow($app, $a)['result']);
    same(null, jobsRow($app, $b)['result']);
    same('done', jobsRow($app, $b)['status']);
});

test('un trabajo que falla dos veces y a la tercera va: reintentos con retraso creciente', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $calls = 0;
    $q->register('demo.flaky', function () use (&$calls): string {
        ++$calls;
        if ($calls < 3) {
            throw new \RuntimeException('boom ' . $calls);
        }

        return 'por fin';
    });
    $id = $q->push('demo.flaky');
    $start = $app->clock()->now();

    same(1, $q->work()['retried']);
    $row = jobsRow($app, $id);
    same('pending', $row['status']);
    same(1, (int) $row['attempts']);
    same('boom 1', $row['error']);
    same($app->clock()->sql($start + 60), $row['run_at']);

    // Todavía no toca: no se vuelve a ejecutar.
    same(['done' => 0, 'failed' => 0, 'retried' => 0, 'reclaimed' => 0], $q->work());
    $app->clock()->advance(59);
    same(0, $q->work()['retried']);
    same(1, $calls);

    $app->clock()->advance(1);
    same(1, $q->work()['retried']);
    $row = jobsRow($app, $id);
    same(2, (int) $row['attempts']);
    same($app->clock()->sql($start + 60 + 240), $row['run_at']);

    $app->clock()->advance(239);
    same(0, $q->work()['done']);
    $app->clock()->advance(1);
    same(1, $q->work()['done']);
    $row = jobsRow($app, $id);
    same('done', $row['status']);
    same(3, (int) $row['attempts']);
    same('por fin', $row['result']);
    same(null, $row['error']);
});

test('agotados los intentos el trabajo acaba en failed con el mensaje', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.bad', function (): void {
        throw new \LogicException('nunca funciona');
    });
    $id = $q->push('demo.bad', [], ['max_attempts' => 2]);
    $q->work();
    $app->clock()->advance(60);
    same(['done' => 0, 'failed' => 1, 'retried' => 0, 'reclaimed' => 0], $q->work());
    $row = jobsRow($app, $id);
    same('failed', $row['status']);
    same('nunca funciona', $row['error']);
    same(2, (int) $row['attempts']);
    same($app->clock()->sql(), $row['finished_at']);
});

test('cualquier Throwable se recoge, también un Error, y no tumba al resto de la cola', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.error', function (): void {
        throw new \Error('esto no es una excepción');
    });
    $q->register('demo.fine', function (): string {
        return 'bien';
    });
    $bad = $q->push('demo.error', [], ['max_attempts' => 1]);
    $good = $q->push('demo.fine');
    same(['done' => 1, 'failed' => 1, 'retried' => 0, 'reclaimed' => 0], $q->work());
    same('failed', jobsRow($app, $bad)['status']);
    same('esto no es una excepción', jobsRow($app, $bad)['error']);
    same('done', jobsRow($app, $good)['status']);
});

test('PermanentFailure manda el trabajo a failed a la primera, sin reintentos', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $calls = 0;
    $q->register('demo.perm', function () use (&$calls): void {
        ++$calls;
        throw new PermanentFailure('dirección mal escrita');
    });
    $id = $q->push('demo.perm');
    same(['done' => 0, 'failed' => 1, 'retried' => 0, 'reclaimed' => 0], $q->work());
    $row = jobsRow($app, $id);
    same('failed', $row['status']);
    same('dirección mal escrita', $row['error']);
    same(1, (int) $row['attempts']);
    $app->clock()->advance(3600);
    $q->work();
    same(1, $calls);
});

test('un tipo sin manejador falla a la primera con «No handler for»', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $id = $q->push('ghost.type');
    same(1, $q->work()['failed']);
    $row = jobsRow($app, $id);
    same('failed', $row['status']);
    same('No handler for ghost.type', $row['error']);
    same(1, (int) $row['attempts']);
});

test('unique: dos push iguales dan el mismo id y al terminar el primero se puede encolar otro', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.u', function (): string {
        return 'x';
    });
    $a = $q->push('demo.u', ['n' => 1], ['unique' => 'cat:5']);
    $b = $q->push('demo.u', ['n' => 2], ['unique' => 'cat:5']);
    $c = $q->push('demo.u', ['n' => 3], ['unique' => 'cat:6']);
    same($a, $b);
    check($c !== $a, 'otra clave, otro trabajo');
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'demo.u'"));

    // En curso también cuenta como «ya hay uno».
    $app->db()->update('jobs', ['status' => 'running', 'locked_at' => $app->clock()->sql()], ['id' => $a]);
    same($a, $q->push('demo.u', [], ['unique' => 'cat:5']));

    $app->db()->update('jobs', ['status' => 'pending', 'locked_at' => null], ['id' => $a]);
    $q->work();
    $d = $q->push('demo.u', [], ['unique' => 'cat:5']);
    check($d !== $a, 'terminado el primero, se puede encolar otro');

    // Una clave larguísima cabe en la columna y sigue siendo la misma.
    $long = str_repeat('ñ', 300);
    same($q->push('demo.u', [], ['unique' => $long]), $q->push('demo.u', [], ['unique' => $long]));
});

test('delay: el trabajo no se ejecuta hasta que pasa el tiempo', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.later', function (): string {
        return 'ya';
    });
    $id = $q->push('demo.later', [], ['delay' => 300]);
    same(0, $q->work()['done']);
    $app->clock()->advance(299);
    same(0, $q->work()['done']);
    $app->clock()->advance(1);
    same(1, $q->work()['done']);
    same('done', jobsRow($app, $id)['status']);
});

test('reclamar es una sola sentencia: lo que ya tomó otro proceso no se ejecuta dos veces', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $calls = 0;
    $q->register('demo.once', function () use (&$calls): void {
        ++$calls;
    });
    $id = $q->push('demo.once');

    same(true, $q->claim($id));
    same(false, $q->claim($id), 'el segundo proceso no puede reclamarlo');
    same('running', jobsRow($app, $id)['status']);
    same(1, (int) jobsRow($app, $id)['attempts']);

    // Otro proceso lo tiene en curso desde hace un momento: work() ni lo toca.
    same(['done' => 0, 'failed' => 0, 'retried' => 0, 'reclaimed' => 0], $q->work());
    same(0, $calls);
});

test('un trabajo abandonado en running hace 11 minutos se recupera o se cierra con Timed out', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $calls = 0;
    $q->register('demo.lost', function () use (&$calls): string {
        ++$calls;

        return 'recuperado';
    });
    $old = $app->clock()->sql($app->clock()->now() - 660);
    $recent = $app->clock()->sql($app->clock()->now() - 300);
    $withAttempts = jobsInsert($app, ['type' => 'demo.lost', 'status' => 'running', 'locked_at' => $old, 'attempts' => 1]);
    $exhausted = jobsInsert($app, ['type' => 'demo.lost', 'status' => 'running', 'locked_at' => $old, 'attempts' => 3, 'max_attempts' => 3]);
    $alive = jobsInsert($app, ['type' => 'demo.lost', 'status' => 'running', 'locked_at' => $recent, 'attempts' => 1]);

    $r = $q->work();
    same(2, $r['reclaimed']);
    same(1, $r['done']);
    same(1, $calls, 'solo el que tenía intentos se vuelve a ejecutar');

    same('done', jobsRow($app, $withAttempts)['status']);
    same(2, (int) jobsRow($app, $withAttempts)['attempts']);
    $row = jobsRow($app, $exhausted);
    same('failed', $row['status']);
    same('Timed out', $row['error']);
    same(null, $row['locked_at']);
    same('running', jobsRow($app, $alive)['status'], 'uno que lleva 5 minutos sigue en curso');
});

test('el resultado y el error se guardan recortados a 2.000 caracteres', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.long', function (): string {
        return str_repeat('é', 5000);
    });
    $q->register('demo.longerror', function (): void {
        throw new \RuntimeException(str_repeat('ñ', 3000));
    });
    $a = $q->push('demo.long');
    $b = $q->push('demo.longerror', [], ['max_attempts' => 1]);
    $q->work();
    same(2000, mb_strlen((string) jobsRow($app, $a)['result']));
    same(2000, mb_strlen((string) jobsRow($app, $b)['error']));
});

test('work respeta el presupuesto: sin tiempo no empieza ninguno y con $max se para', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->register('demo.n', function (): string {
        return 'n';
    });
    for ($i = 0; $i < 3; ++$i) {
        $q->push('demo.n');
    }
    same(0, $q->work(0)['done']);
    same(3, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status = 'pending'"));
    same(2, $q->work(20, 2)['done']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status = 'pending'"));
    same(1, $q->work()['done']);
});

test('retry devuelve un fallido a pendiente con los intentos a cero; los demás estados no', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $failed = jobsInsert($app, ['status' => 'failed', 'attempts' => 3, 'error' => 'x', 'finished_at' => $app->clock()->sql()]);
    $done = jobsInsert($app, ['status' => 'done', 'attempts' => 1, 'finished_at' => $app->clock()->sql()]);
    same(true, $q->retry($failed));
    $row = jobsRow($app, $failed);
    same('pending', $row['status']);
    same(0, (int) $row['attempts']);
    same(null, $row['finished_at']);
    same(false, $q->retry($done));
    same(false, $q->retry(99999));
    same('done', jobsRow($app, $done)['status']);
});

test('push valida el tipo y respeta max_attempts, queue y la carga con tildes y emojis', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    throws(function () use ($q): void {
        $q->push('');
    }, \InvalidArgumentException::class);
    throws(function () use ($q): void {
        $q->push(str_repeat('a', 81));
    }, \InvalidArgumentException::class);

    $id = $q->push('demo.opts', ['t' => 'Ñandú "x" & <y> 😀'], ['max_attempts' => 5, 'queue' => 'mail']);
    $row = jobsRow($app, $id);
    same(5, (int) $row['max_attempts']);
    same('mail', $row['queue']);
    same(['t' => 'Ñandú "x" & <y> 😀'], json_decode((string) $row['payload'], true));

    $q->register('demo.opts', function (array $payload): string {
        return $payload['t'];
    });
    same(0, $q->work(20, 50, 'default')['done'], 'otra cola no se toca');
    same(1, $q->work(20, 50, 'mail')['done']);
    same('Ñandú "x" & <y> 😀', jobsRow($app, $id)['result']);
});
