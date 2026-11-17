<?php
/**
 * AIFEC — comprobaciones de las tareas en segundo plano (solo si el módulo de tareas está).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;

final class TaskChecks
{
    /** Segundos sin que corra el cron a partir de los cuales se avisa. */
    private const WARN_AFTER = 900;

    /** Segundos sin que corra el cron a partir de los cuales es un error. */
    private const BAD_AFTER = 7200;

    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        if (!$app->has('jobs')) {
            return [];
        }

        return [
            ['id' => 'tasks.cron', 'group' => 'tasks', 'label' => t('Scheduled tasks are running'), 'run' => [self::class, 'cron']],
            ['id' => 'tasks.failed_jobs', 'group' => 'tasks', 'label' => t('Background jobs that failed in 24 hours'), 'run' => [self::class, 'failedJobs']],
            ['id' => 'tasks.scheduled', 'group' => 'tasks', 'label' => t('Scheduled tasks with an error'), 'run' => [self::class, 'scheduled']],
        ];
    }

    /** @return array<string,string> */
    public static function cron(App $app): array
    {
        $fix = $app->url()->plain('/admin/jobs/schedule');
        $command = '* * * * * php ' . $app->root() . '/aifec cron --site=' . $app->site()->id;
        $last = $app->service('jobs')->lastRun();
        if ($last === null) {
            return Doctor::result('bad', t('They have never run. Add this to the server cron: {command}', ['command' => $command]), $fix);
        }
        $age = $app->clock()->now() - $last;
        $when = t('Last run: {time} UTC', ['time' => $app->clock()->sql($last)]);
        if ($age >= self::BAD_AFTER) {
            return Doctor::result('bad', $when . ' — ' . t('Add this to the server cron: {command}', ['command' => $command]), $fix);
        }
        if ($age >= self::WARN_AFTER) {
            return Doctor::result('warn', $when, $fix);
        }

        return Doctor::result('ok', $when);
    }

    /** @return array<string,string> */
    public static function failedJobs(App $app): array
    {
        $since = $app->clock()->sql($app->clock()->now() - 86400);
        $n = (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status = 'failed' AND finished_at >= ?", [$since]);
        if ($n === 0) {
            return Doctor::result('ok', t('None'));
        }

        return Doctor::result('warn', tn('{n} job failed', '{n} jobs failed', $n), $app->url()->plain('/admin/r/jobs') . '?f%5Bstatus%5D=failed');
    }

    /** @return array<string,string> */
    public static function scheduled(App $app): array
    {
        $failing = [];
        foreach ($app->service('jobs')->tasks() as $task) {
            if ($task['last_error'] !== null && $task['last_error'] !== '') {
                $failing[] = (string) $task['task'];
            }
        }
        if ($failing === []) {
            return Doctor::result('ok', t('None'));
        }

        return Doctor::result('bad', implode(', ', $failing), $app->url()->plain('/admin/jobs/schedule'));
    }
}
