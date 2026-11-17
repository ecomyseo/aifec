<?php
/**
 * AIFEC — pantalla de copias de seguridad: crear (por tandas, con barra de avance), comprobar, restaurar, descargar y borrar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Passwords;
use Aifec\Util\Bytes;

final class BackupController
{
    /** Segundos de trabajo de cada petición de una copia por tandas. */
    private const INLINE_SECONDS = 15.0;

    /** Intentos de contraseña para restaurar y su ventana (segundos). */
    private const RESTORE_ATTEMPTS = 5;
    private const RESTORE_WINDOW = 600;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/tools/backups
     *
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $backups = new Backups($this->app);

        return Admin::page($this->app, 'tools::admin/backups', [
            'backups' => $backups->all(),
            'auto' => $this->app->settings()->bool('tools.backup_auto', true),
            'keep' => max(1, $this->app->settings()->int('tools.keep_backups', 7)),
            'content' => $backups->mode('auto'),
            'zip' => Backups::zipAvailable(),
            'canRestore' => $this->app->auth()->can('tools.restore'),
            'settingsUrl' => $this->app->url()->plain('/admin/settings/tools'),
        ], [
            'title' => t('Backups'),
            'active' => '/admin/tools/backups',
            'help' => Admin::helpText("A complete backup is one zip file with the database of this site, its images and its private files (invoices, attachments, downloads). It never includes the configuration file with the secret key, the caches or the logs.\nCheck a backup to be sure it can be read: the warning on the dashboard only counts backups that have been checked.\nRestoring makes a copy of what is there first, puts the shop in maintenance mode, replaces the database and the files all together, and keeps the old folders next to the new ones. If something fails, the site is left as it was.\nThe copies live in the private folder of the site and cannot be reached by their address."),
        ]);
    }

    /**
     * POST /admin/tools/backups/create
     *
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $backups = new Backups($this->app);
        $mode = $backups->mode($request->str('mode', 'auto'));
        try {
            if ($this->app->has('jobs') && $backups->databaseSize() > Backups::BACKGROUND_FROM) {
                $this->app->service('jobs')->push('tools.backup_job', ['mode' => $mode], ['unique' => 'tools.backup']);
                Admin::audit($this->app, 'tools.backup', 'backup', '', 'Backup queued');
                Admin::flash($this->app, 'info', t('The database is large: the backup is being made in the background.'));
            } elseif (!Backups::zipAvailable()) {
                $done = $backups->create($mode);
                $this->created($done);
            } else {
                $run = $backups->begin($mode);

                return $this->advance($run);
            }
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'tools.backup');
            Admin::flash($this->app, 'bad', t('The backup could not be made: {error}', ['error' => $e->getMessage()]));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
    }

    /**
     * GET /admin/tools/backups/run/{token}: la barra de avance de una copia que sigue en marcha.
     *
     * @param array<string,string> $params
     */
    public function progress(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $run = (new Backups($this->app))->resume(isset($params['token']) ? (string) $params['token'] : '');
        if ($run === null) {
            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }
        $state = $run->state();
        if ($state['stage'] === 'done') {
            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }

        return Admin::page($this->app, 'tools::admin/backup_run', [
            'state' => $state,
            'runUrl' => $this->app->url()->plain('/admin/tools/backups/run/' . $state['token']),
            'cancelUrl' => $this->app->url()->plain('/admin/tools/backups/run/' . $state['token'] . '/cancel'),
        ], ['title' => t('Backup in progress'), 'active' => '/admin/tools/backups']);
    }

    /**
     * POST /admin/tools/backups/run/{token}: otra tanda.
     *
     * @param array<string,string> $params
     */
    public function step(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $run = (new Backups($this->app))->resume(isset($params['token']) ? (string) $params['token'] : '');
        if ($run === null) {
            throw HttpError::notFound();
        }
        try {
            return $this->advance($run);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'tools.backup');
            Admin::flash($this->app, 'bad', t('The backup could not be made: {error}', ['error' => $e->getMessage()]));

            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }
    }

    /**
     * POST /admin/tools/backups/run/{token}/cancel
     *
     * @param array<string,string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $run = (new Backups($this->app))->resume(isset($params['token']) ? (string) $params['token'] : '');
        if ($run === null) {
            throw HttpError::notFound();
        }
        $run->abort();
        Admin::flash($this->app, 'info', t('The backup was cancelled.'));

        return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
    }

    /**
     * POST /admin/tools/backups/verify/{name}
     *
     * @param array<string,string> $params
     */
    public function verify(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $backups = new Backups($this->app);
        if ($backups->path($name) === null) {
            throw HttpError::notFound();
        }
        $result = $backups->verify($name);
        Admin::audit($this->app, 'tools.backup_verify', 'backup', $name, $result['ok'] ? 'Backup checked: ok' : 'Backup checked: failed');
        if ($result['ok']) {
            Admin::flash($this->app, 'ok', t('The backup {name} is good: it can be read and its sums match.', ['name' => $name]));
        } else {
            $texts = array_map(static function (string $error): string {
                return BackupException::text($error);
            }, $result['errors']);
            Admin::flash($this->app, 'bad', t('The backup {name} is not good: {errors}', ['name' => $name, 'errors' => implode('; ', array_slice($texts, 0, 5))]));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
    }

    /**
     * GET /admin/tools/backups/restore/{name}: lo que va a pasar, y la confirmación.
     *
     * @param array<string,string> $params
     */
    public function restoreForm(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.restore');
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $backups = new Backups($this->app);
        if ($backups->path($name) === null) {
            throw HttpError::notFound();
        }
        try {
            $info = (new BackupRestorer($backups))->inspect($name);
        } catch (BackupException $e) {
            Admin::flash($this->app, 'bad', BackupException::text($e->getMessage()));

            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }

        return Admin::page($this->app, 'tools::admin/backup_restore', [
            'name' => $name,
            'manifest' => $info['manifest'],
            'otherSite' => $info['other_site'],
            'hasFiles' => $info['has_files'],
            'shop' => self::shopName($this->app),
            'action' => $this->app->url()->plain('/admin/tools/backups/restore/' . rawurlencode($name)),
            'backUrl' => $this->app->url()->plain('/admin/tools/backups'),
        ], ['title' => t('Restore a backup'), 'active' => '/admin/tools/backups']);
    }

    /**
     * POST /admin/tools/backups/restore/{name}
     *
     * @param array<string,string> $params
     */
    public function restore(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.restore');
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $backups = new Backups($this->app);
        if ($backups->path($name) === null) {
            throw HttpError::notFound();
        }
        $back = Response::redirect($this->app->url()->plain('/admin/tools/backups/restore/' . rawurlencode($name)));
        $adminId = (int) $this->app->auth()->adminId();
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if (!$limiter->hit('backup-restore:' . $adminId, self::RESTORE_ATTEMPTS, self::RESTORE_WINDOW)) {
            Admin::flash($this->app, 'bad', t('Too many attempts. Wait a few minutes.'));

            return $back;
        }
        $hash = (string) $this->app->db()->value('SELECT password FROM {users} WHERE id = ?', [$adminId]);
        if (!Passwords::verify((string) $request->input('password', ''), $hash)) {
            Admin::flash($this->app, 'bad', t('The password is not right.'));

            return $back;
        }
        if (mb_strtolower(trim($request->str('shop')), 'UTF-8') !== mb_strtolower(self::shopName($this->app), 'UTF-8')) {
            Admin::flash($this->app, 'bad', t('The name of the shop does not match what you typed.'));

            return $back;
        }
        $only = $request->str('only');
        $restorer = new BackupRestorer($backups);
        try {
            $info = $restorer->inspect($name);
            if ($info['other_site'] && !$request->bool('other_site')) {
                Admin::flash($this->app, 'bad', t('The backup is from another site: tick the box to confirm that you want to move that shop here.'));

                return $back;
            }
            $result = $restorer->restore($name, ['only' => $only, 'other_site' => $info['other_site']]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'tools.restore');
            $text = $e instanceof BackupException ? BackupException::text($e->getMessage()) : $e->getMessage();
            Admin::flash($this->app, 'bad', t('The backup could not be restored, and the site was left as it was: {error}', ['error' => $text]));

            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }
        Admin::audit($this->app, 'tools.restore', 'backup', $name, 'Backup restored');
        Admin::flash($this->app, 'ok', t('Backup restored. A copy of what was there is {name}.', ['name' => $result['safety']]));
        foreach ($result['old'] as $old) {
            Admin::flash($this->app, 'info', t('The previous folder was kept in {path}', ['path' => $old]));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
    }

    /**
     * GET /admin/tools/backups/download/{name}
     *
     * @param array<string,string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $path = (new Backups($this->app))->path($name);
        if ($path === null) {
            throw HttpError::notFound();
        }
        Admin::audit($this->app, 'tools.backup_download', 'backup', $name, 'Backup downloaded');
        $type = substr($name, -4) === '.zip' ? 'application/zip' : (substr($name, -3) === '.gz' ? 'application/gzip' : 'application/octet-stream');

        return Response::file($path, $name, $type);
    }

    /**
     * POST /admin/tools/backups/delete/{name}
     *
     * @param array<string,string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.backup');
        $name = isset($params['name']) ? (string) $params['name'] : '';
        if ((new Backups($this->app))->delete($name)) {
            Admin::audit($this->app, 'tools.backup_delete', 'backup', $name, 'Backup deleted');
            Admin::flash($this->app, 'ok', t('Backup deleted.'));
        } else {
            throw HttpError::notFound();
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
    }

    // ------------------------------------------------------------------ piezas

    /** Una tanda; si la copia acaba, se avisa y se vuelve a la lista; si no, a la barra de avance. */
    private function advance(BackupRun $run): Response
    {
        $state = $run->work(self::INLINE_SECONDS);
        if ($state['stage'] === 'done') {
            $this->created(['name' => $state['name'], 'bytes' => $state['bytes_total']]);
            (new Backups($this->app))->prune();

            return Response::redirect($this->app->url()->plain('/admin/tools/backups'));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/backups/run/' . $state['token']));
    }

    /**
     * @param array{name:string,bytes:int} $done
     */
    private function created(array $done): void
    {
        Admin::audit($this->app, 'tools.backup', 'backup', $done['name'], 'Backup created');
        Admin::flash($this->app, 'ok', t('Backup created: {name} ({size}).', ['name' => $done['name'], 'size' => Bytes::format($done['bytes'], 1)]));
    }

    /** El nombre que hay que escribir para confirmar: el de la tienda, o el identificador del sitio si no tiene. */
    public static function shopName(App $app): string
    {
        $name = trim($app->settings()->str('store.name', ''));

        return $name !== '' ? $name : $app->site()->id;
    }
}
