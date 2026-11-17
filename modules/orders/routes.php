<?php
/**
 * AIFEC — pedidos: las rutas.
 *
 * Secciones (otros paquetes añaden las suyas debajo): carrito · caja · pedidos · panel de pedidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Orders\Admin\ManualOrderController;
use Aifec\Modules\Orders\Admin\OrdersController;
use Aifec\Modules\Orders\Admin\PaymentsController;
use Aifec\Modules\Orders\Admin\ReportsController;
use Aifec\Modules\Orders\CartController;
use Aifec\Modules\Orders\Front\CheckoutController;
use Aifec\Modules\Orders\Front\OrderPageController;
use Aifec\Modules\Orders\OrderController;

return static function (Router $r, App $app): void {
    // ------------------------------------------------------------------ carrito

    $cart = '/' . trim($app->settings()->str('urls.cart', 'carrito'), '/');

    // La página del carrito la pinta la plantilla orders::front/cart (un tema puede sustituirla).
    $r->get($cart, [CartController::class, 'page'])->name('cart');
    // El resumen para el minicarrito: sin cookie de carrito no toca la base de datos.
    $r->get($cart . '/summary.json', [CartController::class, 'summaryJson'])->name('cart.summary');

    // Las acciones: solo desde la propia web (intermediario csrf).
    $r->group(['prefix' => $cart, 'middleware' => ['csrf']], static function (Router $r): void {
        $r->post('/add', [CartController::class, 'add'])->name('cart.add');
        $r->post('/update', [CartController::class, 'update'])->name('cart.update');
        $r->post('/remove', [CartController::class, 'remove'])->name('cart.remove');
        $r->post('/coupon', [CartController::class, 'coupon'])->name('cart.coupon');
        $r->post('/destination', [CartController::class, 'destination'])->name('cart.destination');
    });

    // ------------------------------------------------------------------ caja

    // La caja es una sola página. Actualizar y hacer el pedido solo se aceptan desde la propia web (intermediario csrf).
    $checkout = '/' . trim($app->settings()->str('urls.checkout', 'finalizar-compra'), '/');
    $r->get($checkout, [CheckoutController::class, 'page'])->name('checkout');
    // Las direcciones que la gente (y los agentes) prueban por costumbre llevan a la caja y al carrito (14/11/2026: /checkout y /caja
    // daban 404). Solo las que no son ya las de la tienda.
    foreach (['/checkout' => 'checkout', '/caja' => 'checkout', '/cart' => 'cart', '/cesta' => 'cart'] as $alias => $target) {
        if ($alias !== $checkout && $alias !== $cart) {
            $r->get($alias, [CartController::class, $target === 'cart' ? 'toCart' : 'toCheckout']);
        }
    }
    $r->group(['prefix' => $checkout, 'middleware' => ['csrf']], static function (Router $r): void {
        $r->post('/update', [CheckoutController::class, 'update'])->name('checkout.update');
        $r->post('/understand', [CheckoutController::class, 'understand'])->name('checkout.understand');
        $r->post('/place', [CheckoutController::class, 'place'])->name('checkout.place');
    });

    // La página del pedido: el número y la ficha de la dirección dan acceso sin cuenta. El botón de descarga pide un
    // enlace nuevo (el del correo deja de valer), por eso es un POST.
    $order = '/' . trim($app->settings()->str('urls.order', 'pedido'), '/');
    $r->get($order . '/{number}/{secret}', [OrderPageController::class, 'show'])->name('order.thanks');
    // Pagar un pedido que sigue sin cobrar: lleva a la persona a la pasarela de su forma de pago. Es la dirección que un
    // asistente le da a quien le encargó la compra, porque él no puede enviar el formulario de la pasarela.
    $r->get($order . '/{number}/{secret}/pay', [OrderPageController::class, 'pay'])->name('order.pay');
    $r->post($order . '/{number}/{secret}/download/{id:\d+}', [OrderPageController::class, 'download'])->middleware('csrf')->name('order.thanks.download');

    // ------------------------------------------------------------------ pedidos

    // La descarga de lo digital comprado: la ficha del enlace del correo es la que manda (sin cuenta ni sesión).
    $r->get('/descarga/{token}', [OrderController::class, 'download'])->name('order.download');

    // ------------------------------------------------------------------ panel de pedidos

    // Listado, ficha con sus acciones, carritos y exportación. Las rutas fijas van antes que la de la ficha.
    $r->group(['prefix' => '/admin/orders', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [OrdersController::class, 'index'])->name('admin.orders');
        $r->get('/export.csv', [OrdersController::class, 'export'])->name('admin.orders.export');
        $r->get('/carts', [OrdersController::class, 'carts'])->name('admin.orders.carts');
        $r->get('/picking', [OrdersController::class, 'picking'])->name('admin.orders.picking');
        $r->get('/{id:\d+}', [OrdersController::class, 'show'])->name('admin.orders.show');
        $r->post('/{id:\d+}/status', [OrdersController::class, 'status']);
        $r->post('/{id:\d+}/note', [OrdersController::class, 'note']);
        $r->post('/{id:\d+}/tracking', [OrdersController::class, 'tracking']);
        $r->post('/{id:\d+}/paid', [OrdersController::class, 'paid']);
        $r->post('/{id:\d+}/cancel', [OrdersController::class, 'cancel']);
        $r->post('/{id:\d+}/refund', [OrdersController::class, 'refund']);
        $r->post('/{id:\d+}/resend', [OrdersController::class, 'resend']);
        // Pedido a mano (Admin\ManualOrderController): la pantalla, sus pasos, los dos buscadores, crear y «Repetir».
        $r->get('/new', [ManualOrderController::class, 'show'])->name('admin.orders.new');
        $r->get('/new/find/{what:customers|products}', [ManualOrderController::class, 'find']);
        $r->post('/new/place', [ManualOrderController::class, 'place']);
        $r->post('/new/{step:[a-z-]+}', [ManualOrderController::class, 'step']);
        $r->post('/{id:\d+}/repeat', [ManualOrderController::class, 'repeat']);
    });

    // El resumen del pedido a mano va fuera del grupo: pasa por el intermediario «admin» dentro de quote() para poder mandar
    // las tres cabeceras anti-caché (el grupo deja solo «no-store, private») y contestar siempre en JSON.
    $r->post('/admin/orders/new/quote', [ManualOrderController::class, 'quote'])->name('admin.orders.new.quote');

    // «Ajustes › Formas de pago»: la lista de las formas registradas y el orden de la caja.
    $r->group(['prefix' => '/admin/payments', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [PaymentsController::class, 'index'])->name('admin.payments');
        $r->post('/order', [PaymentsController::class, 'order']);
        $r->post('/enable/{id:[a-z0-9-]+}', [PaymentsController::class, 'enable']);
    });

    // «Ventas › Informes»: solo lectura, siempre acotado a un periodo.
    $r->group(['prefix' => '/admin/reports', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ReportsController::class, 'index'])->name('admin.reports');
        $r->get('/export.csv', [ReportsController::class, 'export'])->name('admin.reports.export');
    });
};
