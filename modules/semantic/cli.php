<?php
/**
 * AIFEC — órdenes de consola de los datos para máquinas.
 *
 *   php aifec aifec:audit [--queue]   enseña la nota del sitio y sus comprobaciones y mide todos los productos ahora, tanda a
 *                                     tanda; con --queue deja el cálculo de los productos en la cola de tareas
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Semantic\Audit;
use Aifec\Modules\Semantic\AuditJobs;
use Aifec\Modules\Semantic\AuditStore;
use Aifec\Modules\Semantic\Semantic;

return [
    'aifec:audit' => ['help' => 'AIFEC audit: the shop score and the checks, then measures the products: aifec:audit [--queue]', 'run' => static function (App $app, array $args, Console $io): int {
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $site = Audit::site($semantic);
        $io->line('AIFEC score of the shop: ' . $site['score'] . ' / 100');
        $rows = [];
        foreach ($site['checks'] as $check) {
            $rows[] = [$check['ok'] ? 'OK' : 'MISSING', (string) $check['weight'], (string) $check['label']];
        }
        $io->table(['Result', 'Weight', 'Check'], $rows);

        if (!empty($args['queue'])) {
            $io->line(AuditJobs::queueAll($app) ? 'The products are queued for measuring' : 'The job queue is not active: nothing queued');

            return 0;
        }
        $after = 0;
        $done = 0;
        do {
            $batch = Audit::batch($app, $after);
            $after = $batch['last'];
            $done += $batch['done'];
        } while ($batch['more']);
        $io->line('Products measured: ' . $done);
        foreach (AuditStore::distribution($app) as $band) {
            $io->line('Score ' . $band['from'] . ' to ' . $band['to'] . ': ' . $band['count']);
        }

        return 0;
    }],
];
