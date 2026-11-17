<?php
/**
 * AIFEC — formularios declarativos del panel: se describen con un array y esta clase los pinta, los lee y los valida.
 *
 * Definición:
 *   ['tabs' => ['cpgeneral' => 'General', …],          // opcional; claves con las iniciales del módulo
 *    'fields' => ['code' => ['type' => 'text', 'label' => 'Code', 'rules' => 'required|max:40', 'tab' => 'cpgeneral', …]]]
 *
 * Tipos: text, email, url, tel, slug, password, secret, number, money, percent, textarea, code, markdown, richtext,
 * blocks, json, toggle, select, radio, checks, tags, date, datetime, time, color, hidden, image, images, file,
 * relation, repeater, html, heading, readonly.
 *
 * Opciones de un campo: label, hint, placeholder, default, rules, tab, width (sm|md), prefix, suffix, rows, options
 * (valor => texto, o función), options_from (['table','value','label','where','order']), empty (texto de «ninguno»),
 * i18n (true = un valor por idioma), column (columna si no se llama igual), store (false = no se guarda), cap
 * (permiso para verlo), show_if (['campo' => valor o lista]), fields (subcampos de un repeater), resource (relation),
 * decimal (number con decimales), scale (percent: 100 = guarda centésimas enteras), min, max, step, maxlength, attrs,
 * seeded (text: el valor puede ser un nombre de serie en inglés; el campo enseña t(valor) mientras ese valor tenga traducción,
 * como el listado, y al guardar se guarda lo que haya escrito).
 *
 * Los nombres de los campos viajan como f[nombre]; un campo por idioma, como f[nombre][es].
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Text\HtmlSanitizer;
use Aifec\Validation\Validator;

final class Form
{
    private const NO_VALUE = ['html', 'heading', 'readonly'];
    private const JSON_TYPES = ['checks', 'tags', 'repeater', 'images', 'blocks', 'json'];

    /** @var App */
    private $app;

    /** @var array<string,array<string,mixed>> */
    private $fields = [];

    /** @var array<string,string> clave => texto */
    private $tabs = [];

    /** @var string */
    private $prefix;

    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(App $app, array $definition, string $prefix = 'f')
    {
        $this->app = $app;
        $this->prefix = $prefix;
        foreach (isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [] as $key => $tab) {
            $this->tabs[(string) $key] = is_array($tab) ? (string) (isset($tab['label']) ? $tab['label'] : $key) : (string) $tab;
        }
        $firstTab = $this->tabs !== [] ? (string) array_keys($this->tabs)[0] : '';
        foreach (isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [] as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            $field += ['type' => 'text', 'label' => '', 'rules' => '', 'tab' => $firstTab];
            if ($this->tabs !== [] && !isset($this->tabs[(string) $field['tab']])) {
                $field['tab'] = $firstTab;
            }
            $this->fields[(string) $name] = $field;
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string,string>
     */
    public function tabs(): array
    {
        return $this->tabs;
    }

    /**
     * Valores de partida de un alta.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        $values = [];
        foreach ($this->fields as $name => $f) {
            if (array_key_exists('default', $f)) {
                $values[$name] = $f['default'];
            }
        }

        return $values;
    }

    // ------------------------------------------------------------------ fila de la base ↔ valores del formulario

    /**
     * Convierte una fila de la base en valores del formulario (descodifica las columnas JSON).
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function load(array $row): array
    {
        $values = [];
        foreach ($this->fields as $name => $f) {
            if (in_array($f['type'], ['html', 'heading'], true) || !empty($f['i18n'])) {
                continue;
            }
            $column = isset($f['column']) ? (string) $f['column'] : $name;
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $value = $row[$column];
            if (in_array($f['type'], self::JSON_TYPES, true) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Convierte los valores validados en columnas para guardar (codifica JSON; deja fuera lo que no se guarda aquí).
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    public function store(array $values): array
    {
        $row = [];
        foreach ($this->fields as $name => $f) {
            if (!array_key_exists($name, $values) || in_array($f['type'], self::NO_VALUE, true) || $f['type'] === 'file'
                || !empty($f['i18n']) || (isset($f['store']) && $f['store'] === false)) {
                continue;
            }
            $value = $values[$name];
            if (in_array($f['type'], self::JSON_TYPES, true)) {
                $value = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $row[isset($f['column']) ? (string) $f['column'] : $name] = $value;
        }

        return $row;
    }

    // ------------------------------------------------------------------ leer y validar

    /**
     * Lee lo enviado y lo valida.
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} [valores limpios, errores por campo]
     */
    public function validate(Request $request): array
    {
        $in = $request->arr($this->prefix);
        $validator = new Validator($this->app->db());
        $values = [];
        $errors = [];
        foreach ($this->fields as $name => $f) {
            if (in_array($f['type'], self::NO_VALUE, true) || !$this->visible($f)) {
                continue;
            }
            $raw = isset($in[$name]) ? $in[$name] : null;
            $error = null;
            if ($f['type'] === 'file') {
                $value = $this->upload($request, $name);
                if ($value === null && in_array('required', $this->rules($f), true)) {
                    $error = t('Choose a file');
                }
            } elseif (!empty($f['i18n'])) {
                $value = $this->readI18n($f, $raw, $in, $validator, $error);
            } elseif ($f['type'] === 'repeater') {
                $value = $this->readRepeater($f, $raw, $validator, $error);
            } else {
                $value = $this->read($f, $raw, $in, $validator, $error);
            }
            if (in_array($f['type'], ['password', 'secret'], true) && ($value === '' || $value === null) && $error === null) {
                // Vacío = no se cambia.
                continue;
            }
            $values[$name] = $value;
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }

        return [$values, $errors];
    }

    /**
     * @param array<string,mixed> $f
     *
     * @return string[]
     */
    private function rules(array $f): array
    {
        $rules = is_array($f['rules']) ? $f['rules'] : ($f['rules'] === '' ? [] : explode('|', (string) $f['rules']));
        switch ($f['type']) {
            case 'toggle':
                array_unshift($rules, 'bool');
                break;
            case 'number':
                array_unshift($rules, !empty($f['decimal']) ? 'decimal' : 'int');
                break;
            case 'money':
                array_unshift($rules, 'money');
                break;
            case 'percent':
                array_unshift($rules, 'number');
                break;
            case 'slug':
                // Un slug que es un código interno ('code' => true) admite también guiones bajos.
                array_unshift($rules, !empty($f['code']) ? 'code' : 'slug');
                break;
            case 'email':
            case 'url':
            case 'date':
            case 'datetime':
            case 'time':
            case 'color':
                array_unshift($rules, (string) $f['type']);
                break;
            case 'tel':
                array_unshift($rules, 'phone');
                break;
            case 'image':
            case 'relation':
                array_unshift($rules, 'int');
                break;
            case 'checks':
            case 'tags':
            case 'images':
                array_unshift($rules, 'array');
                break;
            case 'password':
            case 'secret':
            case 'code':
            case 'markdown':
            case 'richtext':
            case 'textarea':
                array_unshift($rules, 'raw');
                break;
            case 'json':
            case 'blocks':
                array_unshift($rules, 'json');
                break;
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string,mixed> $f
     * @param mixed               $raw
     * @param array<string,mixed> $in
     *
     * @return mixed
     */
    private function read(array $f, $raw, array $in, Validator $validator, ?string &$error)
    {
        $type = (string) $f['type'];
        if ($type === 'tags' && is_string($raw)) {
            $raw = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), 'strlen')));
        }
        if ($type === 'images' && is_string($raw)) {
            $raw = array_values(array_filter(array_map('intval', explode(',', $raw))));
        }
        if (in_array($type, ['textarea', 'code', 'markdown', 'richtext'], true) && is_string($raw)) {
            $raw = str_replace("\r\n", "\n", $raw);
        }
        if ($type === 'blocks' && is_string($raw) && trim($raw) !== '' && !in_array(substr(ltrim($raw), 0, 1), ['[', '{'], true)) {
            // Sin el editor de bloques (sin JavaScript, o un lector de pantalla) llega texto, no JSON: cada párrafo, un bloque de
            // párrafo (14/11/2026: «Esto no es JSON válido» a quien solo escribió su texto).
            $blocks = [];
            foreach (preg_split('/\n\s*\n/u', str_replace("\r\n", "\n", trim($raw))) ?: [] as $paragraph) {
                if (trim($paragraph) !== '') {
                    $blocks[] = ['type' => 'paragraph', 'attrs' => ['html' => nl2br(e(trim($paragraph)), false)]];
                }
            }
            $raw = (string) json_encode($blocks, JSON_UNESCAPED_UNICODE);
        }
        $value = $validator->field($raw, $this->rules($f), $in, $error);
        if ($error !== null) {
            return $value;
        }
        if (in_array($type, ['select', 'radio', 'checks'], true) && empty($f['free'])) {
            $options = $this->options($f);
            foreach (is_array($value) ? $value : ($value === '' || $value === null ? [] : [$value]) as $one) {
                if (!is_scalar($one) || !array_key_exists((string) $one, $options)) {
                    $error = t('Choose one of the options');

                    return $value;
                }
            }
            if ($type === 'checks' && is_array($value)) {
                $value = array_values(array_map('strval', $value));
            }
        }
        if ($type === 'images' && is_array($value)) {
            $value = array_values(array_unique(array_filter(array_map('intval', $value))));
        }
        if ($type === 'richtext' && is_string($value)) {
            $value = HtmlSanitizer::clean($value, 'content');
        }
        if ($type === 'blocks' && is_array($value) && class_exists('Aifec\\Cms\\Blocks\\BlockSchema')) {
            $value = \Aifec\Cms\Blocks\BlockSchema::validate($value);
        }
        if ($type === 'percent' && $value !== null && isset($f['scale'])) {
            $value = (int) round((float) $value * (int) $f['scale']);
        }
        if ($type === 'datetime' && is_string($value) && $value !== '') {
            $value = $this->toUtc($value);
        }
        if ($value === '' && in_array($type, ['select', 'radio'], true) && !empty($f['null_empty'])) {
            $value = null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $f
     * @param mixed               $raw
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed> idioma => valor
     */
    private function readI18n(array $f, $raw, array $in, Validator $validator, ?string &$error): array
    {
        $out = [];
        $default = $this->app->defaultLang();
        foreach ($this->app->languages() as $lang) {
            $one = $f;
            if ($lang !== $default) {
                // Solo el idioma principal es obligatorio; los demás pueden quedar vacíos.
                $one['rules'] = array_values(array_diff($this->rules($f), ['required']));
            }
            $problem = null;
            $out[$lang] = $this->read($one, is_array($raw) && isset($raw[$lang]) ? $raw[$lang] : null, $in, $validator, $problem);
            if ($problem !== null && $error === null) {
                $error = count($this->app->languages()) > 1 ? strtoupper($lang) . ': ' . $problem : $problem;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $f
     * @param mixed               $raw
     *
     * @return array<int,array<string,mixed>>
     */
    private function readRepeater(array $f, $raw, Validator $validator, ?string &$error): array
    {
        $rows = [];
        $sub = new self($this->app, ['fields' => isset($f['fields']) && is_array($f['fields']) ? $f['fields'] : []]);
        $n = 0;
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n++;
            $clean = [];
            $filled = false;
            foreach ($sub->fields as $name => $sf) {
                $problem = null;
                $clean[$name] = $sub->read($sf, isset($row[$name]) ? $row[$name] : null, $row, $validator, $problem);
                if ($problem !== null && $error === null) {
                    $error = t('Row {n}: {error}', ['n' => $n, 'error' => t((string) $sf['label']) . ' — ' . $problem]);
                }
                $isEmpty = $clean[$name] === '' || $clean[$name] === null || $clean[$name] === [] || ($sf['type'] === 'toggle' && !$clean[$name]);
                $filled = $filled || !$isEmpty;
            }
            if ($filled) {
                $rows[] = $clean;
            } elseif ($error !== null && strpos($error, (string) $n) !== false) {
                // Una fila vacía no cuenta ni da error.
                $error = null;
            }
        }
        $rules = $this->rules($f);
        if ($rows === [] && in_array('required', $rules, true)) {
            $error = t('Add at least one row');
        }

        return $rows;
    }

    /**
     * Fichero subido en el campo file_<nombre>, o null.
     *
     * @return array{name:string,tmp_name:string,size:int,type:string}|null
     */
    private function upload(Request $request, string $name): ?array
    {
        $key = 'file_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $file = isset($request->files[$key]) && is_array($request->files[$key]) ? $request->files[$key] : null;
        if ($file === null || !isset($file['tmp_name'], $file['error']) || (int) $file['error'] !== 0 || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            return null;
        }

        return ['name' => (string) $file['name'], 'tmp_name' => $file['tmp_name'], 'size' => (int) $file['size'], 'type' => isset($file['type']) ? (string) $file['type'] : ''];
    }

    // ------------------------------------------------------------------ pintar

    /**
     * Formulario completo: pestañas, campos, ficha y botones.
     *
     * $options: action, submit (texto), cancel (dirección), tab (pestaña activa), delete (dirección de borrado),
     * side (HTML de la columna derecha), extra (HTML junto a los botones), hidden (campos ocultos nombre => valor),
     * attrs (atributos del <form>).
     *
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     * @param array<string,mixed>  $options
     */
    public function render(array $values, array $errors = [], array $options = []): string
    {
        $o = $options + ['action' => '', 'submit' => t('Save'), 'cancel' => '', 'tab' => '', 'delete' => '', 'side' => '', 'extra' => '', 'hidden' => [], 'attrs' => []];
        $multipart = false;
        foreach ($this->fields as $f) {
            $multipart = $multipart || $f['type'] === 'file';
        }
        $formAttrs = (array) $o['attrs'] + ['class' => 'af-form', 'method' => 'post', 'action' => (string) $o['action'], 'novalidate' => true, 'data-af-dirty' => true, 'accept-charset' => 'UTF-8'];
        if ($multipart) {
            $formAttrs['enctype'] = 'multipart/form-data';
        }

        // Pestaña activa: la primera con errores; si no, la pedida; si no, la primera.
        $active = '';
        $perTab = [];
        foreach ($errors as $name => $message) {
            if (isset($this->fields[$name])) {
                $tab = (string) $this->fields[$name]['tab'];
                $perTab[$tab] = (isset($perTab[$tab]) ? $perTab[$tab] : 0) + 1;
                $active = $active === '' ? $tab : $active;
            }
        }
        if ($active === '' && isset($this->tabs[(string) $o['tab']])) {
            $active = (string) $o['tab'];
        }
        if ($active === '' && $this->tabs !== []) {
            $active = (string) array_keys($this->tabs)[0];
        }

        $html = '<form' . attrs($formAttrs) . '>' . Admin::tokenField($this->app);
        $html .= '<input type="hidden" name="_tab" value="' . e($active) . '" data-af-tab-input>';
        foreach ((array) $o['hidden'] as $name => $value) {
            $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
        }
        if ($errors !== []) {
            $html .= '<div class="af-alert af-alert-bad" role="alert">' . e(tn('There is {n} field to review', 'There are {n} fields to review', count($errors))) . '</div>';
        }
        $body = $this->renderFields($values, $errors, $active, $perTab);
        if ((string) $o['side'] !== '') {
            $html .= '<div class="af-grid"><div class="af-col-8">' . $body . '</div><div class="af-col-4 af-stack">' . $o['side'] . '</div></div>';
        } else {
            $html .= $body;
        }
        $html .= '<div class="af-form-actions"><button type="submit" class="af-btn af-btn-primary">' . Admin::icon('check') . e((string) $o['submit']) . '</button>';
        if ((string) $o['cancel'] !== '') {
            $html .= '<a class="af-btn" href="' . e($o['cancel']) . '">' . e(t('Cancel')) . '</a>';
        }
        $html .= (string) $o['extra'] . '<span class="af-top-space"></span>';
        if ((string) $o['delete'] !== '') {
            $html .= '<button type="submit" class="af-btn af-btn-danger" formaction="' . e($o['delete']) . '" data-af-confirm="' . e(t('Delete this? It cannot be undone.')) . '">' . Admin::icon('trash') . e(t('Delete')) . '</button>';
        }

        return $html . '</div></form>';
    }

    /**
     * Solo las pestañas y los campos, sin <form> ni botones (para pantallas que montan su propio formulario).
     *
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     * @param array<string,int>    $errorsPerTab
     */
    public function renderFields(array $values, array $errors = [], string $activeTab = '', array $errorsPerTab = []): string
    {
        $byTab = [];
        foreach ($this->fields as $name => $f) {
            if (!$this->visible($f)) {
                continue;
            }
            $value = array_key_exists($name, $values) ? $values[$name] : (array_key_exists('default', $f) ? $f['default'] : null);
            $byTab[(string) $f['tab']][] = $this->field($name, $f, $value, isset($errors[$name]) ? $errors[$name] : null, $values);
        }
        if ($this->tabs === []) {
            return '<div class="af-card"><div class="af-card-body">' . implode('', isset($byTab['']) ? $byTab[''] : []) . '</div></div>';
        }
        if ($activeTab === '' || !isset($this->tabs[$activeTab])) {
            $activeTab = (string) array_keys($this->tabs)[0];
        }
        $nav = '<div class="af-tabs" role="tablist" data-af-tabs data-af-active="' . e($activeTab) . '">';
        $panels = '';
        foreach ($this->tabs as $key => $label) {
            if (!isset($byTab[$key])) {
                continue;
            }
            $selected = $key === $activeTab;
            $badge = isset($errorsPerTab[$key]) ? ' <span class="af-badge af-badge-bad">' . (int) $errorsPerTab[$key] . '</span>' : '';
            $nav .= '<button type="button" class="af-tab" role="tab" data-af-tab="' . e($key) . '" aria-selected="' . ($selected ? 'true' : 'false') . '">' . e(t($label)) . $badge . '</button>';
            $panels .= '<div class="af-tabpanel" role="tabpanel" data-af-panel="' . e($key) . '"' . ($selected ? '' : ' hidden') . '>' . implode('', $byTab[$key]) . '</div>';
        }

        return $nav . '</div><div class="af-card"><div class="af-card-body">' . $panels . '</div></div>';
    }

    /**
     * @param array<string,mixed> $f
     * @param mixed               $value
     * @param array<string,mixed> $all
     */
    private function field(string $name, array $f, $value, ?string $error, array $all): string
    {
        $type = (string) $f['type'];
        if ($type === 'hidden') {
            return '<input type="hidden" name="' . e($this->name($name)) . '" value="' . e(is_scalar($value) ? $value : '') . '">';
        }
        if ($type === 'heading') {
            return '<div class="af-field af-field-wide"><div><h3>' . e(t((string) $f['label'])) . '</h3>' . (isset($f['hint']) ? '<p class="af-hint">' . e(t((string) $f['hint'])) . '</p>' : '') . '</div></div>';
        }
        if ($type === 'html') {
            $html = isset($f['html']) ? $f['html'] : '';
            $html = $html instanceof \Closure ? (string) $html($all, $this->app) : (string) $html;
            if ((string) $f['label'] === '') {
                return '<div class="af-field af-field-wide"><div class="af-control">' . $html . '</div></div>';
            }

            return '<div class="af-field"><div class="af-label">' . e(t((string) $f['label'])) . '</div><div class="af-control">' . $html . '</div></div>';
        }
        $id = $this->id($name);
        $required = in_array('required', $this->rules($f), true);
        // Control propio: un módulo que necesita algo que ningún tipo pinta (un selector con búsqueda, por ejemplo) da una función
        // (nombre del campo, identificador, valor, App, valores del formulario): string. La validación y el resto del campo siguen siendo los del tipo.
        $custom = isset($f['render']) && $f['render'] instanceof \Closure ? (string) $f['render']($this->name($name), $id, $value, $this->app, $all) : null;
        if ($custom !== null) {
            $control = $custom;
        } elseif (!empty($f['i18n'])) {
            $control = $this->i18nControl($name, $f, is_array($value) ? $value : [], $id);
        } else {
            $control = $this->control($this->name($name), $id, $f, $value);
        }
        $wide = in_array($type, ['richtext', 'blocks', 'repeater'], true) || !empty($f['wide']);
        $attrs = ['class' => 'af-field' . ($wide ? ' af-field-wide' : '') . ($error !== null ? ' af-field-invalid' : ''), 'data-af-field' => $name];
        if (isset($f['show_if']) && is_array($f['show_if'])) {
            $attrs['data-af-show-if'] = json_encode($f['show_if'], JSON_UNESCAPED_UNICODE);
        }
        $label = in_array($type, ['toggle', 'radio', 'checks', 'readonly', 'repeater', 'image', 'images'], true)
            ? '<div class="af-label">' . e(t((string) $f['label'])) . ($required ? '<span class="af-req" aria-hidden="true">*</span>' : '') . '</div>'
            : '<label class="af-label" for="' . e($id) . '">' . e(t((string) $f['label'])) . ($required ? '<span class="af-req" aria-hidden="true">*</span>' : '') . '</label>';

        return '<div' . attrs($attrs) . '>' . $label . '<div class="af-control">' . $control
            . (isset($f['hint']) && $f['hint'] !== '' ? '<div class="af-hint">' . e(t((string) $f['hint'])) . '</div>' : '')
            . ($error !== null ? '<div class="af-error" role="alert">' . e($error) . '</div>' : '')
            . '</div></div>';
    }

    /**
     * @param array<string,mixed> $f
     * @param array<string,mixed> $value idioma => valor
     */
    private function i18nControl(string $name, array $f, array $value, string $id): string
    {
        $langs = $this->app->languages();
        $one = $f;
        unset($one['i18n']);
        if (count($langs) < 2) {
            $lang = $this->app->defaultLang();

            return $this->control($this->name($name) . '[' . $lang . ']', $id, $one, isset($value[$lang]) ? $value[$lang] : null);
        }
        $html = '<div class="af-i18n" data-af-langs><div class="af-langs" role="group" aria-label="' . e(t('Language')) . '">';
        foreach ($langs as $i => $lang) {
            $html .= '<button type="button" data-af-lang="' . e($lang) . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . e($lang) . '</button>';
        }
        $html .= '</div>';
        foreach ($langs as $i => $lang) {
            $html .= '<div data-af-lang-panel="' . e($lang) . '"' . ($i === 0 ? '' : ' hidden') . '>'
                . $this->control($this->name($name) . '[' . $lang . ']', $i === 0 ? $id : $id . '-' . $lang, $one, isset($value[$lang]) ? $value[$lang] : null) . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * El control de un campo (sin etiqueta).
     *
     * @param array<string,mixed> $f
     * @param mixed               $value
     */
    private function control(string $inputName, string $id, array $f, $value): string
    {
        $type = (string) $f['type'];
        $extra = isset($f['attrs']) && is_array($f['attrs']) ? $f['attrs'] : [];
        $width = isset($f['width']) ? ' af-input-' . $f['width'] : '';
        $base = ['id' => $id, 'name' => $inputName];
        if (isset($f['placeholder'])) {
            $base['placeholder'] = t((string) $f['placeholder']);
        }
        if (isset($f['maxlength'])) {
            $base['maxlength'] = (int) $f['maxlength'];
        }
        $text = is_scalar($value) ? (string) $value : '';

        switch ($type) {
            case 'textarea':
            case 'code':
            case 'markdown':
            case 'richtext':
            case 'json':
            case 'blocks':
                if (is_array($value)) {
                    $text = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                }
                $class = 'af-textarea' . (in_array($type, ['code', 'json', 'markdown', 'blocks'], true) ? ' af-mono' : '');
                $data = ['richtext' => ['data-af-richtext' => true], 'blocks' => ['data-af-blocks' => true], 'markdown' => ['data-af-markdown' => true]];
                $rows = isset($f['rows']) ? (int) $f['rows'] : ($type === 'textarea' ? 4 : 10);

                return '<textarea' . attrs($extra + $base + ['class' => $class, 'rows' => $rows, 'spellcheck' => in_array($type, ['code', 'json', 'blocks'], true) ? 'false' : null] + (isset($data[$type]) ? $data[$type] : [])) . '>' . e($text) . '</textarea>';
            case 'toggle':
                $on = !empty($value);

                return '<label class="af-toggle"><input type="hidden" name="' . e($inputName) . '" value="0"><input type="checkbox"' . attrs($extra + $base + ['value' => '1', 'checked' => $on]) . '><span class="af-toggle-track"></span>'
                    . (isset($f['text']) ? '<span>' . e(t((string) $f['text'])) . '</span>' : '') . '</label>';
            case 'select':
            case 'relation':
                $options = $type === 'relation' ? $this->relationOptions($f, $value) : $this->options($f);
                $html = '<select' . attrs($extra + $base + ['class' => 'af-select' . ($width !== '' ? $width : ' af-input-md')] + ($type === 'relation' && isset($f['resource']) ? ['data-af-relation' => $this->app->url()->plain('/admin/r/' . $f['resource'] . '/search.json')] : [])) . '>';
                if (isset($f['empty']) || $type === 'relation') {
                    $html .= '<option value="">' . e(isset($f['empty']) ? t((string) $f['empty']) : '—') . '</option>';
                }
                foreach ($options as $key => $label) {
                    $html .= '<option value="' . e((string) $key) . '"' . ((string) $key === $text && $value !== null ? ' selected' : '') . '>' . e($this->optionLabel($f, $label)) . '</option>';
                }

                return $html . '</select>';
            case 'radio':
            case 'checks':
                $chosen = is_array($value) ? array_map('strval', $value) : ($value === null ? [] : [$text]);
                $html = '<div class="af-checks" id="' . e($id) . '">';
                foreach ($this->options($f) as $key => $label) {
                    $html .= '<label class="af-check"><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . e($inputName . ($type === 'checks' ? '[]' : '')) . '" value="' . e((string) $key) . '"'
                        . (in_array((string) $key, $chosen, true) ? ' checked' : '') . '><span>' . e($this->optionLabel($f, $label)) . '</span></label>';
                }

                return $html . '</div>';
            case 'tags':
                return '<input' . attrs($extra + $base + ['type' => 'text', 'class' => 'af-input', 'value' => is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : $text, 'data-af-tags' => true, 'autocomplete' => 'off']) . '>';
            case 'money':
                $shown = $value === null || $value === '' ? '' : number_format(((int) $value) / 100, 2, ',', '');

                return '<div class="af-input-group"><input' . attrs($extra + $base + ['type' => 'text', 'inputmode' => 'decimal', 'class' => 'af-input', 'value' => is_int($value) || ctype_digit(ltrim($text, '-')) ? $shown : $text, 'autocomplete' => 'off']) . '>'
                    . '<span class="af-input-addon">' . e(isset($f['suffix']) ? (string) $f['suffix'] : $this->app->settings()->str('shop.currency_symbol', '€')) . '</span></div>';
            case 'percent':
                if ($value !== null && $value !== '' && isset($f['scale']) && is_numeric($value)) {
                    $text = rtrim(rtrim(number_format(((float) $value) / (int) $f['scale'], 4, ',', ''), '0'), ',');
                } elseif (is_float($value)) {
                    $text = str_replace('.', ',', (string) $value);
                }

                return '<div class="af-input-group"><input' . attrs($extra + $base + ['type' => 'text', 'inputmode' => 'decimal', 'class' => 'af-input', 'value' => $text, 'autocomplete' => 'off']) . '><span class="af-input-addon">%</span></div>';
            case 'password':
            case 'secret':
                $has = $text !== '';

                return '<input' . attrs($extra + $base + ['type' => 'password', 'class' => 'af-input af-input-md', 'value' => '', 'autocomplete' => 'new-password', 'placeholder' => $has ? t('Saved. Leave empty to keep it') : (isset($base['placeholder']) ? $base['placeholder'] : null)]) . '>';
            case 'image':
            case 'images':
                return $this->mediaControl($inputName, $id, $type === 'images', $value);
            case 'file':
                return '<input' . attrs($extra + ['id' => $id, 'name' => 'file_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) substr($inputName, strlen($this->prefix) + 1, -1)), 'type' => 'file', 'class' => 'af-input', 'accept' => isset($f['accept']) ? (string) $f['accept'] : null]) . '>';
            case 'repeater':
                return $this->repeaterControl($inputName, $id, $f, is_array($value) ? $value : []);
            case 'readonly':
                return '<div class="af-readonly" id="' . e($id) . '">' . ($text !== '' ? e($text) : '<span class="af-muted">—</span>') . '</div>';
            case 'datetime':
                $text = $text !== '' ? str_replace(' ', 'T', substr($this->toLocal($text), 0, 16)) : '';

                return '<input' . attrs($extra + $base + ['type' => 'datetime-local', 'class' => 'af-input af-input-md', 'value' => $text]) . '>';
            case 'number':
                if (is_float($value)) {
                    $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
                }
                $input = '<input' . attrs($extra + $base + ['type' => 'number', 'class' => 'af-input' . ($width !== '' ? $width : ' af-input-sm'), 'value' => $text,
                    'min' => isset($f['min']) ? $f['min'] : null, 'max' => isset($f['max']) ? $f['max'] : null, 'step' => isset($f['step']) ? $f['step'] : (!empty($f['decimal']) ? 'any' : null)]) . '>';

                return isset($f['suffix']) ? '<div class="af-input-group">' . $input . '<span class="af-input-addon">' . e(t((string) $f['suffix'])) . '</span></div>' : $input;
        }

        // Un nombre de serie (papel, zona…) se guarda en inglés y se traduce al listarlo: el campo enseña lo mismo que el listado.
        // Al guardar se guarda lo que haya en el campo, así que si nadie lo toca ya queda en el idioma del panel.
        if ($type === 'text' && !empty($f['seeded']) && $text !== '') {
            $text = t($text);
        }

        // Entradas de una línea: text, email, url, tel, slug, date, time, color.
        $htmlType = in_array($type, ['email', 'url', 'tel', 'date', 'time', 'color'], true) ? $type : 'text';
        $attrs = $extra + $base + ['type' => $htmlType, 'class' => 'af-input' . ($width !== '' ? $width : (in_array($type, ['date', 'time', 'color'], true) ? ' af-input-sm' : '')), 'value' => $text, 'autocomplete' => 'off'];
        if ($type === 'slug' && isset($f['from'])) {
            $attrs['data-af-slug-from'] = $this->id((string) $f['from']);
        }
        $input = '<input' . attrs($attrs) . '>';
        if (isset($f['prefix']) || isset($f['suffix'])) {
            return '<div class="af-input-group af-input-group-wide">' . (isset($f['prefix']) ? '<span class="af-input-addon af-input-addon-pre">' . e((string) $f['prefix']) . '</span>' : '') . $input
                . (isset($f['suffix']) ? '<span class="af-input-addon">' . e(t((string) $f['suffix'])) . '</span>' : '') . '</div>';
        }

        return $input;
    }

    /**
     * @param mixed $value un identificador de la biblioteca de medios, o una lista
     */
    private function mediaControl(string $inputName, string $id, bool $multiple, $value): string
    {
        $ids = array_values(array_filter(array_map('intval', is_array($value) ? $value : ($value ? [$value] : []))));
        $html = '<div class="af-media" id="' . e($id) . '" data-af-media data-multiple="' . ($multiple ? '1' : '0') . '" data-picker="' . e($this->app->url()->plain('/admin/media/picker')) . '">'
            . '<input type="hidden" name="' . e($inputName) . '" value="' . e(implode(',', $ids)) . '" data-af-media-input><div class="af-thumbs" data-af-media-list>';
        $library = $this->app->has('media') ? $this->app->service('media') : null;
        foreach ($ids as $mediaId) {
            $src = $library !== null ? (string) $library->url($mediaId, 'thumb') : '';
            $html .= '<div class="af-thumb-item" data-id="' . $mediaId . '">' . ($src !== '' ? '<img class="af-thumb" src="' . e($src) . '" alt="" loading="lazy">' : '<span class="af-thumb"></span>')
                . '<button type="button" data-af-media-remove aria-label="' . e(t('Remove')) . '">×</button></div>';
        }

        return $html . '</div><button type="button" class="af-btn af-btn-sm" data-af-media-pick>' . Admin::icon('image') . e($multiple ? t('Add images') : t('Choose image')) . '</button></div>';
    }

    /**
     * @param array<string,mixed>            $f
     * @param array<int,array<string,mixed>> $rows
     */
    private function repeaterControl(string $inputName, string $id, array $f, array $rows): string
    {
        $sub = new self($this->app, ['fields' => isset($f['fields']) && is_array($f['fields']) ? $f['fields'] : []]);
        $row = function ($index, array $values) use ($sub, $inputName, $id): string {
            $html = '<div class="af-repeater-row" data-af-row><span class="af-drag" data-af-drag title="' . e(t('Drag to reorder')) . '">' . Admin::icon('drag') . '</span><div class="af-repeater-fields">';
            foreach ($sub->fields as $name => $sf) {
                $control = $sub->control($inputName . '[' . $index . '][' . $name . ']', $id . '-' . $index . '-' . $name, $sf, array_key_exists($name, $values) ? $values[$name] : (array_key_exists('default', $sf) ? $sf['default'] : null));
                $html .= $sf['type'] === 'hidden' ? $control : '<label class="af-repeater-cell"><span class="af-small af-muted">' . e(t((string) $sf['label'])) . '</span>' . $control . '</label>';
            }

            return $html . '</div><button type="button" class="af-icon-btn" data-af-remove aria-label="' . e(t('Remove row')) . '">' . Admin::icon('x') . '</button></div>';
        };
        $html = '<div class="af-repeater" id="' . e($id) . '" data-af-repeater><div data-af-rows>';
        foreach (array_values($rows) as $i => $values) {
            $html .= $row($i, is_array($values) ? $values : []);
        }

        return $html . '</div><template data-af-template>' . $row('__i__', []) . '</template><button type="button" class="af-btn af-btn-sm" data-af-add>' . Admin::icon('plus') . e(isset($f['add']) ? t((string) $f['add']) : t('Add row')) . '</button></div>';
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,mixed> $f
     *
     * @return array<int|string,mixed> valor => texto
     */
    public function options(array $f): array
    {
        if (isset($f['options'])) {
            $options = $f['options'];
            if ($options instanceof \Closure) {
                $options = $options($this->app);
            } elseif (is_array($options) && count($options) === 2 && isset($options[0], $options[1]) && is_string($options[0]) && is_string($options[1]) && strpos($options[0], '\\') !== false && is_callable($options)) {
                $options = call_user_func($options, $this->app);
            }

            return is_array($options) ? $options : [];
        }
        if (isset($f['options_from']) && is_array($f['options_from'])) {
            $from = $f['options_from'] + ['value' => 'id', 'label' => 'name', 'where' => '', 'order' => '', 'params' => []];
            $db = $this->app->db();
            $sql = 'SELECT ' . $db->ident((string) $from['value']) . ' AS v, ' . $db->ident((string) $from['label']) . ' AS l FROM ' . $db->table((string) $from['table'])
                . ($from['where'] !== '' ? ' WHERE ' . $from['where'] : '') . ' ORDER BY ' . ($from['order'] !== '' ? $from['order'] : $db->ident((string) $from['label'])) . ' LIMIT 500';
            $options = [];
            foreach ($db->select($sql, (array) $from['params']) as $row) {
                $options[(string) $row['v']] = (string) $row['l'];
            }

            return $options;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $f
     * @param mixed               $label
     */
    private function optionLabel(array $f, $label): string
    {
        if ($f['type'] === 'relation' || (isset($f['translate']) && $f['translate'] === false)) {
            return (string) $label;
        }

        // Lo que viene de una tabla no se traduce, salvo que se pida (nombres sembrados en inglés: papeles, estados…).
        return isset($f['options_from']) && empty($f['translate']) ? (string) $label : t((string) $label);
    }

    /**
     * En una relación solo se pinta la opción elegida; el resto lo trae el buscador.
     *
     * @param array<string,mixed> $f
     * @param mixed               $value
     *
     * @return array<string,string>
     */
    private function relationOptions(array $f, $value): array
    {
        if ($value === null || $value === '' || !isset($f['resource'])) {
            return [];
        }
        $resource = Admin::resource($this->app, (string) $f['resource']);
        if ($resource === null || !isset($resource['table'])) {
            return [(string) $value => '#' . $value];
        }
        $key = isset($resource['key']) ? (string) $resource['key'] : 'id';
        $labelField = isset($resource['label_field']) ? (string) $resource['label_field'] : 'name';
        $row = $this->app->db()->find((string) $resource['table'], [$key => $value]);

        return [(string) $value => $row !== null && isset($row[$labelField]) ? (string) $row[$labelField] : '#' . $value];
    }

    /**
     * @param array<string,mixed> $f
     */
    private function visible(array $f): bool
    {
        return !isset($f['cap']) || $this->app->auth()->can((string) $f['cap']);
    }

    private function name(string $field): string
    {
        return $this->prefix . '[' . $field . ']';
    }

    private function id(string $field): string
    {
        return $this->prefix . '-' . trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $field), '-');
    }

    /** Hora local del sitio («Y-m-d H:i:s») → UTC. */
    private function toUtc(string $local): string
    {
        try {
            $d = new \DateTime($local, new \DateTimeZone($this->app->site()->timezone()));
            $d->setTimezone(new \DateTimeZone('UTC'));

            return $d->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $local;
        }
    }

    /** UTC → hora local del sitio. */
    private function toLocal(string $utc): string
    {
        try {
            $d = new \DateTime($utc, new \DateTimeZone('UTC'));
            $d->setTimezone(new \DateTimeZone($this->app->site()->timezone()));

            return $d->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $utc;
        }
    }
}
