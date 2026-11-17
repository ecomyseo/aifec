<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;

/**
 * Los trabajos en segundo plano del módulo (los registra jobs.php):
 *
 *   semantic.feeds   rehace los ficheros de los feeds de producto. Lo encolan los ganchos de guardado y de existencias con
 *                    cinco minutos de retraso y una clave única: cien cambios seguidos son un solo trabajo. La tarea periódica del
 *                    mismo nombre lo hace cada hora.
 */
final class SemanticJobs
{
    /** Minutos que se espera antes de rehacer los feeds: los cambios que lleguen mientras tanto entran en la misma pasada. */
    private const DELAY_SECONDS = 300;

    /** Encola el trabajo de los feeds (uno solo a la vez). Sin el módulo de tareas no hace nada: los rehace la tarea periódica. */
    public static function queue(App $app): void
    {
        if (!$app->has('jobs') || !$app->has('semantic')) {
            return;
        }
        $app->service('jobs')->push('semantic.feeds', [], ['unique' => 'semantic.feeds', 'delay' => self::DELAY_SECONDS]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function feeds(array $payload, App $app): string
    {
        return self::run($app);
    }

    /** La tarea periódica (cada hora). */
    public static function feedsTask(App $app): string
    {
        return self::run($app);
    }

    private static function run(App $app): string
    {
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $counts = Feeds::generate($semantic);
        if ($counts === []) {
            return 'No feeds to build';
        }
        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = Feeds::FILES[$key] . ': ' . $count;
        }

        return 'Feeds built: ' . implode(', ', $parts);
    }
}
