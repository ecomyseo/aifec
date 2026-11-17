<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

/**
 * Tareas programadas del asistente (las llama el módulo de tareas, ver jobs.php).
 */
final class AssistantTasks
{
    /**
     * «assistant.purge»: borra las conversaciones que llevan más de «assistant.retention_days» días (30) sin actividad.
     * Lo que escribe un cliente puede llevar datos personales, así que no se guarda para siempre. Devuelve una nota
     * corta, o null si no había nada que borrar.
     */
    public static function purge(App $app): ?string
    {
        $days = $app->settings()->int('assistant.retention_days', 30);
        $done = (new Conversations($app))->purge($days);

        return $done['conversations'] > 0 ? $done['conversations'] . ' conversations, ' . $done['messages'] . ' messages' : null;
    }
}
