<?php
/**
 * AIFEC — listados del panel: búsqueda, filtros, orden, paginación, acciones por fila y en bloque, y exportación.
 *
 * Configuración:
 *   'from'    => 'coupons'  (tabla)  o  '{orders} o LEFT JOIN {customers} c ON c.id = o.customer_id'  (con espacios = SQL tal cual)
 *   'select'  => '*',  'key' => 'id'
 *   'columns' => ['code' => ['label' => 'Code', 'sort' => true, 'link' => true],
 *                 'total' => ['label' => 'Total', 'type' => 'money', 'sort' => 'o.total'], …]
 *       type: text, int, money, bool, badge (con 'map' => [valor => [texto, ok|warn|bad|info]]), date, datetime, image, html
 *       translate: true = el texto de la celda se pasa por t() (nombres sembrados en inglés: papeles, estados, clases de impuesto)
 *       render: function (array $row, App $app): string  → HTML ya escapado por quien lo escribe
 *   'search'  => ['code', 'name'],
 *   'filters' => ['active' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive'], 'column' => 'active']],
 *   'where' => ['deleted_at IS NULL'], 'params' => [], 'sort' => ['id', 'desc'], 'per_page' => 25,
 *   'row_url' => function (array $row): string, 'row_actions' => function (array $row): array,
 *   'bulk' => ['delete' => ['label' => 'Delete', 'confirm' => '…']], 'bulk_url' => '…',
 *   'empty' => ['title' => '…', 'text' => '…', 'action_label' => '…', 'action_url' => '…']
 *
 * Parámetros de la dirección: q, f[nombre], sort, dir, p.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Csv;

final class Table
{
    /** @var int */
    private static $serial = 0;

    /** @var App */
    private $app;

    /** @var array<string,mixed> */
    private $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(App $app, array $config)
    {
        $this->app = $app;
        $this->config = $config + ['select' => '*', 'key' => 'id', 'columns' => [], 'search' => [], 'filters' => [], 'where' => [], 'params' => [], 'sort' => ['id', 'desc'], 'per_page' => 25, 'bulk' => []];
    }

    /**
     * Ejecuta la consulta con lo que pide la dirección.
     *
     * @return array<string,mixed> rows, total, page, pages, per_page, sort, dir, q, filters, from, to
     */
    public function query(Request $request, bool $all = false, int $max = 20000): array
    {
        $db = $this->app->db();
        $c = $this->config;
        $from = strpos((string) $c['from'], ' ') === false && strpos((string) $c['from'], '{') === false ? $db->table((string) $c['from']) : (string) $c['from'];
        $where = array_values((array) $c['where']);
        $params = array_values((array) $c['params']);

        $q = trim($request->str('q'));
        if ($q !== '' && $c['search'] !== []) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $parts = [];
            foreach ((array) $c['search'] as $column) {
                $parts[] = $column . " LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
            if (ctype_digit($q) && strpos((string) $c['key'], ' ') === false) {
                $parts[] = (isset($c['key_sql']) ? (string) $c['key_sql'] : $db->ident((string) $c['key'])) . ' = ?';
                $params[] = (int) $q;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $chosen = [];
        $sent = $request->get('f', []);
        $sent = is_array($sent) ? $sent : [];
        foreach ((array) $c['filters'] as $name => $filter) {
            $value = isset($sent[$name]) && is_scalar($sent[$name]) ? trim((string) $sent[$name]) : '';
            if ($value === '') {
                continue;
            }
            $column = isset($filter['column']) ? (string) $filter['column'] : $db->ident((string) $name);
            $type = isset($filter['type']) ? (string) $filter['type'] : 'select';
            if ($type === 'date_from' || $type === 'date_to') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    continue;
                }
                $where[] = $column . ($type === 'date_from' ? ' >= ?' : ' <= ?');
                $params[] = $value . ($type === 'date_from' ? ' 00:00:00' : ' 23:59:59');
            } elseif (isset($filter['options']) && !array_key_exists($value, $this->filterOptions($filter))) {
                continue;
            } elseif (isset($filter['sql']) && is_array($filter['sql']) && isset($filter['sql'][$value])) {
                // Filtros que no son «columna = valor»: 'sql' => ['low' => 'stock <= 5', …]
                $where[] = '(' . $filter['sql'][$value] . ')';
            } else {
                $where[] = $column . ' = ?';
                $params[] = $value;
            }
            $chosen[(string) $name] = $value;
        }

        $sortable = [];
        foreach ((array) $c['columns'] as $name => $column) {
            if (!empty($column['sort'])) {
                $sortable[(string) $name] = is_string($column['sort']) ? $column['sort'] : $db->ident((string) $name);
            }
        }
        $default = (array) $c['sort'];
        $sort = $request->str('sort');
        $dir = strtolower($request->str('dir')) === 'asc' ? 'asc' : (strtolower($request->str('dir')) === 'desc' ? 'desc' : '');
        if (!isset($sortable[$sort])) {
            $sort = '';
        }
        $orderSql = $sort !== ''
            ? $sortable[$sort] . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC')
            : (isset($sortable[(string) $default[0]]) ? $sortable[(string) $default[0]] : (string) $default[0]) . ' ' . (isset($default[1]) && strtolower((string) $default[1]) === 'asc' ? 'ASC' : 'DESC');
        if ($sort === '') {
            $sort = (string) $default[0];
            $dir = isset($default[1]) && strtolower((string) $default[1]) === 'asc' ? 'asc' : 'desc';
        } elseif ($dir === '') {
            $dir = 'desc';
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $db->value('SELECT COUNT(*) FROM ' . $from . $whereSql, $params);
        $perPage = max(1, min(200, (int) $c['per_page']));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $request->int('p', 1)));
        $sql = 'SELECT ' . $c['select'] . ' FROM ' . $from . $whereSql . ' ORDER BY ' . $orderSql;
        if ($all) {
            $rows = $db->select($sql . ' LIMIT ' . max(1, $max), $params);
        } else {
            $rows = $db->select($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        }

        return [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'sort' => $sort, 'dir' => $dir,
            'q' => $q, 'filters' => $chosen, 'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1, 'to' => min($total, $page * $perPage),
        ];
    }

    public function render(Request $request): string
    {
        $c = $this->config;
        $data = $this->query($request);
        $url = $this->app->url();
        $formId = 'af-bulk-' . (++self::$serial);
        $hasBulk = $c['bulk'] !== [] && isset($c['bulk_url']);
        $filtered = $data['q'] !== '' || $data['filters'] !== [];

        $html = '<div class="af-card" data-af-table>';
        if ($c['search'] !== [] || $c['filters'] !== [] || isset($c['toolbar'])) {
            $html .= '<form class="af-toolbar" method="get" action="' . e($url->plain($request->path)) . '" role="search">';
            if ($c['search'] !== []) {
                $html .= '<input class="af-input af-search" type="search" name="q" value="' . e($data['q']) . '" placeholder="' . e(t('Search…')) . '" aria-label="' . e(t('Search')) . '">';
            }
            // Los filtros van juntos en un bloque plegable: abierto de serie (sin JavaScript se ven todos) y, en pantallas
            // estrechas, el guion del panel lo pliega si no hay ninguno puesto, para que la lista quede a la vista.
            if ($c['filters'] !== []) {
                $active = count($data['filters']);
                $html .= '<details class="af-filters" open data-af-filters="' . $active . '"><summary class="af-btn">' . Admin::icon('filter') . e(t('Filters'))
                    . ($active > 0 ? ' <span class="af-badge af-badge-bad">' . $active . '</span>' : '') . '</summary><div class="af-filters-body">';
            }
            foreach ((array) $c['filters'] as $name => $filter) {
                $current = isset($data['filters'][$name]) ? (string) $data['filters'][$name] : '';
                $label = t(isset($filter['label']) ? (string) $filter['label'] : (string) $name);
                $type = isset($filter['type']) ? (string) $filter['type'] : 'select';
                if ($type === 'date_from' || $type === 'date_to') {
                    $html .= '<label class="af-small af-muted">' . e($label) . ' <input class="af-input" type="date" name="f[' . e($name) . ']" value="' . e($current) . '"></label>';
                    continue;
                }
                $html .= '<select class="af-select" name="f[' . e($name) . ']" aria-label="' . e($label) . '" data-af-autosubmit><option value="">' . e($label) . '</option>';
                foreach ($this->filterOptions($filter) as $value => $text) {
                    $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e(!empty($filter['raw']) ? (string) $text : t((string) $text)) . '</option>';
                }
                $html .= '</select>';
            }
            if ($c['filters'] !== []) {
                $html .= '</div></details>';
            }
            if ($data['sort'] !== (string) ((array) $c['sort'])[0]) {
                $html .= '<input type="hidden" name="sort" value="' . e($data['sort']) . '"><input type="hidden" name="dir" value="' . e($data['dir']) . '">';
            }
            $html .= '<button type="submit" class="af-btn">' . Admin::icon('search') . e(t('Filter')) . '</button>';
            if ($filtered) {
                $html .= '<a class="af-btn af-btn-ghost" href="' . e($url->plain($request->path)) . '">' . e(t('Clear')) . '</a>';
            }
            $html .= '<span class="af-top-space"></span>' . (isset($c['toolbar']) ? (string) $c['toolbar'] : '') . '</form>';
        }

        if ($data['total'] === 0) {
            $empty = (isset($c['empty']) && is_array($c['empty']) ? $c['empty'] : []) + ['title' => 'Nothing here yet', 'text' => '', 'action_label' => '', 'action_url' => ''];
            $html .= '<div class="af-empty"><h3>' . e($filtered ? t('No results') : t((string) $empty['title'])) . '</h3>'
                . '<p>' . e($filtered ? t('Try other words or clear the filters.') : ($empty['text'] !== '' ? t((string) $empty['text']) : '')) . '</p>';
            if (!$filtered && $empty['action_url'] !== '') {
                $html .= '<a class="af-btn af-btn-primary" href="' . e($empty['action_url']) . '">' . Admin::icon('plus') . e(t((string) $empty['action_label'])) . '</a>';
            }

            return $html . '</div></div>';
        }

        if ($hasBulk) {
            $html .= '<form method="post" action="' . e((string) $c['bulk_url']) . '" id="' . $formId . '" class="af-bulk" data-af-bulk>' . Admin::tokenField($this->app)
                . '<strong><span data-af-count>0</span> ' . e(t('selected')) . '</strong><select class="af-select" name="action" aria-label="' . e(t('Action')) . '">';
            foreach ((array) $c['bulk'] as $key => $action) {
                $html .= '<option value="' . e((string) $key) . '" data-confirm="' . e(isset($action['confirm']) ? t((string) $action['confirm']) : '') . '">' . e(t((string) $action['label'])) . '</option>';
            }
            $html .= '</select><button type="submit" class="af-btn af-btn-sm" data-af-bulk-apply>' . e(t('Apply')) . '</button></form>';
        }

        $html .= '<div class="af-table-wrap"><table class="af-table"><thead><tr>';
        if ($hasBulk) {
            $html .= '<th class="af-cell-check"><label class="af-pick"><input type="checkbox" data-af-check-all aria-label="' . e(t('Select all')) . '"></label></th>';
        }
        $labels = [];
        foreach ((array) $c['columns'] as $name => $column) {
            $class = $this->cellClass($column);
            $label = e(t(isset($column['label']) ? (string) $column['label'] : (string) $name));
            // El nombre de la columna viaja en cada celda: por debajo de 768 px cada fila es una tarjeta y la celda lo enseña.
            $labels[(string) $name] = $label;
            if (!empty($column['sort'])) {
                $isCurrent = $data['sort'] === (string) $name;
                $next = $isCurrent && $data['dir'] === 'asc' ? 'desc' : 'asc';
                $label = '<a href="' . e($url->current(['sort' => (string) $name, 'dir' => $next, 'p' => null])) . '"' . ($isCurrent ? ' aria-sort="' . ($data['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
                    . $label . ($isCurrent ? ($data['dir'] === 'asc' ? ' ↑' : ' ↓') : '') . '</a>';
            }
            $html .= '<th' . ($class !== '' ? ' class="' . $class . '"' : '') . ' scope="col">' . $label . '</th>';
        }
        $rowActions = isset($c['row_actions']) && $c['row_actions'] instanceof \Closure ? $c['row_actions'] : null;
        if ($rowActions !== null) {
            $html .= '<th class="af-cell-actions"><span class="af-sr">' . e(t('Actions')) . '</span></th>';
        }
        $html .= '</tr></thead><tbody>';
        $rowUrl = isset($c['row_url']) && $c['row_url'] instanceof \Closure ? $c['row_url'] : null;
        foreach ($data['rows'] as $row) {
            $key = isset($row[$c['key']]) ? (string) $row[$c['key']] : '';
            $link = $rowUrl !== null ? (string) $rowUrl($row) : '';
            $html .= '<tr>';
            if ($hasBulk) {
                $html .= '<td class="af-cell-check"><label class="af-pick"><input type="checkbox" name="ids[]" value="' . e($key) . '" form="' . $formId . '" data-af-check aria-label="' . e(t('Select')) . '"></label></td>';
            }
            foreach ((array) $c['columns'] as $name => $column) {
                $cell = $this->cell((string) $name, $column, $row);
                if (!empty($column['link']) && $link !== '') {
                    $cell = '<a href="' . e($link) . '"><strong>' . ($cell !== '' ? $cell : '—') . '</strong></a>';
                }
                $class = $this->cellClass($column);
                $html .= '<td' . ($class !== '' ? ' class="' . $class . '"' : '') . ' data-label="' . $labels[(string) $name] . '">' . $cell . '</td>';
            }
            if ($rowActions !== null) {
                $html .= '<td class="af-cell-actions">' . $this->actions((array) $rowActions($row)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div><div class="af-pagination"><span class="af-muted af-small">'
            . e(t('{from}–{to} of {total}', ['from' => $data['from'], 'to' => $data['to'], 'total' => $data['total']])) . '</span><span class="af-top-space"></span>';
        if ($data['pages'] > 1) {
            $html .= $data['page'] > 1 ? '<a class="af-btn af-btn-sm" rel="prev" href="' . e($url->current(['p' => $data['page'] - 1])) . '">' . e(t('Previous')) . '</a>' : '';
            $html .= '<span class="af-small">' . e(t('Page {page} of {pages}', ['page' => $data['page'], 'pages' => $data['pages']])) . '</span>';
            $html .= $data['page'] < $data['pages'] ? '<a class="af-btn af-btn-sm" rel="next" href="' . e($url->current(['p' => $data['page'] + 1])) . '">' . e(t('Next')) . '</a>' : '';
        }

        return $html . '</div></div>';
    }

    /** Lo mismo que se ve (con los filtros puestos), en CSV para una hoja de cálculo. */
    public function csv(Request $request, string $filename): Response
    {
        $data = $this->query($request, true);
        $header = [];
        $names = [];
        foreach ((array) $this->config['columns'] as $name => $column) {
            if (isset($column['render']) || (isset($column['type']) && in_array($column['type'], ['image', 'html'], true))) {
                continue;
            }
            $names[] = (string) $name;
            $header[] = t(isset($column['label']) ? (string) $column['label'] : (string) $name);
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            $line = [];
            foreach ($names as $name) {
                $value = isset($row[$name]) ? $row[$name] : '';
                $type = isset($this->config['columns'][$name]['type']) ? (string) $this->config['columns'][$name]['type'] : 'text';
                $line[] = $type === 'money' && $value !== '' && $value !== null ? number_format(((int) $value) / 100, 2, ',', '') : (is_scalar($value) ? (string) $value : '');
            }
            $rows[] = $line;
        }
        $response = Response::text("\xEF\xBB\xBF" . Csv::toString($header, $rows, ';'), 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) . '"';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * Botones de acción de una fila.
     *
     * @param array<int,array<string,mixed>> $actions label, url, post (bool), confirm, danger, icon
     */
    public function actions(array $actions): string
    {
        $html = '';
        foreach ($actions as $action) {
            if (!is_array($action) || !isset($action['label'], $action['url'])) {
                continue;
            }
            $icon = isset($action['icon']) ? Admin::icon((string) $action['icon']) : '';
            $class = 'af-btn af-btn-sm af-btn-ghost' . (!empty($action['danger']) ? ' af-link-danger' : '');
            $confirm = isset($action['confirm']) ? ' data-af-confirm="' . e(t((string) $action['confirm'])) . '"' : '';
            if (!empty($action['post'])) {
                $html .= '<form method="post" action="' . e($action['url']) . '" class="af-inline">' . Admin::tokenField($this->app)
                    . '<button type="submit" class="' . $class . '"' . $confirm . '>' . $icon . e(t((string) $action['label'])) . '</button></form>';
            } else {
                $html .= '<a class="' . $class . '" href="' . e($action['url']) . '"' . $confirm . '>' . $icon . e(t((string) $action['label'])) . '</a>';
            }
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $column
     * @param array<string,mixed> $row
     */
    private function cell(string $name, array $column, array $row): string
    {
        if (isset($column['render']) && $column['render'] instanceof \Closure) {
            return (string) $column['render']($row, $this->app);
        }
        $value = isset($row[$name]) ? $row[$name] : null;
        $type = isset($column['type']) ? (string) $column['type'] : 'text';
        if ($type === 'bool') {
            return !empty($value) ? Admin::badge(t('Yes'), 'ok') : '<span class="af-muted">' . e(t('No')) . '</span>';
        }
        if ($value === null || $value === '') {
            return '<span class="af-muted">—</span>';
        }
        switch ($type) {
            case 'money':
                return e(money((int) $value, isset($column['currency_field'], $row[$column['currency_field']]) ? (string) $row[$column['currency_field']] : null));
            case 'int':
                return e($this->app->i18n()->number((float) $value));
            case 'badge':
                $map = isset($column['map'][(string) $value]) ? (array) $column['map'][(string) $value] : [(string) $value, ''];

                return Admin::badge(t((string) $map[0]), isset($map[1]) ? (string) $map[1] : '');
            case 'date':
            case 'datetime':
                $time = Clock::parse((string) $value);

                return $time > 0 ? '<span class="af-nowrap">' . e($this->app->i18n()->date($time, $type === 'date' ? 'short' : 'medium', $this->app->site()->timezone())) . '</span>' : '';
            case 'image':
                $src = ctype_digit((string) $value) ? ($this->app->has('media') ? (string) $this->app->service('media')->url((int) $value, 'thumb') : '') : (string) $value;

                return $src !== '' ? '<img class="af-thumb af-thumb-sm" src="' . e($src) . '" alt="" loading="lazy" width="44" height="44">' : '';
            case 'html':
                return (string) $value;
        }
        $text = !empty($column['translate']) ? t((string) $value) : (string) $value;
        if (isset($column['limit']) && mb_strlen($text) > (int) $column['limit']) {
            $text = mb_substr($text, 0, (int) $column['limit']) . '…';
        }

        return e($text);
    }

    /**
     * @param array<string,mixed> $column
     */
    private function cellClass(array $column): string
    {
        $type = isset($column['type']) ? (string) $column['type'] : '';
        $class = in_array($type, ['money', 'int'], true) ? 'af-num' : '';
        if (!empty($column['hide_sm'])) {
            $class .= ($class !== '' ? ' ' : '') . 'af-hide-sm';
        }

        return $class;
    }

    /**
     * @param array<string,mixed> $filter
     *
     * @return array<int|string,mixed>
     */
    private function filterOptions(array $filter): array
    {
        $options = isset($filter['options']) ? $filter['options'] : [];
        if ($options instanceof \Closure) {
            $options = $options($this->app);
        }

        return is_array($options) ? $options : [];
    }
}
