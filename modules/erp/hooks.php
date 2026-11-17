<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Erp\ApiGate;
use Aifec\Modules\Erp\Erp;
use Aifec\Modules\Erp\NativeFtp;
use Aifec\Modules\Erp\OrderCard;
use Aifec\Modules\Erp\Present;

return static function (App $app): void {
    $app->bind('erp', static function (App $app): Erp {
        return new Erp($app);
    });
    // El servidor FTP/FTPS: se puede sustituir (las pruebas ponen uno de mentira).
    $app->bind('erp.ftp', static function (): NativeFtp {
        return new NativeFtp();
    });
    $app->middleware('erp.api', [ApiGate::class, 'handle']);

    // Los hechos que el ERP puede querer saber. Se avisa en el instante en que ocurren (nunca se recorre lo antiguo) y los datos solo
    // se montan si hay alguien suscrito a ese hecho.
    $emit = static function (string $event, callable $data, ?string $occurredAt = null) use ($app): void {
        $app->service('erp')->webhooks()->emit($event, $data, $occurredAt);
    };
    $hooks = $app->hooks();
    $hooks->on('order.placed', static function (array $order) use ($emit, $app): void {
        $emit('order.created', static function () use ($app, $order): array {
            return ['order' => Present::order($app, $order)];
        }, isset($order['placed_at']) ? (string) $order['placed_at'] : null);
    });
    $hooks->on('order.paid', static function (array $order) use ($emit, $app): void {
        $emit('order.paid', static function () use ($app, $order): array {
            return ['order' => Present::order($app, $order)];
        }, isset($order['paid_at']) && $order['paid_at'] !== null ? (string) $order['paid_at'] : null);
    });
    $hooks->on('order.status', static function (array $order, string $from, string $to) use ($emit, $app): void {
        $emit('order.status_changed', static function () use ($app, $order, $from, $to): array {
            return ['order' => Present::order($app, $order), 'from' => $from, 'to' => $to];
        });
    });
    $hooks->on('order.refunded', static function (array $order, array $refund) use ($emit, $app): void {
        $emit('order.refunded', static function () use ($app, $order, $refund): array {
            return ['order' => Present::order($app, $order)]
                + ['refund' => ['id' => (int) ($refund['id'] ?? 0), 'reason' => isset($refund['reason']) ? (string) $refund['reason'] : null] + Present::amounts($refund, ['amount', 'tax'])];
        });
    });
    $hooks->on('stock.changed', static function ($productId, $variantId, $stock) use ($emit, $app): void {
        $emit('product.stock_changed', static function () use ($app, $productId, $variantId, $stock): array {
            $sku = (int) $variantId > 0
                ? $app->db()->value('SELECT sku FROM {variants} WHERE id = ?', [(int) $variantId])
                : $app->db()->value('SELECT sku FROM {products} WHERE id = ?', [(int) $productId]);

            return ['product_id' => (int) $productId, 'variant_id' => (int) $variantId > 0 ? (int) $variantId : null, 'sku' => $sku !== null ? (string) $sku : null, 'stock' => (int) $stock];
        });
    });
    $hooks->on('customer.registered', static function (array $customer) use ($emit): void {
        $emit('customer.created', static function () use ($customer): array {
            return ['customer' => Present::customer($customer)];
        }, isset($customer['created_at']) ? (string) $customer['created_at'] : null);
    });
    $hooks->on('invoice.issued', static function (array $invoice) use ($emit, $app): void {
        $emit('invoice.issued', static function () use ($app, $invoice): array {
            return ['invoice' => Present::invoice($app, $invoice)];
        }, isset($invoice['issued_at']) ? (string) $invoice['issued_at'] : null);
    });

    // La ficha del pedido en el panel: qué ERP lo ha confirmado y con qué identificador.
    $hooks->slot('admin.order.side', static function (array $order) use ($app): string {
        return OrderCard::render($app, $order);
    });
};
