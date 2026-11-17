<?php
/**
 * AIFEC — apoyo de las pruebas de la transferencia: IBAN de mentira (bueno y malo), un pedido hecho con ella y el formulario de ajustes.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 *   TRANSFER_IBAN, TRANSFER_IBAN_BAD
 *       Un IBAN inventado con sus dígitos de control bien puestos (cuenta de ceros) y el mismo con ellos mal.
 *
 *   transferOrder(array $ajustes = []): array
 *       Una tienda con la transferencia y un pedido de dos camisetas hecho con ella: [app, id de la camiseta, resultado de place()].
 *
 *   transferForm(array $cambios = []): array
 *       Los campos del formulario de ajustes como los manda un navegador.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/../../payments-simulated/tests/PaymentsKit.php';

if (!defined('TRANSFER_IBAN')) {
    define('TRANSFER_IBAN', 'ES8200000000000000000000');
    define('TRANSFER_IBAN_BAD', 'ES8300000000000000000000');
}

if (!function_exists('transferOrder')) {
    /**
     * @param array<string,mixed> $settings
     *
     * @return array{0:App,1:int,2:array<string,mixed>}
     */
    function transferOrder(array $settings = []): array
    {
        $app = paymentsApp(['payments-transfer'], $settings + ['payments.transfer.iban' => TRANSFER_IBAN, 'payments.transfer.holder' => 'Sillas del Sur']);
        $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
        $placed = paymentsPlace($app, new TestClient($app), 'transfer', [[$p, 0, 2]]);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);

        return [$app, $p, $placed];
    }

    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function transferForm(array $over = []): array
    {
        return $over + [
            'payments.transfer.holder' => 'Sillas del Sur', 'payments.transfer.iban' => '', 'payments.transfer.bank' => 'Banco de Mentira',
            'payments.transfer.days' => '5', 'payments.transfer.note' => '', 'payments.transfer.title' => '', 'payments.transfer.description' => '',
        ];
    }
}
