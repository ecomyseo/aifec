<?php
/**
 * AIFEC — seguridad: órdenes de consola.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Security\SecurityAudit;
use Aifec\Modules\Security\UpdateCheck;

return [
    'security:audit' => ['help' => 'Runs the security audit and exits with 1 if something is wrong', 'run' => static function (App $app, array $args, Console $io): int {
        $audit = new SecurityAudit($app);
        $rows = $audit->run();
        $audit->save($rows);
        $marks = ['ok' => 'OK', 'warn' => 'WARN', 'bad' => 'BAD'];
        $table = [];
        foreach ($rows as $row) {
            $table[] = [$marks[$row['status']], $row['title'], $row['detail']];
        }
        $io->table(['STATUS', 'CHECK', 'DETAIL'], $table);
        $counts = SecurityAudit::counts($rows);
        $io->line('security: ' . SecurityAudit::score($rows) . '/100 (' . $counts['ok'] . ' ok, ' . $counts['warn'] . ' warnings, ' . $counts['bad'] . ' problems)');

        return $counts['bad'] > 0 ? 1 : 0;
    }],
    'security:update-check' => ['help' => 'Asks GitHub for the latest version of AIFEC', 'run' => static function (App $app, array $args, Console $io): int {
        $latest = (new UpdateCheck($app))->refresh();
        if ($latest === null) {
            $io->line('update-check: no answer (see the log), or the check is turned off');

            return 1;
        }
        $io->line('update-check: latest ' . $latest['version'] . ', installed ' . App::VERSION . (version_compare((string) $latest['version'], App::VERSION, '>') ? ' (NEWER AVAILABLE)' : ' (up to date)'));

        return 0;
    }],
];
