<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;

/**
 * Las tareas programadas del plugin (las ejecuta el módulo de tareas, ver jobs.php). Devuelven una nota corta o null.
 */
final class Tasks
{
    /** Borra el registro de comprobaciones de la API de más de 90 días. */
    public static function purge(App $app): ?string
    {
        $n = (new Licenses($app))->purgeChecks();

        return $n > 0 ? $n . ' checks deleted' : null;
    }
}
