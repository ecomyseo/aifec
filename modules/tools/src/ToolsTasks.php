<?php
/**
 * AIFEC — tareas del módulo de herramientas (las que declara su jobs.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Util\Bytes;

final class ToolsTasks
{
    /** Segundos de trabajo de cada vuelta de una copia en segundo plano; si no acaba, se encola otra vuelta. */
    private const BACKUP_SLICE = 120.0;

    /**
     * Tarea «tools.backup»: la copia nocturna (lo que diga `tools.backup_content`). Se apaga con el ajuste tools.backup_auto.
     * Al acabar se comprueba: si la comprobación falla, queda dicho en `tools.backup_failed` y el inicio del panel lo enseña.
     */
    public static function backup(App $app): ?string
    {
        if (!$app->settings()->bool('tools.backup_auto', true)) {
            return 'Skipped: the nightly backup is off';
        }
        return self::start($app, new Backups($app), 'auto');
    }

    /** Empieza una copia: por tandas si se puede (zip); si no, la de solo la base de datos de siempre, de un tirón. */
    private static function start(App $app, Backups $backups, string $mode): string
    {
        if (!Backups::zipAvailable()) {
            try {
                $done = $backups->create($mode);
            } catch (\Throwable $e) {
                self::noteFailure($app, 'The backup could not be made :: ' . $e->getMessage());
                throw $e;
            }

            return self::finalize($app, $backups, $done['name'], $done['bytes']);
        }

        return self::advance($app, $backups, $backups->begin($backups->mode($mode)));
    }

    /**
     * Trabajo «tools.backup_job»: la copia que el panel pidió en segundo plano porque la base de datos es grande, o la siguiente
     * vuelta de una copia que no acabó en la anterior (lleva su ficha).
     *
     * @param array<string,mixed> $payload mode o token
     */
    public static function backupJob(array $payload, App $app): string
    {
        $backups = new Backups($app);
        $token = isset($payload['token']) && is_string($payload['token']) ? $payload['token'] : '';
        if ($token !== '') {
            $run = $backups->resume($token);
            if ($run === null) {
                return 'The backup in progress no longer exists';
            }

            return self::advance($app, $backups, $run);
        }
        $mode = isset($payload['mode']) && is_string($payload['mode']) ? $payload['mode'] : 'auto';

        return self::start($app, $backups, $mode);
    }

    /**
     * Una vuelta de trabajo; si la copia queda hecha, se comprueba y se anota; si no, se encola la siguiente vuelta.
     */
    private static function advance(App $app, Backups $backups, BackupRun $run): string
    {
        try {
            $state = $run->work(self::BACKUP_SLICE);
        } catch (\Throwable $e) {
            $run->abort();
            self::noteFailure($app, 'The backup could not be made :: ' . $e->getMessage());
            throw $e;
        }
        if ($state['stage'] !== 'done') {
            if (!$app->has('jobs')) {
                $state = $run->work(86400.0);
            } else {
                $app->service('jobs')->push('tools.backup_job', ['token' => $state['token']]);

                return 'Backup in progress: ' . $state['done'] . ' of ' . $state['total'] . ' files';
            }
        }
        $backups->prune();

        return self::finalize($app, $backups, $state['name'], $state['bytes_total']);
    }

    /** Comprueba la copia recién hecha y anota el resultado. */
    private static function finalize(App $app, Backups $backups, string $name, int $bytes): string
    {
        $state = ['name' => $name, 'bytes_total' => $bytes];
        $check = $backups->verify($state['name']);
        if (!$check['ok']) {
            $first = $check['errors'] !== [] ? $check['errors'][0] : 'The backup is broken';
            $message = 'The backup failed its check :: ' . $state['name'] . ': ' . $first;
            self::noteFailure($app, $message);
            throw new \RuntimeException($message);
        }
        $app->settings()->set('tools.backup_failed', '');

        return 'Backup ' . $state['name'] . ' (' . Bytes::format($state['bytes_total'], 1, 'en') . ') checked';
    }

    private static function noteFailure(App $app, string $message): void
    {
        $app->settings()->set('tools.backup_failed', mb_substr($message, 0, 300, 'UTF-8'));
    }

    /**
     * Filtro «admin.status»: la última copia hecha por la tarea no pasó la comprobación (o no se pudo hacer).
     *
     * @param mixed $status
     *
     * @return array<int,array<string,string>>
     */
    public static function backupStatus($status, App $app): array
    {
        $status = is_array($status) ? $status : [];
        $error = $app->settings()->str('tools.backup_failed', '');
        if ($error !== '' && $app->auth()->can('tools.backup')) {
            $status[] = [
                'kind' => 'bad',
                'text' => t('The last backup did not pass its check: {error}', ['error' => BackupException::text($error)]),
                'url' => $app->url()->plain('/admin/tools/backups'),
                'label' => t('Open the backups'),
            ];
        }

        return $status;
    }

    /**
     * Tarea «tools.logs»: borra los ficheros de registro de más de `tools.keep_logs_days` días.
     */
    public static function purgeLogs(App $app): ?string
    {
        $days = $app->settings()->int('tools.keep_logs_days', 30);
        $removed = (new LogReader($app->site()->path('logs')))->purge($days, $app->clock()->now());
        $imports = (new ImportRuns($app))->purge();

        return 'Removed ' . $removed . ' log files older than ' . max(1, $days) . ' days and ' . $imports . ' old imports';
    }

    /**
     * Trabajo «tools.csv_import»: sigue una importación de productos por tandas hasta agotar su tiempo; si queda trabajo, se vuelve a encolar.
     *
     * @param array<string,mixed> $payload token
     */
    public static function csvImport(array $payload, App $app): string
    {
        $token = isset($payload['token']) && is_string($payload['token']) ? $payload['token'] : '';
        $runs = new ImportRuns($app);
        $state = $runs->work($token, 20);
        if ($state['status'] === 'running' && $app->has('jobs')) {
            $app->service('jobs')->push('tools.csv_import', ['token' => $token]);
        }

        return $state['status'] . ': ' . $state['row'] . ' rows';
    }
}
