<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

use Aifec\Kernel\App;

/**
 * Tareas programadas del consentimiento (las ejecuta el módulo de tareas, ver jobs.php).
 */
final class ConsentTasks
{
    /** Días que se guarda la prueba de una decisión (5 años). */
    public const RETENTION_DAYS = 1825;

    /**
     * «consent.purge»: borra las decisiones de más de cinco años. Devuelve una nota corta o null si no había nada.
     */
    public static function purge(App $app): ?string
    {
        $limit = $app->clock()->sql($app->clock()->now() - self::RETENTION_DAYS * 86400);
        $deleted = $app->db()->run('DELETE FROM {consent_log} WHERE created_at < ?', [$limit]);

        return $deleted > 0 ? $deleted . ' rows' : null;
    }
}
