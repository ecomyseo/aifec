<?php
/**
 * AIFEC — apoyo de las pruebas del módulo de pago por Redsys.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 *   rdsApp(array $ajustes = [], bool $claves = true): App
 *       Aplicación de pedidos con el módulo de Redsys encendido, el comercio de pruebas de Redsys puesto y la clave de pruebas
 *       guardada (cifrada, como lo hace el panel). Con $claves = false no hay ninguna clave.
 *
 *   rdsPlace(App $app, TestClient $web, string $codigo, int $producto, int $unidades = 1): array
 *       En UNA petición: añade el producto al carrito, elige esa forma de pago y llama a Orders::place().
 *
 *   rdsOrder(string $codigo = 'redsys', array $ajustes = []): array
 *       Un pedido recién hecho con esa forma de pago. Devuelve [app, producto, resultado de place(), parámetros de Redsys
 *       descodificados].
 *
 *   rdsParams(PaymentAction $accion): array
 *       Los parámetros (Ds_MerchantParameters) del formulario que va al banco, descodificados.
 *
 *   rdsRequestSignatureOk(string $parametrosB64, string $firma, string $clave): bool
 *       ¿Está bien firmada una petición del comercio al banco con esa clave?
 *
 *   rdsSigned(array $datos, string $clave = RDS_KEY): array
 *       Un aviso o una respuesta del banco firmados como los firma Redsys.
 *
 *   rdsAviso(string $numeroRedsys, int $importe, array $cambios = [], string $clave = RDS_KEY): array
 *       El aviso de un pago autorizado (respuesta 0000) con tarjeta; $cambios se funde encima (Ds_Response, Ds_Currency…).
 *
 *   rdsNotify(App $app, array $post): Response
 *       Lo que hace el servidor del banco: un POST sin sesión, sin cookies y sin ficha de formulario.
 *
 *   rdsOrderRow(App $app, array $placed): array
 *       El pedido completo, leído de nuevo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;
use Aifec\Payments\Redsys\RedsysSigner;

require_once __DIR__ . '/../../orders/tests/helpers.php';

/** Clave inventada para las pruebas: no es de ningún comercio ni vale contra Redsys. */
if (!defined('RDS_KEY')) {
    define('RDS_KEY', 'Y2xhdmUtaW52ZW50YWRhLUFJRkVDLTAx');
}

/** Otra clave, distinta: la de producción de las pruebas, o la de quien falsifica. */
if (!defined('RDS_LIVE_KEY')) {
    define('RDS_LIVE_KEY', 'b3RyYS1jbGF2ZS1pbnZlbnRhZGEtMDAy');
}

/** Número de comercio inventado. */
if (!defined('RDS_MERCHANT')) {
    define('RDS_MERCHANT', '123456789');
}

