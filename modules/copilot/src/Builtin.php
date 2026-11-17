<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Actions\CatalogActions;
use Aifec\Modules\Copilot\Actions\CouponActions;
use Aifec\Modules\Copilot\Actions\OrderActions;
use Aifec\Modules\Copilot\Actions\SettingActions;
use Aifec\Modules\Copilot\Actions\ShippingActions;

/**
 * Las acciones de serie, que entran en el registro por el filtro «copilot.actions» como cualquier otra. Cada grupo solo se ofrece
 * si su módulo está activo: sin el módulo de pedidos no hay acciones de pedidos, y así con todos.
 */
final class Builtin
{
    /**
     * @param mixed $actions lo que llevan los filtros anteriores
     *
     * @return array<int,array<string,mixed>>
     */
    public static function register($actions, App $app): array
    {
        $list = is_array($actions) ? array_values($actions) : [];
        $modules = $app->modules();
        $groups = [
            'orders' => OrderActions::class,
            'catalog' => CatalogActions::class,
            'coupons' => CouponActions::class,
            'shipping' => ShippingActions::class,
        ];
        foreach ($groups as $module => $class) {
            if ($modules->isLoaded($module)) {
                foreach (call_user_func([$class, 'definitions'], $app) as $definition) {
                    $list[] = $definition;
                }
            }
        }
        foreach (SettingActions::definitions($app) as $definition) {
            $list[] = $definition;
        }

        return $list;
    }
}
