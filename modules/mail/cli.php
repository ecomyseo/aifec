<?php
/**
 * AIFEC — órdenes de consola del correo.
 *
 *   php aifec mail:send             envía lo pendiente
 *   php aifec mail:test <correo>    manda el correo de prueba
 *   php aifec mail:status           cuántos mensajes hay en cada estado
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'mail:send' => ['help' => 'Sends the queued email', 'run' => static function ($app, array $args, $io): int {
        $result = $app->service('mailer')->sendPending(100);
        $io->line('Sent: ' . $result['sent'] . ', failed: ' . $result['failed'] . ', held: ' . $result['held']);

        return $result['failed'] > 0 ? 1 : 0;
    }],
    'mail:test' => ['help' => 'Sends the test email: mail:test <email>', 'run' => static function ($app, array $args, $io): int {
        $email = isset($args[0]) ? trim((string) $args[0]) : '';
        if ($email === '') {
            $io->error('Usage: mail:test <email>');

            return 2;
        }
        $error = $app->service('mailer')->sendTest($email);
        if ($error !== null) {
            $io->error($error);

            return 1;
        }
        $io->line('Test email sent to ' . $email);

        return 0;
    }],
    'mail:status' => ['help' => 'Shows how many messages are in each state', 'run' => static function ($app, array $args, $io): int {
        $counts = ['pending' => 0, 'held' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($app->db()->select('SELECT status, COUNT(*) AS n FROM {mail_messages} GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $rows = [];
        foreach ($counts as $status => $n) {
            $rows[] = [$status, (string) $n];
        }
        $io->table(['STATUS', 'MESSAGES'], $rows);
        $io->line('Mode: ' . $app->service('mailer')->mode());

        return 0;
    }],
];
