<?php
/**
 * AIFEC — ayudas de prueba del panel de pedidos. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   adminOrdersApp(array $opciones = []): App
 *       Como aifecOrderApp() (con correo, medios y tareas), con la pasarela «gw» fuera de pruebas y una forma de pago
 *       «transfer» sin pasarela (el pedido queda en espera y la transferencia no se devuelve por la pasarela).
 *
 *   adminOrdersProduct(App $app, array $datos = []): int
 *       Una camiseta de 20,00 € con diez unidades y 200 g (dos unidades con el envío de serie: 44,95 €).
 *
 *   adminOrdersPlace(App $app, string $metodo, array $lineas, array $caja = []): int
 *       Crea un pedido sin pagar con esa forma de pago y devuelve su id. $lineas = [[producto, variante, unidades], …].
 *
 *   adminOrdersPaid(App $app, string $metodo, array $lineas, array $caja = []): int
 *       Lo mismo, y además da el cobro por bueno (la pasarela avisa con el importe justo).
 *
 *   adminOrdersPost(TestClient $web, string $url, array $datos = []): Response
 *       POST con la ficha de formulario de una página del panel recién abierta. No sigue la redirección.
 *
 *   adminOrdersRole(App $app, string $codigo, array $permisos): void
 *       Un papel nuevo con esos permisos (se entra con adminClient($app, $codigo)).
 *
 *   adminOrdersNumber(App $app, int $id): string
 *       El número del pedido.
 *
 *   adminOrdersSees(string $html, string $numero): bool
 *       ¿Sale ese número de pedido en la tabla del listado?
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

if (!function_exists('adminOrdersApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function adminOrdersApp(array $opciones = []): App
    {
        $app = aifecOrderApp($opciones);
        aifecOrderPayment($app)->test = false;
        $transfer = new AifecOrderPayment('transfer');
        $transfer->test = false;
        $transfer->canRefund = false;
        $transfer->action = PaymentAction::instructions('<p>IBAN de mentira</p>');
        $app->service('payments')->register($transfer);

        return $app;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function adminOrdersProduct(App $app, array $datos = []): int
    {
        return aifecProduct($app, $datos + ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     */
    function adminOrdersPlace(App $app, string $metodo, array $lineas, array $caja = []): int
    {
        $web = new TestClient($app);
        $placed = aifecCartRun($web, static function ($cart, App $app) use ($lineas, $caja, $metodo): array {
            foreach ($lineas as $linea) {
                $added = $cart->add((int) $linea[0], isset($linea[1]) ? (int) $linea[1] : 0, isset($linea[2]) ? (int) $linea[2] : 1);
                check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            }
            $cart->setPaymentMethod($metodo);

            return $app->service('orders')->place(aifecOrderCheckout($caja));
        });
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);

        return (int) $placed['id'];
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     */
    function adminOrdersPaid(App $app, string $metodo, array $lineas, array $caja = []): int
    {
        static $n = 0;
        ++$n;
        $id = adminOrdersPlace($app, $metodo, $lineas, $caja);
        $order = $app->service('orders')->find($id);
        check($order !== null, 'el pedido existe');
        check($app->service('orders')->markPaid($id, $metodo, 'PAY-' . $n, (int) $order['total']), 'el cobro se apunta');

        return $id;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function adminOrdersPost(TestClient $web, string $url, array $datos = []): Response
    {
        $web->get('/admin/profile');

        return $web->post($url, ['_token' => aifecToken($web)] + $datos);
    }

    /**
     * @param string[] $permisos
     */
    function adminOrdersRole(App $app, string $codigo, array $permisos): void
    {
        $app->db()->insert('roles', ['code' => $codigo, 'name' => ucfirst($codigo), 'caps' => json_encode($permisos), 'system' => 0]);
    }

    function adminOrdersNumber(App $app, int $id): string
    {
        return (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    }

    function adminOrdersSees(string $html, string $numero): bool
    {
        return strpos($html, '>' . $numero . '</span>') !== false;
    }
}
