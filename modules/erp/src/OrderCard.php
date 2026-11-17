<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * El cuadro que sale en la ficha del pedido del panel: qué ERP lo ha recibido y con qué identificador. Sin confirmaciones, no sale nada.
 */
final class OrderCard
{
    /**
     * @param array<string,mixed> $order
     */
    public static function render(App $app, array $order): string
    {
        if (!isset($order['id']) || !$app->auth()->can('erp.view')) {
            return '';
        }
        $rows = $app->db()->select(
            'SELECT a.external_id, a.acked_at, c.name FROM {erp_acks} a LEFT JOIN {erp_connections} c ON c.id = a.connection_id WHERE a.order_id = ? ORDER BY a.id',
            [(int) $order['id']]
        );
        if ($rows === []) {
            return '';
        }

        return $app->view()->render('erp::admin/order-card', ['rows' => $rows]);
    }
}
