<?php
/**
 * AIFEC — clientes: pantallas propias del panel (direcciones, anonimizar, copia de datos y exportación).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Customers\CustomersController;
use Aifec\Modules\Customers\Front\AccountController;
use Aifec\Modules\Customers\Front\AddressesController;
use Aifec\Modules\Customers\Front\AuthController;
use Aifec\Modules\Customers\Front\OrdersController;
use Aifec\Modules\Customers\Front\PrivacyController;
use Aifec\Modules\Customers\Front\ProfileController;

return static function (Router $r, App $app): void {
    // ------------------------------------------------------------------ zona de cliente de la tienda

    $account = trim($app->settings()->str('urls.account', 'mi-cuenta'), '/');
    $account = '/' . ($account !== '' ? $account : 'mi-cuenta');

    // Entrar, registrarse, recuperar la contraseña, los enlaces del correo y salir. Todos los envíos pasan por «csrf»
    // (solo desde la propia web). Los nombres account.magic, account.verify y account.reset los usa el servicio de
    // clientes para montar los enlaces de sus correos.
    $r->group(['prefix' => $account, 'middleware' => ['csrf']], static function (Router $r): void {
        $r->add(['GET', 'POST'], '/entrar', [AuthController::class, 'login'])->name('account.login');
        $r->post('/entrar/enlace', [AuthController::class, 'loginLink'])->name('account.login.link');
        $r->get('/entrar/{token}', [AuthController::class, 'magic'])->name('account.magic');
        $r->get('/verificar/{token}', [AuthController::class, 'verify'])->name('account.verify');
        $r->add(['GET', 'POST'], '/registro', [AuthController::class, 'register'])->name('account.register');
        $r->add(['GET', 'POST'], '/recuperar', [AuthController::class, 'recover'])->name('account.recover');
        $r->add(['GET', 'POST'], '/restablecer/{token}', [AuthController::class, 'reset'])->name('account.reset');
        $r->post('/salir', [AuthController::class, 'logout'])->name('account.logout');
        // Quién ha entrado, para la cabecera: sin caché y solo el nombre de pila.
        $r->get('/estado.json', [AccountController::class, 'status'])->name('account.status');
    });

    // Lo que pide haber entrado: sin sesión, a la pantalla de entrada (y de vuelta a la página pedida).
    $r->group(['prefix' => $account, 'middleware' => ['customer', 'csrf']], static function (Router $r): void {
        $r->get('', [AccountController::class, 'index'])->name('account');
        $r->post('/verificacion/reenviar', [AccountController::class, 'resend'])->name('account.verify.resend');
        $r->get('/pedidos', [OrdersController::class, 'index'])->name('account.orders');
        $r->get('/pedidos/{number}', [OrdersController::class, 'show'])->name('account.order');
        $r->get('/descargas', [OrdersController::class, 'downloads'])->name('account.downloads');
        $r->post('/descargas/{id:\d+}/enlace', [OrdersController::class, 'downloadLink'])->name('account.downloads.link');
        $r->get('/direcciones', [AddressesController::class, 'index'])->name('account.addresses');
        $r->add(['GET', 'POST'], '/direcciones/nueva', [AddressesController::class, 'form'])->name('account.address.new');
        $r->add(['GET', 'POST'], '/direcciones/{id:\d+}', [AddressesController::class, 'form'])->name('account.address');
        $r->post('/direcciones/{id:\d+}/borrar', [AddressesController::class, 'delete'])->name('account.address.delete');
        $r->add(['GET', 'POST'], '/datos', [ProfileController::class, 'profile'])->name('account.profile');
        $r->get('/privacidad', [PrivacyController::class, 'index'])->name('account.privacy');
        $r->get('/privacidad/datos.json', [PrivacyController::class, 'export'])->name('account.privacy.export');
        $r->post('/privacidad/eliminar', [PrivacyController::class, 'delete'])->name('account.privacy.delete');
    });

    // ------------------------------------------------------------------ panel
    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        // La exportación de clientes tiene su propio permiso. La ruta fija del recurso se adelanta a la genérica del núcleo
        // (las rutas sin parámetros se miran primero), que solo pediría permiso de lectura.
        $r->get('/customers/export.csv', [CustomersController::class, 'exportCsv'])->name('admin.customers.export');
        $r->get('/r/customers/export.csv', [CustomersController::class, 'exportCsv']);
        $r->get('/customers/{id:\d+}/addresses', [CustomersController::class, 'addresses'])->name('admin.customers.addresses');
        $r->post('/customers/{id:\d+}/addresses/save', [CustomersController::class, 'saveAddress'])->name('admin.customers.addresses.save');
        $r->post('/customers/{id:\d+}/addresses/{address:\d+}/delete', [CustomersController::class, 'deleteAddress'])->name('admin.customers.addresses.delete');
        $r->post('/customers/{id:\d+}/anonymise', [CustomersController::class, 'anonymise'])->name('admin.customers.anonymise');
        $r->get('/customers/{id:\d+}/data.json', [CustomersController::class, 'data'])->name('admin.customers.data');
    });
};
