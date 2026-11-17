<?php
/**
 * AIFEC — rutas del módulo de tareas: dirección web de cron, latido del panel y pantallas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Jobs\JobsController;

return static function (Router $r, App $app): void {
    // Para alojamientos sin cron: la clave va en la dirección. Con clave mala, 404.
    $r->get('/cron/{key:[A-Za-z0-9_-]+}', [JobsController::class, 'cron'])->name('jobs.cron');

    $r->group(['prefix' => '/admin/jobs', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/schedule', [JobsController::class, 'schedule'])->name('admin.jobs.schedule');
        $r->post('/run/{task:[A-Za-z0-9._-]+}', [JobsController::class, 'run']);
        $r->post('/retry/{id:\d+}', [JobsController::class, 'retry']);
        $r->post('/tick', [JobsController::class, 'tick'])->name('admin.jobs.tick');
    });
};
