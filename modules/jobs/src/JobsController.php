<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Jobs;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las tres puertas de entrada de las tareas (dirección web, latido del panel) y las pantallas del panel.
 */
final class JobsController
{
    /** Segundos mínimos entre dos ejecuciones desde la dirección web de cron. */
    private const WEB_GATE = 20;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /cron/{key}: ejecuta las tareas que tocan y trabaja unos segundos. Como mucho una vez cada 20 s.
     *
     * @param array<string,string> $params
     */
    public function cron(Request $request, array $params): Response
    {
        $queue = $this->queue();
        $key = $queue->webKey();
        if (!isset($params['key']) || !hash_equals($key, (string) $params['key'])) {
            throw HttpError::notFound();
        }
        if (!$queue->gate('_web', self::WEB_GATE)) {
            return Response::json(['skipped' => true]);
        }

        return Response::json(['ok' => true, 'cron' => $queue->cron(), 'work' => $queue->work(10)]);
    }

    /**
     * POST /admin/jobs/tick: el latido del panel.
     *
     * @param array<string,string> $params
     */
    public function tick(Request $request, array $params): Response
    {
        $queue = $this->queue();

        return Response::json(['ok' => true, 'cron' => $queue->cron(), 'work' => $queue->work(5)]);
    }

    /**
     * @param array<string,string> $params
     */
    public function schedule(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('jobs.view');
        $queue = $this->queue();
        $canEdit = $this->app->auth()->can('jobs.edit');
        $webUrl = '';
        $cronLine = '';
        if ($canEdit) {
            $webUrl = $this->app->url()->abs('/cron/' . $queue->webKey(), [], '');
            $cronLine = '* * * * * php ' . escapeshellarg($this->app->root() . '/aifec') . ' cron --site=' . $this->app->site()->id . ' >/dev/null 2>&1';
        }

        return Admin::page($this->app, 'jobs::admin/schedule', [
            'tasks' => $queue->tasks(),
            'canEdit' => $canEdit,
            'webUrl' => $webUrl,
            'cronLine' => $cronLine,
            'lastRun' => $queue->lastRun(),
        ], [
            'title' => t('Scheduled tasks'),
            'crumbs' => [[t('Background tasks'), Admin::resourceUrl($this->app, 'jobs')]],
            'active' => '/admin/jobs/schedule',
            'help' => Admin::helpText("Tasks run when the server cron, the web address or an open panel calls them.\nA task that fails keeps its error here and the other tasks keep running."),
        ]);
    }

    /**
     * POST /admin/jobs/run/{task}: «Run now».
     *
     * @param array<string,string> $params
     */
    public function run(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('jobs.edit');
        $task = isset($params['task']) ? (string) $params['task'] : '';
        $known = false;
        foreach ($this->queue()->tasks() as $row) {
            $known = $known || $row['task'] === $task;
        }
        if (!$known) {
            throw HttpError::notFound();
        }
        try {
            $note = $this->queue()->runTask($task);
            Admin::audit($this->app, 'jobs.run', 'task', $task, 'Task run by hand');
            Admin::flash($this->app, 'ok', $note !== null ? t('Task «{task}» finished: {note}', ['task' => $task, 'note' => $note]) : t('Task «{task}» finished.', ['task' => $task]));
        } catch (\Throwable $e) {
            Admin::flash($this->app, 'bad', t('Task «{task}» failed: {error}', ['task' => $task, 'error' => $e->getMessage() !== '' ? $e->getMessage() : get_class($e)]));
        }

        return Response::redirect($this->app->url()->plain('/admin/jobs/schedule'));
    }

    /**
     * POST /admin/jobs/retry/{id}: un trabajo fallido vuelve a pendiente.
     *
     * @param array<string,string> $params
     */
    public function retry(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('jobs.edit');
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($this->app->db()->row('SELECT id FROM {jobs} WHERE id = ?', [$id]) === null) {
            throw HttpError::notFound();
        }
        if ($this->queue()->retry($id)) {
            Admin::audit($this->app, 'jobs.retry', 'job', (string) $id, 'Job sent back to the queue');
            Admin::flash($this->app, 'ok', t('The job is waiting to run again.'));
        } else {
            Admin::flash($this->app, 'warn', t('Only failed jobs can be retried.'));
        }

        return Response::redirect(Admin::resourceUrl($this->app, 'jobs', $id));
    }

    private function queue(): Queue
    {
        /** @var Queue $queue */
        $queue = $this->app->service('jobs');

        return $queue;
    }
}
