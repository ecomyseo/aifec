<?php
/**
 * AIFEC — pruebas de la firma HMAC_SHA256_V1 de Redsys.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysSigner;

require_once __DIR__ . '/helpers.php';

test('la firma coincide con la de un firmador independiente (vectores fijos)', function (): void {
    // Claves inventadas. Valores calculados con un firmador escrito aparte (utiles/aifec/redsys/recalcular_vectores_firma.php),
    // el mismo que reprodujo las firmas del prototipo en Node.
    same('4xYnRIeKci7V72c1aD5DfgWHDBOmsfNa3AFNSh4QNmU=', RedsysSigner::sign('eyJhIjoxfQ==', '1234ABCDEFGH', REDSYS_TEST_KEY));
    same('Kc0IQQZ14gKQxthN2RyHmaN1EOukSCvQCIKFWQqAjA0=', RedsysSigner::sign('eyJhIjoxfQ==', '1234ABCDEFGH', REDSYS_OTHER_KEY));
});

test('un pedido corto se rellena con ceros hasta múltiplo de 8', function (): void {
    same('QdzeuE/p5cBfQ815mbsgwYxuc4wMRXSHf+1m6KLWjUM=', RedsysSigner::sign('eyJhIjoxfQ==', '0007', REDSYS_TEST_KEY));
    // Un pedido de 8 caracteres exactos no lleva relleno y uno de 9 sí: las firmas son distintas.
    check(RedsysSigner::sign('x', '12345678', REDSYS_TEST_KEY) !== RedsysSigner::sign('x', '123456789', REDSYS_TEST_KEY));
});

test('sign también se puede llamar desde una instancia', function (): void {
    $signer = new RedsysSigner();
    same(RedsysSigner::sign('abc', '1234', REDSYS_TEST_KEY), $signer->sign('abc', '1234', REDSYS_TEST_KEY));
});

test('sign rechaza una clave que no es de 24 bytes en base64 y un pedido vacío', function (): void {
    throws(function (): void {
        RedsysSigner::sign('abc', '1234', 'no es base64 !!');
    }, RedsysException::class);
    throws(function (): void {
        RedsysSigner::sign('abc', '1234', base64_encode('corta'));
    }, RedsysException::class);
    throws(function (): void {
        RedsysSigner::sign('abc', '', REDSYS_TEST_KEY);
    }, RedsysException::class);
});

test('verify acepta una firma buena, en base64 normal y en base64 seguro para URL', function (): void {
    $post = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '4900']);
    same(true, RedsysSigner::verify($post['Ds_MerchantParameters'], $post['Ds_Signature'], REDSYS_TEST_KEY));

    // Se busca un pedido cuya firma lleve + o / para que la variante segura cambie algo de verdad.
    $found = false;
    for ($i = 1; $i < 400 && !$found; ++$i) {
        $data = ['Ds_Order' => sprintf('%04dABC', $i), 'Ds_Response' => '0000', 'Ds_Amount' => '1'];
        $normal = redsysSignedPost($data);
        if (strpbrk($normal['Ds_Signature'], '+/') === false) {
            continue;
        }
        $found = true;
        $safe = redsysSignedPost($data, REDSYS_TEST_KEY, true);
        check($safe['Ds_Signature'] !== $normal['Ds_Signature'], 'la variante segura tiene que ser distinta');
        notContains('+', $safe['Ds_Signature']);
        notContains('/', $safe['Ds_Signature']);
        same(true, RedsysSigner::verify($safe['Ds_MerchantParameters'], $safe['Ds_Signature'], REDSYS_TEST_KEY));
        same(true, RedsysSigner::verify($normal['Ds_MerchantParameters'], $normal['Ds_Signature'], REDSYS_TEST_KEY));
    }
    check($found, 'no se encontró un pedido con + o / en la firma');
});

test('verify también acepta los parámetros en base64 seguro para URL y el pedido en mayúsculas', function (): void {
    $data = ['DS_ORDER' => '1234ABCDEFGH', 'DS_RESPONSE' => '0000', 'DS_AMOUNT' => '4900'];
    $params = base64_encode((string) json_encode($data));
    $signature = RedsysSigner::sign($params, '1234ABCDEFGH', REDSYS_TEST_KEY);
    same(true, RedsysSigner::verify($params, $signature, REDSYS_TEST_KEY));

    $safeParams = rtrim(strtr($params, '+/', '-_'), '=');
    $safeSig = RedsysSigner::sign($safeParams, '1234ABCDEFGH', REDSYS_TEST_KEY);
    same(true, RedsysSigner::verify($safeParams, $safeSig, REDSYS_TEST_KEY));
});

test('verify rechaza parámetros cambiados, otra clave, firma vacía y basura, sin lanzar', function (): void {
    $post = redsysSignedPost(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '4900']);
    $tampered = base64_encode((string) json_encode(['Ds_Order' => '1234ABCDEFGH', 'Ds_Response' => '0000', 'Ds_Amount' => '1']));
    same(false, RedsysSigner::verify($tampered, $post['Ds_Signature'], REDSYS_TEST_KEY), 'importe cambiado');
    same(false, RedsysSigner::verify($post['Ds_MerchantParameters'], $post['Ds_Signature'], REDSYS_OTHER_KEY), 'otra clave');
    same(false, RedsysSigner::verify($post['Ds_MerchantParameters'], '', REDSYS_TEST_KEY), 'firma vacía');
    same(false, RedsysSigner::verify('', $post['Ds_Signature'], REDSYS_TEST_KEY), 'parámetros vacíos');
    same(false, RedsysSigner::verify('%%%', 'x', REDSYS_TEST_KEY), 'basura');
    same(false, RedsysSigner::verify($post['Ds_MerchantParameters'], $post['Ds_Signature'], 'clave rota'), 'clave rota');
    same(false, RedsysSigner::verify(base64_encode('{"Ds_Amount":"1"}'), 'abc', REDSYS_TEST_KEY), 'sin pedido');

    // Un solo carácter de la firma cambiado.
    $sig = $post['Ds_Signature'];
    $bad = ($sig[0] === 'A' ? 'B' : 'A') . substr($sig, 1);
    same(false, RedsysSigner::verify($post['Ds_MerchantParameters'], $bad, REDSYS_TEST_KEY), 'un carácter de la firma');
});

test('field busca sin distinguir mayúsculas y solo devuelve valores simples', function (): void {
    $data = ['DS_ORDER' => '1234', 'Ds_Amount' => 49, 'Ds_Nested' => ['a' => 1], 'Ds_Flag' => true];
    same('1234', RedsysSigner::field($data, 'Ds_Order'));
    same('49', RedsysSigner::field($data, 'ds_amount'));
    same('1', RedsysSigner::field($data, 'Ds_Flag'));
    same(null, RedsysSigner::field($data, 'Ds_Nested'));
    same(null, RedsysSigner::field($data, 'Ds_Missing'));
});
