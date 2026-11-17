<?php
/**
 * AIFEC — facturas: publica el servicio «invoices», emite la factura al pagar y la rectificativa al devolver, enseña los enlaces
 * de descarga en la página del pedido y en la zona de cliente, y ofrece el adjunto del correo de confirmación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Invoices\Admin\OrderCard;
use Aifec\Modules\Invoices\Front\InvoiceLinks;
use Aifec\Modules\Invoices\Invoices;

return static function (App $app): void {
    $app->bind('invoices', static function (App $app): Invoices {
        return new Invoices($app);
    });

    // Un pedido se cobra (solo la primera vez): su factura. Si falla, se anota y el cobro sigue en pie.
    $app->hooks()->on('order.paid', static function (array $order) use ($app): void {
        if (!$app->settings()->bool('invoices.auto', true)) {
            return;
        }
        try {
            $app->service('invoices')->issue((int) $order['id']);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'invoices.issue');
        }
    });

    // Se devuelve dinero: la rectificativa de esa devolución.
    $app->hooks()->on('order.refunded', static function (array $order, array $refund) use ($app): void {
        if (!$app->settings()->bool('invoices.auto', true)) {
            return;
        }
        try {
            $app->service('invoices')->credit((int) $order['id'], (int) $refund['id']);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'invoices.credit');
        }
    });

    // La página del pedido (con la ficha de la dirección) y la zona de cliente enseñan las facturas.
    $app->hooks()->slot('order.thanks', static function (array $order) use ($app): string {
        return InvoiceLinks::forOrder($app, $order);
    });
    $app->hooks()->slot('account.dashboard', static function (array $customer) use ($app): string {
        return InvoiceLinks::forCustomer($app, $customer);
    });

    // La ficha del pedido en el panel: sus facturas con el PDF y, si aún no tiene, el botón de emitirla.
    $app->hooks()->slot('admin.order.side', static function (array $order) use ($app): string {
        return OrderCard::render($app, $order);
    });

    // El adjunto del correo de confirmación: el filtro lo aplica el módulo de pedidos al componer el correo.
    $app->hooks()->filter('order.mail_attachments', static function ($attachments, array $order, string $template) use ($app) {
        return $app->service('invoices')->mailAttachments(is_array($attachments) ? $attachments : [], $order, $template);
    });
};
