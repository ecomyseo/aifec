<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * Tareas programadas del copiloto (las llama el módulo de tareas, ver jobs.php).
 */
final class CopilotTasks
{
    /**
     * «copilot.purge»: borra del historial lo que lleva más de «copilot.history_days» días (90) sin tocarse. Solo borra filas del
     * propio copiloto: nunca datos de la tienda. Devuelve una nota corta, o null si no había nada.
     */
    public static function purge(App $app): ?string
    {
        $done = (new Proposals($app))->purge($app->settings()->int('copilot.history_days', 90));

        return $done > 0 ? $done . ' proposals' : null;
    }
}
