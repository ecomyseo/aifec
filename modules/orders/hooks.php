<?php
/**
 * AIFEC — pedidos: publica los servicios «payments», «cart», «checkout» y «orders» y engancha el carrito a la entrada de clientes.
 *
 * Secciones (otros paquetes añaden las suyas debajo): formas de pago · carrito · caja · pedidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\BrowserCommerce;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Admin\OrdersDashboard;
use Aifec\Modules\Orders\Cart;
use Aifec\Modules\Orders\Checkout\CheckoutSession;
use Aifec\Modules\Orders\OrderPrivacy;
use Aifec\Modules\Orders\Orders;
use Aifec\Modules\Orders\Payments;

return static function (App $app): void {
    // ------------------------------------------------------------------ formas de pago

    // Cada módulo de pago registra la suya: $app->service('payments')->register(new MiForma($app)).
    $app->bind('payments', static function (App $app): Payments {
        return new Payments($app);
    });

    // ------------------------------------------------------------------ carrito

    $app->middleware('orders.cart', static function (Request $request, App $application, callable $next): Response {
        if (BrowserCommerce::crawler($request->userAgent())) {
            throw HttpError::forbidden(t('Automated cart access is blocked. To buy, open the product page and use Add to cart.'));
        }
        return $next($request);
    });

    // Las líneas de ajuste de los módulos son dinero: solo un módulo con la capacidad «pricing» concedida puede añadirlas.
    $app->hooks()->seal('cart.adjustments', 'pricing');

    $app->bind('cart', static function (App $app): Cart {
        return new Cart($app);
    });

    // La caja como motor, sin HTML ni petición: la usan la página, el asistente y la API. Sobre el carrito de la petición
    // en curso, o sobre otro con forCart($cart).
    $app->bind('checkout', static function (App $app): CheckoutSession {
        return new CheckoutSession($app);
    });

    // Un cliente acaba de entrar: el carrito de este navegador pasa a ser suyo y se funde con el que ya tuviera.
    $app->hooks()->on('customer.login', static function (array $customer) use ($app): void {
        $app->service('cart')->onLogin($customer);
    });

    // ------------------------------------------------------------------ pedidos

    // Entregar un fichero de lo digital comprado es una decisión del comerciante (p. ej. exigir antes la renuncia al desistimiento):
    // solo un plugin con la capacidad «orders» concedida puede decir que una descarga no se entrega.
    // Filtro order.download.allowed (bool $allowed, array $order, array $line, array $file) → ['allowed' => bool, 'reason' => string].
    $app->hooks()->seal('order.download.allowed', 'orders');

    // Crear, cobrar una sola vez, cambiar de estado y devolver. Las descargas de lo digital están en ->downloads().
    $app->bind('orders', static function (App $app): Orders {
        return new Orders($app);
    });

    // Datos personales: lo que se borra cuando un cliente pide que se le olvide, y sus pedidos en la copia de sus datos.
    $app->hooks()->on('customer.anonymised', static function (int $customerId, string $email) use ($app): void {
        OrderPrivacy::forget($app, $customerId, $email);
    });
    $app->hooks()->filter('customer.export', static function ($data, int $customerId) use ($app) {
        return is_array($data) ? OrderPrivacy::export($app, $data, $customerId) : $data;
    });

    // El panel: los avisos del inicio, el buscador de órdenes y la invalidación de las cifras al cobrar o devolver. Va aquí
    // (y no en las rutas ni en admin.php) porque un cobro ocurre en la tienda o en una tarea, donde eso otro no se lee.
    OrdersDashboard::register($app);
};
