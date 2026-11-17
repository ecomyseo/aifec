<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;

/**
 * El recurso «gm_licenses» del panel: el listado, el formulario (alta manual y cambios) y la columna lateral con los dominios y
 * las acciones (revocar, reactivar, ampliar actualizaciones, reemitir la clave firmada, quitar un dominio).
 */
final class LicenseAdmin
{
    public const STATUSES = ['active' => 'Active', 'revoked' => 'Revoked', 'pending' => 'Pending'];
    public const KINDS = ['domain' => 'Key with domains', 'signed' => 'Signed key for a tax ID (works offline)'];

    /**
     * @return array<string,mixed>
     */
    public static function form(App $app, ?int $id): array
    {
        return [
            'tabs' => ['gllmain' => 'Licence', 'gllmore' => 'Updates and notes'],
            'fields' => [
                'license_key' => [
                    'type' => $id !== null ? 'readonly' : 'text', 'label' => 'Licence key', 'hint' => 'Leave it empty and a new one is generated.',
                    'rules' => 'max:64|unique:gm_licenses,license_key', 'width' => 'md', 'tab' => 'gllmain',
                ],
                'email' => ['type' => 'email', 'label' => 'Customer email', 'rules' => 'required|email|max:191', 'tab' => 'gllmain'],
                'product_name' => ['label' => 'Product', 'rules' => 'required|max:191', 'tab' => 'gllmain'],
                'kind' => ['type' => 'select', 'label' => 'Type', 'options' => self::KINDS, 'default' => 'domain', 'tab' => 'gllmain'],
                'status' => ['type' => 'select', 'label' => 'State', 'options' => self::STATUSES, 'default' => 'active', 'tab' => 'gllmain'],
                'max_domains' => ['type' => 'number', 'label' => 'Domains allowed', 'hint' => '0: no limit.', 'default' => 1, 'rules' => 'int|min:0|max:1000', 'width' => 'sm', 'tab' => 'gllmain', 'show_if' => ['kind' => 'domain']],
                'units' => ['type' => 'number', 'label' => 'Units covered', 'default' => 1, 'rules' => 'int|min:1|max:255', 'width' => 'sm', 'tab' => 'gllmain', 'show_if' => ['kind' => 'signed']],
                'holder' => ['label' => 'Tax ID of the holder', 'rules' => 'max:40', 'tab' => 'gllmain', 'show_if' => ['kind' => 'signed']],
                'updates_until' => ['type' => 'datetime', 'label' => 'Updates until', 'hint' => 'Empty: updates with no end date.', 'tab' => 'gllmore'],
                'note' => ['type' => 'textarea', 'label' => 'Notes', 'rules' => 'max:2000', 'tab' => 'gllmore'],
            ],
        ];
    }

    /**
     * Antes de guardar: una licencia nueva sin clave recibe una (o queda pendiente, si es firmada y no se ha podido firmar).
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        // Lo que no viaja en el formulario (los campos de otro tipo de licencia) toma su valor de serie en vez de quedar en nulo.
        foreach (['units' => 1, 'max_domains' => 1] as $column => $default) {
            if (!isset($values[$column]) || $values[$column] === '') {
                $values[$column] = $current !== null && isset($current[$column]) ? (int) $current[$column] : $default;
            }
        }
        if (!isset($values['holder']) || !is_string($values['holder'])) {
            $values['holder'] = $current !== null ? (string) $current['holder'] : '';
        }
        $values['holder'] = Nif::clean((string) $values['holder']);
        if ($current !== null) {
            return $values;
        }
        $key = isset($values['license_key']) && is_string($values['license_key']) ? KeyFormat::normalize($values['license_key']) : '';
        $kind = isset($values['kind']) && $values['kind'] === 'signed' ? 'signed' : 'domain';
        if ($key === '') {
            $key = $kind === 'signed' ? 'PENDING-' . \Aifec\Security\Random::pick(KeyFormat::ALPHABET, 14) : KeyFormat::generate();
        }
        $values['license_key'] = $key;
        if (strpos($key, 'PENDING-') === 0) {
            $values['status'] = 'pending';
        }

        return $values;
    }

    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'license_key' => ['label' => 'Licence key', 'link' => true, 'sort' => true],
                'email' => ['label' => 'Customer', 'sort' => true],
                'product_name' => ['label' => 'Product', 'sort' => true, 'hide_sm' => true],
                'status' => ['label' => 'State', 'type' => 'badge', 'sort' => true, 'map' => ['active' => ['Active', 'ok'], 'revoked' => ['Revoked', 'bad'], 'pending' => ['Pending', 'warn']]],
                'updates_until' => ['label' => 'Updates until', 'type' => 'date', 'sort' => true, 'hide_sm' => true],
                'created_at' => ['label' => 'Created', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
            ],
            'search' => ['license_key', 'email', 'product_name', 'holder'],
            'filters' => [
                'status' => ['label' => 'State', 'options' => self::STATUSES],
                'kind' => ['label' => 'Type', 'options' => self::KINDS],
            ],
            'sort' => ['id', 'desc'],
            'per_page' => 25,
        ];
    }

    /**
     * La columna lateral de la ficha: dominios activados y acciones.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }
        $licenses = new Licenses($app);
        $id = (int) $row['id'];
        $base = $app->url()->plain('/admin/gm-licencias/' . $id);
        $checks = $app->db()->select('SELECT action, result, created_at FROM {gm_license_checks} WHERE license_id = ? ORDER BY id DESC LIMIT 8', [$id]);

        return $app->view()->render('gm-licencias::admin/side', [
            'license' => $row, 'domains' => $licenses->domains($id), 'checks' => $checks, 'base' => $base,
            'can_edit' => $app->auth()->can('gmlic.edit'),
        ]);
    }
}
