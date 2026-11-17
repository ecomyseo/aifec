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
use Aifec\Modules\Jobs\Queue;

/** Aplicación de prueba que solo carga el módulo de tareas, ya arrancada. */
if (!function_exists('jobsApp')) {
    function jobsApp(): App
    {
        $app = testApp(['modules' => ['jobs']]);
        $app->boot();

        return $app;
    }

    function jobsQueue(App $app): Queue
    {
        /** @var Queue $queue */
        $queue = $app->service('jobs');

        return $queue;
    }

    /** @return array<string,mixed> */
    function jobsRow(App $app, int $id): array
    {
        $row = $app->db()->row('SELECT * FROM {jobs} WHERE id = ?', [$id]);
        check($row !== null, 'el trabajo ' . $id . ' no existe');

        return (array) $row;
    }

    /**
     * Da de alta un trabajo a mano (para simular estados que solo se dan con otro proceso en marcha).
     *
     * @param array<string,mixed> $values
     */
    function jobsInsert(App $app, array $values): int
    {
        $now = $app->clock()->sql();

        return $app->db()->insert('jobs', $values + [
            'queue' => 'default', 'type' => 'demo', 'payload' => '[]', 'status' => 'pending', 'attempts' => 0,
            'max_attempts' => 3, 'run_at' => $now, 'created_at' => $now,
        ]);
    }

    /**
     * Tareas que cron() ha ejecutado bien, sin la limpieza propia del módulo (que también toca a las 13:00).
     *
     * @param array<string,mixed> $cron
     *
     * @return string[]
     */
    function jobsRan(array $cron): array
    {
        return array_values(array_diff($cron['ran'], ['jobs.purge']));
    }

    /** @return array<string,mixed> */
    function jobsCronRow(App $app, string $task): array
    {
        $row = $app->db()->row('SELECT * FROM {cron_runs} WHERE task = ?', [$task]);
        check($row !== null, 'la tarea ' . $task . ' no tiene fila en cron_runs');

        return (array) $row;
    }
}
