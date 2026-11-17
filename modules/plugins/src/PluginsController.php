<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Plugins;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las pantallas del panel «Módulos y plugins»: el listado con sus dos pestañas, el informe del contrato, subir un ZIP y
 * el asistente que crea un plugin. Ver = plugins.view; todo lo demás = plugins.edit.
 */
final class PluginsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.view');
        $canEdit = $this->app->auth()->can('plugins.edit');
        $actions = '';
        if ($canEdit) {
            $actions = '<a class="af-btn af-btn-primary" href="' . e($this->app->url()->plain('/admin/plugins/new')) . '">' . Admin::icon('plus') . e(t('New plugin')) . '</a>';
        }

        return Admin::page($this->app, 'plugins::admin/index', [
            'rows' => $this->plugins()->all(),
            'canEdit' => $canEdit,
            'titles' => Validator::titles($this->app->i18n()),
        ], [
            'title' => t('Modules and plugins'),
            'active' => '/admin/plugins',
            'actions' => $actions,
            'help' => Admin::helpText("A module is part of the system; a plugin is an extension that you can install, turn on, configure and remove.\nBefore a plugin can be turned on it passes the AIFEC contract: it cannot touch prices, weigh the pages down or bring the shop down without it being seen.\nA capability like «pricing» lets a plugin change prices, taxes or deadlines: grant it only to plugins you trust.\nUninstalling with the box «Keep the data» ticked leaves its tables and settings in place."),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.view');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        $row = $this->plugins()->find($id);
        if ($row === null) {
            throw HttpError::notFound();
        }

        return Admin::page($this->app, 'plugins::admin/report', [
            'row' => $row,
            'report' => isset($this->plugins()->reports()[$id]) ? $this->plugins()->reports()[$id] : null,
            'titles' => Validator::titles($this->app->i18n()),
            'canEdit' => $this->app->auth()->can('plugins.edit'),
        ], [
            'title' => t('Contract report'),
            'crumbs' => [[t('Modules and plugins'), $this->app->url()->plain('/admin/plugins')]],
            'active' => '/admin/plugins',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function enable(Request $request, array $params): Response
    {
        return $this->act('enable', $params, function (string $id): array {
            return $this->plugins()->enable($id);
        });
    }

    /**
     * @param array<string,string> $params
     */
    public function disable(Request $request, array $params): Response
    {
        return $this->act('disable', $params, function (string $id): array {
            return $this->plugins()->disable($id);
        });
    }

    /**
     * @param array<string,string> $params
     */
    public function uninstall(Request $request, array $params): Response
    {
        $keep = $request->bool('keep');

        return $this->act('uninstall', $params, function (string $id) use ($keep): array {
            return $this->plugins()->uninstall($id, $keep);
        });
    }

    /**
     * @param array<string,string> $params
     */
    public function grant(Request $request, array $params): Response
    {
        $capability = $request->str('capability');
        $on = $request->bool('on');

        return $this->act('grant', $params, function (string $id) use ($capability, $on): array {
            return $this->plugins()->grant($id, $capability, $on);
        });
    }

    /**
     * Pasa el validador y lleva al informe.
     *
     * @param array<string,string> $params
     */
    public function validate(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.edit');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        if ($this->plugins()->find($id) === null) {
            throw HttpError::notFound();
        }
        $report = $this->plugins()->validate($id);
        Admin::audit($this->app, 'plugins.validate', 'plugin', $id, 'Contract validated');
        Admin::flash($this->app, $report['ok'] ? 'ok' : 'bad', $report['ok']
            ? t('«{id}» passes the contract: {score} / 100', ['id' => $id, 'score' => $report['score']])
            : t('«{id}» does not pass the contract: {score} / 100', ['id' => $id, 'score' => $report['score']]));

        return Response::redirect($this->app->url()->plain('/admin/plugins/' . rawurlencode($id) . '/report'));
    }

    /**
     * Sube un plugin en un ZIP.
     *
     * @param array<string,string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.edit');
        $file = isset($request->files['plugin_zip']) && is_array($request->files['plugin_zip']) ? $request->files['plugin_zip'] : null;
        if ($file === null || (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
            Admin::flash($this->app, 'bad', t('Choose a ZIP file with the plugin.'));

            return $this->back('plplugins');
        }
        $result = $this->plugins()->install($file['tmp_name']);
        if ($result['ok']) {
            Admin::audit($this->app, 'plugins.install', 'plugin', (string) $result['id'], 'Plugin installed from a ZIP');
        }
        $this->flashResult($result);

        return $this->back('plplugins');
    }

    /**
     * @param array<string,string> $params
     */
    public function newForm(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.edit');

        return $this->renderNew(['id' => '', 'name' => '', 'description' => '', 'author' => '', 'features' => ['setting']], '');
    }

    /**
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('plugins.edit');
        $values = [
            'id' => $request->str('id'),
            'name' => $request->str('name'),
            'description' => $request->str('description'),
            'author' => $request->str('author'),
            'features' => array_values(array_filter(array_map('strval', array_filter($request->arr('features'), 'is_scalar')), static function (string $f): bool {
                return in_array($f, Generator::FEATURES, true);
            })),
        ];
        $result = (new Generator($this->plugins()))->create($values['id'], $values['name'], $values['features'], ['description' => $values['description'], 'author' => $values['author']]);
        if (!$result['ok']) {
            return $this->renderNew($values, (string) $result['message'], 422);
        }
        Admin::audit($this->app, 'plugins.create', 'plugin', $values['id'], 'Plugin created with the generator');
        Admin::flash($this->app, 'ok', (string) $result['message']);

        return $this->back('plplugins');
    }

    // ------------------------------------------------------------------ ayudas

    private function plugins(): Plugins
    {
        /** @var Plugins $service */
        $service = $this->app->service('plugins');

        return $service;
    }

    /**
     * @param array<string,string> $params
     */
    private function act(string $action, array $params, callable $run): Response
    {
        $this->app->auth()->requireCap('plugins.edit');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        $row = $this->plugins()->find($id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $result = $run($id);
        if ($result['ok']) {
            Admin::audit($this->app, 'plugins.' . $action, $row['kind'], $id, ucfirst($action));
        }
        $this->flashResult($result);

        return $this->back($row['kind'] === 'module' ? 'plmodules' : 'plplugins');
    }

    /**
     * @param array<string,mixed> $result
     */
    private function flashResult(array $result): void
    {
        $message = (string) $result['message'];
        if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) {
            $message .= ' — ' . implode(' · ', array_map('strval', $result['errors']));
        }
        Admin::flash($this->app, $result['ok'] ? 'ok' : 'bad', $message);
    }

    /** Vuelve al listado en la pestaña que se estaba viendo. */
    private function back(string $tab): Response
    {
        return Response::redirect($this->app->url()->plain('/admin/plugins') . '#tab=' . $tab);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function renderNew(array $values, string $error, int $status = 200): Response
    {
        return Admin::page($this->app, 'plugins::admin/new', ['values' => $values, 'error' => $error], [
            'title' => t('New plugin'),
            'crumbs' => [[t('Modules and plugins'), $this->app->url()->plain('/admin/plugins')]],
            'active' => '/admin/plugins/new',
            'help' => Admin::helpText("The generator creates plugins/<id> with its manifest, its Spanish catalogue, a test and a README.\nThe plugin is created turned off: turn it on from the list, where it passes the contract first."),
        ], $status);
    }
}
