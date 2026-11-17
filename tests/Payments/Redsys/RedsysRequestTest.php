<?php
/**
 * AIFEC — pruebas de la petición de pago a Redsys y de la configuración.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\Redsys\RedsysConfig;
use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysRequest;
use Aifec\Payments\Redsys\RedsysSigner;

require_once __DIR__ . '/helpers.php';

/** @return array<string,mixed> */
function redsysBaseRequest(): array
{
    return [
        'order' => '1234ABCDEFGH',
        'amount' => 4900,
        'urlNotify' => 'https://x/n',
        'urlOk' => 'https://x/ok',
        'urlKo' => 'https://x/ko',
        'description' => 'Módulo ñ €',
    ];
}

/** @return array<string,mixed> */
function redsysDecodeFields(array $built): array
{
    return RedsysSigner::decodeParameters($built['fields']['Ds_MerchantParameters']);
}

test('la petición en pruebas lleva los parámetros y la firma esperados (vector de un firmador independiente)', function (): void {
    // Vector de un comercio y una clave inventados, calculado con un firmador escrito aparte del de AIFEC
    // (utiles/aifec/redsys/quitar_credenciales_publicas.php), que antes reprodujo el vector del prototipo en Node.
    $config = new RedsysConfig('123456789', '1', REDSYS_TEST_KEY, 'test', '978', 'gmartos.es');
    $built = RedsysRequest::build($config, redsysBaseRequest());

    $expectedJson = '{"DS_MERCHANT_AMOUNT":"4900","DS_MERCHANT_ORDER":"1234ABCDEFGH","DS_MERCHANT_MERCHANTCODE":"123456789",'
        . '"DS_MERCHANT_CURRENCY":"978","DS_MERCHANT_TRANSACTIONTYPE":"0","DS_MERCHANT_TERMINAL":"1",'
        . '"DS_MERCHANT_MERCHANTURL":"https://x/n","DS_MERCHANT_URLOK":"https://x/ok","DS_MERCHANT_URLKO":"https://x/ko",'
        . '"DS_MERCHANT_CONSUMERLANGUAGE":"001","DS_MERCHANT_PRODUCTDESCRIPTION":"M' . chr(92) . 'u00f3dulo ' . chr(92) . 'u00f1 ' . chr(92) . 'u20ac",'
        . '"DS_MERCHANT_MERCHANTNAME":"gmartos.es","DS_MERCHANT_TITULAR":"","DS_MERCHANT_MERCHANTDATA":""}';
    same($expectedJson, base64_decode($built['fields']['Ds_MerchantParameters'], true));
    same(
        'eyJEU19NRVJDSEFOVF9BTU9VTlQiOiI0OTAwIiwiRFNfTUVSQ0hBTlRfT1JERVIiOiIxMjM0QUJDREVGR0giLCJEU19NRVJDSEFOVF9NRVJDSEFOVENPREUiOiIxMjM0NTY3ODkiLCJEU19NRVJDSEFOVF9DVVJSRU5DWSI6Ijk3OCIsIkRTX01FUkNIQU5UX1RSQU5TQUNUSU9OVFlQRSI6IjAiLCJEU19NRVJDSEFOVF9URVJNSU5BTCI6IjEiLCJEU19NRVJDSEFOVF9NRVJDSEFOVFVSTCI6Imh0dHBzOi8veC9uIiwiRFNfTUVSQ0hBTlRfVVJMT0siOiJodHRwczovL3gvb2siLCJEU19NRVJDSEFOVF9VUkxLTyI6Imh0dHBzOi8veC9rbyIsIkRTX01FUkNIQU5UX0NPTlNVTUVSTEFOR1VBR0UiOiIwMDEiLCJEU19NRVJDSEFOVF9QUk9EVUNUREVTQ1JJUFRJT04iOiJNXHUwMGYzZHVsbyBcdTAwZjEgXHUyMGFjIiwiRFNfTUVSQ0hBTlRfTUVSQ0hBTlROQU1FIjoiZ21hcnRvcy5lcyIsIkRTX01FUkNIQU5UX1RJVFVMQVIiOiIiLCJEU19NRVJDSEFOVF9NRVJDSEFOVERBVEEiOiIifQ==',
        $built['fields']['Ds_MerchantParameters']
    );
    same('lq4JnSdRFoytNZ/6oITa7xu7J9r9CHCG+R+8wJDZQ+8=', $built['fields']['Ds_Signature']);
    same('HMAC_SHA256_V1', $built['fields']['Ds_SignatureVersion']);
    same('https://sis-t.redsys.es:25443/sis/realizarPago', $built['url']);
});

