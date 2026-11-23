<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * La auditoría de seguridad de la tienda: una lista de comprobaciones, cada una con su estado, lo que pasa y lo que hay que hacer.
 * Solo mira (ajustes, base de datos, ficheros, configuración de PHP): no cambia nada ni sale a ninguna red.
 */
final class SecurityAudit
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const BAD = 'bad';

    private const BACKUP_STALE = 8 * 86400;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<int,array{id:string,status:string,title:string,detail:string,fix:string}>
     */
    public function run(): array
    {
        $out = [];
        foreach (['https', 'adminPath', 'staff', 'debug', 'dataOutside', 'php', 'phpIni', 'csp', 'backup', 'tasks', 'version', 'mail'] as $check) {
            try {
                foreach ($this->$check() as $row) {
                    $out[] = $row;
                }
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'security audit ' . $check);
                $out[] = $this->row($check, self::WARN, t('A check could not be run'), $check . ': ' . $e->getMessage(), '');
            }
        }

        return $out;
    }

    /**
     * Nota de 0 a 100: bien cuenta 1, aviso 0,5 y mal 0.
     *
     * @param array<int,array{status:string}> $rows
     */
    public static function score(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += $row['status'] === self::OK ? 1.0 : ($row['status'] === self::WARN ? 0.5 : 0.0);
        }

        return (int) round($sum * 100 / count($rows));
    }

    /**
     * @param array<int,array{status:string}> $rows
     *
     * @return array{ok:int,warn:int,bad:int}
     */
    public static function counts(array $rows): array
    {
        $n = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($rows as $row) {
            ++$n[$row['status']];
        }

        return $n;
    }

    /** Guarda el resultado para el aviso del inicio. @param array<int,array<string,string>> $rows */
    public function save(array $rows): void
    {
        $counts = self::counts($rows);
        $this->app->settings()->set('security.last_audit', json_encode(['at' => $this->app->clock()->now(), 'score' => self::score($rows)] + $counts));
    }

    /** @return array<string,int>|null */
    public function last(): ?array
    {
        $raw = $this->app->settings()->str('security.last_audit', '');
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) && isset($data['score']) ? $data : null;
    }

    // ------------------------------------------------------------------ las comprobaciones

    /** @return array<int,array<string,string>> */
    private function https(): array
    {
        $secure = strpos($this->app->url()->origin(), 'https://') === 0;

        return [$this->row('https', $secure ? self::OK : self::BAD, t('The shop is served over HTTPS'),
            $secure ? t('The address of the shop starts with https.') : t('The address of the shop is plain http: passwords and sessions travel in the clear.'),
            $secure ? '' : t('Install a certificate and set the address of the site with https.'))];
    }

    /** @return array<int,array<string,string>> */
    private function adminPath(): array
    {
        $own = $this->app->adminAlias() !== '';

        return [$this->row('admin_path', $own ? self::OK : self::WARN, t('The admin panel has its own address'),
            $own ? t('The panel is not in /admin, the first place bots look.') : t('The panel is in /admin, the first place bots look.'),
            $own ? '' : t('Set an address of your own in Settings, Security.'))];
    }

    /** @return array<int,array<string,string>> */
    private function staff(): array
    {
        $db = $this->app->db();
        $users = $db->select('SELECT id, email, totp_secret FROM {users} WHERE active = 1');
        $keys = [];
        foreach ($db->select('SELECT DISTINCT user_id FROM {passkeys}') as $row) {
            $keys[(int) $row['user_id']] = true;
        }
        $none = [];
        $noPasskey = 0;
        foreach ($users as $user) {
            $hasKey = isset($keys[(int) $user['id']]);
            if (!$hasKey) {
                ++$noPasskey;
            }
            if (!$hasKey && empty($user['totp_secret'])) {
                $none[] = (string) $user['email'];
            }
        }
        if ($none !== []) {
            return [$this->row('staff', self::BAD, t('People who sign in with only a password'), t('These accounts have no passkey and no two-step verification: {list}', ['list' => implode(', ', array_slice($none, 0, 5)) . (count($none) > 5 ? '…' : '')]),
                t('Open Security, Passkeys, and add one; or turn on two-step verification in My account.'))];
        }
        if ($noPasskey > 0) {
            return [$this->row('staff', self::WARN, t('Everybody has a second step, not everybody a passkey'), tn('{n} account has two-step verification but no passkey.', '{n} accounts have two-step verification but no passkey.', $noPasskey),
                t('A passkey cannot be phished: add one in Security, Passkeys.'))];
        }

        return [$this->row('staff', self::OK, t('Everybody signs in with a passkey'), t('All the active accounts have a passkey.'), '')];
    }

    /** @return array<int,array<string,string>> */
    private function debug(): array
    {
        $on = $this->app->debug();

        return [$this->row('debug', $on ? self::BAD : self::OK, t('Debug mode is off'),
            $on ? t('Debug mode shows the cause of errors to anyone who finds one.') : t('Errors show a plain page and the details go to the log.'),
            $on ? t('Set debug to false in the configuration of the site.') : '')];
    }

    /** @return array<int,array<string,string>> */
    private function dataOutside(): array
    {
        $data = realpath($this->app->site()->dataDir);
        $public = realpath($this->app->publicDir());
        if ($data === false || $public === false) {
            return [$this->row('data_outside', self::WARN, t('The data folder is outside the public folder'), t('The folders could not be compared.'), '')];
        }
        $inside = strpos(str_replace('\\', '/', $data) . '/', rtrim(str_replace('\\', '/', $public), '/') . '/') === 0;

        return [$this->row('data_outside', $inside ? self::BAD : self::OK, t('The data folder is outside the public folder'),
            $inside ? t('The data of the shop (database file, backups, logs) sits where the web server can serve it.') : t('The data of the shop cannot be requested from the web.'),
            $inside ? t('Move the data folder out of the public folder and point to it from data-path.php.') : '')];
    }

    /** @return array<int,array<string,string>> */
    private function php(): array
    {
        $id = PHP_VERSION_ID;
        $status = $id >= 80300 ? self::OK : ($id >= 80100 ? self::WARN : self::BAD);

        return [$this->row('php', $status, t('PHP has security support'), t('This server runs PHP {v}.', ['v' => PHP_VERSION]),
            $status === self::OK ? '' : t('Ask your host for PHP 8.3 or later.'))];
    }

    /** @return array<int,array<string,string>> */
    private function phpIni(): array
    {
        $display = in_array(strtolower((string) ini_get('display_errors')), ['1', 'on', 'true', 'yes', 'stdout'], true);
        $expose = in_array(strtolower((string) ini_get('expose_php')), ['1', 'on', 'true', 'yes'], true);
        if ($display) {
            return [$this->row('php_ini', self::BAD, t('PHP does not print its errors'), t('display_errors is on: a visitor can read paths and queries in an error.'), t('Set display_errors = Off in php.ini.'))];
        }
        if ($expose) {
            return [$this->row('php_ini', self::WARN, t('PHP does not print its errors'), t('expose_php is on: every answer says which PHP runs here.'), t('Set expose_php = Off in php.ini.'))];
        }

        return [$this->row('php_ini', self::OK, t('PHP does not print its errors'), t('display_errors and expose_php are off.'), '')];
    }

    /** @return array<int,array<string,string>> */
    private function csp(): array
    {
        $mode = $this->app->settings()->str('security.csp', 'strict');

        return [$this->row('csp', $mode === 'strict' ? self::OK : ($mode === 'relaxed' ? self::WARN : self::BAD), t('Content security policy'),
            $mode === 'strict' ? t('Only the scripts of the shop may run.') : ($mode === 'relaxed' ? t('Scripts from any secure site may run.') : t('The policy is off: a script injected into a page would run.')),
            $mode === 'strict' ? '' : t('Choose the strict policy in Settings, Security.'))];
    }

    /** @return array<int,array<string,string>> */
    private function backup(): array
    {
        if (!$this->app->has('backups')) {
            return [];
        }
        $service = $this->app->service('backups');
        $all = is_object($service) && method_exists($service, 'all') ? $service->all() : [];
        $newest = 0;
        foreach (is_array($all) ? $all : [] as $backup) {
            $newest = max($newest, Clock::parse(is_array($backup) && isset($backup['created_at']) ? (string) $backup['created_at'] : ''));
        }
        $fresh = $newest > 0 && $this->app->clock()->now() - $newest <= self::BACKUP_STALE;

        return [$this->row('backup', $fresh ? self::OK : ($newest > 0 ? self::WARN : self::BAD), t('There is a recent backup'),
            $newest > 0 ? t('The last backup is from {date}.', ['date' => $this->app->clock()->sql($newest)]) : t('There is no backup of the shop yet.'),
            $fresh ? '' : t('Make a backup in Tools, Backups, and schedule it.'))];
    }

    /** @return array<int,array<string,string>> */
    private function tasks(): array
    {
        if (!$this->app->has('jobs')) {
            return [];
        }
        $queue = $this->app->service('jobs');
        $last = is_object($queue) && method_exists($queue, 'lastRun') ? $queue->lastRun() : null;
        $ok = $last !== null && $this->app->clock()->now() - (int) $last <= 1800;

        return [$this->row('tasks', $ok ? self::OK : self::WARN, t('Scheduled tasks are running'),
            $ok ? t('The tasks ran in the last half hour.') : t('The scheduled tasks have not run in the last half hour: backups, the audit and the version check wait for them.'),
            $ok ? '' : t('Set up the cron job described in Tasks.'))];
    }

    /** @return array<int,array<string,string>> */
    private function version(): array
    {
        $check = new UpdateCheck($this->app);
        $latest = $check->latest();
        if ($latest === null) {
            return [$this->row('version', self::WARN, t('AIFEC is up to date'), t('The latest version has not been checked yet.'), t('The daily task checks it; turn it on in Settings, Security.'))];
        }
        $newer = $check->newer();

        return [$this->row('version', $newer === null ? self::OK : self::WARN, t('AIFEC is up to date'),
            $newer === null ? t('You have the latest version, {v}.', ['v' => App::VERSION]) : t('Version {new} is out and you have {v}.', ['new' => (string) $newer['version'], 'v' => App::VERSION]),
            $newer === null ? '' : t('Update AIFEC: the notes are on GitHub.'))];
    }

    /** @return array<int,array<string,string>> */
    private function mail(): array
    {
        if (!$this->app->has('mailer')) {
            return [];
        }
        $held = !in_array($this->app->settings()->str('mail.mode', 'hold'), ['live', 'redirect'], true);

        return [$this->row('mail', $held ? self::WARN : self::OK, t('Emails are sent'),
            $held ? t('Emails are on hold: the password reset and the notices do not arrive.') : t('Emails go out.'),
            $held ? t('Turn the mail on in Settings, Mail, when the shop is ready.') : '')];
    }

    /** @return array{id:string,status:string,title:string,detail:string,fix:string} */
    private function row(string $id, string $status, string $title, string $detail, string $fix): array
    {
        return ['id' => $id, 'status' => $status, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
    }
}
