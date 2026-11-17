<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * Lo que el módulo de tareas ejecuta (ver jobs.php): los avisos al ERP, las exportaciones e importaciones programadas y la limpieza.
 */
final class ErpTasks
{
    /**
     * Trabajo «erp.deliver»: un intento de envío de un aviso.
     *
     * @param array<string,mixed> $payload id del aviso
     */
    public static function deliver(array $payload, App $app): string
    {
        return $app->service('erp')->webhooks()->attempt(isset($payload['id']) ? (int) $payload['id'] : 0);
    }

    /** Cada minuto: caduca lo viejo, suelta lo apartado y envía los avisos que tocan. */
    public static function deliveries(App $app): ?string
    {
        $r = $app->service('erp')->webhooks()->sweep();
        $total = $r['sent'] + $r['failed'] + $r['expired'] + $r['released'];

        return $total > 0 ? $r['sent'] . ' sent, ' . $r['failed'] . ' failed, ' . $r['expired'] . ' expired, ' . $r['released'] . ' released' : null;
    }

    /**
     * Cada minuto: las exportaciones y las importaciones cuya hora ha llegado. La hora siguiente se calcula desde «ahora» y se reclama con
     * una sentencia condicionada, así dos procesos a la vez no corren el mismo perfil y una parada larga no se recupera de golpe.
     */
    public static function profiles(App $app): ?string
    {
        $db = $app->db();
        $now = $app->clock()->now();
        $stamp = $app->clock()->sql($now);
        $erp = $app->service('erp');
        $due = $db->select("SELECT * FROM {erp_exports} WHERE enabled = 1 AND schedule <> 'manual' AND next_run_at IS NOT NULL AND next_run_at <= ? ORDER BY next_run_at LIMIT 10", [$stamp]);
        $ran = 0;
        foreach ($due as $row) {
            $next = Profiles::nextRun($app, (string) $row['schedule'], $row['schedule_every'] !== null ? (int) $row['schedule_every'] : null, $row['schedule_at'] !== null ? (string) $row['schedule_at'] : null, $now);
            if ($next === null || $db->run('UPDATE {erp_exports} SET next_run_at = ? WHERE id = ? AND next_run_at <= ?', [$next, (int) $row['id'], $stamp]) !== 1) {
                continue;
            }
            try {
                if ((string) $row['direction'] === 'import') {
                    $erp->importer()->run((int) $row['id'], ['trigger' => 'schedule']);
                } else {
                    $erp->exporter()->run((int) $row['id'], ['trigger' => 'schedule']);
                }
                ++$ran;
            } catch (\Throwable $e) {
                $app->log()->exception($e, 'erp.profile ' . (int) $row['id']);
            }
        }

        return $ran > 0 ? $ran . ' profiles run' : null;
    }

    /** Cada día: ficheros caducados, claves de idempotencia viejas y avisos antiguos ya cerrados. */
    public static function purge(App $app): ?string
    {
        $erp = $app->service('erp');
        $files = $erp->exporter()->purge();
        $keys = $erp->idempotency()->purge();
        $cut = $app->clock()->sql($app->clock()->now() - 30 * 86400);
        $old = $app->db()->run("DELETE FROM {erp_deliveries} WHERE status IN ('sent', 'failed', 'expired') AND created_at < ?", [$cut]);

        return $files['files'] + $files['runs'] + $keys + $old > 0 ? $files['files'] . ' files, ' . $files['runs'] . ' runs, ' . $keys . ' keys, ' . $old . ' deliveries removed' : null;
    }
}
