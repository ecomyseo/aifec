<?php
/**
 * AIFEC — módulo de tareas: publica el servicio «jobs», el latido del panel y el aviso de estado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Jobs\Queue;

return static function (App $app): void {
    $app->bind('jobs', static function (App $app): Queue {
        return new Queue($app);
    });

    // Latido del panel: mientras haya una pestaña visible, un guion llama a /admin/jobs/tick cada minuto.
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        return '<script src="' . e($app->url()->asset('m', 'jobs', 'tick.js')) . '" data-af-tick-url="'
            . e($app->url()->plain('/admin/jobs/tick')) . '" defer></script>';
    });

    // Avisos del inicio: tareas paradas y trabajos fallidos.
    $app->hooks()->filter('admin.status', static function ($status, App $app): array {
        $status = is_array($status) ? $status : [];
        if (!$app->auth()->can('jobs.view')) {
            return $status;
        }
        /** @var Queue $queue */
        $queue = $app->service('jobs');
        $last = $queue->lastRun();
        if ($last === null || $app->clock()->now() - $last > 900) {
            $status[] = ['kind' => 'warn', 'text' => t('Background tasks are not running'), 'url' => $app->url()->plain('/admin/jobs/schedule'), 'label' => t('See how to start them')];
        }
        $failed = (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status = 'failed' AND finished_at >= ?", [$app->clock()->sql($app->clock()->now() - 86400)]);
        if ($failed > 0) {
            $status[] = [
                'kind' => 'bad',
                'text' => tn('{n} background job failed in the last 24 hours', '{n} background jobs failed in the last 24 hours', $failed),
                'url' => $app->url()->plain('/admin/r/jobs') . '?f%5Bstatus%5D=failed',
                'label' => t('Review'),
            ];
        }

        return $status;
    });
};