if (!function_exists('rdsApp')) {
    /**
     * @param array<string,mixed> $ajustes
     */
    function rdsApp(array $ajustes = [], bool $claves = true): App
    {
        // El módulo no viene encendido de serie: se enciende como lo haría el panel.
        $ajustes += ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-redsys']];
        $app = aifecOrderApp(['payment' => false, 'modules' => ['payments-redsys'], 'settings' => $ajustes]);
        $app->settings()->setMany([
            RedsysSettings::ENVIRONMENT => 'test',
            RedsysSettings::MERCHANT_CODE => RDS_MERCHANT,
            RedsysSettings::TERMINAL => '1',
        ]);
        if ($claves) {
            $app->setSecret(RedsysSettings::SECRET_TEST, RDS_KEY);
        }

        return $app;
    }

    function rdsPlace(App $app, TestClient $web, string $codigo, int $producto, int $unidades = 1): array
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($codigo, $producto, $unidades): array {
            $added = $cart->add($producto, 0, $unidades);
            check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            $cart->setPaymentMethod($codigo);

            return $app->service('orders')->place(aifecOrderCheckout());
        });
    }

    /**
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:int,2:array<string,mixed>,3:array<string,string>}
     */
    function rdsOrder(string $codigo = 'redsys', array $ajustes = []): array
    {
        $app = rdsApp($ajustes);
        if ($codigo === 'bizum') {
            $app->settings()->set(RedsysSettings::BIZUM, true);
        }
        $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
        $placed = rdsPlace($app, new TestClient($app), $codigo, $p);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        check($placed['action'] instanceof PaymentAction && $placed['action']->type === PaymentAction::FORM, 'la caja recibe un formulario para el banco');

        return [$app, $p, $placed, rdsParams($placed['action'])];
    }

    /**
     * @return array<string,string>
     */
    function rdsParams(PaymentAction $accion): array
    {
        $json = base64_decode($accion->fields['Ds_MerchantParameters'], true);
        check(is_string($json), 'los parámetros van en base64');
        $data = json_decode((string) $json, true);
        check(is_array($data), 'los parámetros son un JSON');

        return $data;
    }

    /**
     * ¿La firma de una PETICIÓN del comercio al banco es buena? (RedsysSigner::verify() es para avisos: busca «Ds_Order», y
     * una petición lleva «DS_MERCHANT_ORDER».)
     */
    function rdsRequestSignatureOk(string $parametrosB64, string $firma, string $clave): bool
    {
        $json = base64_decode($parametrosB64, true);
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['DS_MERCHANT_ORDER'])) {
            return false;
        }

        return hash_equals(RedsysSigner::sign($parametrosB64, (string) $data['DS_MERCHANT_ORDER'], $clave), $firma);
    }

    /**
     * @param array<string,mixed> $datos
     *
     * @return array<string,string>
     */
    function rdsSigned(array $datos, string $clave = RDS_KEY): array
    {
        $b64 = base64_encode((string) json_encode($datos, JSON_UNESCAPED_SLASHES));

        return [
            'Ds_SignatureVersion' => RedsysSigner::VERSION,
            'Ds_MerchantParameters' => $b64,
            'Ds_Signature' => RedsysSigner::sign($b64, (string) $datos['Ds_Order'], $clave),
        ];
    }

    /**
     * @param array<string,mixed> $cambios
     *
     * @return array<string,string>
     */
    function rdsAviso(string $numeroRedsys, int $importe, array $cambios = [], string $clave = RDS_KEY): array
    {
        return rdsSigned($cambios + [
            'Ds_Date' => '12%2F11%2F2026', 'Ds_Hour' => '10%3A30', 'Ds_SecurePayment' => '1', 'Ds_Card_Number' => '454881******0004',
            'Ds_Amount' => (string) $importe, 'Ds_Currency' => '978', 'Ds_Order' => $numeroRedsys, 'Ds_MerchantCode' => RDS_MERCHANT,
            'Ds_Terminal' => '001', 'Ds_Response' => '0000', 'Ds_TransactionType' => '0', 'Ds_AuthorisationCode' => '123456',
            'Ds_Card_Country' => '724', 'Ds_Card_Brand' => '1', 'Ds_ExpiryDate' => '3012',
        ], $clave);
    }

    /**
     * @param array<string,string> $post
     */
    function rdsNotify(App $app, array $post): Response
    {
        // El servidor del banco no es un navegador de la tienda: ni cookies ni «sec-fetch-site», y desde luego ninguna ficha.
        $bank = new TestClient($app);
        $bank->headers = ['user-agent' => 'Redsys/1.0'];

        return $bank->post('/pago/redsys/aviso', $post);
    }

    /**
     * @param array<string,mixed> $placed
     *
     * @return array<string,mixed>
     */
    function rdsOrderRow(App $app, array $placed): array
    {
        $order = $app->service('orders')->find((int) $placed['id']);
        check($order !== null, 'el pedido existe');

        return $order;
    }

    /**
     * Los cobros de un pedido de un tipo dado.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    function rdsPayments(array $order, string $tipo): array
    {
        $out = [];
        foreach ($order['payments'] as $payment) {
            if ((string) $payment['kind'] === $tipo) {
                $out[] = $payment;
            }
        }

        return $out;
    }
}
