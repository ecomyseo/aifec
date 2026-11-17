<?php
/**
 * AIFEC — pruebas del cifrado de secretos guardados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Crypto;

/** @return string[] los motores que hay en esta máquina */
function aifecTestDrivers(): array
{
    $drivers = [Crypto::DRIVER_OPENSSL];
    if (extension_loaded('sodium') && function_exists('sodium_crypto_secretbox')) {
        $drivers[] = Crypto::DRIVER_SODIUM;
    }

    return $drivers;
}

/** Cambia un bit del binario que hay dentro de la ficha y la vuelve a empaquetar. */
function aifecTestFlip(string $cipher, int $byte): string
{
    $raw = base64_decode(substr($cipher, 3), true);
    $raw[$byte < 0 ? strlen($raw) + $byte : $byte] = chr(ord($raw[$byte < 0 ? strlen($raw) + $byte : $byte]) ^ 1);

    return substr($cipher, 0, 3) . base64_encode($raw);
}

test('Crypto: ida y vuelta con vacío, UTF-8, binario y 100 kB, con cada motor', function (): void {
    $texts = ['', 'a', 'Contraseña ñ & "comillas" \'x\' <b> áéíóú', "emoji \u{1F600}", random_bytes(300), str_repeat('Zñ', 51200)];
    foreach (aifecTestDrivers() as $driver) {
        $crypto = new Crypto('clave maestra ñ', $driver);
        foreach ($texts as $i => $plain) {
            $cipher = $crypto->encrypt($plain);
            same($plain, $crypto->decrypt($cipher), $driver . ' texto nº ' . $i);
            check($cipher !== $plain || $plain === '');
        }
    }
    check(strlen($texts[5]) >= 100000, 'el texto largo mide al menos 100 kB');
});

test('Crypto: el prefijo dice el motor y los dos se abren con cualquier instancia', function (): void {
    $openssl = new Crypto('k', Crypto::DRIVER_OPENSSL);
    $cipherG = $openssl->encrypt('secreto');
    same('g1:', substr($cipherG, 0, 3));
    same('secreto', (new Crypto('k'))->decrypt($cipherG), 'la instancia por defecto abre un g1:');
    if (in_array(Crypto::DRIVER_SODIUM, aifecTestDrivers(), true)) {
        $cipherS = (new Crypto('k', Crypto::DRIVER_SODIUM))->encrypt('secreto');
        same('s1:', substr($cipherS, 0, 3));
        same('secreto', $openssl->decrypt($cipherS), 'la instancia openssl abre un s1:');
        same('s1:', substr((new Crypto('k'))->encrypt('x'), 0, 3), 'con sodium disponible se elige sodium');
    }
});

test('Crypto: dos cifrados del mismo texto son distintos y los dos se abren', function (): void {
    foreach (aifecTestDrivers() as $driver) {
        $crypto = new Crypto('k', $driver);
        $a = $crypto->encrypt('mismo texto');
        $b = $crypto->encrypt('mismo texto');
        check($a !== $b, $driver);
        same('mismo texto', $crypto->decrypt($a));
        same('mismo texto', $crypto->decrypt($b));
    }
});

test('Crypto: un texto manipulado (primer, medio y último byte) no se descifra', function (): void {
    foreach (aifecTestDrivers() as $driver) {
        $crypto = new Crypto('k', $driver);
        $cipher = $crypto->encrypt('clave-api-0123456789');
        same('clave-api-0123456789', $crypto->decrypt($cipher));
        foreach ([0, 5, 14, 20, 30, -1] as $byte) {
            same(null, $crypto->decrypt(aifecTestFlip($cipher, $byte)), $driver . ' byte ' . $byte);
        }
        same(null, $crypto->decrypt($cipher . 'AAAA'), $driver . ' con cola añadida');
        same(null, $crypto->decrypt(substr($cipher, 0, -8)), $driver . ' recortado');
    }
});

test('Crypto: con otra clave no se descifra (ni siquiera con la misma tras un espacio)', function (): void {
    foreach (aifecTestDrivers() as $driver) {
        $cipher = (new Crypto('clave uno', $driver))->encrypt('secreto');
        same('secreto', (new Crypto('clave uno', $driver))->decrypt($cipher));
        same(null, (new Crypto('clave dos', $driver))->decrypt($cipher), $driver);
        same(null, (new Crypto('clave uno ', $driver))->decrypt($cipher), $driver);
        same(null, (new Crypto('Clave uno', $driver))->decrypt($cipher), $driver);
    }
});

test('Crypto: basura, prefijos desconocidos y fichas truncadas devuelven null sin lanzar', function (): void {
    $junk = ['', 's', 's1', 's1:', 'g1:', 'g1', 'x1:AAAA', 'texto en claro', 's1:!!!!', 'g1:!!!!', 's1:AAAA', 'g1:AAAA', 's1:' . base64_encode(str_repeat("\0", 30)), 'g1:' . base64_encode(str_repeat("\0", 27)), 'g1:' . base64_encode(str_repeat("\0", 60)), 's1:' . base64_encode(str_repeat("\0", 80)), "\0\0\0\0", random_bytes(40), 's1:' . base64_encode(random_bytes(100)), 'g1:' . base64_encode(random_bytes(100)), str_repeat('A', 100000)];
    foreach (aifecTestDrivers() as $driver) {
        $crypto = new Crypto('k', $driver);
        foreach ($junk as $i => $text) {
            same(null, $crypto->decrypt($text), $driver . ' basura nº ' . $i);
        }
    }
});

test('Crypto: clave vacía y motor desconocido son errores de quien lo usa', function (): void {
    throws(function (): void {
        new Crypto('');
    }, \InvalidArgumentException::class);
    throws(function (): void {
        new Crypto('k', 'rot13');
    }, \InvalidArgumentException::class);
});
