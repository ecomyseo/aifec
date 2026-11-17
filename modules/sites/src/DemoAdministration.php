<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Kernel\App;
use Aifec\Security\Passwords;

/** Las dos cuentas públicas de una tienda aislada para evaluar su administración. */
final class DemoAdministration
{
    public const ROLE = 'demo_admin';

    public static function email(string $url): string
    {
        return 'admin-' . DemoGuard::email($url);
    }

    public static function apply(App $app, string $password): void
    {
        if (!$app->site()->isDemo() || !$app->site()->get('public_admin_demo', false) || $app->site()->get('master', false)) {
            throw new \InvalidArgumentException('Public administration needs an isolated demo site');
        }
        $db = $app->db();
        $roleName = $app->i18n()->t('Demo administrator', [], substr($app->site()->locale(), 0, 2));
        $db->upsert('roles', ['code' => self::ROLE, 'name' => $roleName, 'caps' => '["*"]', 'system' => 1], ['code']);
        $email = self::email($app->site()->url());
        $now = $app->clock()->sql();
        $row = [
            'name' => 'Administrador de demostración', 'password' => Passwords::hash($password), 'role' => self::ROLE,
            'locale' => substr($app->site()->locale(), 0, 2), 'active' => 1, 'updated_at' => $now,
        ];
        $id = $db->value('SELECT id FROM {users} WHERE email = ?', [$email]);
        if ($id === null) {
            $db->insert('users', $row + ['email' => $email, 'created_at' => $now]);
        } else {
            $db->update('users', $row, ['id' => (int) $id]);
        }
        // No se heredan credenciales del operador: la primera experiencia usa el proveedor simulado.
        $app->settings()->set('ai.enabled', true);
        foreach (['economy', 'standard', 'advanced'] as $tier) {
            $app->settings()->set('ai.' . $tier . '.provider', 'fake');
            $app->settings()->set('ai.' . $tier . '.model', '');
            $app->settings()->set('ai.' . $tier . '.base_url', '');
            $app->setSecret('ai.' . $tier . '.key', '');
        }
    }
}
