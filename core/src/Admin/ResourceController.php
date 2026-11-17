<?php
/**
 * AIFEC — altas, bajas y cambios genéricos del panel. Un módulo declara un «recurso» en su admin.php y esta clase
 * le da listado, formulario, guardado, borrado, acciones en bloque, exportación y buscador, sin escribir controlador.
 *
 * Recurso (admin.php → 'resources' => ['coupons' => […]]):
 *   table, key ('id'), title, singular, label_field ('name'), cap ('marketing' → marketing.view / marketing.edit)
 *   o caps (['view' => …, 'edit' => …, 'delete' => …]), list (config de Table), form (definición de Form),
 *   scope (['type' => 'post']: filtra el listado y se fuerza al guardar), timestamps (true), can_create, can_delete,
 *   readonly, i18n (['table' => 'page_texts', 'fk' => 'page_id', 'lang' => 'lang']: guarda ahí los campos con i18n),
 *   slug (['entity' => 'page', 'field' => 'slug', 'from' => 'title', 'prefix' => '' o ['es' => 'producto', …]]),
 *   tags (etiquetas de caché que se invalidan al cambiar algo), help (HTML del acordeón de ayuda),
 *   before_save(array $values, ?array $current, App $app): array|string   (cadena = error; array = valores)
 *   after_save(int $id, array $values, ?array $current, App $app): void
 *   before_delete(array $row, App $app): ?string   (cadena = motivo por el que no se puede borrar)
 *   after_delete(array $row, App $app): void
 *   side(?array $row, App $app): string            (columna derecha del formulario)
 *   actions(?array $row, App $app): string         (botones de la cabecera del formulario)
 *   show(array $row, App $app): string             (ficha de solo lectura)
 *   bulk (['publish' => ['label' => …, 'confirm' => …, 'run' => function (array $ids, App $app): string]])
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Str;

final class ResourceController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ listado

    /** @param array<string,string> $p */
    public function index(Request $request, array $p): Response
    {
        $res = $this->def($p, 'view');
        $name = (string) $res['name'];
        $canEdit = $this->can($res, 'edit') && !$res['readonly'];
        $canDelete = $canEdit && $res['can_delete'] && $this->can($res, 'delete');
        $table = $this->table($res, $canEdit, $canDelete);

        $actions = '';
        if (!isset($res['list']['export']) || $res['list']['export'] !== false) {
            $actions .= '<a class="af-btn" href="' . e(Admin::resourceUrl($this->app, $name, 'export.csv', $request->query)) . '">' . Admin::icon('download') . e(t('Export')) . '</a>';
        }
        if ($canEdit && $res['can_create']) {
            $actions .= '<a class="af-btn af-btn-primary" href="' . e(Admin::resourceUrl($this->app, $name, 'new')) . '">' . Admin::icon('plus') . e(t('New')) . '</a>';
        }

        return Admin::html($this->app, $table->render($request), [
            'title' => t((string) $res['title']),
            'actions' => $actions,
            'help' => isset($res['help']) ? Admin::helpText((string) $res['help']) : '',
        ]);
    }

    /** @param array<string,string> $p */
    public function export(Request $request, array $p): Response
    {
        $res = $this->def($p, 'view');
        Admin::audit($this->app, 'export', (string) $res['name']);

        return $this->table($res, false, false)->csv($request, $res['name'] . '-' . gmdate('Ymd', $this->app->clock()->now()) . '.csv');
    }

    /** Buscador para los campos «relation»: /admin/r/<recurso>/search.json?q= */
    public function search(Request $request, array $p): Response
    {
        $res = $this->def($p, 'view');
        $db = $this->app->db();
        $key = 't.' . $db->ident((string) $res['key']);
        $from = $db->table((string) $res['table']) . ' t';
        $where = [];
        $params = [];
        if (isset($res['i18n']['table']) && !in_array((string) $res['label_field'], $db->columns((string) $res['table']), true)) {
            // El nombre está en la tabla de textos: se busca en el idioma principal.
            $cfg = (array) $res['i18n'] + ['fk' => 'entity_id', 'lang' => 'lang'];
            $label = 'x.' . $db->ident((string) $res['label_field']);
            $from .= ' JOIN ' . $db->table((string) $cfg['table']) . ' x ON x.' . $db->ident((string) $cfg['fk']) . ' = ' . $key . ' AND x.' . $db->ident((string) $cfg['lang']) . ' = ?';
            $params[] = $this->app->defaultLang();
        } else {
            $label = 't.' . $db->ident((string) $res['label_field']);
        }
        foreach ((array) $res['scope'] as $column => $value) {
            $where[] = 't.' . $db->ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $q = trim($request->str('q'));
        if ($q !== '') {
            $where[] = $label . " LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        }
        $rows = $db->select('SELECT ' . $key . ' AS id, ' . $label . ' AS label FROM ' . $from
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $label . ' LIMIT 20', $params);

        return Response::json(['items' => $rows]);
    }

    // ------------------------------------------------------------------ formulario

    /** @param array<string,string> $p */
    public function create(Request $request, array $p): Response
    {
        $res = $this->def($p, 'edit');
        if ($res['readonly'] || !$res['can_create']) {
            throw HttpError::forbidden();
        }
        $form = new Form($this->app, $this->formDefinition($res, null));

        return $this->screen($res, $form, null, $form->defaults(), [], $request);
    }

    /** @param array<string,string> $p */
    public function edit(Request $request, array $p): Response
    {
        $res = $this->def($p, 'view');
        $row = $this->find($res, (int) $p['id']);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $id = (int) $row[$res['key']];
        if ($res['readonly'] || !$this->can($res, 'edit')) {
            if (!isset($res['show'])) {
                throw HttpError::forbidden();
            }

            return Admin::html($this->app, (string) $this->call($res['show'], [$row, $this->app]), [
                'title' => $this->label($res, $row),
                'crumbs' => [[t((string) $res['title']), Admin::resourceUrl($this->app, (string) $res['name'])]],
                'active' => '/admin/r/' . $res['name'],
            ]);
        }
        $form = new Form($this->app, $this->formDefinition($res, $id));
        $values = $form->load($row) + $this->loadI18n($res, $form, $id);
        $values = $this->loadSlug($res, $form, $id, $values);

        return $this->screen($res, $form, $row, $values, [], $request);
    }

    /** @param array<string,string> $p */
    public function save(Request $request, array $p): Response
    {
        $res = $this->def($p, 'edit');
        if ($res['readonly']) {
            throw HttpError::forbidden();
        }
        $name = (string) $res['name'];
        $key = (string) $res['key'];
        $current = null;
        if (isset($p['id'])) {
            $current = $this->find($res, (int) $p['id']);
            if ($current === null) {
                throw HttpError::notFound();
            }
        } elseif (!$res['can_create']) {
            throw HttpError::forbidden();
        }
        $id = $current !== null ? (int) $current[$key] : null;
        $form = new Form($this->app, $this->formDefinition($res, $id));
        list($values, $errors) = $form->validate($request);

        $global = '';
        if ($errors === [] && isset($res['before_save'])) {
            $result = $this->call($res['before_save'], [$values, $current, $this->app]);
            if (is_string($result) && $result !== '') {
                $global = $result;
            } elseif (is_array($result)) {
                if (isset($result['_errors']) && is_array($result['_errors'])) {
                    $errors = $result['_errors'];
                } else {
                    $values = $result;
                }
            }
        }
        if ($errors !== [] || $global !== '') {
            if ($global !== '') {
                Admin::flash($this->app, 'bad', $global);
            }
            // Lo que no viaja en el formulario (contraseñas vacías) se conserva de la fila actual.
            $shown = $values + ($current !== null ? $form->load($current) : []);

            return $this->screen($res, $form, $current, $shown, $errors, $request, 422);
        }

        $db = $this->app->db();
        $data = $form->store($values);
        foreach ((array) $res['scope'] as $column => $value) {
            $data[(string) $column] = $value;
        }
        $now = $this->app->clock()->sql();
        if ($res['timestamps']) {
            $data['updated_at'] = $now;
            if ($current === null) {
                $data['created_at'] = $now;
            }
        }
        $id = (int) $db->transaction(function () use ($db, $res, $form, $data, $values, $current, $key): int {
            if ($current === null) {
                $newId = $db->insert((string) $res['table'], $data);
            } else {
                $newId = (int) $current[$key];
                if ($data !== []) {
                    $db->update((string) $res['table'], $data, [$key => $newId]);
                }
            }
            $this->saveI18n($res, $form, $newId, $values);
            $this->saveSlug($res, $form, $newId, $values);

            return $newId;
        });

        if (isset($res['after_save'])) {
            $this->call($res['after_save'], [$id, $values, $current, $this->app]);
        }
        $changes = [];
        if ($current !== null) {
            foreach ($data as $column => $value) {
                if (in_array($column, ['updated_at', 'created_at'], true) || !array_key_exists($column, $current) || (string) $current[$column] === (string) $value) {
                    continue;
                }
                $secret = preg_match('/pass|secret|token|key_hash/i', (string) $column) === 1;
                $changes[$column] = $secret ? ['***', '***'] : [self::short($current[$column]), self::short($value)];
            }
        }
        $fresh = $this->find($res, $id);
        Admin::audit($this->app, $current === null ? 'create' : 'update', $name, (string) $id, $fresh !== null ? $this->label($res, $fresh) : '', $changes);
        $this->app->invalidate(array_merge(array_map('strval', (array) $res['tags']), [$name, $name . ':' . $id]));
        $this->app->hooks()->fire('admin.resource.saved', $name, $id, $values, $current);
        Admin::flash($this->app, 'ok', $current === null ? t('Created') : t('Saved'));

        if ($request->str('_then') === 'list') {
            return Response::redirect(Admin::resourceUrl($this->app, $name));
        }
        $tab = preg_replace('/[^a-zA-Z0-9_-]/', '', $request->str('_tab'));

        return Response::redirect(Admin::resourceUrl($this->app, $name, $id) . ($tab !== '' ? '#tab=' . $tab : ''));
    }

    /** @param array<string,string> $p */
    public function delete(Request $request, array $p): Response
    {
        $res = $this->def($p, 'delete');
        if ($res['readonly'] || !$res['can_delete']) {
            throw HttpError::forbidden();
        }
        $row = $this->find($res, (int) $p['id']);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $problem = $this->remove($res, $row);
        Admin::flash($this->app, $problem === null ? 'ok' : 'bad', $problem === null ? t('Deleted') : $problem);

        return Response::redirect($problem === null ? Admin::resourceUrl($this->app, (string) $res['name']) : Admin::resourceUrl($this->app, (string) $res['name'], (int) $row[$res['key']]));
    }

    /** @param array<string,string> $p */
    public function bulk(Request $request, array $p): Response
    {
        $res = $this->def($p, 'edit');
        $name = (string) $res['name'];
        $action = $request->str('action');
        $ids = array_values(array_unique(array_filter(array_map('intval', $request->arr('ids')))));
        $back = Response::redirect(Admin::resourceUrl($this->app, $name));
        if ($ids === [] || $res['readonly']) {
            Admin::flash($this->app, 'warn', t('Select at least one row'));

            return $back;
        }
        if ($action === 'delete') {
            if (!$res['can_delete'] || !$this->can($res, 'delete')) {
                throw HttpError::forbidden();
            }
            $done = 0;
            $problems = [];
            foreach ($ids as $id) {
                $row = $this->find($res, $id);
                if ($row === null) {
                    continue;
                }
                $problem = $this->remove($res, $row);
                if ($problem === null) {
                    $done++;
                } else {
                    $problems[] = $this->label($res, $row) . ': ' . $problem;
                }
            }
            if ($done > 0) {
                Admin::flash($this->app, 'ok', tn('{n} deleted', '{n} deleted', $done));
            }
            if ($problems !== []) {
                Admin::flash($this->app, 'bad', implode(' · ', array_slice($problems, 0, 5)));
            }

            return $back;
        }
        if (isset($res['bulk'][$action]['run'])) {
            $message = $this->call($res['bulk'][$action]['run'], [$ids, $this->app]);
            Admin::audit($this->app, 'bulk:' . $action, $name, '', count($ids) . ' rows');
            $this->app->invalidate(array_merge(array_map('strval', (array) $res['tags']), [$name]));
            Admin::flash($this->app, 'ok', is_string($message) && $message !== '' ? $message : t('Done'));

            return $back;
        }
        throw HttpError::badRequest('Unknown action');
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,string> $p
     *
     * @return array<string,mixed>
     */
    private function def(array $p, string $ability): array
    {
        $res = Admin::resource($this->app, isset($p['resource']) ? (string) $p['resource'] : '');
        if ($res === null || !isset($res['table'])) {
            throw HttpError::notFound();
        }
        $res += [
            'key' => 'id', 'title' => ucfirst((string) $res['name']), 'label_field' => 'name', 'list' => [], 'form' => ['fields' => []], 'scope' => [],
            'timestamps' => true, 'can_create' => true, 'can_delete' => true, 'readonly' => false, 'tags' => [],
        ];
        $res += ['singular' => $res['title']];
        $this->app->auth()->requireCap($this->cap($res, $ability));
        if ($res['list'] instanceof \Closure) {
            // El listado puede declararse como función (columnas que dependen de ajustes o de otros módulos).
            $res['list'] = (array) $res['list']($this->app);
        }

        return $res;
    }

    /**
     * @param array<string,mixed> $res
     */
    private function cap(array $res, string $ability): string
    {
        if (isset($res['caps'][$ability])) {
            return (string) $res['caps'][$ability];
        }
        $base = isset($res['cap']) ? (string) $res['cap'] : (string) $res['module'];

        return $base . '.' . ($ability === 'view' ? 'view' : 'edit');
    }

    /**
     * @param array<string,mixed> $res
     */
    private function can(array $res, string $ability): bool
    {
        return $this->app->auth()->can($this->cap($res, $ability));
    }

    /**
     * @param array<string,mixed> $res
     */
    private function table(array $res, bool $canEdit, bool $canDelete): Table
    {
        $app = $this->app;
        $name = (string) $res['name'];
        $key = (string) $res['key'];
        $config = $res['list'] instanceof \Closure ? (array) $res['list']($app) : (array) $res['list'];
        $db = $app->db();
        if (!isset($config['columns']) || $config['columns'] === []) {
            $config['columns'] = [$res['label_field'] => ['label' => 'Name', 'link' => true, 'sort' => true]];
        }
        if (!isset($config['search'])) {
            $config['search'] = [(string) $res['label_field']];
        }
        $alias = isset($config['alias']) ? $config['alias'] . '.' : '';
        $joinParams = [];
        if (!isset($config['from']) && isset($res['i18n']['table'])) {
            // Recurso con textos por idioma: el listado une él solo la tabla de textos en el idioma principal,
            // así las columnas traducidas (el título) se pueden enseñar, buscar y ordenar por su nombre.
            $cfg = (array) $res['i18n'] + ['fk' => 'entity_id', 'lang' => 'lang'];
            $main = $db->columns((string) $res['table']);
            $texts = array_values(array_diff($db->columns((string) $cfg['table']), $main, [(string) $cfg['fk'], (string) $cfg['lang']]));
            $qualify = static function (string $column) use ($db, $main, $texts): string {
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
                    return $column;
                }

                return (in_array($column, $main, true) ? 't.' : (in_array($column, $texts, true) ? 'x.' : '')) . $db->ident($column);
            };
            $config['from'] = $db->table((string) $res['table']) . ' t LEFT JOIN ' . $db->table((string) $cfg['table']) . ' x ON x.' . $db->ident((string) $cfg['fk'])
                . ' = t.' . $db->ident($key) . ' AND x.' . $db->ident((string) $cfg['lang']) . ' = ?';
            $joinParams[] = $app->defaultLang();
            $select = 't.*';
            foreach ($texts as $column) {
                $select .= ', x.' . $db->ident($column);
            }
            $config['select'] = $select;
            $config['key_sql'] = 't.' . $db->ident($key);
            // Ojo: $name es el nombre del recurso y lo usan los enlaces de las filas de abajo; estos bucles no pueden pisarlo
            // (lo pisaban con la última columna o filtro: «/admin/r/type/2» en vez de «/admin/r/attributes/2», 14/11/2026).
            foreach ($config['columns'] as $colName => $column) {
                if (isset($column['sort']) && $column['sort'] === true) {
                    $config['columns'][$colName]['sort'] = $qualify((string) $colName);
                }
            }
            $config['search'] = array_map($qualify, array_map('strval', (array) $config['search']));
            foreach (isset($config['filters']) ? (array) $config['filters'] : [] as $filterName => $filter) {
                if (is_array($filter) && !isset($filter['column'])) {
                    $config['filters'][$filterName]['column'] = $qualify((string) $filterName);
                }
            }
            $sort = isset($config['sort']) ? (array) $config['sort'] : ['id', 'desc'];
            if (!isset($config['columns'][(string) $sort[0]]['sort']) || !is_string($config['columns'][(string) $sort[0]]['sort'])) {
                $sort[0] = $qualify((string) $sort[0]);
            }
            $config['sort'] = $sort;
            $alias = 't.';
        }
        $config += ['from' => $res['table'], 'key' => $key];
        $scopeWhere = [];
        $scopeParams = [];
        foreach ((array) $res['scope'] as $column => $value) {
            $scopeWhere[] = $alias . $db->ident((string) $column) . ' = ?';
            $scopeParams[] = $value;
        }
        $config['where'] = array_merge($scopeWhere, isset($config['where']) ? (array) $config['where'] : []);
        $config['params'] = array_merge($joinParams, $scopeParams, isset($config['params']) ? (array) $config['params'] : []);
        if (!isset($config['row_url']) && ($canEdit || isset($res['show']))) {
            $config['row_url'] = static function (array $row) use ($app, $name, $key): string {
                return Admin::resourceUrl($app, $name, isset($row[$key]) ? (int) $row[$key] : 0);
            };
        }
        if (!isset($config['row_actions']) && $canDelete) {
            $config['row_actions'] = static function (array $row) use ($app, $name, $key): array {
                return [['label' => 'Delete', 'url' => Admin::resourceUrl($app, $name, ((int) $row[$key]) . '/delete'), 'post' => true, 'danger' => true, 'confirm' => 'Delete this? It cannot be undone.']];
            };
        }
        $bulk = [];
        foreach (isset($res['bulk']) && is_array($res['bulk']) ? $res['bulk'] : [] as $action => $definition) {
            if ($canEdit && is_array($definition) && isset($definition['label'])) {
                $bulk[(string) $action] = $definition;
            }
        }
        if ($canDelete) {
            $bulk['delete'] = ['label' => 'Delete', 'confirm' => 'Delete the selected rows? It cannot be undone.'];
        }
        $config['bulk'] = $bulk;
        $config['bulk_url'] = Admin::resourceUrl($app, $name, 'bulk');
        if (!isset($config['empty']) && $canEdit && $res['can_create']) {
            $config['empty'] = ['title' => 'Nothing here yet', 'action_label' => 'New', 'action_url' => Admin::resourceUrl($app, $name, 'new')];
        }

        return new Table($app, $config);
    }

    /**
     * La definición del formulario, con las reglas «unique» ajustadas para no chocar con la propia fila.
     *
     * @param array<string,mixed> $res
     *
     * @return array<string,mixed>
     */
    private function formDefinition(array $res, ?int $id): array
    {
        $definition = $res['form'] instanceof \Closure ? (array) $res['form']($this->app, $id) : (array) $res['form'];
        if (isset($definition['fields']) && $definition['fields'] instanceof \Closure) {
            $definition['fields'] = (array) $definition['fields']($this->app, $id);
        }
        if ($id === null) {
            return $definition;
        }
        foreach (isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [] as $name => $field) {
            if (!is_array($field) || !isset($field['rules']) || !is_string($field['rules']) || strpos($field['rules'], 'unique:') === false) {
                continue;
            }
            $rules = explode('|', $field['rules']);
            foreach ($rules as $i => $rule) {
                if (strpos($rule, 'unique:') === 0 && substr_count($rule, ',') === 1) {
                    $rules[$i] = $rule . ',' . $id . ',' . $res['key'];
                }
            }
            $definition['fields'][$name]['rules'] = implode('|', $rules);
        }

        return $definition;
    }

    /**
     * @param array<string,mixed>      $res
     * @param array<string,mixed>|null $row
     * @param array<string,mixed>      $values
     * @param array<string,string>     $errors
     */
    private function screen(array $res, Form $form, ?array $row, array $values, array $errors, Request $request, int $status = 200): Response
    {
        $name = (string) $res['name'];
        $id = $row !== null ? (int) $row[$res['key']] : null;
        $canDelete = $id !== null && $res['can_delete'] && $this->can($res, 'delete');
        $html = $form->render($values, $errors, [
            'action' => Admin::resourceUrl($this->app, $name, $id !== null ? $id . '/save' : 'save'),
            'cancel' => Admin::resourceUrl($this->app, $name),
            'delete' => $canDelete ? Admin::resourceUrl($this->app, $name, $id . '/delete') : '',
            'tab' => $request->str('_tab'),
            'side' => isset($res['side']) ? (string) $this->call($res['side'], [$row, $this->app]) : '',
            'extra' => '<button type="submit" name="_then" value="list" class="af-btn">' . e(t('Save and close')) . '</button>',
        ]);
        $actions = isset($res['actions']) ? (string) $this->call($res['actions'], [$row, $this->app]) : '';
        if ($id !== null && isset($res['slug']['entity'])) {
            $actions .= '<a class="af-btn" target="_blank" rel="noopener" href="' . e($this->app->url()->entity((string) $res['slug']['entity'], $id)) . '">' . Admin::icon('external') . e(t('View')) . '</a>';
        }

        return Admin::html($this->app, $html, [
            'title' => $row !== null ? $this->label($res, $row) : t('New: {name}', ['name' => t((string) $res['singular'])]),
            'crumbs' => [[t((string) $res['title']), Admin::resourceUrl($this->app, $name)]],
            'actions' => $actions,
            'active' => '/admin/r/' . $name,
        ], $status);
    }

    /**
     * @param array<string,mixed> $res
     *
     * @return array<string,mixed>|null
     */
    private function find(array $res, int $id): ?array
    {
        return $this->app->db()->find((string) $res['table'], [(string) $res['key'] => $id] + (array) $res['scope']);
    }

    /**
     * @param array<string,mixed> $res
     * @param array<string,mixed> $row
     */
    private function label(array $res, array $row): string
    {
        $field = (string) $res['label_field'];
        $label = isset($row[$field]) && is_scalar($row[$field]) ? trim((string) $row[$field]) : '';
        if ($label === '' && isset($res['i18n']['table'])) {
            $cfg = (array) $res['i18n'] + ['fk' => 'entity_id', 'lang' => 'lang'];
            $db = $this->app->db();
            $text = $db->row('SELECT * FROM ' . $db->table((string) $cfg['table']) . ' WHERE ' . $db->ident((string) $cfg['fk']) . ' = ? AND ' . $db->ident((string) $cfg['lang']) . ' = ?', [(int) $row[$res['key']], $this->app->defaultLang()]);
            $label = $text !== null && isset($text[$field]) ? trim((string) $text[$field]) : '';
        }

        return $label !== '' ? $label : t((string) $res['singular']) . ' #' . $row[$res['key']];
    }

    /**
     * Borra una fila con todo lo suyo. Devuelve el motivo si no se puede, o null si se ha borrado.
     *
     * @param array<string,mixed> $res
     * @param array<string,mixed> $row
     */
    private function remove(array $res, array $row): ?string
    {
        if (isset($res['before_delete'])) {
            $problem = $this->call($res['before_delete'], [$row, $this->app]);
            if (is_string($problem) && $problem !== '') {
                return $problem;
            }
        }
        $db = $this->app->db();
        $id = (int) $row[$res['key']];
        $label = $this->label($res, $row);
        $db->transaction(function () use ($db, $res, $id): void {
            $db->delete((string) $res['table'], [(string) $res['key'] => $id]);
            if (isset($res['i18n']['table'])) {
                $db->delete((string) $res['i18n']['table'], [isset($res['i18n']['fk']) ? (string) $res['i18n']['fk'] : 'entity_id' => $id]);
            }
            if (isset($res['slug']['entity'])) {
                $this->app->slugs()->remove((string) $res['slug']['entity'], $id);
            }
        });
        if (isset($res['after_delete'])) {
            $this->call($res['after_delete'], [$row, $this->app]);
        }
        Admin::audit($this->app, 'delete', (string) $res['name'], (string) $id, $label);
        $this->app->invalidate(array_merge(array_map('strval', (array) $res['tags']), [(string) $res['name'], $res['name'] . ':' . $id]));
        $this->app->hooks()->fire('admin.resource.deleted', (string) $res['name'], $id, $row);

        return null;
    }

    /**
     * @param array<string,mixed> $res
     *
     * @return array<string,array<string,mixed>> campo => [idioma => valor]
     */
    private function loadI18n(array $res, Form $form, int $id): array
    {
        if (!isset($res['i18n']['table'])) {
            return [];
        }
        $cfg = (array) $res['i18n'] + ['fk' => 'entity_id', 'lang' => 'lang'];
        $db = $this->app->db();
        $values = [];
        foreach ($db->select('SELECT * FROM ' . $db->table((string) $cfg['table']) . ' WHERE ' . $db->ident((string) $cfg['fk']) . ' = ?', [$id]) as $row) {
            foreach ($form->fields() as $name => $f) {
                $column = isset($f['column']) ? (string) $f['column'] : $name;
                if (empty($f['i18n']) || !array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                if (in_array($f['type'], ['blocks', 'json', 'tags', 'repeater', 'images', 'checks'], true) && is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : [];
                }
                $values[$name][(string) $row[$cfg['lang']]] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $res
     * @param array<string,mixed> $values
     */
    private function saveI18n(array $res, Form $form, int $id, array $values): void
    {
        if (!isset($res['i18n']['table'])) {
            return;
        }
        $cfg = (array) $res['i18n'] + ['fk' => 'entity_id', 'lang' => 'lang'];
        $skip = isset($res['slug']['field']) ? (string) $res['slug']['field'] : '';
        foreach ($this->app->languages() as $lang) {
            $data = [];
            foreach ($form->fields() as $name => $f) {
                if (empty($f['i18n']) || !isset($values[$name]) || !is_array($values[$name]) || (isset($f['store']) && $f['store'] === false) || $name === $skip) {
                    continue;
                }
                $value = array_key_exists($lang, $values[$name]) ? $values[$name][$lang] : null;
                $data[isset($f['column']) ? (string) $f['column'] : $name] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
            }
            if ($data !== []) {
                $this->app->db()->upsert((string) $cfg['table'], [(string) $cfg['fk'] => $id, (string) $cfg['lang'] => $lang] + $data, [(string) $cfg['fk'], (string) $cfg['lang']]);
            }
        }
    }

    /**
     * @param array<string,mixed> $res
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private function loadSlug(array $res, Form $form, int $id, array $values): array
    {
        if (!isset($res['slug']['entity'], $res['slug']['field'])) {
            return $values;
        }
        $field = (string) $res['slug']['field'];
        $fields = $form->fields();
        $perLang = isset($fields[$field]) && !empty($fields[$field]['i18n']);
        foreach ($this->app->languages() as $lang) {
            $path = $this->app->slugs()->path((string) $res['slug']['entity'], $id, $lang);
            if ($path === null) {
                continue;
            }
            $prefix = $this->slugPrefix($res, $lang);
            $slug = $prefix !== '' && strpos($path, $prefix . '/') === 0 ? substr($path, strlen($prefix) + 1) : $path;
            if ($perLang) {
                $values[$field][$lang] = $slug;
            } elseif ($lang === $this->app->defaultLang()) {
                $values[$field] = $slug;
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $res
     * @param array<string,mixed> $values
     */
    private function saveSlug(array $res, Form $form, int $id, array $values): void
    {
        if (!isset($res['slug']['entity'])) {
            return;
        }
        $field = isset($res['slug']['field']) ? (string) $res['slug']['field'] : 'slug';
        $from = isset($res['slug']['from']) ? (string) $res['slug']['from'] : (string) $res['label_field'];
        $default = $this->app->defaultLang();
        foreach ($this->app->languages() as $lang) {
            $slug = '';
            foreach ([$field, $from] as $source) {
                if ($slug !== '' || !isset($values[$source])) {
                    continue;
                }
                $candidate = is_array($values[$source]) ? (isset($values[$source][$lang]) && $values[$source][$lang] !== '' ? $values[$source][$lang] : (isset($values[$source][$default]) ? $values[$source][$default] : '')) : $values[$source];
                $slug = Str::slug(is_scalar($candidate) ? (string) $candidate : '', 90);
            }
            if ($slug === '') {
                $slug = $res['slug']['entity'] . '-' . $id;
            }
            $prefix = $this->slugPrefix($res, $lang);
            $this->app->slugs()->set((string) $res['slug']['entity'], $id, $lang, ($prefix !== '' ? $prefix . '/' : '') . $slug);
        }
    }

    /**
     * @param array<string,mixed> $res
     */
    private function slugPrefix(array $res, string $lang): string
    {
        $prefix = isset($res['slug']['prefix']) ? $res['slug']['prefix'] : '';
        if (is_array($prefix)) {
            $prefix = isset($prefix[$lang]) ? $prefix[$lang] : (isset($prefix[$this->app->defaultLang()]) ? $prefix[$this->app->defaultLang()] : '');
        }

        return trim((string) $prefix, '/');
    }

    /**
     * @param mixed            $callable función o [Clase::class, 'método'] (estático)
     * @param array<int,mixed> $args
     *
     * @return mixed
     */
    private function call($callable, array $args)
    {
        if (!is_callable($callable)) {
            throw new \LogicException('A resource callback is not callable');
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * @param mixed $value
     */
    private static function short($value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : '[…]';

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text;
    }
}
