<?php
/**
 * AIFEC — ayudas de las pruebas de la compra dentro del chat. No es una prueba (no acaba en «Test.php»): se carga con
 * require_once.
 *
 *   chatApp(array $ajustes = [], array $opciones = []): App
 *       Como assistantApp() con el módulo de transferencia bancaria y su IBAN (de mentira) puestos: la tienda ofrece dos formas de
 *       pago, la pasarela «gw» (se paga en línea) y «transfer» (se paga fuera). Un solo transportista, «Estándar».
 *
 *   chatCart(App $app, TestClient $web, int $productId, int $unidades = 1): void
 *       Añade un producto al carrito de ese navegador.
 *
 *   chatTotal(App $app, TestClient $web): int
 *       El total del carrito de ese navegador en céntimos, tal como lo calcula el carrito.
 *
 *   chatConfirm(TestClient $web): array
 *       Pulsa el botón «Confirmar pedido» (POST /asistente/confirmar) y devuelve el JSON de la respuesta.
 *
 *   chatAction(array $respuesta, string $tipo): ?array
 *       La acción de ese tipo de una respuesta del asistente, o null.
 *
 *   chatOrders(App $app): array
 *       Las filas de orders, de la más antigua a la más nueva.
 *
 *   chatAsk(App $app, TestClient $web, string $texto): array
 *       Escribe al asistente el correo, el nombre y la dirección de la frase de ejemplo (ver CHAT_DATA).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../payments-transfer/tests/TransferKit.php';

if (!defined('CHAT_DATA')) {
    /** Lo que escribe una persona de inventada: nombre, calle con piso, código postal y ciudad, teléfono y correo. */
    define('CHAT_DATA', 'Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid, 999000001, ana@correo.test');
}

if (!function_exists('chatApp')) {
    /**
     * @param array<string,mixed> $ajustes
     * @param array<string,mixed> $opciones
     */
    function chatApp(array $ajustes = [], array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['payments-transfer'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);

        return assistantApp($ajustes + ['payments.transfer.iban' => TRANSFER_IBAN, 'payments.transfer.holder' => 'Sillas del Sur'], $opciones);
    }

    /** Un segundo transportista, «Urgente» (12,95 € a la Península, 24 h), para que haya que elegir. Devuelve su id. */
    function chatFast(App $app): int
    {
        $fast = shippingAddCarrier($app, 'fast', ['name' => 'Urgente', 'delay' => '24 h']);
        foreach (['Peninsula' => 1295, 'Balearic Islands' => 1995, 'Canary Islands' => 2995, 'Ceuta and Melilla' => 2495] as $zone => $price) {
            shippingAddRate($app, $fast, shippingZoneId($app, $zone), 'weight', 0, 0, $price);
        }

        return $fast;
    }

    function chatCart(App $app, TestClient $web, int $productId, int $unidades = 1): void
    {
        $added = aifecCartRun($web, static function ($cart) use ($productId, $unidades): array {
            return $cart->add($productId, 0, $unidades);
        });
        check(is_array($added) && !empty($added['ok']), 'el producto se añade al carrito');
    }

    function chatTotal(App $app, TestClient $web): int
    {
        $summary = aifecCartRun($web, static function ($cart): array {
            return $cart->summary();
        });

        return is_array($summary) ? (int) $summary['totals']['total'] : -1;
    }

    /**
     * @return array<string,mixed>
     */
    function chatConfirm(TestClient $web): array
    {
        $web->postJson('/asistente/confirmar', []);

        return $web->json();
    }

    /**
     * El borrador de la caja de ese navegador: lo que se ha ido rellenando, venga del chat o de la web.
     *
     * @return array<string,mixed>
     */
    function chatDraft(App $app, TestClient $web): array
    {
        $draft = aifecCartRun($web, static function ($cart, App $application): array {
            return $application->service('checkout')->draft();
        });

        return is_array($draft) ? $draft : [];
    }

    /**
     * @param array<string,mixed> $respuesta
     *
     * @return array<string,mixed>|null
     */
    function chatAction(array $respuesta, string $tipo): ?array
    {
        foreach (isset($respuesta['actions']) && is_array($respuesta['actions']) ? $respuesta['actions'] : [] as $action) {
            if (isset($action['type']) && $action['type'] === $tipo) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function chatOrders(App $app): array
    {
        return $app->db()->select('SELECT * FROM {orders} ORDER BY id');
    }

    /**
     * @return array<string,mixed>
     */
    function chatAsk(App $app, TestClient $web, string $texto = CHAT_DATA): array
    {
        return assistantSay($web, $texto);
    }
}