test('la petición de Bizum en real lleva los parámetros y la firma esperados (PAYMETHODS z, titular y datos)', function (): void {
    $config = new RedsysConfig('123456789', '1', REDSYS_TEST_KEY, 'live', '978', 'gmartos.es');
    $p = redsysBaseRequest();
    $p['method'] = 'bizum';
    $p['titular'] = 'Ana Pérez';
    $p['merchantData'] = 'cart=7&x=1';
    $built = RedsysRequest::build($config, $p);

    same(
        'eyJEU19NRVJDSEFOVF9BTU9VTlQiOiI0OTAwIiwiRFNfTUVSQ0hBTlRfT1JERVIiOiIxMjM0QUJDREVGR0giLCJEU19NRVJDSEFOVF9NRVJDSEFOVENPREUiOiIxMjM0NTY3ODkiLCJEU19NRVJDSEFOVF9DVVJSRU5DWSI6Ijk3OCIsIkRTX01FUkNIQU5UX1RSQU5TQUNUSU9OVFlQRSI6IjAiLCJEU19NRVJDSEFOVF9URVJNSU5BTCI6IjEiLCJEU19NRVJDSEFOVF9NRVJDSEFOVFVSTCI6Imh0dHBzOi8veC9uIiwiRFNfTUVSQ0hBTlRfVVJMT0siOiJodHRwczovL3gvb2siLCJEU19NRVJDSEFOVF9VUkxLTyI6Imh0dHBzOi8veC9rbyIsIkRTX01FUkNIQU5UX0NPTlNVTUVSTEFOR1VBR0UiOiIwMDEiLCJEU19NRVJDSEFOVF9QUk9EVUNUREVTQ1JJUFRJT04iOiJNXHUwMGYzZHVsbyBcdTAwZjEgXHUyMGFjIiwiRFNfTUVSQ0hBTlRfTUVSQ0hBTlROQU1FIjoiZ21hcnRvcy5lcyIsIkRTX01FUkNIQU5UX1RJVFVMQVIiOiJBbmEgUFx1MDBlOXJleiIsIkRTX01FUkNIQU5UX01FUkNIQU5UREFUQSI6ImNhcnQ9NyZ4PTEiLCJEU19NRVJDSEFOVF9QQVlNRVRIT0RTIjoieiJ9',
        $built['fields']['Ds_MerchantParameters']
    );
    same('ercfyBFf7UuuPIo/W1Ib+W9olv9TadDpk8Bi7zA56GQ=', $built['fields']['Ds_Signature']);
    same('https://sis.redsys.es/sis/realizarPago', $built['url']);
});

test('la firma de la petición es la del pedido que lleva dentro y solo vale con su clave', function (): void {
    $built = RedsysRequest::build(redsysTestConfig(), redsysBaseRequest());
    $f = $built['fields'];
    same(RedsysSigner::sign($f['Ds_MerchantParameters'], '1234ABCDEFGH', REDSYS_TEST_KEY), $f['Ds_Signature']);
    check(RedsysSigner::sign($f['Ds_MerchantParameters'], '1234ABCDEFGH', REDSYS_OTHER_KEY) !== $f['Ds_Signature'], 'otra clave, otra firma');
    check(RedsysSigner::sign($f['Ds_MerchantParameters'], '1234ABCDEFGI', REDSYS_TEST_KEY) !== $f['Ds_Signature'], 'otro pedido, otra firma');
    $params = redsysDecodeFields($built);
    same('1234ABCDEFGH', $params['DS_MERCHANT_ORDER']);
    same('123456789', $params['DS_MERCHANT_MERCHANTCODE']);
});

test('el JSON de parámetros no lleva ningún byte fuera de ASCII (ñ, €, emoji) y el texto vuelve íntegro', function (): void {
    $p = redsysBaseRequest();
    $p['description'] = 'Año nuevo ñandú 5 € & "comillas" 😀';
    $p['titular'] = 'José Ñúñez';
    $built = RedsysRequest::build(redsysTestConfig(), $p);

    $json = (string) base64_decode($built['fields']['Ds_MerchantParameters'], true);
    same(0, preg_match('/[^\x20-\x7e]/', $json), 'hay un carácter fuera de ASCII imprimible');
    notContains('\\/', $json, 'las barras no se escapan');
    $decoded = redsysDecodeFields($built);
    same('Año nuevo ñandú 5 € & "comillas" 😀', $decoded['DS_MERCHANT_PRODUCTDESCRIPTION']);
    same('José Ñúñez', $decoded['DS_MERCHANT_TITULAR']);
    $f = $built['fields'];
    same(RedsysSigner::sign($f['Ds_MerchantParameters'], '1234ABCDEFGH', REDSYS_TEST_KEY), $f['Ds_Signature']);
});

