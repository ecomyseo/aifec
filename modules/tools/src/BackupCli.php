<?php
/**
 * AIFEC — órdenes de consola de las copias: backup, backup:verify y backup:restore.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Util\Bytes;

final class BackupCli
{
    /**
     * backup [--mode=full|db]: crea una copia de un tirón y la comprueba.
     *
     * @param array<int|string,mixed> $args
     */
    public static function backup(App $app, array $args, Console $io): int
    {
        $backups = new Backups($app);
        $mode = isset($args['mode']) && is_string($args['mode']) ? $args['mode'] : 'auto';
        if ($mode !== 'auto' && !in_array($mode, Backups::MODES, true)) {
            $io->error('Usage: backup [--mode=full|db]');

            return 2;
        }
        try {
            $info = $backups->create($mode);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->line('Backup ' . $info['name'] . ' (' . Bytes::format((int) $info['bytes'], 1, 'en') . ')');
        $check = $backups->verify($info['name']);
        if (!$check['ok']) {
            $io->error('The backup failed its check: ' . implode('; ', $check['errors']));

            return 1;
        }
        $io->line('Checked: the backup can be read and its sums match');

        return 0;
    }

    /**
     * backup:verify <fichero>: abre la copia y comprueba el manifiesto, las sumas y el volcado, sin restaurar nada.
     *
     * @param array<int|string,mixed> $args
     */
    public static function verify(App $app, array $args, Console $io): int
    {
        $arg = isset($args[0]) ? trim((string) $args[0]) : '';
        if ($arg === '') {
            $io->error('Usage: backup:verify <file>');

            return 2;
        }
        $backups = new Backups($app);
        if ($backups->path($arg) !== null) {
            $result = $backups->verify($arg);
        } elseif (is_file($arg)) {
            $result = BackupVerifier::verify($arg, $app->db()->prefix());
        } else {
            $io->error('The backup does not exist');

            return 1;
        }
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $io->error($error);
            }

            return 1;
        }
        if ($result['manifest'] !== null) {
            $io->line(self::summary($result['manifest']));
        }
        $io->line('Checked: the backup can be read and its sums match');

        return 0;
    }

    /**
     * backup:restore <fichero> [--yes] [--only=db|files] [--other-site]: restaura una copia (antes hace otra de lo que hay).
     *
     * @param array<int|string,mixed> $args
     * @param callable|null           $ask  `function (string $question): string`; de serie lee de la entrada estándar
     */
    public static function restore(App $app, array $args, Console $io, ?callable $ask = null): int
    {
        $arg = isset($args[0]) ? trim((string) $args[0]) : '';
        $only = isset($args['only']) && is_string($args['only']) ? $args['only'] : '';
        if ($arg === '' || !in_array($only, ['', 'db', 'files'], true)) {
            $io->error('Usage: backup:restore <file> [--yes] [--only=db|files] [--other-site]');

            return 2;
        }
        $yes = isset($args['yes']);
        $otherSite = isset($args['other-site']);
        $ask = $ask !== null ? $ask : static function (string $question): string {
            echo $question;
            $answer = defined('STDIN') ? fgets(STDIN) : false;

            return is_string($answer) ? trim($answer) : '';
        };
        $backups = new Backups($app);
        $name = self::resolve($backups, $arg);
        if ($name === null) {
            $io->error('The backup does not exist');

            return 1;
        }
        $restorer = new BackupRestorer($backups);
        try {
            $info = $restorer->inspect($name);
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->line(self::summary($info['manifest']));
        if ($info['other_site']) {
            $io->line('Warning: the backup is from another site (' . (string) $info['manifest']['site']['url'] . '). Restoring it moves a shop; the address of this site is not changed.');
        }
        if (!$yes && $ask('This replaces the current ' . ($only === 'db' ? 'database' : ($only === 'files' ? 'files' : 'database and files')) . ' of the site. Type YES to continue: ') !== 'YES') {
            $io->line('Cancelled: nothing was changed');

            return 1;
        }
        if ($info['other_site'] && !$otherSite) {
            if ($yes || $ask('The backup is from another site. Type MOVE to restore it here anyway: ') !== 'MOVE') {
                $io->error('The backup is from another site: add --other-site to restore it here anyway');

                return 1;
            }
        }
        try {
            $result = $restorer->restore($name, ['only' => $only, 'other_site' => $info['other_site']]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'tools.restore');
            $io->error($e->getMessage());
            $io->line('The site was left as it was.');

            return 1;
        }
        $io->line('Restored. A copy of what was there is in ' . $backups->dir() . '/' . $result['safety']);
        foreach ($result['old'] as $old) {
            $io->line('The previous folder was kept in ' . $old);
        }
        $io->line('Tables: ' . $result['tables'] . ', files: ' . $result['files'] . ', migrations applied: ' . $result['migrations']);

        return 0;
    }

    /** El nombre de una copia de la carpeta de copias; si es un fichero de fuera, se trae a esa carpeta. */
    private static function resolve(Backups $backups, string $arg): ?string
    {
        if ($backups->path($arg) !== null) {
            return $arg;
        }
        if (!is_file($arg) || substr($arg, -4) !== '.zip') {
            return null;
        }
        $name = $backups->nextName('zip');
        if (!@copy($arg, $backups->dir() . '/' . $name)) {
            return null;
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function summary(array $manifest): string
    {
        $db = $manifest['database'];
        $files = isset($manifest['files']) ? (int) $manifest['files'] : 0;

        return 'AIFEC ' . $manifest['version'] . ', ' . $manifest['created_at'] . ' UTC, ' . $manifest['mode'] . ', ' . $db['engine']
            . ', ' . $db['tables'] . ' tables, ' . $db['rows'] . ' rows, ' . $files . ' files, site ' . $manifest['site']['url'];
    }
}
