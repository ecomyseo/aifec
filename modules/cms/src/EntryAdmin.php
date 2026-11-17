<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Csv;

/**
 * Los mensajes recibidos por los formularios, en el panel (recurso «form_entries»; ver Aifec\Admin\ResourceController y
 * Aifec\Admin\Form): listado con filtro por formulario, ficha, marcar como leída, borrar y exportar a CSV.
 *
 * Los datos de una entrada los escribe una persona: nada se pinta sin escapar.
 */
final class EntryAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        $db = $app->db();

        return [
            'from' => $db->table('form_entries') . ' t LEFT JOIN ' . $db->table('forms') . ' f ON f.id = t.form_id',
            'select' => 't.id AS id, t.form_id AS form_id, t.data AS data, t.ip AS ip, t.status AS status, t.created_at AS created_at, f.name AS form_name',
            'key_sql' => 't.id',
            'columns' => [
                'id' => ['label' => 'Number', 'link' => true, 'sort' => 't.id'],
                'form_name' => ['label' => 'Form', 'sort' => 'f.name', 'translate' => true],
                'summary' => ['label' => 'Message', 'render' => static function (array $row, App $app): string {
                    return e(self::summary($app, $row));
                }],
                'status' => ['label' => 'Status', 'type' => 'badge', 'map' => ['new' => ['New', 'info'], 'read' => ['Read', 'ok']], 'sort' => 't.status'],
                'created_at' => ['label' => 'Received', 'type' => 'datetime', 'sort' => 't.created_at'],
            ],
            'search' => ['t.data', 'f.name'],
            'filters' => [
                'form_id' => ['label' => 'Form', 'column' => 't.form_id', 'options' => static function (App $app): array {
                    $out = [];
                    foreach ($app->db()->select('SELECT id, name FROM {forms} ORDER BY name') as $row) {
                        $out[(string) $row['id']] = (string) $row['name'];
                    }

                    return $out;
                }],
                'status' => ['label' => 'Status', 'column' => 't.status', 'options' => ['new' => 'New', 'read' => 'Read']],
            ],
            'sort' => ['created_at', 'desc'],
            'per_page' => 25,
            'export' => false,
        ];
    }

    /**
     * Las dos o tres primeras respuestas de una entrada en una línea.
     *
     * @param array<string,mixed> $row
     */
    public static function summary(App $app, array $row): string
    {
        $parts = [];
        foreach ((new Forms($app))->describe($row) as $item) {
            if ($item['value'] !== '' && count($parts) < 3) {
                $parts[] = $item['value'];
            }
        }
        $text = implode(' · ', $parts);

        return mb_strlen($text) > 110 ? mb_substr($text, 0, 110) . '…' : $text;
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        return ['fields' => [
            'id' => ['type' => 'readonly', 'label' => 'Number'],
            'entry' => ['type' => 'html', 'label' => '', 'html' => static function (array $all, App $app): string {
                return self::ficha($app, isset($all['id']) ? (int) $all['id'] : 0);
            }],
            'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['new' => 'New', 'read' => 'Read'], 'default' => 'new', 'rules' => 'required', 'width' => 'sm'],
        ]];
    }

    /**
     * Los datos de la entrada en una lista de pares, con la etiqueta de cada campo; escapado.
     */
    public static function ficha(App $app, int $id): string
    {
        $entry = $app->db()->row('SELECT e.*, f.name AS form_name FROM {form_entries} e LEFT JOIN {forms} f ON f.id = e.form_id WHERE e.id = ?', [$id]);
        if ($entry === null) {
            return '';
        }
        $html = '<dl class="af-kv"><dt>' . e(t('Form')) . '</dt><dd>' . e((string) $entry['form_name']) . '</dd>'
            . '<dt>' . e(t('Received')) . '</dt><dd>' . e($app->i18n()->date(\Aifec\Kernel\Clock::parse((string) $entry['created_at']), 'medium', $app->site()->timezone())) . '</dd>';
        if (isset($entry['ip']) && $entry['ip'] !== null && $entry['ip'] !== '') {
            $html .= '<dt>' . e(t('Address (IP)')) . '</dt><dd class="af-mono">' . e((string) $entry['ip']) . '</dd>';
        }
        foreach ((new Forms($app))->describe($entry) as $item) {
            $html .= '<dt>' . e($item['label']) . '</dt><dd>' . nl2br(e($item['value']), false) . '</dd>';
        }

        return $html . '</dl>';
    }

    /**
     * @param int[] $ids
     */
    public static function markRead(array $ids, App $app): string
    {
        $done = 0;
        foreach ($ids as $id) {
            $done += $app->db()->update('form_entries', ['status' => 'read'], ['id' => (int) $id]);
        }

        return tn('{n} marked as read', '{n} marked as read', $done);
    }

    /**
     * Todas las entradas (o las de un formulario) en CSV: una columna por campo.
     */
    public static function export(Request $request, App $app): Response
    {
        $app->auth()->requireCap('content.view');
        $formId = $request->int('form_id');
        $db = $app->db();
        $forms = new Forms($app);
        $params = [];
        $where = '';
        if ($formId > 0) {
            $where = ' WHERE form_id = ?';
            $params[] = $formId;
        }
        $entries = $db->select('SELECT * FROM {form_entries}' . $where . ' ORDER BY id LIMIT 20000', $params);
        $columns = [];
        $rows = [];
        foreach ($entries as $entry) {
            $values = [];
            foreach ($forms->describe($entry) as $item) {
                $columns[$item['label']] = true;
                $values[$item['label']] = $item['value'];
            }
            $rows[] = [$entry, $values];
        }
        $header = [t('Number'), t('Form'), t('Received'), t('Status'), t('Address (IP)')];
        foreach (array_keys($columns) as $label) {
            $header[] = (string) $label;
        }
        $names = [];
        foreach ($db->select('SELECT id, name FROM {forms}') as $form) {
            $names[(int) $form['id']] = (string) $form['name'];
        }
        $lines = [];
        foreach ($rows as $pair) {
            $line = [(string) $pair[0]['id'], isset($names[(int) $pair[0]['form_id']]) ? $names[(int) $pair[0]['form_id']] : '', (string) $pair[0]['created_at'], (string) $pair[0]['status'], (string) $pair[0]['ip']];
            foreach (array_keys($columns) as $label) {
                $line[] = isset($pair[1][$label]) ? self::safeCell($pair[1][$label]) : '';
            }
            $lines[] = $line;
        }
        Admin::audit($app, 'export', 'form_entries');
        $response = Response::text("\xEF\xBB\xBF" . Csv::toString($header, $lines, ';'), 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="form-entries-' . gmdate('Ymd', $app->clock()->now()) . '.csv"';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * Una celda que empieza por =, +, - o @ se lee como fórmula en una hoja de cálculo: se le antepone una comilla.
     */
    private static function safeCell(string $value): string
    {
        return $value !== '' && strpos('=+-@', $value[0]) !== false ? "'" . $value : $value;
    }
}
