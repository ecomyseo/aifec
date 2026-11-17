<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('una tarea por segundos corre, no vuelve a correr antes de tiempo y vuelve a correr después', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $runs = 0;
    $q->schedule('demo.sec', 60, function () use (&$runs): string {
        ++$runs;

        return 'nota';
    });

    same(['demo.sec'], jobsRan($q->cron()));
    same(1, $runs);
    $again = $q->cron();
    same([], jobsRan($again));
    check($again['skipped'] >= 1, 'se cuenta como saltada');
    same(1, $runs);

    $app->clock()->advance(59);
    same([], jobsRan($q->cron()));
    $app->clock()->advance(1);
    same(['demo.sec'], jobsRan($q->cron()));
    same(2, $runs);
    same(2, (int) jobsCronRow($app, 'demo.sec')['runs']);
});

test('una tarea hourly toca cada 3.600 segundos', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $runs = 0;
    $q->schedule('demo.hour', 'hourly', function () use (&$runs): void {
        ++$runs;
    });
    $q->cron();
    $app->clock()->advance(3599);
    $q->cron();
    same(1, $runs);
    $app->clock()->advance(1);
    $q->cron();
    same(2, $runs);
});

test('daily@HH:MM no corre antes de la hora del sitio, corre una vez ese día y otra al día siguiente', function (): void {
    // El reloj de las pruebas marca las 12:00 UTC; el sitio está en Europe/Madrid (invierno): son las 13:00.
    $app = jobsApp();
    $q = jobsQueue($app);
    $runs = 0;
    $q->schedule('demo.day', 'daily@14:00', function () use (&$runs): void {
        ++$runs;
    });

    same([], jobsRan($q->cron()), 'a las 13:00 todavía no');
    $app->clock()->advance(3540);
    $q->cron();
    same(0, $runs, 'a las 13:59');
    $app->clock()->advance(60);
    same(['demo.day'], jobsRan($q->cron()));
    same(1, $runs, 'a las 14:00 corre');
    $app->clock()->advance(3600);
    $q->cron();
    $app->clock()->advance(8 * 3600);
    $q->cron();
    same(1, $runs, 'una sola vez ese día');

    $app->clock()->advance(14 * 3600 + 59 * 60); // de las 23:00 a las 13:59 del día siguiente
    $q->cron();
    same(1, $runs, 'al día siguiente, antes de la hora, no');
    $app->clock()->advance(60);
    $q->cron();
    same(2, $runs, 'al día siguiente, a su hora, otra vez');
});

test('una tarea que lanza no impide las demás y deja su last_error; si luego va bien, se borra', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $fail = true;
    $q->schedule('a.bad', 60, function () use (&$fail): void {
        if ($fail) {
            throw new \RuntimeException('Fallo con «comillas» & ñ');
        }
    });
    $q->schedule('b.good', 60, function (): string {
        return 'bien';
    });

    $r = $q->cron();
    same(['b.good'], jobsRan($r));
    same(['a.bad' => 'Fallo con «comillas» & ñ'], $r['failed']);
    $row = jobsCronRow($app, 'a.bad');
    same('Fallo con «comillas» & ñ', $row['last_error']);
    same(null, $row['last_ok_at']);
    same(1, (int) $row['runs']);
    same(null, jobsCronRow($app, 'b.good')['last_error']);
    check(jobsCronRow($app, 'b.good')['last_ok_at'] !== null, 'la buena apunta su última vez bien');

    $fail = false;
    $app->clock()->advance(60);
    $r = $q->cron();
    same(['a.bad', 'b.good'], jobsRan($r));
    $row = jobsCronRow($app, 'a.bad');
    same(null, $row['last_error']);
    same($app->clock()->sql(), $row['last_ok_at']);
    same(2, (int) $row['runs']);
});

test('si otro proceso acaba de reclamar la tarea, esta no la ejecuta', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $runs = 0;
    $q->schedule('demo.race', 60, function () use (&$runs): void {
        ++$runs;
    });
    // El otro proceso ya apuntó su reclamo hace un segundo.
    $app->db()->insert('cron_runs', ['task' => 'demo.race', 'last_run_at' => $app->clock()->sql($app->clock()->now() - 1), 'runs' => 0]);
    same([], jobsRan($q->cron()));
    same(0, $runs);
    $app->clock()->advance(60);
    same(['demo.race'], jobsRan($q->cron()));
    same(1, $runs);
});

test('runTask ejecuta ya, devuelve la nota, cuenta la ejecución y relanza si la tarea falla', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->schedule('demo.now', 3600, function (): string {
        return 'hecho & ñ';
    });
    $q->schedule('demo.boom', 3600, function (): void {
        throw new \RuntimeException('se rompió');
    });

    same('hecho & ñ', $q->runTask('demo.now'));
    $row = jobsCronRow($app, 'demo.now');
    same(1, (int) $row['runs']);
    same($app->clock()->sql(), $row['last_run_at']);
    same($app->clock()->sql(), $row['last_ok_at']);
    // Ejecutada a mano, la periódica no se vuelve a lanzar enseguida.
    same([], jobsRan($q->cron()));

    throws(function () use ($q): void {
        $q->runTask('demo.boom');
    }, \RuntimeException::class);
    same('se rompió', jobsCronRow($app, 'demo.boom')['last_error']);
    throws(function () use ($q): void {
        $q->runTask('no.existe');
    }, \InvalidArgumentException::class);
});

test('lastRun marca cuándo corrió cron por última vez y la marca no sale entre las tareas', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    same(null, $q->lastRun());
    $q->cron();
    same($app->clock()->now(), $q->lastRun());
    $app->clock()->advance(500);
    $q->cron();
    same($app->clock()->now(), $q->lastRun());
    foreach ($q->tasks() as $task) {
        check(strpos((string) $task['task'], '_') !== 0, 'la marca interna no es una tarea');
    }
});

test('las tareas que declara un módulo salen con su descripción y cada cuánto', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->schedule('demo.list', '3600', function (): void {
    }, 'Texto & descripción');
    $by = [];
    foreach ($q->tasks() as $task) {
        $by[$task['task']] = $task;
    }
    check(isset($by['jobs.purge']), 'la limpieza del propio módulo está');
    same('daily@03:40', $by['jobs.purge']['every']);
    same('Texto & descripción', $by['demo.list']['description']);
    same(0, $by['demo.list']['runs']);
    same(null, $by['demo.list']['last_run_at']);
});

test('schedule rechaza lo que no es una frecuencia válida', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $noop = function (): void {
    };
    foreach ([10, 'daily@25:00', 'daily@4:10', 'weekly', ''] as $bad) {
        throws(function () use ($q, $noop, $bad): void {
            $q->schedule('demo.bad', $bad, $noop);
        }, \InvalidArgumentException::class, 'frecuencia ' . var_export($bad, true));
    }
    throws(function () use ($q, $noop): void {
        $q->schedule('_oculta', 60, $noop);
    }, \InvalidArgumentException::class);
    $q->schedule('demo.ok', 30, $noop);
    $q->schedule('demo.ok2', 'daily@23:59', $noop);
    same(3, count($q->tasks()), 'la limpieza y las dos buenas');
});
