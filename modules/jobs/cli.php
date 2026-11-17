<?php
/**
 * AIFEC — órdenes de consola del módulo de tareas.
 *
 *   php aifec cron [--seconds=50]       tareas periódicas y después la cola (es lo que va en el cron del servidor, cada minuto)
 *   php aifec jobs:work [--seconds=20]  solo la cola
 *   php aifec jobs:status               cuántos trabajos hay en cada estado y cuándo corrió cron por última vez
 *   php aifec jobs:retry <id>           un trabajo fallido vuelve a pendiente
 *   php aifec jobs:run <tarea>          ejecuta una tarea periódica ahora
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Jobs\Queue;

/**
 * @param array<int|string,string|bool> $args
 */
$seconds = static function (array $args, int $default): int {
    return isset($args['seconds']) && is_string($args['seconds']) && ctype_digit($args['seconds']) ? max(1, (int) $args['seconds']) : $default;
};

return [
    'cron' => ['help' => 'Runs the scheduled tasks and then the queue: cron [--seconds=50]', 'run' => static function (App $app, array $args, Console $io) use ($seconds): int {
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        $cron = $queue->cron();
        $work = $queue->work($seconds($args, 50));
        $io->line('cron: ran ' . count($cron['ran']) . ', failed ' . count($cron['failed']) . ', skipped ' . $cron['skipped']
            . ' · jobs: done ' . $work['done'] . ', failed ' . $work['failed'] . ', retried ' . $work['retried'] . ', reclaimed ' . $work['reclaimed']);
        foreach ($cron['failed'] as $task => $reason) {
            $io->error($task . ': ' . $reason);
        }

        return 0;
    }],
    'jobs:work' => ['help' => 'Works through the queue: jobs:work [--seconds=20]', 'run' => static function (App $app, array $args, Console $io) use ($seconds): int {
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        $work = $queue->work($seconds($args, 20));
        $io->line('jobs: done ' . $work['done'] . ', failed ' . $work['failed'] . ', retried ' . $work['retried'] . ', reclaimed ' . $work['reclaimed']);

        return 0;
    }],
    'jobs:status' => ['help' => 'Shows the jobs by state and the last cron run', 'run' => static function (App $app, array $args, Console $io): int {
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($app->db()->select('SELECT status, COUNT(*) AS n FROM {jobs} GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $last = $queue->lastRun();
        $io->line('jobs: pending ' . $counts['pending'] . ', running ' . $counts['running'] . ', done ' . $counts['done'] . ', failed ' . $counts['failed']
            . ' · last cron: ' . ($last === null ? 'never' : $app->clock()->sql($last) . ' UTC'));

        return 0;
    }],
    'jobs:retry' => ['help' => 'Sends a failed job back to the queue: jobs:retry <id>', 'run' => static function (App $app, array $args, Console $io): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '' || !ctype_digit($id)) {
            $io->error('Usage: jobs:retry <id>');

            return 2;
        }
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        if (!$queue->retry((int) $id)) {
            $io->error('Job ' . $id . ' does not exist or is not failed');

            return 1;
        }
        $io->line('Job ' . $id . ' is pending again');

        return 0;
    }],
    'jobs:run' => ['help' => 'Runs a scheduled task now: jobs:run <task>', 'run' => static function (App $app, array $args, Console $io): int {
        $task = isset($args[0]) ? (string) $args[0] : '';
        if ($task === '') {
            $io->error('Usage: jobs:run <task>');

            return 2;
        }
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        try {
            $note = $queue->runTask($task);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        }
        $io->line('Task ' . $task . ' finished' . ($note !== null ? ': ' . $note : ''));

        return 0;
    }],
];
