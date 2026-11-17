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

use Aifec\Kernel\App;
use Aifec\Util\Str;

/**
 * Formularios del comerciante: la definición, la validación de lo que envía una persona, el guardado de la entrada y el
 * HTML del bloque «form».
 *
 * Un campo: `key` (a-z, 0-9 y guion bajo), `type` (text, email, tel, textarea, select, checkbox, consent), `label`,
 * `required` y `options` (solo para select). La casilla de consentimiento es siempre obligatoria. Lo que llega se limpia:
 * sin caracteres de control, con el largo acotado y los saltos de línea normalizados; NUNCA se confía en el HTML.
 */
final class Forms
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'consent'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ definición

    /**
     * @param int|string $idOrCode identificador o código
     *
     * @return array<string,mixed>|null
     */
    public function get($idOrCode): ?array
    {
        $db = $this->app->db();
        if (is_int($idOrCode) || (is_string($idOrCode) && ctype_digit($idOrCode))) {
            $row = $db->row('SELECT * FROM {forms} WHERE id = ?', [(int) $idOrCode]);
        } elseif (is_string($idOrCode) && $idOrCode !== '') {
            $row = $db->row('SELECT * FROM {forms} WHERE code = ?', [$idOrCode]);
        } else {
            $row = null;
        }
        if ($row === null) {
            return null;
        }
        $fields = json_decode(isset($row['fields']) && $row['fields'] !== null ? (string) $row['fields'] : '[]', true);
        $settings = json_decode(isset($row['settings']) && $row['settings'] !== null ? (string) $row['settings'] : '{}', true);
        $settings = is_array($settings) ? $settings : [];

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'fields' => self::cleanFields($fields),
            'settings' => [
                'notify_to' => isset($settings['notify_to']) && is_string($settings['notify_to']) ? $settings['notify_to'] : '',
                'thanks' => isset($settings['thanks']) && is_string($settings['thanks']) ? $settings['thanks'] : '',
            ],
        ];
    }

    /**
     * Deja la lista de campos como se guarda y se pinta: claves válidas y sin repetir, tipos conocidos, opciones solo donde valen.
     *
     * @param mixed $raw
     *
     * @return array<int,array{key:string,type:string,label:string,required:int,options:string[]}>
     */
    public static function cleanFields($raw): array
    {
        $out = [];
        $used = [];
        foreach (is_array($raw) ? $raw : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = isset($field['type']) && is_string($field['type']) && in_array($field['type'], self::TYPES, true) ? $field['type'] : 'text';
            $label = isset($field['label']) && is_string($field['label']) ? trim(self::clean($field['label'])) : '';
            if (function_exists('mb_substr')) {
                $label = mb_substr($label, 0, 120);
            }
            $key = isset($field['key']) && is_string($field['key']) ? strtolower(trim($field['key'])) : '';
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                $key = str_replace('-', '_', Str::slug($label, 30));
                if (preg_match('/^[a-z]/', $key) !== 1) {
                    $key = 'field_' . ($key !== '' ? $key : (string) (count($out) + 1));
                }
            }
            $base = $key;
            for ($n = 2; isset($used[$key]); ++$n) {
                $key = substr($base, 0, 36) . '_' . $n;
            }
            $used[$key] = true;
            $options = [];
            if ($type === 'select') {
                $list = isset($field['options']) ? $field['options'] : [];
                if (is_string($list)) {
                    $list = preg_split('/\R/', $list);
                }
                foreach (is_array($list) ? $list : [] as $option) {
                    $option = is_string($option) ? trim(self::clean($option)) : '';
                    if ($option !== '' && !in_array($option, $options, true) && count($options) < 50) {
                        $options[] = $option;
                    }
                }
            }
            $out[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label !== '' ? $label : $key,
                'required' => $type === 'consent' || !empty($field['required']) ? 1 : 0,
                'options' => $options,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ lo que envía una persona

    /**
     * @param array<string,mixed> $form  lo que da get()
     * @param array<string,mixed> $input los campos enviados, por clave
     *
     * @return array{0:array<string,string>,1:array<string,string>} [valores limpios, errores]
     */
    public function validate(array $form, array $input): array
    {
        $clean = [];
        $errors = [];
        foreach (isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [] as $field) {
            $key = (string) $field['key'];
            $type = (string) $field['type'];
            $required = !empty($field['required']) || $type === 'consent';
            $raw = array_key_exists($key, $input) ? $input[$key] : null;

            if ($type === 'checkbox' || $type === 'consent') {
                $on = is_scalar($raw) && (string) $raw !== '' && (string) $raw !== '0';
                $clean[$key] = $on ? '1' : '';
                if ($required && !$on) {
                    $errors[$key] = t('This field is required');
                }
                continue;
            }
            if ($raw !== null && !is_scalar($raw)) {
                $clean[$key] = '';
                $errors[$key] = t('This value is not valid');
                continue;
            }
            $value = $raw === null ? '' : self::clean((string) $raw, $type === 'textarea');
            $value = trim($value);
            $clean[$key] = $value;
            if ($value === '') {
                if ($required) {
                    $errors[$key] = t('This field is required');
                }
                continue;
            }
            $limit = $type === 'textarea' ? 5000 : ($type === 'email' ? 191 : ($type === 'tel' ? 25 : 300));
            if (mb_strlen($value) > $limit) {
                $clean[$key] = mb_substr($value, 0, $limit);
                $errors[$key] = t('This text is too long');
                continue;
            }
            if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$key] = t('Enter a valid email address');
            } elseif ($type === 'tel' && preg_match('/^[0-9 +().\-]{6,25}$/', $value) !== 1) {
                $errors[$key] = t('Enter a valid phone number');
            } elseif ($type === 'select' && !in_array($value, isset($field['options']) && is_array($field['options']) ? $field['options'] : [], true)) {
                $errors[$key] = t('Choose one of the options');
            }
        }

        return [$clean, $errors];
    }

    /**
     * Guarda una entrada recibida (estado «new») y devuelve su identificador.
     *
     * @param array<string,string> $data valores limpios por clave
     */
    public function store(int $formId, array $data, string $ip = ''): int
    {
        if ($this->get($formId) === null) {
            throw new \InvalidArgumentException('Unknown form');
        }
        $row = [
            'form_id' => $formId,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'new',
            'created_at' => $this->app->clock()->sql(),
        ];
        if ($ip !== '') {
            $row['ip'] = substr($ip, 0, 45);
        }

        return $this->app->db()->insert('form_entries', $row);
    }

    /**
     * Los datos de una entrada, en el orden y con las etiquetas del formulario: [['label','value']]. Lo que el formulario ya no
     * tiene se enseña igualmente, con su clave.
     *
     * @param array<string,mixed> $entry fila de form_entries
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function describe(array $entry): array
    {
        $data = json_decode(isset($entry['data']) ? (string) $entry['data'] : '{}', true);
        $data = is_array($data) ? $data : [];
        $form = $this->get((int) $entry['form_id']);
        $out = [];
        $seen = [];
        foreach ($form !== null ? $form['fields'] : [] as $field) {
            $key = (string) $field['key'];
            $seen[$key] = true;
            $value = isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
            if ($field['type'] === 'checkbox' || $field['type'] === 'consent') {
                $value = $value !== '' ? t('Yes') : t('No');
            }
            $out[] = ['label' => t((string) $field['label']), 'value' => $value];
        }
        foreach ($data as $key => $value) {
            if (!isset($seen[(string) $key]) && is_scalar($value)) {
                $out[] = ['label' => (string) $key, 'value' => (string) $value];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ el bloque

    /**
     * HTML del bloque «form». Lleva el campo trampa y la marca vacías (el JavaScript pide la marca al enfocar un campo: la
     * página se puede guardar en caché porque no lleva nada personal). Si llega `?enviado=1` enseña el texto de gracias.
     *
     * @param array<string,mixed> $attrs atributos del bloque: form_id (identificador o código)
     */
    public function block(array $attrs): string
    {
        $form = $this->get(isset($attrs['form_id']) && (is_int($attrs['form_id']) || is_string($attrs['form_id'])) ? $attrs['form_id'] : '');
        if ($form === null || $form['fields'] === []) {
            return '';
        }
        $code = (string) $form['code'];
        $anchor = 'form-' . $code;
        $sent = $this->app->request()->get('enviado') === '1';
        $state = $this->app->hooks()->apply('cms.form.state', ['sent' => $sent, 'errors' => [], 'old' => []], $form, $this->app);
        $state = is_array($state) ? $state : [];
        $errors = isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : [];
        $old = isset($state['old']) && is_array($state['old']) ? $state['old'] : [];

        if (!empty($state['sent'])) {
            $thanks = $form['settings']['thanks'] !== '' ? $form['settings']['thanks'] : 'Thank you. We have received your message and we will answer soon.';

            return '<div class="af-alert af-alert-ok" role="status" id="' . e($anchor) . '">' . e(t($thanks)) . '</div>';
        }

        $html = '<form class="af-form-block" method="post" id="' . e($anchor) . '" action="' . e($this->app->url()->to('/formulario/' . rawurlencode($code))) . '" data-af-form="' . e($code) . '">';
        if ($errors !== []) {
            $html .= '<div class="af-alert af-alert-bad" role="alert">' . e(t('Check the highlighted fields.')) . '</div>';
        }
        foreach ($form['fields'] as $field) {
            $html .= $this->field($anchor, $field, isset($old[$field['key']]) && is_scalar($old[$field['key']]) ? (string) $old[$field['key']] : '', isset($errors[$field['key']]) && is_string($errors[$field['key']]) ? $errors[$field['key']] : '');
        }
        $html .= '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>' . e(t('Leave this field empty'))
            . '<input type="text" name="' . e(Cms::FORM_TRAP) . '" tabindex="-1" autocomplete="off" value=""></label></div>'
            . '<input type="hidden" name="' . e(Cms::FORM_STAMP) . '" value="">'
            . '<p><button class="af-btn af-btn-primary" type="submit">' . e(t('Send')) . '</button></p></form>';

        return $html;
    }

    /**
     * @param array<string,mixed> $f
     */
    private function field(string $anchor, array $f, string $value, string $error): string
    {
        $key = (string) $f['key'];
        $type = (string) $f['type'];
        $id = $anchor . '-' . $key;
        $name = 'f[' . $key . ']';
        $required = !empty($f['required']);
        $label = e(t((string) $f['label'])) . ($required ? ' <span aria-hidden="true">*</span>' : '');
        $common = ' name="' . e($name) . '" id="' . e($id) . '"' . ($required ? ' required aria-required="true"' : '')
            . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '');
        $message = $error !== '' ? '<p class="af-error" id="' . e($id) . '-error" role="alert">' . e($error) . '</p>' : '';

        if ($type === 'checkbox' || $type === 'consent') {
            return '<div class="af-field"><label class="af-check" for="' . e($id) . '"><input type="checkbox"' . $common . ' value="1"' . ($value !== '' ? ' checked' : '') . '><span>' . $label . '</span></label>' . $message . '</div>';
        }
        $control = '';
        if ($type === 'textarea') {
            $control = '<textarea class="af-textarea"' . $common . ' rows="5" maxlength="5000">' . e($value) . '</textarea>';
        } elseif ($type === 'select') {
            $control = '<select class="af-select"' . $common . '><option value="">—</option>';
            foreach (isset($f['options']) && is_array($f['options']) ? $f['options'] : [] as $option) {
                $control .= '<option value="' . e((string) $option) . '"' . ((string) $option === $value ? ' selected' : '') . '>' . e((string) $option) . '</option>';
            }
            $control .= '</select>';
        } else {
            $htmlType = $type === 'email' ? 'email' : ($type === 'tel' ? 'tel' : 'text');
            $control = '<input class="af-input" type="' . $htmlType . '"' . $common . ' value="' . e($value) . '" maxlength="' . ($type === 'email' ? 191 : ($type === 'tel' ? 25 : 300)) . '"'
                . ($type === 'email' ? ' autocomplete="email"' : ($type === 'tel' ? ' autocomplete="tel"' : '')) . '>';
        }

        return '<div class="af-field"><label for="' . e($id) . '">' . $label . '</label>' . $control . $message . '</div>';
    }

    /**
     * Quita los caracteres de control (menos el tabulador y, en los textos largos, el salto de línea) y normaliza los saltos.
     */
    private static function clean(string $text, bool $multiline = false): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (!$multiline) {
            $text = str_replace("\n", ' ', $text);
        }

        return $text;
    }
}
