<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Returns\ReturnLabels;
use Aifec\Modules\Returns\Returns;

/**
 * La tarjeta «Devoluciones» de la ficha de un pedido en el panel (hueco «admin.order.side»): las devoluciones de ese pedido con su
 * estado y un enlace a cada una. Solo la ve quien puede ver devoluciones.
 */
final class OrderCard
{
    /**
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     */
    public static function render(App $app, array $order): string
    {
        if (!$app->auth()->can('returns.view')) {
            return '';
        }
        /** @var Returns $returns */
        $returns = $app->service('returns');
        $rows = [];
        foreach ($returns->forOrder((int) $order['id']) as $return) {
            $time = Clock::parse((string) $return['requested_at']);
            $rows[] = [
                'number' => (string) $return['number'],
                'type' => ReturnLabels::type((string) $return['kind']),
                'status' => ReturnLabels::status((string) $return['status']),
                'kind' => ReturnLabels::kind((string) $return['status']),
                'date' => $time > 0 ? $app->i18n()->date($time, 'medium', $app->site()->timezone()) : '',
                'refunded' => (int) $return['refunded_amount'] > 0 ? money((int) $return['refunded_amount'], (string) $return['currency']) : '',
                'url' => $app->url()->plain('/admin/returns/' . (int) $return['id']),
            ];
        }

        return $app->view()->render('returns::admin/order-card', ['rows' => $rows]);
    }
}