test('los textos largos se recortan por caracteres, no por bytes', function (): void {
    $p = redsysBaseRequest();
    $p['description'] = str_repeat('ñ', 200);
    $p['titular'] = str_repeat('€', 100);
    $built = RedsysRequest::build(new RedsysConfig('1', '1', REDSYS_TEST_KEY, 'test', '978', str_repeat('é', 40)), $p);
    $decoded = redsysDecodeFields($built);
    same(str_repeat('ñ', 125), $decoded['DS_MERCHANT_PRODUCTDESCRIPTION']);
    same(str_repeat('€', 60), $decoded['DS_MERCHANT_TITULAR']);
    same(str_repeat('é', 25), $decoded['DS_MERCHANT_MERCHANTNAME']);
});

test('el importe llega como cadena de céntimos y 0, negativos y no enteros lanzan', function (): void {
    $c = redsysTestConfig();
    $p = redsysBaseRequest();
    $p['amount'] = 4999;
    $decoded = redsysDecodeFields(RedsysRequest::build($c, $p));
    same('4999', $decoded['DS_MERCHANT_AMOUNT']);

    $p['amount'] = '4999';
    $decoded = redsysDecodeFields(RedsysRequest::build($c, $p));
    same('4999', $decoded['DS_MERCHANT_AMOUNT']);

    foreach ([0, -1, -4999, 49.99, 4999.0, '49.99', '-5', '', 'abc', null, true, [4999]] as $bad) {
        $p['amount'] = $bad;
        throws(function () use ($c, $p): void {
            RedsysRequest::build($c, $p);
        }, RedsysException::class, 'importe ' . var_export($bad, true));
    }
    unset($p['amount']);
    throws(function () use ($c, $p): void {
        RedsysRequest::build($c, $p);
    }, RedsysException::class, 'sin importe');
});

test('número de pedido: se rechazan los que no valen para Redsys', function (): void {
    $c = redsysTestConfig();
    foreach (['ABC1', '123', '', '1234ABCDEFGHI', '12345678901234', '12ab5678', '1234 ABC', "1234AB\n", '1234ÑÑÑ', '1234-ABC', 'ÁÁÁÁ1234'] as $bad) {
        $p = redsysBaseRequest();
        $p['order'] = $bad;
        throws(function () use ($c, $p): void {
            RedsysRequest::build($c, $p);
        }, RedsysException::class, 'pedido ' . var_export($bad, true));
    }
    foreach (['1234', '0007', '1234ABCD', '123456789012', '1234abcdefgh'] as $good) {
        $p = redsysBaseRequest();
        $p['order'] = $good;
        $decoded = redsysDecodeFields(RedsysRequest::build($c, $p));
        same($good, $decoded['DS_MERCHANT_ORDER']);
    }
    $p = redsysBaseRequest();
    $p['order'] = 1234;
    throws(function () use ($c, $p): void {
        RedsysRequest::build($c, $p);
    }, RedsysException::class, 'un pedido entero no es una cadena');
});

test('orderNumber: secuencial con ceros a la izquierda, sufijo opcional y tope de 12', function (): void {
    same('0007', RedsysRequest::orderNumber(7));
    same('0000', RedsysRequest::orderNumber(0));
    same('1234', RedsysRequest::orderNumber(1234));
    same('12345', RedsysRequest::orderNumber(12345));
    same('0007A1', RedsysRequest::orderNumber(7, 'A1'));
    same('0007', RedsysRequest::orderNumber(7, ''));
    same('0007abcdefgh', RedsysRequest::orderNumber(7, 'abcdefgh'));
    same('123456789012', RedsysRequest::orderNumber(123456789012));

    foreach ([[7, 'abcdefghi'], [7, 'A-1'], [7, 'ñ'], [7, "A\n"], [-1, null], [1234567890123, null]] as $case) {
        throws(function () use ($case): void {
            RedsysRequest::orderNumber($case[0], $case[1]);
        }, RedsysException::class, 'orderNumber ' . var_export($case, true));
    }

    // Lo que fabrica orderNumber siempre vale para build().
    $decoded = redsysDecodeFields(RedsysRequest::build(redsysTestConfig(), ['order' => RedsysRequest::orderNumber(42, 'R2')] + redsysBaseRequest()));
    same('0042R2', $decoded['DS_MERCHANT_ORDER']);
});

