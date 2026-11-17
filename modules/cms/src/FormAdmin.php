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

/**
 * El constructor de formularios en el panel (recurso «forms»; ver Aifec\Admin\ResourceController y Aifec\Admin\Form).
 *
 * Los campos se guardan como filas de un repetidor (etiqueta, clave, tipo, obligatorio y, para las listas, una opción por
 * línea); la clave se genera sola de la etiqueta la primera vez y ya no cambia, para que las entradas antiguas sigan
 * encajando. El correo de aviso y el texto de gracias van en la columna `settings`.
 */
final class FormAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => true, 'translate' => true],
                'code' => ['label' => 'Code', 'sort' => true],
                'entries' => ['label' => 'Entries', 'type' => 'int', 'render' => static function (array $row, App $app): string {
                    $all = (int) $app->db()->value('SELECT COUNT(*) FROM {form_entries} WHERE form_id = ?', [(int) $row['id']]);
                    $new = (int) $app->db()->value("SELECT COUNT(*) FROM {form_entries} WHERE form_id = ? AND status = 'new'", [(int) $row['id']]);

                    return e((string) $all) . ($new > 0 ? ' <span class="af-badge af-badge-info">' . e(tn('{n} new', '{n} new', $new)) . '</span>' : '');
                }],
            ],
            'search' => ['name', 'code'],
            'sort' => ['name', 'asc'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $form = $id !== null ? (new Forms($app))->get($id) : null;

        return ['fields' => [
            'name' => ['label' => 'Name', 'rules' => 'required|max:120', 'hint' => 'Only you see it.'],
            'code' => ['type' => 'slug', 'code' => true, 'label' => 'Code', 'rules' => 'required|max:60|unique:forms,code', 'width' => 'md',
                'hint' => 'Lowercase letters, numbers and hyphens. It is part of the address that receives the form.'],
            'fields' => ['type' => 'repeater', 'label' => 'Fields', 'add' => 'Add a field', 'rules' => 'required', 'wide' => true,
                'fields' => [
                    'label' => ['label' => 'Label', 'rules' => 'required|max:120'],
                    'key' => ['label' => 'Key', 'rules' => 'max:40', 'placeholder' => 'Automatic'],
                    'type' => ['type' => 'select', 'label' => 'Type', 'default' => 'text', 'options' => [
                        'text' => 'Text', 'email' => 'Email', 'tel' => 'Phone', 'textarea' => 'Long text', 'select' => 'List', 'checkbox' => 'Checkbox', 'consent' => 'Consent checkbox',
                    ]],
                    'required' => ['type' => 'toggle', 'label' => 'Required', 'default' => 0],
                    'options' => ['type' => 'textarea', 'label' => 'Options (one per line, only for lists)', 'rows' => 2, 'rules' => 'max:2000'],
                ],
                'hint' => 'The consent checkbox is always required. The key identifies the field in the stored messages: it is made from the label and does not change afterwards.'],
            'notify_to' => ['type' => 'email', 'label' => 'Send the messages to', 'store' => false, 'width' => 'md',
                'default' => $form !== null ? $form['settings']['notify_to'] : '', 'hint' => 'Leave it empty to use the contact email of the shop.'],
            'thanks' => ['type' => 'textarea', 'label' => 'Thank-you text', 'store' => false, 'rows' => 3, 'rules' => 'max:500',
                'default' => $form !== null ? $form['settings']['thanks'] : '', 'hint' => 'Shown to the person after sending the form.'],
        ]];
    }

    /**
     * Botón de la cabecera de la ficha: descargar los mensajes de este formulario.
     *
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }

        return '<a class="af-btn" href="' . e($app->url()->plain('/admin/cms/entries/export', ['form_id' => (int) $row['id']])) . '">' . Admin::icon('download') . e(t('Export messages')) . '</a>';
    }

    /**
     * Deja los campos como se guardan: con su clave fija, tipos conocidos y las opciones de una lista en un texto de varias líneas.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $rows = Forms::cleanFields(isset($values['fields']) && is_array($values['fields']) ? $values['fields'] : []);
        if ($rows === []) {
            return ['_errors' => ['fields' => t('Add at least one field')]];
        }
        $stored = [];
        foreach ($rows as $row) {
            $row['options'] = implode("\n", $row['options']);
            $stored[] = $row;
        }
        $values['fields'] = $stored;

        return $values;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $settings = [
            'notify_to' => isset($values['notify_to']) && is_string($values['notify_to']) ? trim($values['notify_to']) : '',
            'thanks' => isset($values['thanks']) && is_string($values['thanks']) ? trim($values['thanks']) : '',
        ];
        $app->db()->update('forms', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
        $app->invalidate(['cms']);
    }

    /**
     * Un formulario con mensajes recibidos no se borra: primero se borran (o se exportan) los mensajes.
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $entries = (int) $app->db()->value('SELECT COUNT(*) FROM {form_entries} WHERE form_id = ?', [(int) $row['id']]);

        return $entries > 0 ? tn('This form has {n} stored message. Delete it first.', 'This form has {n} stored messages. Delete them first.', $entries) : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $app->invalidate(['cms']);
    }
}
