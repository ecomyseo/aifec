<?php
/**
 * AIFEC — apoyo de las pruebas de los módulos de pago sencillos (simulado, transferencia y contra reembolso).
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 *   paymentsApp(array $modulos, array $ajustes = []): App
 *       Aplicación de pedidos (con correo, medios y tareas, un transportista «std» y precios con impuestos dentro) con esos
 *       módulos de pago cargados y sin la forma de pago de mentira. Es un sitio en modo de depuración, como todos los de prueba.
 *
 *   paymentsPlace(App $app, TestClient $web, string $codigo, array $lineas, array $caja = []): array
 *       En UNA petición: añade las líneas ([[producto, variante, unidades], …]), elige esa forma de pago y llama a
 *       Orders::place(). Devuelve lo que devuelve place().
 *
 *   paymentsContext(array $cambios = []): array
 *       El contexto que la caja da a available() y a fee(): una compra de 20,00 € con envío a España.
 *
 *   paymentsSaveSettings(TestClient $web, string $modulo, array $formulario, string $pestana): Response
 *       Envía el formulario de ajustes de un módulo como lo manda un navegador (con su ficha).
 *
 *   paymentsCodes(array $formas): array
 *       Los códigos de una lista de formas de pago, en su orden.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!function_exists('paymentsApp')) {
    /**
     * @param string[]            $modulos
     * @param array<string,mixed> $ajustes
     */
    function paymentsApp(array $modulos, array $ajustes = []): App
    {
        // Los módulos de contra reembolso y de pago simulado no vienen encendidos de serie: se encienden como lo haría el panel.
        $ajustes += ['modules.active' => array_merge(['orders', 'mail', 'media', 'jobs'], $modulos)];

        return aifecOrderApp(['payment' => false, 'modules' => $modulos, 'settings' => $ajustes]);
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     *
     * @return array<string,mixed>
     */
    function paymentsPlace(App $app, TestClient $web, string $codigo, array $lineas, array $caja = []): array
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($codigo, $lineas, $caja): array {
            foreach ($lineas as $linea) {
                $added = $cart->add((int) $linea[0], isset($linea[1]) ? (int) $linea[1] : 0, isset($linea[2]) ? (int) $linea[2] : 1);
                check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            }
            $cart->setPaymentMethod($codigo);

            return $app->service('orders')->place(aifecOrderCheckout($caja));
        });
    }

    /**
     * @param array<string,mixed> $cambios
     *
     * @return array<string,mixed>
     */
    function paymentsContext(array $cambios = []): array
    {
        return $cambios + ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es'];
    }

    /**
     * @param array<string,mixed> $formulario
     */
    function paymentsSaveSettings(TestClient $web, string $modulo, array $formulario, string $pestana): Response
    {
        $web->get('/admin/settings/' . $modulo);

        return $web->post('/admin/settings/' . $modulo, ['_token' => aifecToken($web), '_tab' => $pestana, 'f' => $formulario]);
    }

    /**
     * @param array<int,\Aifec\Commerce\PaymentMethod> $formas
     *
     * @return string[]
     */
    function paymentsCodes(array $formas): array
    {
        $codes = [];
        foreach ($formas as $forma) {
            $codes[] = $forma->code();
        }

        return $codes;
    }
}
