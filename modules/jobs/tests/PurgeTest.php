<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('jobs.purge borra lo antiguo (trabajos, sesiones, intentos de acceso, contadores) y conserva lo reciente', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $db = $app->db();
    $now = $app->clock()->now();
    $ago = function (int $days) use ($app, $now): string {
        return $app->clock()->sql($now - $days * 86400);
    };

    $oldDone = jobsInsert($app, ['status' => 'done', 'finished_at' => $ago(8), 'created_at' => $ago(8)]);
    $newDone = jobsInsert($app, ['status' => 'done', 'finished_at' => $ago(6), 'created_at' => $ago(6)]);
    $oldFailed = jobsInsert($app, ['status' => 'failed', 'finished_at' => $ago(31), 'created_at' => $ago(31), 'error' => 'x']);
    $newFailed = jobsInsert($app, ['status' => 'failed', 'finished_at' => $ago(29), 'created_at' => $ago(29), 'error' => 'x']);
    $pendingOld = jobsInsert($app, ['status' => 'pending', 'created_at' => $ago(90)]);
    $runningOld = jobsInsert($app, ['status' => 'running', 'locked_at' => $ago(1), 'created_at' => $ago(90)]);

    foreach ([['old', -86400], ['new', 86400]] as $s) {
        $db->insert('sessions', ['id' => 'sess-' . $s[0], 'guard' => 'customer', 'last_at' => $ago(1), 'expires_at' => $app->clock()->sql($now + $s[1])]);
    }
    foreach ([['old', 31], ['new', 29]] as $a) {
        $db->insert('login_attempts', ['guard' => 'admin', 'identifier' => $a[0] . '@x.es', 'ip' => '127.0.0.1', 'ok' => 0, 'created_at' => $ago($a[1])]);
    }
    $db->insert('rate_limits', ['k' => 'old-key', 'hits' => 3, 'window_start' => $now - 2 * 86400]);
    $db->insert('rate_limits', ['k' => 'new-key', 'hits' => 3, 'window_start' => $now - 60]);

    $note = $q->runTask('jobs.purge');
    same('Removed 1 done and 1 failed jobs, 1 sessions, 1 sign-in attempts and 1 rate limits', $note);

    $left = array_map('intval', $db->column('SELECT id FROM {jobs} ORDER BY id'));
    same([$newDone, $newFailed, $pendingOld, $runningOld], $left, 'solo caen los terminados y fallidos antiguos');
    check(!in_array($oldDone, $left, true) && !in_array($oldFailed, $left, true), 'los antiguos se han ido');
    same(['sess-new'], $db->column('SELECT id FROM {sessions}'));
    same(['new@x.es'], $db->column('SELECT identifier FROM {login_attempts}'));
    same(['new-key'], $db->column('SELECT k FROM {rate_limits}'));
});

test('jobs.purge respeta los días de los ajustes', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $now = $app->clock()->now();
    $app->settings()->set('jobs.keep_done_days', 2);
    $app->settings()->set('jobs.keep_failed_days', 60);
    $three = $app->clock()->sql($now - 3 * 86400);
    $fortyFive = $app->clock()->sql($now - 45 * 86400);
    jobsInsert($app, ['status' => 'done', 'finished_at' => $three, 'created_at' => $three]);
    $failed = jobsInsert($app, ['status' => 'failed', 'finished_at' => $fortyFive, 'created_at' => $fortyFive, 'error' => 'x']);

    $q->runTask('jobs.purge');
    same([$failed], array_map('intval', $app->db()->column('SELECT id FROM {jobs}')), 'el de hace 3 días cae con el ajuste de 2; el fallido de 45 se queda con el de 60');
});

test('la limpieza corre sola con cron a partir de las 03:40 y una vez al día', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $r = $q->cron();
    check(in_array('jobs.purge', $r['ran'], true), 'a las 13:00 ya ha pasado la hora de la limpieza');
    check(!in_array('jobs.purge', $q->cron()['ran'], true), 'no vuelve a correr el mismo día');
    same(1, (int) jobsCronRow($app, 'jobs.purge')['runs']);
});
