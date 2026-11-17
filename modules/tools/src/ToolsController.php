<?php
/**
 * AIFEC — pantallas del módulo de herramientas: diagnóstico, registro, caché y mantenimiento, e información del sistema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\AssetServer;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Bytes;

final class ToolsController
{
    /** Cuántas líneas se pueden pedir en el registro. */
    private const LOG_LIMITS = [50, 100, 200, 500];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/tools/doctor
     *
     * @param array<string,string> $params
     */
    public function doctor(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.view');
        $results = $this->app->service('doctor')->run();

        return Admin::page($this->app, 'tools::admin/doctor', [
            'results' => $results,
            'summary' => Doctor::summary($results),
        ], [
            'title' => t('Diagnostics'),
            'active' => '/admin/tools/doctor',
            'help' => Admin::helpText("Every check looks at the running system, not at the code.\nA red check is something that does not work; an orange one is something to look at.\nFrom the console: php aifec doctor (it exits with 1 if anything is red)."),
        ]);
    }

    /**
     * GET /admin/tools/logs?file=&level=&q=&n=
     *
     * @param array<string,string> $params
     */
    public function logs(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.view');
        $reader = new LogReader($this->app->site()->path('logs'));
        $files = $reader->files();
        $name = $request->str('file');
        if ($name === '' && $files !== []) {
            $name = $files[0]['name'];
        }
        $path = $name !== '' ? $reader->path($name) : null;
        if ($name !== '' && $path === null) {
            throw HttpError::notFound();
        }
        $level = $request->str('level');
        $level = in_array($level, ['error', 'warning', 'info'], true) ? $level : '';
        $text = mb_substr($request->str('q'), 0, 200, 'UTF-8');
        $limit = $request->int('n', 100);
        $limit = in_array($limit, self::LOG_LIMITS, true) ? $limit : 100;
        $tail = $path !== null ? $reader->tail($path, $limit, $level, $text) : ['entries' => [], 'bytes_read' => 0, 'size' => 0, 'complete' => true];

        return Admin::page($this->app, 'tools::admin/logs', [
            'files' => $files,
            'file' => $name,
            'level' => $level,
            'q' => $text,
            'limit' => $limit,
            'limits' => self::LOG_LIMITS,
            'tail' => $tail,
            'downloadUrl' => $name !== '' ? $this->app->url()->plain('/admin/tools/logs/download', ['file' => $name]) : '',
        ], [
            'title' => t('Logs'),
            'active' => '/admin/tools/logs',
            'help' => Admin::helpText("The newest lines come first and the file is read from its end, so even a huge log opens at once.\nSecrets are already hidden in the file; the text is shown as plain text."),
        ]);
    }

    /**
     * GET /admin/tools/logs/download?file=
     *
     * @param array<string,string> $params
     */
    public function logDownload(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.view');
        $name = $request->str('file');
        $path = (new LogReader($this->app->site()->path('logs')))->path($name);
        if ($path === null) {
            throw HttpError::notFound();
        }
        Admin::audit($this->app, 'tools.log_download', 'log', $name, 'Log file downloaded');

        return Response::file($path, $name, 'text/plain; charset=UTF-8');
    }

    /**
     * GET /admin/tools/cache
     *
     * @param array<string,string> $params
     */
    public function cache(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.view');

        return Admin::page($this->app, 'tools::admin/cache', [
            'stats' => CacheTools::stats($this->app),
            'maintenance' => Maintenance::isOn($this->app),
            'canEdit' => $this->app->auth()->can('tools.edit'),
            'settingsUrl' => $this->app->url()->plain('/admin/settings/tools'),
        ], [
            'title' => t('Cache and maintenance'),
            'active' => '/admin/tools/cache',
            'help' => Admin::helpText("The data cache keeps the results of heavy queries; the page cache keeps whole pages for visitors.\nEmptying them is always safe: they are rebuilt when someone asks for them.\nPublishing the static files copies the scripts and styles of the theme and the modules to the public folder."),
        ]);
    }

    /**
     * POST /admin/tools/cache/clear-data
     *
     * @param array<string,string> $params
     */
    public function clearData(Request $request, array $params): Response
    {
        return $this->clear('data', t('Data cache emptied: {files} files, {size}.'));
    }

    /**
     * POST /admin/tools/cache/clear-pages
     *
     * @param array<string,string> $params
     */
    public function clearPages(Request $request, array $params): Response
    {
        return $this->clear('pages', t('Page cache emptied: {files} files, {size}.'));
    }

    /**
     * POST /admin/tools/cache/publish-assets
     *
     * @param array<string,string> $params
     */
    public function publishAssets(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.edit');
        try {
            $done = AssetServer::publish($this->app->root(), $this->app->publicDir());
            $this->app->invalidate(['pages']);
            Admin::audit($this->app, 'tools.publish_assets', 'assets', (string) $done['build'], 'Static files published');
            Admin::flash($this->app, 'ok', t('Static files published: {files} files.', ['files' => $done['files']]));
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'tools.publish_assets');
            Admin::flash($this->app, 'bad', t('The static files could not be published: {error}', ['error' => $e->getMessage()]));
        }

        return Response::redirect($this->app->url()->plain('/admin/tools/cache'));
    }

    /**
     * POST /admin/tools/cache/maintenance  (state = on | off)
     *
     * @param array<string,string> $params
     */
    public function toggleMaintenance(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.edit');
        $on = $request->str('state') === 'on';
        Maintenance::set($this->app, $on);
        Admin::audit($this->app, 'tools.maintenance', 'settings', 'tools.maintenance', $on ? 'Maintenance mode on' : 'Maintenance mode off');
        Admin::flash($this->app, $on ? 'warn' : 'ok', $on ? t('Maintenance mode is on.') : t('Maintenance mode is off.'));

        return Response::redirect($this->app->url()->plain('/admin/tools/cache'));
    }

    /**
     * GET /admin/tools/info
     *
     * @param array<string,string> $params
     */
    public function info(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('tools.view');

        return Admin::page($this->app, 'tools::admin/info', ['info' => SystemInfo::collect($this->app)], [
            'title' => t('System information'),
            'active' => '/admin/tools/info',
            'help' => Admin::helpText('This page never shows keys, passwords or configuration values: it is safe to copy it into a support request.'),
        ]);
    }

    // ------------------------------------------------------------------ piezas

    private function clear(string $kind, string $message): Response
    {
        $this->app->auth()->requireCap('tools.edit');
        $done = CacheTools::clear($this->app, $kind);
        Admin::audit($this->app, 'tools.cache_clear', 'cache', $kind, $done['files'] . ' files');
        Admin::flash($this->app, 'ok', str_replace(['{files}', '{size}'], [(string) $done['files'], Bytes::format($done['bytes'], 1)], $message));

        return Response::redirect($this->app->url()->plain('/admin/tools/cache'));
    }
}
