<?php
/**
 * AIFEC — GM legal texts per product: los bloques legales en la ficha, la caja y el correo, y la renuncia al desistimiento.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\LegalProducto\Blocks;
use Gm\LegalProducto\Checkout;
use Gm\LegalProducto\DownloadGate;
use Gm\LegalProducto\OrderNotice;

return static function (App $app): void {
    $hooks = $app->hooks();

    // La ficha del producto: bajo el resumen o al final, según el ajuste (nunca en los dos). Los textos van en la página que se
    // guarda en caché: cambiar un bloque o su asignación vacía la etiqueta «catalog».
    $ficha = static function (string $where) use ($app): callable {
        return static function ($product) use ($app, $where): string {
            if (!is_array($product) || !isset($product['id']) || $app->settings()->str('gm_legal_producto.placement', 'summary') !== $where) {
                return '';
            }
            $blocks = Blocks::view((new Blocks($app))->forProduct((int) $product['id']));

            return $blocks === [] ? '' : $app->view()->render('gm-legal-producto::front/blocks', ['blocks' => $blocks]);
        };
    };
    $hooks->slot('product.summary.after', $ficha('summary'));
    $hooks->slot('product.bottom', $ficha('bottom'));

    // La caja: los textos de lo que hay en el carrito y la casilla de la renuncia (que no impide comprar).
    $hooks->slot('checkout.review.after', static function ($summary) use ($app): string {
        return Checkout::slot($app, $summary);
    });
    $hooks->on('order.placed', static function ($order) use ($app): void {
        Checkout::placed($order, $app);
    });

    // Un contenido digital no se entrega hasta que el cliente acepta la renuncia al desistimiento. Es un filtro sellado: entra con la
    // capacidad «orders» que el comerciante concede. Sin ella, la descarga sale como siempre.
    $hooks->filter('order.download.allowed', static function ($allowed, $order = [], $line = [], $file = []) use ($app) {
        return DownloadGate::filter($allowed, $order, $line, $file, $app);
    });

    // Los textos legales van dentro del correo de confirmación del pedido (bloques). El correo aparte solo sale de recambio.
    $hooks->filter('order.mail.blocks', static function ($blocks, $order = [], $template = '') use ($app) {
        return OrderNotice::blocks($blocks, $order, $template, $app);
    });

    // El correo de recambio con los textos, cuando el pedido queda pagado, y la tarjeta de la ficha del pedido en el panel.
    $hooks->on('order.paid', static function ($order) use ($app): void {
        OrderNotice::paid($order, $app);
    });
    $hooks->slot('admin.order.side', static function ($order) use ($app): string {
        return OrderNotice::side($order, $app);
    });

    // Cambiar los ajustes cambia lo que dicen las fichas guardadas en la caché.
    $hooks->on('settings.saved', static function ($module) use ($app): void {
        if ($module === 'gm-legal-producto') {
            $app->invalidate(['catalog']);
        }
    });
};
