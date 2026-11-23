<?php
/**
 * AIFEC — pruebas de WebAuthn con criptografía real: alta y entrada con una clave P-256 generada en la prueba, y cada motivo de
 * rechazo (desafío, origen, sitio, indicadores, algoritmo, firma alterada, contador que no sube).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Security\Cbor;
use Aifec\Modules\Security\WebAuthn;

require_once __DIR__ . '/helpers.php';

const WA_ORIGIN = 'https://tienda.test';
const WA_RP = 'tienda.test';

/** @return array{0:array<string,mixed>,1:array<string,string>} la passkey y lo guardado al darla de alta */
function waRegister(string $challenge = 'desafio-de-alta'): array
{
    $key = FakePasskey::create();
    $in = $key->registration($challenge, WA_ORIGIN, WA_RP);
    $saved = WebAuthn::verifyRegistration(['id' => $in['id']] + $in['response'], $challenge, WA_ORIGIN, WA_RP);

    return [$key, $saved];
}

/** @param array<string,mixed> $in */
function waFails(callable $fn, string $part): void
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        contains($part, $e->getMessage());

        return;
    }
    throw new \RuntimeException('Se esperaba un rechazo con «' . $part . '»');
}

test('el CBOR de una passkey se lee: enteros, bytes, texto y mapas', function (): void {
    $data = FakePasskey::map([[FakePasskey::int(1), FakePasskey::int(2)], [FakePasskey::int(-2), FakePasskey::bytes('abc')], [FakePasskey::text('fmt'), FakePasskey::text('none')]]);
    same([1 => 2, -2 => 'abc', 'fmt' => 'none'], Cbor::decode($data));
    $offset = 0;
    $two = FakePasskey::int(300) . FakePasskey::int(-1000);
    same(300, Cbor::decode($two, $offset));
    same(-1000, Cbor::decode($two, $offset));
    same(strlen($two), $offset);
});

test('el CBOR roto o no admitido se rechaza', function (): void {
    throws(function (): void {
        Cbor::decode("\xa2\x01");
    }, \InvalidArgumentException::class);
    throws(function (): void {
        Cbor::decode("\x5f\x41a\xff"); // cadena de longitud indefinida
    }, \InvalidArgumentException::class);
    throws(function (): void {
        Cbor::decode(str_repeat("\x81", 20) . "\x01"); // listas dentro de listas sin fin razonable
    }, \InvalidArgumentException::class);
});

test('el alta de una passkey se acepta y devuelve el identificador y la clave pública', function (): void {
    [$key, $saved] = waRegister();
    same($key->id(), $saved['credentialId']);
    contains('BEGIN PUBLIC KEY', $saved['publicKey']);
    same(0, $saved['signCount']);
});

test('el alta se rechaza por desafío, origen, sitio, indicadores y algoritmo', function (): void {
    $key = FakePasskey::create();
    $good = $key->registration('c1', WA_ORIGIN, WA_RP);
    $in = ['id' => $good['id']] + $good['response'];
    waFails(function () use ($in): void {
        WebAuthn::verifyRegistration($in, 'otro', WA_ORIGIN, WA_RP);
    }, 'challenge');
    waFails(function () use ($in): void {
        WebAuthn::verifyRegistration($in, 'c1', 'https://otra.test', WA_RP);
    }, 'origin');
    waFails(function () use ($in): void {
        WebAuthn::verifyRegistration($in, 'c1', WA_ORIGIN, 'otra.test');
    }, 'relying party');
    $noUv = $key->registration('c1', WA_ORIGIN, WA_RP, 0x41);
    waFails(function () use ($noUv): void {
        WebAuthn::verifyRegistration(['id' => $noUv['id']] + $noUv['response'], 'c1', WA_ORIGIN, WA_RP);
    }, 'verified');
    $rsa = $key->registration('c1', WA_ORIGIN, WA_RP, 0x45, -257);
    waFails(function () use ($rsa): void {
        WebAuthn::verifyRegistration(['id' => $rsa['id']] + $rsa['response'], 'c1', WA_ORIGIN, WA_RP);
    }, 'ES256');
    $other = ['id' => 'AAAA'] + $good['response'];
    waFails(function () use ($other): void {
        WebAuthn::verifyRegistration($other, 'c1', WA_ORIGIN, WA_RP);
    }, 'credential id');
});

test('la entrada con una firma buena devuelve el contador nuevo', function (): void {
    [$key, $saved] = waRegister();
    $in = $key->assertion('c2', WA_ORIGIN, WA_RP, 5)['response'];
    same(5, WebAuthn::verifyAssertion($in, 'c2', WA_ORIGIN, WA_RP, $saved['publicKey'], 0));
    // Llaves que siempre mandan contador cero (iCloud, Google): se aceptan mientras el guardado también sea cero.
    $zero = $key->assertion('c3', WA_ORIGIN, WA_RP, 0)['response'];
    same(0, WebAuthn::verifyAssertion($zero, 'c3', WA_ORIGIN, WA_RP, $saved['publicKey'], 0));
});

test('la entrada se rechaza por firma alterada, otra clave, desafío, origen, tipo, indicadores y contador', function (): void {
    [$key, $saved] = waRegister();
    $good = $key->assertion('c4', WA_ORIGIN, WA_RP, 7)['response'];
    $tampered = $good;
    $tampered['authenticatorData'] = WebAuthn::b64url(WebAuthn::fromB64url($good['authenticatorData']) . "\x00");
    waFails(function () use ($tampered, $saved): void {
        WebAuthn::verifyAssertion($tampered, 'c4', WA_ORIGIN, WA_RP, $saved['publicKey'], 0);
    }, 'signature');
    $stranger = FakePasskey::create();
    waFails(function () use ($stranger, $saved): void {
        WebAuthn::verifyAssertion($stranger->assertion('c4', WA_ORIGIN, WA_RP, 7)['response'], 'c4', WA_ORIGIN, WA_RP, $saved['publicKey'], 0);
    }, 'signature');
    waFails(function () use ($good, $saved): void {
        WebAuthn::verifyAssertion($good, 'c9', WA_ORIGIN, WA_RP, $saved['publicKey'], 0);
    }, 'challenge');
    waFails(function () use ($good, $saved): void {
        WebAuthn::verifyAssertion($good, 'c4', 'https://otra.test', WA_RP, $saved['publicKey'], 0);
    }, 'origin');
    waFails(function () use ($key, $saved): void {
        WebAuthn::verifyAssertion($key->assertion('c4', WA_ORIGIN, WA_RP, 7, 0x05, 'webauthn.create')['response'], 'c4', WA_ORIGIN, WA_RP, $saved['publicKey'], 0);
    }, 'type');
    waFails(function () use ($key, $saved): void {
        WebAuthn::verifyAssertion($key->assertion('c4', WA_ORIGIN, WA_RP, 7, 0x01)['response'], 'c4', WA_ORIGIN, WA_RP, $saved['publicKey'], 0);
    }, 'verified');
    // Un contador que no sube es una clave copiada.
    waFails(function () use ($good, $saved): void {
        WebAuthn::verifyAssertion($good, 'c4', WA_ORIGIN, WA_RP, $saved['publicKey'], 7);
    }, 'counter');
});
