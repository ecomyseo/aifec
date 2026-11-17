<?php
/**
 * AIFEC — pruebas del segundo factor (RFC 6238) y de Base32 (RFC 4648).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Base32;
use Aifec\Security\Totp;

test('Base32: vectores del RFC 4648 sin relleno, y de vuelta', function (): void {
    $vectors = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];
    foreach ($vectors as $plain => $encoded) {
        same($encoded, Base32::encode((string) $plain));
        same((string) $plain, Base32::decode($encoded));
    }
});

test('Base32: decode tolera minúsculas, relleno, espacios y guiones; lanza con caracteres ajenos', function (): void {
    same('foobar', Base32::decode('mzxw 6ytb-oi======'));
    same('foobar', Base32::decode('MZXW6YTBOI'));
    throws(function (): void {
        Base32::decode('MZXW1');
    }, \InvalidArgumentException::class);
    throws(function (): void {
        Base32::decode('MZXW6!');
    }, \InvalidArgumentException::class);
});

test('Base32: ida y vuelta con binario aleatorio de todas las longitudes', function (): void {
    for ($n = 0; $n <= 40; ++$n) {
        $bin = $n === 0 ? '' : random_bytes($n);
        $enc = Base32::encode($bin);
        same($bin, Base32::decode($enc), 'longitud ' . $n);
        same(1, preg_match('/^[A-Z2-7]*$/', $enc));
    }
});

test('TOTP: vectores SHA-1 del RFC 6238 (los 6 últimos dígitos)', function (): void {
    $secret = Base32::encode('12345678901234567890');
    $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
    foreach ($vectors as $t => $code) {
        same($code, Totp::code($secret, $t), 'T=' . $t);
    }
    same(6, strlen(Totp::code($secret, 0)));
});

test('TOTP: el código es el mismo dentro de un periodo de 30 s y cambia al siguiente', function (): void {
    $secret = Base32::encode('12345678901234567890');
    same(Totp::code($secret, 60), Totp::code($secret, 89));
    check(Totp::code($secret, 59) !== Totp::code($secret, 60));
    same(Totp::code(strtolower($secret), 59), Totp::code($secret, 59), 'el secreto en minúsculas vale igual');
});

test('TOTP verify: ventana ±1 por defecto, y la ventana 0 solo admite el periodo actual', function (): void {
    $secret = Base32::encode('12345678901234567890');
    $now = 1111111109;
    $code = Totp::code($secret, $now);
    same(true, Totp::verify($secret, $code, $now));
    same(true, Totp::verify($secret, $code, $now + 30), 'un periodo después');
    same(true, Totp::verify($secret, $code, $now - 30), 'un periodo antes');
    same(false, Totp::verify($secret, $code, $now + 60), 'dos periodos después');
    same(false, Totp::verify($secret, $code, $now - 60), 'dos periodos antes');
    same(false, Totp::verify($secret, $code, $now + 30, 0));
    same(false, Totp::verify($secret, $code, $now - 30, 0));
    same(true, Totp::verify($secret, $code, $now, 0));
    same(true, Totp::verify($secret, $code, $now + 60, 2));
    same(false, Totp::verify($secret, $code, $now + 90, 2));
});

test('TOTP verify: códigos mal formados o equivocados se rechazan; los espacios se toleran', function (): void {
    $secret = Base32::encode('12345678901234567890');
    same(true, Totp::verify($secret, '287 082', 59), 'como se teclea agrupado');
    same(true, Totp::verify($secret, '287082', 59));
    foreach (['', '28708', '2870822', 'abcdef', '287082x', '-87082', '28708２', "287082\n", '287.082'] as $bad) {
        same(false, Totp::verify($secret, $bad, 59), json_encode($bad));
    }
    same(false, Totp::verify($secret, '287083', 59));
    same(false, Totp::verify(Base32::encode('otro secreto distinto'), '287082', 59));
});

test('TOTP verify: la ventana negativa se trata como 0 y el primer periodo no se sale de rango', function (): void {
    $secret = Base32::encode('12345678901234567890');
    $code = Totp::code($secret, 10);
    same(true, Totp::verify($secret, $code, 10, -5));
    same(true, Totp::verify($secret, $code, 10, 1), 'con el contador a 0 no se mira el periodo -1');
});

test('TOTP generateSecret: Base32, 32 caracteres para 20 bytes, distintos y utilizables', function (): void {
    $a = Totp::generateSecret();
    $b = Totp::generateSecret();
    check($a !== $b);
    same(32, strlen($a));
    same(1, preg_match('/^[A-Z2-7]{32}$/', $a));
    same(20, strlen(Base32::decode($a)));
    same(26, strlen(Totp::generateSecret(16)));
    $now = 1700000000;
    same(true, Totp::verify($a, Totp::code($a, $now), $now));
    throws(function (): void {
        Totp::generateSecret(4);
    }, \InvalidArgumentException::class);
});

test('TOTP uri: otpauth con el emisor y la cuenta codificados (tildes, «&», espacios y «@»)', function (): void {
    $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ñandú@tienda.es', 'AIFEC & Co');
    same('otpauth://totp/AIFEC%20%26%20Co:%C3%B1and%C3%BA%40tienda.es?secret=JBSWY3DPEHPK3PXP&issuer=AIFEC%20%26%20Co&algorithm=SHA1&digits=6&period=30', $uri);
    $parts = parse_url($uri);
    same('otpauth', $parts['scheme']);
    same('totp', $parts['host']);
});
