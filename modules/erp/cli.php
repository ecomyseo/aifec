<?php
/**
 * AIFEC — órdenes de consola del módulo ERP.
 *
 *   php aifec erp:export <perfil>   ejecuta una exportación ahora (por su número o por su nombre exacto)
 *   php aifec erp:import <perfil>   lee los ficheros pendientes de una importación
 *   php aifec erp:deliveries        los avisos al ERP pendientes y fallidos
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;

/**
 * El perfil por su número o por su nombre exacto; null si no existe.
 */
$find = static function (App $app, string $direction, string $name): ?int {
    $db = $app->db();
    $row = ctype_digit($name) ? $db->row('SELECT id FROM {erp_exports} WHERE id = ? AND direction = ?', [(int) $name, $direction]) : null;
    if ($row === null) {
        $row = $db->row('SELECT id FROM {erp_exports} WHERE name = ? AND direction = ?', [$name, $direction]);
    }

    return $row === null ? null : (int) $row['id'];
};

return [
    'erp:export' => ['help' => 'Runs an ERP export now: erp:export <profile id or name>', 'run' => static function (App $app, array $args, Console $io) use ($find): int {
        $name = isset($args[0]) ? trim((string) $args[0]) : '';
        $id = $name !== '' ? $find($app, 'export', $name) : null;
        if ($id === null) {
            $io->error('Usage: erp:export <profile id or name> (it has to be an export profile)');

            return 2;
        }
        $run = $app->service('erp')->exporter()->run($id, ['trigger' => 'cli']);
        $io->line('export ' . $id . ': ' . (string) $run['status'] . ', ' . (int) $run['records'] . ' records' . (!empty($run['error']) ? ' - ' . (string) $run['error'] : ''));

        return in_array((string) $run['status'], ['ok', 'empty'], true) ? 0 : 1;
    }],
    'erp:import' => ['help' => 'Reads the pending files of an ERP import: erp:import <profile id or name>', 'run' => static function (App $app, array $args, Console $io) use ($find): int {
        $name = isset($args[0]) ? trim((string) $args[0]) : '';
        $id = $name !== '' ? $find($app, 'import', $name) : null;
        if ($id === null) {
            $io->error('Usage: erp:import <profile id or name> (it has to be an import profile)');

            return 2;
        }
        $failed = 0;
        foreach ($app->service('erp')->importer()->run($id, ['trigger' => 'cli']) as $run) {
            $io->line('import ' . $id . ': ' . (string) $run['status'] . ', ' . (int) $run['records'] . ' lines applied' . (!empty($run['file_name']) ? ' (' . (string) $run['file_name'] . ')' : '') . (!empty($run['error']) ? ' - ' . (string) $run['error'] : ''));
            $failed += in_array((string) $run['status'], ['ok', 'empty'], true) ? 0 : 1;
        }

        return $failed > 0 ? 1 : 0;
    }],
    'erp:deliveries' => ['help' => 'Shows the pending and failed messages to the ERP', 'run' => static function (App $app, array $args, Console $io): int {
        $counts = ['pending' => 0, 'held' => 0, 'sending' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($app->db()->select("SELECT status, COUNT(*) AS n FROM {erp_deliveries} WHERE status <> 'sent' GROUP BY status") as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $io->line('deliveries: pending ' . $counts['pending'] . ', held ' . $counts['held'] . ', sending ' . $counts['sending'] . ', failed ' . $counts['failed'] . ', expired ' . $counts['expired']
            . ' · outgoing messages ' . ($app->settings()->bool('erp.outbound', false) ? 'on' : 'off'));
        foreach ($app->service('erp')->webhooks()->recent(null, 'failed', 10) as $row) {
            $io->line('  #' . (int) $row['id'] . ' ' . (string) $row['event'] . ' (' . (string) $row['connection_name'] . ') ' . (string) $row['created_at'] . ' - ' . (string) $row['error']);
        }

        return 0;
    }],
];
