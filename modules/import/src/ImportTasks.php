<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

/**
 * Lo que el módulo deja a la cola de trabajos: un paso de una migración y el barrido de las colgadas.
 */
final class ImportTasks
{
    /**
     * Trabajo «import.step»: una tanda de la migración. Si queda más por hacer, encola la siguiente; si el origen falló,
     * la encola más tarde (10 segundos por cada fallo seguido).
     *
     * @param array<string,mixed> $payload run = identificador de la migración
     */
    public static function step(array $payload, App $app): string
    {
        $id = isset($payload['run']) ? (int) $payload['run'] : 0;
        /** @var ImportService $service */
        $service = $app->service('import');
        $runner = $service->runner();
        $outcome = $runner->step($id);
        if ($outcome === 'continue') {
            $runner->enqueue($id);
        } elseif ($outcome === 'retry') {
            $run = $service->runs()->find($id);
            $runner->enqueue($id, 10 * max(1, $run !== null ? (int) $run['source_errors'] : 1));
        }

        return $outcome;
    }

    /**
     * Tarea periódica «import.sweep»: cierra como fallida la migración que lleva una hora «en curso» sin latido y cancela
     * los borradores abandonados.
     */
    public static function sweep(App $app): ?string
    {
        /** @var ImportService $service */
        $service = $app->service('import');
        $closed = $service->runner()->sweep();

        return $closed > 0 ? 'Closed ' . $closed . ' stale migrations' : null;
    }
}
