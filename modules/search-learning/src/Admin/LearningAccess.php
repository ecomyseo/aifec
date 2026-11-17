<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;

/** El comodín de un perfil no lo convierte en el superadministrador real de una tienda. */
final class LearningAccess
{
    public static function read(App $app): void
    {
        $app->auth()->requireCap('search_learning.view');
    }

    public static function canManage(App $app): bool
    {
        if ($app->site()->isDemo() || $app->site()->get('public_admin_demo', false)) {
            return false;
        }
        $user = $app->auth()->admin();
        if ($user === null || !$app->auth()->can('search_learning.manage')) {
            return false;
        }
        $fresh = $app->db()->row('SELECT id, role, active FROM {users} WHERE id = ?', [(int) $user['id']]);
        if ($fresh === null || (int) $fresh['active'] !== 1 || $fresh['role'] !== 'admin') {
            return false;
        }
        $json = $app->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['admin']);
        $caps = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($caps)) {
            return false;
        }
        return in_array('*', $caps, true) || in_array('search_learning.manage', $caps, true) || in_array('search_learning.*', $caps, true);
    }

    public static function mutate(App $app, Request $request): int
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'Search learning changes require POST');
        }
        if (!self::canManage($app)) {
            throw HttpError::forbidden(t('Only an active superadministrator can authorise search learning.'));
        }
        if (!$request->isSameOrigin() || !Admin::checkToken($app, $request)) {
            throw HttpError::forbidden(t('The learning form expired. Reload it before continuing.'));
        }
        return (int) $app->auth()->adminId();
    }
}
