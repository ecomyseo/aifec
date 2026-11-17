<?php
/**
 * AIFEC — órdenes de consola del buscador.
 *
 *   php aifec search:reindex [type] [--queue]   reindexa todo (o un tipo: product, page, post) ahora, tanda a tanda;
 *                                               con --queue lo deja en la cola de tareas
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Search\Search;

return [
    'search:reindex' => ['help' => 'Rebuilds the search index: search:reindex [product|page|post] [--queue]', 'run' => static function (App $app, array $args, Console $io): int {
        /** @var Search $search */
        $search = $app->service('search');
        $type = isset($args[0]) && is_string($args[0]) && $args[0] !== '' ? $args[0] : null;
        if ($type !== null && !in_array($type, Search::TYPES, true)) {
            $io->error('Unknown type: ' . $type . ' (use product, page or post)');

            return 2;
        }
        if (!empty($args['queue'])) {
            $job = $search->reindex($type);
            $io->line('Reindex queued (job ' . $job . ')');

            return 0;
        }
        $read = $search->reindexNow($type);
        $io->line('Reindexed ' . $read . ' items');

        return 0;
    }],
];
