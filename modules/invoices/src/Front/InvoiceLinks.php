<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Invoices\Invoices;

/**
 * Los enlaces de descarga de las facturas que enseñan la página del pedido (hueco «order.thanks») y la zona de cliente (hueco
 * «account.dashboard»). Nada de esto va en una página que se guarde en la caché: las dos son privadas.
 */
final class InvoiceLinks
{
    /**
     * @param array<string,mixed> $order el pedido (Orders::findBySecret)
     */
    public static function forOrder(App $app, array $order): string
    {
        /** @var Invoices $invoices */
        $invoices = $app->service('invoices');
        $links = [];
        foreach ($invoices->forOrder((int) $order['id']) as $invoice) {
            $url = $invoices->url($invoice);
            if ($url !== '') {
                $links[] = self::link($invoice, $url);
            }
        }
        if ($links === []) {
            return '';
        }

        return $app->view()->render('invoices::front/order-card', ['links' => $links]);
    }

    /**
     * @param array<string,mixed> $customer
     */
    public static function forCustomer(App $app, array $customer): string
    {
        /** @var Invoices $invoices */
        $invoices = $app->service('invoices');
        $links = [];
        $rows = $app->db()->select(
            'SELECT i.id FROM {invoices} i INNER JOIN {orders} o ON o.id = i.order_id WHERE o.customer_id = ? ORDER BY i.id DESC LIMIT 5',
            [(int) $customer['id']]
        );
        foreach ($rows as $row) {
            $invoice = $invoices->find((int) $row['id']);
            $url = $invoice !== null ? $invoices->url($invoice) : '';
            if ($invoice !== null && $url !== '') {
                $links[] = self::link($invoice, $url);
            }
        }
        if ($links === []) {
            return '';
        }

        return $app->view()->render('invoices::front/order-card', ['links' => $links]);
    }

    /**
     * @param array<string,mixed> $invoice
     *
     * @return array<string,string>
     */
    private static function link(array $invoice, string $url): array
    {
        return ['number' => (string) $invoice['full_number'], 'credit' => (string) $invoice['type'] === 'credit' ? '1' : '', 'url' => $url];
    }
}
