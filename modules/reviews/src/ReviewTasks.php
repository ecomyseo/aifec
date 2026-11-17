<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews;

use Aifec\Kernel\App;

/**
 * Tareas programadas de las valoraciones (las llama el módulo de tareas, ver jobs.php).
 */
final class ReviewTasks
{
    /**
     * «reviews.ask»: manda las peticiones de opinión que tocan (anotadas al completarse el pedido, de las últimas 24 horas).
     * Devuelve una nota corta o null si no había nada que mandar.
     */
    public static function ask(App $app): ?string
    {
        $done = (new ReviewRequests($app))->sendDue();

        return $done['emails'] > 0 ? $done['emails'] . ' emails, ' . $done['links'] . ' links' : null;
    }
}