test('método de pago: tarjeta sin PAYMETHODS, Bizum z, carteras xpay y otro lanza', function (): void {
    $c = redsysTestConfig();
    $p = redsysBaseRequest();

    $decoded = redsysDecodeFields(RedsysRequest::build($c, $p));
    check(!array_key_exists('DS_MERCHANT_PAYMETHODS', $decoded), 'sin método no hay PAYMETHODS');

    $p['method'] = 'card';
    $decoded = redsysDecodeFields(RedsysRequest::build($c, $p));
    check(!array_key_exists('DS_MERCHANT_PAYMETHODS', $decoded), 'card no manda PAYMETHODS');

    $p['method'] = 'bizum';
    same('z', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_PAYMETHODS']);

    $p['method'] = 'wallets';
    same('xpay', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_PAYMETHODS']);

    $p['method'] = 'paypal';
    throws(function () use ($c, $p): void {
        RedsysRequest::build($c, $p);
    }, RedsysException::class);
});

test('idioma y tipo de transacción: tabla de idiomas, castellano por defecto y tipo 0', function (): void {
    $c = redsysTestConfig();
    $expected = ['es' => '001', 'en' => '002', 'ca' => '003', 'fr' => '004', 'de' => '005', 'pt' => '009', 'it' => '007'];
    foreach ($expected as $code => $redsys) {
        $p = redsysBaseRequest();
        $p['language'] = $code;
        same($redsys, redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_CONSUMERLANGUAGE'], $code);
    }
    $p = redsysBaseRequest();
    same('001', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_CONSUMERLANGUAGE']);
    $p['language'] = 'xx';
    same('001', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_CONSUMERLANGUAGE']);

    $p = redsysBaseRequest();
    same('0', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_TRANSACTIONTYPE']);
    $p['transactionType'] = '3';
    same('3', redsysDecodeFields(RedsysRequest::build($c, $p))['DS_MERCHANT_TRANSACTIONTYPE']);
    $p['transactionType'] = 'ab';
    throws(function () use ($c, $p): void {
        RedsysRequest::build($c, $p);
    }, RedsysException::class);
});

test('direcciones obligatorias y parámetros desconocidos', function (): void {
    $c = redsysTestConfig();
    foreach (['urlNotify', 'urlOk', 'urlKo'] as $key) {
        $p = redsysBaseRequest();
        unset($p[$key]);
        throws(function () use ($c, $p): void {
            RedsysRequest::build($c, $p);
        }, RedsysException::class, 'sin ' . $key);

        $p[$key] = 'javascript:alert(1)';
        throws(function () use ($c, $p): void {
            RedsysRequest::build($c, $p);
        }, RedsysException::class, $key . ' no http');
    }
    $p = redsysBaseRequest();
    $p['importe'] = 5;
    throws(function () use ($c, $p): void {
        RedsysRequest::build($c, $p);
    }, RedsysException::class, 'parámetro desconocido');
});

test('la configuración: direcciones, valores de prueba y validación', function (): void {
    same('https://sis-t.redsys.es:25443/sis/realizarPago', redsysTestConfig()->endpoint());
    $live = new RedsysConfig('123456789', '2', REDSYS_TEST_KEY, 'live', '840', 'Tienda');
    same('https://sis.redsys.es/sis/realizarPago', $live->endpoint());
    same(true, $live->isLive());
    same('840', $live->currency());
    same('Tienda', $live->merchantName());

    $d = redsysTestConfig();
    same('123456789', $d->merchantCode());
    same('1', $d->terminal());
    same(REDSYS_TEST_KEY, $d->secretKey());
    same('978', $d->currency());
    same('test', $d->environment());
    same(false, $d->isLive());

    $decoded = redsysDecodeFields(RedsysRequest::build($live, redsysBaseRequest()));
    same('840', $decoded['DS_MERCHANT_CURRENCY']);

    throws(function (): void {
        new RedsysConfig('1', '1', 'k', 'produccion');
    }, RedsysException::class);
    throws(function (): void {
        new RedsysConfig('', '1', 'k');
    }, RedsysException::class);
    throws(function (): void {
        new RedsysConfig('1', '', 'k');
    }, RedsysException::class);
    throws(function (): void {
        new RedsysConfig('1', '1', 'k', 'test', 'EUR');
    }, RedsysException::class);
});
