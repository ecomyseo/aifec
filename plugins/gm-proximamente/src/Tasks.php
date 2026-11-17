<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;

/**
 * Las tareas programadas del plugin (las ejecuta el módulo de tareas, ver jobs.php). Devuelven una nota corta o null.
 */
final class Tasks
{
    /** Pasa a disponibles los productos cuya fecha de salida llegó, y avisa a quien lo pidió en los últimos 90 días. */
    public static function release(App $app): ?string
    {
        $n = (new Soon($app))->releaseDue();

        return $n > 0 ? $n . ' released' : null;
    }

    /** Borra las peticiones de aviso de más de 90 días. */
    public static function purge(App $app): ?string
    {
        $n = (new Soon($app))->purge();

        return $n > 0 ? $n . ' requests deleted' : null;
    }
}
