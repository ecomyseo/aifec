<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Invoices\Invoices;

/**
 * La tarjeta «Facturas» de la ficha de un pedido en el panel (hueco «admin.order.side»): las facturas y rectificativas
 * del pedido con su PDF, y el botón de emitir cuando todavía no tiene ninguna.
 */
final class OrderCard
{
    /**
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     */
    public static function render(App $app, array $order): string
    {
        $auth = $app->auth();
        if (!$auth->can('invoices.view')) {
            return '';
        }
        /** @var Invoices $invoices */
        $invoices = $app->service('invoices');
        $rows = [];
        foreach ($invoices->forOrder((int) $order['id']) as $invoice) {
            $time = Clock::parse(isset($invoice['issued_at']) ? (string) $invoice['issued_at'] : null);
            $rows[] = [
                'number' => (string) $invoice['full_number'],
                'credit' => (string) $invoice['type'] === 'credit',
                'date' => $time > 0 ? $app->i18n()->date($time, 'medium', $app->site()->timezone()) : '',
                'total' => money((int) $invoice['total'], (string) $invoice['currency']),
                'pdf' => $app->url()->plain('/admin/invoices/' . (int) $invoice['id'] . '/pdf'),
            ];
        }
        // Sin factura todavía: se puede emitir a mano, salvo que el pedido esté cancelado (no se factura lo que no se vende).
        $canIssue = $rows === [] && $auth->can('invoices.edit') && (string) $order['status'] !== 'cancelled';

        return $app->view()->render('invoices::admin/order-card', [
            'rows' => $rows,
            'canIssue' => $canIssue,
            'issueUrl' => $app->url()->plain('/admin/invoices/issue'),
            'orderNumber' => (string) $order['number'],
        ]);
    }
}
