<?php
/**
 * AIFEC — pruebas de los avisos de Redsys (notificación al comercio y vuelta del navegador).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\Redsys\RedsysConfig;
use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysNotification;
use Aifec\Payments\Redsys\RedsysRequest;
use Aifec\Payments\Redsys\RedsysSigner;

require_once __DIR__ . '/helpers.php';

test('un aviso autorizado y bien firmado se lee entero', function (): void {
    $post = redsysSignedPost([
        'Ds_Date' => '12%2F11%2F2026',
        'Ds_Hour' => '10%3A31',
        'Ds_Amount' => '4900',
        'Ds_Currency' => '978',
        'Ds_Order' => '1234ABCDEFGH',
        'Ds_MerchantCode' => '123456789',
        'Ds_Terminal' => '001',
        'Ds_Response' => '0000',
        'Ds_MerchantData' => 'cart%3D7%26x%3D1',
        'Ds_SecurePayment' => '1',
        'Ds_TransactionType' => '0',
        'Ds_AuthorisationCode' => '123456  ',
        'Ds_Card_Country' => '724',
        'Ds_Card_Brand' => '1',
    ]);
    $n = RedsysNotification::fromPost($post, redsysTestConfig());

    same('1234ABCDEFGH', $n->order);
    same(4900, $n->amount);
    same('978', $n->currency);
    same(0, $n->responseCode);
    same(true, $n->authorised());
    same(false, $n->isRefund());
    same('123456', $n->authCode);
    same('cart=7&x=1', $n->merchantData);
    same('724', $n->cardCountry);
    same('card', $n->payMethod);
    same(true, $n->secure);
    same('12/11/2026 10:31', $n->date);
    same('12/11/2026', $n->raw['Ds_Date']);
    same('123456789', $n->raw['Ds_MerchantCode']);
    same('Transaction approved', $n->responseText());
});

test('un aviso denegado es válido pero no autorizado', function (): void {
    $post = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0190', 'Ds_Amount' => '4900']);
    $n = RedsysNotification::fromPost($post, redsysTestConfig());
    same(190, $n->responseCode);
    same(false, $n->authorised());
    same('Declined by the issuer without a reason', $n->responseText());
    same('', $n->authCode);
    same('other', $n->payMethod);
    same(false, $n->secure);
    same('', $n->date);
});

test('ida y vuelta: una petición construida y un aviso fabricado con la misma clave', function (): void {
    $config = redsysTestConfig();
    $built = RedsysRequest::build($config, [
        'order' => RedsysRequest::orderNumber(15, 'T1'),
        'amount' => 2287,
        'urlNotify' => 'https://shop.test/notify',
        'urlOk' => 'https://shop.test/ok',
        'urlKo' => 'https://shop.test/ko',
        'merchantData' => 'cart=15',
    ]);
    $sent = RedsysSigner::decodeParameters($built['fields']['Ds_MerchantParameters']);

    $post = redsysSignedPost([
        'Ds_Order' => $sent['DS_MERCHANT_ORDER'],
        'Ds_Amount' => $sent['DS_MERCHANT_AMOUNT'],
        'Ds_Currency' => $sent['DS_MERCHANT_CURRENCY'],
        'Ds_MerchantData' => rawurlencode($sent['DS_MERCHANT_MERCHANTDATA']),
        'Ds_Response' => '0000',
    ]);
    $n = RedsysNotification::fromPost($post, $config);
    same('0015T1', $n->order);
    same(2287, $n->amount);
    same('978', $n->currency);
    same('cart=15', $n->merchantData);
    same(true, $n->authorised());
});

test('un aviso con la firma en base64 seguro para URL (- y _) se acepta', function (): void {
    $config = redsysTestConfig();
    $found = false;
    for ($i = 1; $i < 400 && !$found; ++$i) {
        $data = ['Ds_Order' => sprintf('%04dABC', $i), 'Ds_Response' => '0000', 'Ds_Amount' => '100'];
        $normal = redsysSignedPost($data);
        if (strpbrk($normal['Ds_Signature'], '+/') === false) {
            continue;
        }
        $found = true;
        $safe = redsysSignedPost($data, REDSYS_TEST_KEY, true);
        check(strpbrk($safe['Ds_Signature'], '-_') !== false || strpbrk($safe['Ds_Signature'], '+/') === false);
        same(sprintf('%04dABC', $i), RedsysNotification::fromPost($safe, $config)->order);
        same(sprintf('%04dABC', $i), RedsysNotification::fromPost($normal, $config)->order);
    }
    check($found, 'no se encontró un pedido con + o / en la firma');
});

test('un parámetro cambiado, otra clave o una firma vacía lanzan', function (): void {
    $config = redsysTestConfig();
    $good = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0190', 'Ds_Amount' => '4900']);
    same('1234ABCDEFGH', RedsysNotification::fromPost($good, $config)->order);

    // Un carácter de los parámetros cambiado: el importe de 4900 pasa a 4901.
    $json = (string) base64_decode($good['Ds_MerchantParameters'], true);
    $tampered = $good;
    $tampered['Ds_MerchantParameters'] = base64_encode(str_replace('4900', '4901', $json));
    throws(function () use ($tampered, $config): void {
        RedsysNotification::fromPost($tampered, $config);
    }, RedsysException::class, 'parámetros cambiados');

    // Un aviso denegado al que se le cambia la respuesta a 0000.
    $forged = $good;
    $forged['Ds_MerchantParameters'] = base64_encode(str_replace('0190', '0000', $json));
    throws(function () use ($forged, $config): void {
        RedsysNotification::fromPost($forged, $config);
    }, RedsysException::class, 'respuesta cambiada');

    $otherKey = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '4900'], REDSYS_OTHER_KEY);
    throws(function () use ($otherKey, $config): void {
        RedsysNotification::fromPost($otherKey, $config);
    }, RedsysException::class, 'otra clave');

    $empty = $good;
    $empty['Ds_Signature'] = '';
    throws(function () use ($empty, $config): void {
        RedsysNotification::fromPost($empty, $config);
    }, RedsysException::class, 'firma vacía');

    $noSignature = $good;
    unset($noSignature['Ds_Signature']);
    throws(function () use ($noSignature, $config): void {
        RedsysNotification::fromPost($noSignature, $config);
    }, RedsysException::class, 'sin firma');
});

test('faltan campos, versión de firma distinta y basura: lanzan', function (): void {
    $config = redsysTestConfig();
    throws(function () use ($config): void {
        RedsysNotification::fromPost([], $config);
    }, RedsysException::class, 'POST vacío');
    throws(function () use ($config): void {
        RedsysNotification::fromPost(['Ds_MerchantParameters' => '%%%', 'Ds_Signature' => 'x'], $config);
    }, RedsysException::class, 'basura');
    throws(function () use ($config): void {
        RedsysNotification::fromPost(['Ds_MerchantParameters' => ['a'], 'Ds_Signature' => ['b']], $config);
    }, RedsysException::class, 'campos que no son texto');

    foreach ([
        'sin respuesta' => ['Ds_Order' => '1234ABCDEFGH', 'Ds_Amount' => '4900'],
        'sin importe' => ['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000'],
        'respuesta rara' => ['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => 'OK', 'Ds_Amount' => '4900'],
        'importe con decimales' => ['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '49.00'],
        'importe negativo' => ['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '-1'],
    ] as $label => $data) {
        $post = redsysSignedPost($data);
        throws(function () use ($post, $config): void {
            RedsysNotification::fromPost($post, $config);
        }, RedsysException::class, $label);
    }

    // Firmado pero sin pedido: la firma no se puede ni calcular.
    $params = base64_encode('{"Ds_Response":"0000","Ds_Amount":"1"}');
    throws(function () use ($params, $config): void {
        RedsysNotification::fromPost(['Ds_MerchantParameters' => $params, 'Ds_Signature' => 'abc'], $config);
    }, RedsysException::class, 'sin pedido');

    $v2 = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '4900']);
    $v2['Ds_SignatureVersion'] = 'HMAC_SHA512_V2';
    throws(function () use ($v2, $config): void {
        RedsysNotification::fromPost($v2, $config);
    }, RedsysException::class, 'versión V2');

    // Sin Ds_SignatureVersion también vale (la vuelta del navegador puede no traerla).
    $noVersion = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '4900']);
    unset($noVersion['Ds_SignatureVersion']);
    same('1234ABCDEFGH', RedsysNotification::fromPost($noVersion, $config)->order);
});

test('la vuelta del navegador (GET con parámetros en base64 seguro) se lee con el mismo método', function (): void {
    $config = redsysTestConfig();
    $data = ['Ds_Order' => '0042R2', 'Ds_Response' => '9915', 'Ds_Amount' => '2287'];
    $params = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    $signature = strtr(RedsysSigner::sign($params, '0042R2', REDSYS_TEST_KEY), '+/', '-_');

    $n = RedsysNotification::fromPost(['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => $params, 'Ds_Signature' => $signature], $config);
    same('0042R2', $n->order);
    same(9915, $n->responseCode);
    same(false, $n->authorised());
    same('Payment cancelled by the user', $n->responseText());
});

test('los campos en mayúsculas (DS_ORDER…) y las claves del POST sin distinguir mayúsculas se aceptan', function (): void {
    $config = redsysTestConfig();
    $params = base64_encode((string) json_encode(['DS_ORDER' => '1234ABCDEFGH', 'DS_RESPONSE' => '0099', 'DS_AMOUNT' => 500, 'DS_SECUREPAYMENT' => '1']));
    $signature = RedsysSigner::sign($params, '1234ABCDEFGH', REDSYS_TEST_KEY);
    $n = RedsysNotification::fromPost(['DS_SIGNATUREVERSION' => 'HMAC_SHA256_V1', 'DS_MERCHANTPARAMETERS' => $params, 'DS_SIGNATURE' => $signature], $config);
    same('1234ABCDEFGH', $n->order);
    same(99, $n->responseCode);
    same(500, $n->amount);
    same(true, $n->authorised());
    same(true, $n->secure);
});

test('authorised(): 0 y 99 sí; 100, 190, 900 y 9915 no; 900 es devolución', function (): void {
    $config = redsysTestConfig();
    foreach ([[0, true], [99, true], [100, false], [190, false], [900, false], [9915, false], [9999, false]] as $case) {
        $post = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => sprintf('%04d', $case[0]), 'Ds_Amount' => '1']);
        $n = RedsysNotification::fromPost($post, $config);
        same($case[0], $n->responseCode);
        same($case[1], $n->authorised(), 'respuesta ' . $case[0]);
        same($case[0] === 900, $n->isRefund(), 'devolución ' . $case[0]);
    }
});

test('responseText: textos en inglés para los códigos corrientes y «Unknown response» para el resto', function (): void {
    same('Transaction approved', RedsysNotification::describe(0));
    same('Transaction approved', RedsysNotification::describe(99));
    same('Card expired', RedsysNotification::describe(101));
    same('Wrong security code (CVV)', RedsysNotification::describe(129));
    same('Card not valid for this service', RedsysNotification::describe(180));
    same('Cardholder authentication failed', RedsysNotification::describe(184));
    same('Declined by the issuer without a reason', RedsysNotification::describe(190));
    same('Wrong expiry date', RedsysNotification::describe(191));
    same('Payment cancelled by the user', RedsysNotification::describe(9915));
    same('Transaction waiting for the card data', RedsysNotification::describe(9998));
    same('Transaction redirected to the issuer to authenticate', RedsysNotification::describe(9999));
    same('Unknown response', RedsysNotification::describe(100));
    same('Unknown response', RedsysNotification::describe(12345));
    same('Unknown response', RedsysNotification::describe(-1));
});

test('forma de pago: tabla de códigos opcional, marca de tarjeta y «other»', function (): void {
    $config = redsysTestConfig();
    $base = ['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '1'];

    $withBrand = RedsysNotification::fromPost(redsysSignedPost($base + ['Ds_Card_Brand' => '2']), $config);
    same('card', $withBrand->payMethod);

    $none = RedsysNotification::fromPost(redsysSignedPost($base), $config);
    same('other', $none->payMethod);

    $codes = ['70' => 'bizum', '71' => 'wallet'];
    $bizum = RedsysNotification::fromPost(redsysSignedPost($base + ['Ds_ProcessedPayMethod' => '70']), $config, $codes);
    same('bizum', $bizum->payMethod);
    $wallet = RedsysNotification::fromPost(redsysSignedPost($base + ['Ds_ProcessedPayMethod' => '71', 'Ds_Card_Brand' => '1']), $config, $codes);
    same('wallet', $wallet->payMethod);
    $unknownCode = RedsysNotification::fromPost(redsysSignedPost($base + ['Ds_ProcessedPayMethod' => '5']), $config, $codes);
    same('other', $unknownCode->payMethod);

    throws(function () use ($base, $config): void {
        RedsysNotification::fromPost(redsysSignedPost($base), $config, ['70' => 'efectivo']);
    }, RedsysException::class, 'forma de pago inventada en la tabla');
});

test('el aviso es de solo lectura y no tiene campos inventados', function (): void {
    $n = RedsysNotification::fromPost(redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '1']), redsysTestConfig());
    same(true, isset($n->order));
    same(false, isset($n->inventado));
    throws(function () use ($n): void {
        $n->amount = 1;
    }, \LogicException::class, 'escribir');
    throws(function () use ($n): void {
        $n->inventado;
    }, \LogicException::class, 'leer un campo que no existe');
    throws(function (): void {
        $c = new ReflectionClass(RedsysNotification::class);
        $c->newInstance();
    }, \Throwable::class, 'el constructor es privado');
});

test('texto con «&», comillas, tildes y emoji en los datos del comercio: firma y lectura íntegras', function (): void {
    $config = redsysTestConfig();
    $text = 'Ñandú & "Cía" <b>€ 😀';
    $post = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '1', 'Ds_MerchantData' => rawurlencode($text)]);
    $n = RedsysNotification::fromPost($post, $config);
    same($text, $n->merchantData);
});
