<?php
/**
 * AIFEC — ayudas de prueba de las facturas. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   invoicesApp(array $opciones = []): App
 *       Aplicación con los pedidos (y el catálogo, el correo, los medios y las tareas) y las facturas, con la pasarela «gw» como cobro
 *       real (sus pedidos no son de pruebas). La tienda tiene razón social, NIF B12345678 y dirección. $opciones['settings'] se funde encima de los ajustes de serie; $opciones['modules'] añade módulos.
 *
 *   invoicesOrder(App $app, array $lineas, array $caja = [], bool $pruebas = false): int
 *       Un pedido pagado (la pasarela avisa con el importe justo; con $pruebas, como cobro de pruebas) y devuelve su id. $lineas
 *       como en aifecOrderPlace(): [[producto, variante, unidades], …].
 *
 *   invoicesPdfText(string $pdf): string
 *       El texto del PDF, extraído como lo hacen las pruebas de core/src/Pdf (sin los espacios duros).
 *
 *   invoicesCounter(App $app, string $serie): int
 *       El valor del contador sequences «invoice:<serie>» (0 si no existe).
 *
 *   invoicesDownload(App $app, array $factura, ?string $ficha = null): string
 *       La dirección de descarga de una factura con la ficha de su pedido (o la que se pase).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/../../orders/tests/helpers.php';
require_once __DIR__ . '/../../../tests/Pdf/helpers.php';

if (!function_exists('invoicesApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function invoicesApp(array $opciones = []): App
    {
        $settings = isset($opciones['settings']) && is_array($opciones['settings']) ? $opciones['settings'] : [];
        $opciones['settings'] = $settings + [
            'store.name' => 'Tienda de Pruebas', 'store.legal_name' => 'Tienda de Pruebas S.L.', 'store.vat_id' => 'B12345678',
            'store.address' => "Calle Mayor 1\n35001 Las Palmas de Gran Canaria", 'store.email' => 'tienda@ejemplo.test', 'store.phone' => '999000001',
        ];
        $opciones['modules'] = array_merge(['invoices'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);

        $app = aifecOrderApp($opciones);
        // La pasarela de pruebas crea los pedidos «de pruebas» (serie TEST): aquí los pedidos son de verdad salvo que se pida lo contrario.
        aifecOrderPayment($app)->test = false;

        return $app;
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     */
    function invoicesOrder(App $app, array $lineas, array $caja = [], bool $pruebas = false): int
    {
        static $n = 0;
        ++$n;
        $placed = aifecOrderPlace($app, new TestClient($app), $lineas, $caja);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        $order = $app->service('orders')->find((int) $placed['id']);
        check($order !== null, 'el pedido existe');
        check($app->service('orders')->markPaid((int) $placed['id'], 'gw', 'INV-' . $n, (int) $order['total'], [], $pruebas), 'el cobro se apunta');

        return (int) $placed['id'];
    }

    function invoicesPdfText(string $pdf): string
    {
        $content = pdfTestContent($pdf);
        $out = [];
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\) Tj/s', $content, $found) > 0) {
            foreach ($found[1] as $raw) {
                $bytes = (string) preg_replace_callback('/\\\\(.)/s', static function (array $m): string {
                    return $m[1];
                }, $raw);
                $out[] = str_replace("\xC2\xA0", ' ', (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'));
            }
        }

        return implode("\n", $out);
    }

    function invoicesCounter(App $app, string $serie): int
    {
        return (int) $app->db()->value('SELECT value FROM {sequences} WHERE name = ?', ['invoice:' . $serie]);
    }

    /**
     * @param array<string,mixed> $factura
     */
    function invoicesDownload(App $app, array $factura, ?string $ficha = null): string
    {
        $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [(int) $factura['order_id']]);
        $secret = $ficha !== null ? $ficha : $app->service('orders')->viewTokenOf($number);
        check($secret !== '' || $ficha !== null, 'el pedido tiene ficha');

        return '/factura/' . $factura['full_number'] . '/' . $secret;
    }
}
