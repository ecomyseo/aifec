<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Ai\AiAdmin;
use Aifec\Modules\Ai\BatchFilter;
use Aifec\Modules\Ai\Batches;
use Aifec\Modules\Ai\Generators;
use Aifec\Modules\Ai\Usage;

/**
 * Los lotes del panel: lanzar una tarea sobre un conjunto de productos con la estimación del coste delante, y pausar, reanudar o
 * cancelar los que corren. El trabajo lo hace la cola, por tandas de 10.
 */
final class BatchesController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function batches(): Batches
    {
        return new Batches($this->app);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');

        return $this->screen($request, null);
    }

    /** Calcula lo que costaría, sin lanzar nada, y vuelve a enseñar el formulario con el resultado. @param array<string,string> $params */
    public function estimate(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');
        $form = $this->form($request);
        try {
            $estimate = $this->batches()->estimate($form['task'], $this->filter($form), $this->options($form));
            if ($estimate['count'] === 0) {
                Admin::flash($this->app, 'info', t('There is nothing to process with this selection.'));
            }

            return $this->screen($request, $estimate);
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t('The batch cannot be estimated: {reason}', ['reason' => t($e->getMessage())]));

            return $this->screen($request, null);
        }
    }

    /** @param array<string,string> $params */
    public function start(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');
        $form = $this->form($request);
        try {
            $admin = $this->app->auth()->adminId();
            $id = $this->batches()->start($form['task'], $this->filter($form), $this->options($form), $admin);
            Admin::audit($this->app, 'start', 'ai_batch', (string) $id, 'AI batch: ' . $form['task']);
            Admin::flash($this->app, 'ok', t('The batch is running in the background. You can pause or cancel it here.'));
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t('The batch cannot be started: {reason}', ['reason' => t($e->getMessage())]));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/batches'));
    }

    /** @param array<string,string> $params id */
    public function pause(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');
        $id = $this->existing((int) $params['id']);
        if ($this->batches()->pause($id, 'Paused by a person')) {
            Admin::audit($this->app, 'pause', 'ai_batch', (string) $id, 'AI batch paused');
            Admin::flash($this->app, 'ok', t('The batch is paused.'));
        } else {
            Admin::flash($this->app, 'info', t('The batch is not running.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/batches'));
    }

    /** @param array<string,string> $params id */
    public function resume(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');
        $id = $this->existing((int) $params['id']);
        if ($this->batches()->resume($id)) {
            Admin::audit($this->app, 'resume', 'ai_batch', (string) $id, 'AI batch resumed');
            Admin::flash($this->app, 'ok', t('The batch is running again.'));
        } else {
            Admin::flash($this->app, 'info', t('The batch is not paused.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/batches'));
    }

    /** @param array<string,string> $params id */
    public function cancel(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.use');
        $id = $this->existing((int) $params['id']);
        if ($this->batches()->cancel($id)) {
            Admin::audit($this->app, 'cancel', 'ai_batch', (string) $id, 'AI batch cancelled');
            Admin::flash($this->app, 'ok', t('The batch is cancelled. The proposals already made stay in the inbox.'));
        } else {
            Admin::flash($this->app, 'info', t('The batch had already finished.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/ai/batches'));
    }

    // ------------------------------------------------------------------ interno

    private function existing(int $id): int
    {
        if ($this->batches()->find($id) === null) {
            throw HttpError::notFound();
        }

        return $id;
    }

    /**
     * Lo que se eligió en el formulario.
     *
     * @return array{task:string,type:string,lang:string,category_id:int,field:string,to:string}
     */
    private function form(Request $request): array
    {
        return [
            'task' => $request->str('task'),
            'type' => $request->str('type'),
            'lang' => $request->str('lang'),
            'category_id' => $request->int('category_id'),
            'field' => $request->str('field'),
            'to' => $request->str('to'),
        ];
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array<string,mixed>
     */
    private function filter(array $form): array
    {
        $filter = ['type' => (string) $form['type'], 'lang' => (string) $form['lang']];
        if ((string) $form['type'] === 'category') {
            $filter['category_id'] = (int) $form['category_id'];
        }
        if ((string) $form['type'] === 'missing_lang' && (string) $form['task'] === 'translate') {
            $filter['lang'] = (string) $form['to'];
            $filter['field'] = in_array((string) $form['field'], \Aifec\Modules\Ai\ProductFields::TEXT_FIELDS, true) ? (string) $form['field'] : '';
        }

        return $filter;
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array<string,mixed>
     */
    private function options(array $form): array
    {
        return ['field' => (string) $form['field'], 'to' => (string) $form['to']];
    }

    /**
     * @param array<string,mixed>|null $estimate
     */
    private function screen(Request $request, ?array $estimate): Response
    {
        $app = $this->app;
        $db = $app->db();
        $categories = [];
        if ($db->tableExists('categories') && $db->tableExists('category_i18n')) {
            $categories = $db->select(
                'SELECT c.id, ci.name FROM {categories} c LEFT JOIN {category_i18n} ci ON ci.category_id = c.id AND ci.lang = ? ORDER BY c.path',
                [$app->defaultLang()]
            );
        }
        $rows = $db->select('SELECT * FROM {ai_batches} ORDER BY id DESC LIMIT 50');
        $tasks = [];
        foreach (Generators::PRODUCT_TASKS as $task) {
            $tasks[$task] = AiAdmin::taskLabel($task);
        }

        return Admin::page($app, 'ai::admin/batches', [
            'form' => $this->form($request),
            'estimate' => $estimate,
            'estimateCost' => $estimate !== null ? Usage::cents($app, (int) $estimate['cost_micro']) : '',
            'estimateSpent' => $estimate !== null ? Usage::cents($app, (int) $estimate['spent_micro']) : '',
            'estimateLimit' => $estimate !== null ? Usage::cents($app, (int) $estimate['limit_micro']) : '',
            'tasks' => $tasks,
            'types' => BatchFilter::TYPES,
            'languages' => $app->languages(),
            'defaultLang' => $app->defaultLang(),
            'categories' => $categories,
            'rows' => $rows,
            'urls' => [
                'estimate' => $app->url()->plain('/admin/ai/batches/estimate'),
                'start' => $app->url()->plain('/admin/ai/batches/start'),
                'base' => $app->url()->plain('/admin/ai/batches'),
            ],
        ], [
            'title' => t('AI batches'),
            'active' => '/admin/ai/batches',
            'help' => Admin::helpText("The «AI» menu is your writing helper: it drafts texts for you to approve and never talks to customers. The chat that answers customers is «Assistant»; «Copilot» does tasks in this panel for you.
A batch asks the AI for one kind of text for many products. Every text becomes a proposal that waits for your approval.\nBefore launching it you see an estimate of the cost. The estimate is approximate and needs the prices per million tokens in the AI settings.\nThe batch runs in the background, ten products at a time. It stops by itself when the daily cap is reached, or after three failures in a row, and you can pause, resume or cancel it."),
        ]);
    }
}
