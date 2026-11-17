<?php
/**
 * AIFEC — reglas de las pantallas que trae el núcleo: equipo, papeles y registro de actividad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Security\Passwords;

final class CoreAdmin
{
    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>|string
     */
    public static function userBeforeSave(array $values, ?array $current, App $app)
    {
        if ($current === null && (!isset($values['password']) || $values['password'] === '')) {
            return ['_errors' => ['password' => t('This field is required')]];
        }
        if (isset($values['password']) && $values['password'] !== '') {
            $values['password'] = Passwords::hash((string) $values['password']);
        } else {
            unset($values['password']);
        }
        if ($current !== null) {
            $me = $app->auth()->adminId();
            $loses = empty($values['active']) || (string) $values['role'] !== (string) $current['role'];
            if ($loses && $me === (int) $current['id']) {
                return t('You cannot change your own role or lock yourself out. Ask another administrator.');
            }
            if ($loses && (string) $current['role'] === 'admin' && self::activeAdmins($app, (int) $current['id']) === 0) {
                return t('There must be at least one active administrator.');
            }
        }

        return $values;
    }

    /**
     * Si cambia la contraseña o se le quita el acceso, sus sesiones abiertas dejan de valer.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function userAfterSave(int $id, array $values, ?array $current, App $app): void
    {
        if ($current === null || $id === $app->auth()->adminId()) {
            return;
        }
        if (isset($values['password']) || empty($values['active'])) {
            $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => $id]);
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function userBeforeDelete(array $row, App $app): ?string
    {
        if ((int) $row['id'] === $app->auth()->adminId()) {
            return t('You cannot delete your own account.');
        }
        if ((string) $row['role'] === 'admin' && self::activeAdmins($app, (int) $row['id']) === 0) {
            return t('There must be at least one active administrator.');
        }
        $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => (int) $row['id']]);

        return null;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>|string
     */
    public static function roleBeforeSave(array $values, ?array $current, App $app)
    {
        $caps = isset($values['caps']) && is_array($values['caps']) ? array_values(array_filter($values['caps'], 'is_string')) : [];
        if ($current !== null) {
            if (!empty($current['system'])) {
                $values['code'] = (string) $current['code'];
            } elseif ((string) $values['code'] !== (string) $current['code'] && (int) $app->db()->value('SELECT COUNT(*) FROM {users} WHERE role = ?', [(string) $current['code']]) > 0) {
                return t('This role is in use: its code cannot change.');
            }
            // Los permisos de módulos apagados no salen en la pantalla; no se pierden al guardar.
            $known = self::capabilityOptions($app);
            $before = json_decode((string) $current['caps'], true);
            foreach (is_array($before) ? $before : [] as $cap) {
                if (is_string($cap) && !isset($known[$cap]) && !in_array($cap, $caps, true)) {
                    $caps[] = $cap;
                }
            }
        }
        if ((string) $values['code'] === 'admin' && !in_array('*', $caps, true)) {
            $caps[] = '*';
        }
        foreach ($caps as $cap) {
            if (preg_match('/^(\*|[a-z0-9_-]+\.(\*|[a-z0-9_.-]+))$/', $cap) !== 1) {
                return t('This value is not valid');
            }
        }
        $values['caps'] = $caps;

        return $values;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function roleBeforeDelete(array $row, App $app): ?string
    {
        if (!empty($row['system'])) {
            return t('The built-in roles cannot be deleted.');
        }
        if ((int) $app->db()->value('SELECT COUNT(*) FROM {users} WHERE role = ?', [(string) $row['code']]) > 0) {
            return t('There are people with this role. Give them another one first.');
        }

        return null;
    }

    /**
     * Casillas de permisos: «todo», y por cada área su comodín y los permisos que declaran los módulos.
     *
     * @return array<string,string>
     */
    public static function capabilityOptions(App $app): array
    {
        $options = ['*' => t('Everything, now and in the future')];
        $area = '';
        foreach (Admin::capabilities($app) as $cap => $label) {
            $prefix = (string) strstr($cap, '.', true);
            if ($prefix !== '' && $prefix !== $area) {
                $area = $prefix;
                $options[$area . '.*'] = t('{area}: everything', ['area' => $area]);
            }
            $options[$cap] = $area . ' · ' . t($label);
        }

        return $options;
    }

    /**
     * Ficha de una entrada del registro de actividad.
     *
     * @param array<string,mixed> $row
     */
    public static function auditShow(array $row, App $app): string
    {
        $when = Clock::parse((string) $row['created_at']);
        $html = '<div class="af-card"><div class="af-card-body"><dl class="af-kv">'
            . '<dt>' . e(t('When')) . '</dt><dd>' . e($app->i18n()->date($when, 'long', $app->site()->timezone())) . '</dd>'
            . '<dt>' . e(t('Who')) . '</dt><dd>' . e((string) $row['user_name']) . '</dd>'
            . '<dt>' . e(t('Action')) . '</dt><dd>' . e((string) $row['action']) . '</dd>'
            . '<dt>' . e(t('What')) . '</dt><dd>' . e(trim($row['entity'] . ' ' . $row['entity_id'])) . '</dd>'
            . '<dt>' . e(t('Summary')) . '</dt><dd>' . e((string) $row['summary']) . '</dd>'
            . '<dt>IP</dt><dd>' . e((string) $row['ip']) . '</dd></dl></div></div>';
        $changes = json_decode((string) $row['changes'], true);
        if (is_array($changes) && $changes !== []) {
            $html .= '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Changes')) . '</h2></div><div class="af-table-wrap"><table class="af-table"><thead><tr><th>'
                . e(t('Field')) . '</th><th>' . e(t('Before')) . '</th><th>' . e(t('After')) . '</th></tr></thead><tbody>';
            foreach ($changes as $field => $pair) {
                $html .= '<tr><td class="af-mono">' . e((string) $field) . '</td><td>' . e(is_array($pair) && isset($pair[0]) ? (string) $pair[0] : '') . '</td><td>' . e(is_array($pair) && isset($pair[1]) ? (string) $pair[1] : '') . '</td></tr>';
            }
            $html .= '</tbody></table></div></div>';
        }

        return $html;
    }

    /** Administradores activos, sin contar a uno. */
    private static function activeAdmins(App $app, int $exceptId): int
    {
        return (int) $app->db()->value("SELECT COUNT(*) FROM {users} WHERE role = 'admin' AND active = 1 AND id <> ?", [$exceptId]);
    }
}
