<?php
/**
 * AIFEC — pruebas de las contraseñas y de los formatos heredados de WordPress y PrestaShop.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Passwords;

/** phpass escrito aparte, a partir del algoritmo, para no depender del código que se prueba. */
function aifecTestPhpass(string $password, string $salt8, int $log2, string $id = '$P$'): string
{
    $itoa = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $h = md5($salt8 . $password, true);
    $rounds = 1 << $log2;
    for ($i = 0; $i < $rounds; ++$i) {
        $h = md5($h . $password, true);
    }
    $out = '';
    for ($i = 0; $i < 16; $i += 3) {
        $b1 = $i + 1 < 16 ? ord($h[$i + 1]) : 0;
        $b2 = $i + 2 < 16 ? ord($h[$i + 2]) : 0;
        $v = ord($h[$i]) | ($b1 << 8) | ($b2 << 16);
        $chars = $i + 3 <= 16 ? 4 : 2;
        for ($k = 0; $k < $chars; ++$k) {
            $out .= $itoa[($v >> (6 * $k)) & 63];
        }
    }

    return $id . $itoa[$log2] . $salt8 . $out;
}

/** Hash moderno de WordPress, según `wp_hash_password` (coste bajo para que la prueba sea rápida). */
function aifecTestWpModern(string $password): string
{
    return '$wp' . password_hash(base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true)), PASSWORD_BCRYPT, ['cost' => 4]);
}

/** @return string[] */
function aifecTestPasswords(): array
{
    return ['a', 'Contraseña ñ 123', 'con  espacios y tildes áéíóú', "emoji \u{1F600} & \"comillas\" 'simples'", str_repeat('Zñ9', 33) . 'x'];
}

test('hash y verify: ida y vuelta con tildes, eñes, espacios y 100 caracteres', function (): void {
    foreach (['Contraseña ñ 123', str_repeat('ab ', 33) . 'z'] as $pw) {
        $hash = Passwords::hash($pw);
        check(strlen($pw) > 0);
        same(true, Passwords::verify($pw, $hash));
        same(false, Passwords::verify($pw . 'x', $hash));
        same(false, Passwords::verify('', $hash));
        same(false, Passwords::needsRehash($hash), 'el hash recién hecho no necesita rehacerse');
    }
});

test('hash usa Argon2id si está y bcrypt de coste 12 si no; dos hashes del mismo texto difieren', function (): void {
    $a = Passwords::hash('misma');
    $b = Passwords::hash('misma');
    check($a !== $b, 'la sal tiene que cambiar');
    if (defined('PASSWORD_ARGON2ID')) {
        contains('$argon2id$', $a);
    } else {
        contains('$2y$12$', $a);
    }
});

test('phpass: vector generado por la clase real de WordPress 7.1.2', function (): void {
    $hash = '$P$BARdhXPXPIpVVww7WKXAm.eyEIVVkS1';
    same(true, Passwords::verify('Contraseña ñ 123', $hash));
    same(false, Passwords::verify('Contraseña ñ 124', $hash));
    same(false, Passwords::verify('contraseña ñ 123', $hash));
});

test('phpass: referencia propia con $P$ y $H$, varias contraseñas y costes', function (): void {
    foreach (aifecTestPasswords() as $i => $pw) {
        foreach (['$P$', '$H$'] as $id) {
            $hash = aifecTestPhpass($pw, 'abcdefgh', 7 + ($i % 3), $id);
            same(34, strlen($hash));
            same(true, Passwords::verify($pw, $hash), $id . ' ' . $pw);
            same(false, Passwords::verify($pw . ' ', $hash));
            same(false, Passwords::verify('x' . $pw, $hash));
            same(true, Passwords::needsRehash($hash));
        }
    }
});

test('phpass: sal o resumen tocados, coste fuera de rango o longitud rara se rechazan', function (): void {
    $hash = aifecTestPhpass('clave', 'abcdefgh', 7);
    same(true, Passwords::verify('clave', $hash));
    same(false, Passwords::verify('clave', substr($hash, 0, 33)));
    same(false, Passwords::verify('clave', $hash . 'x'));
    same(false, Passwords::verify('clave', substr_replace($hash, 'z', 5, 1)), 'una letra de la sal cambiada');
    same(false, Passwords::verify('clave', substr_replace($hash, '.', 3, 1)), 'coste 0: fuera de rango');
    same(false, Passwords::verify('clave', '$P$' . str_repeat('9', 31)));
});

test('WordPress moderno ($wp + bcrypt del HMAC-SHA384): acepta la buena y rechaza la mala', function (): void {
    foreach (aifecTestPasswords() as $pw) {
        $hash = aifecTestWpModern($pw);
        same('$wp$2y$', substr($hash, 0, 7));
        same(true, Passwords::verify($pw, $hash), $pw);
        same(false, Passwords::verify($pw . 'x', $hash));
        same(true, Passwords::needsRehash($hash));
    }
});

test('WordPress moderno: más de 72 bytes siguen contando enteros (el HMAC los resume)', function (): void {
    $pw = str_repeat('a', 80);
    $hash = aifecTestWpModern($pw);
    same(true, Passwords::verify($pw, $hash));
    same(false, Passwords::verify(str_repeat('a', 79) . 'b', $hash), 'bcrypt solo mira 72 bytes; con el HMAC se ven todos');
});

test('WordPress moderno: al comprobar no se recorta la contraseña (el código fuente solo lo hace al crear)', function (): void {
    $hash = aifecTestWpModern('abc  ');
    same(true, Passwords::verify('abc', $hash));
    same(false, Passwords::verify('abc  ', $hash));
});

test('WordPress muy antiguo: md5 de 32 hexadecimales', function (): void {
    foreach (aifecTestPasswords() as $pw) {
        $hash = md5($pw);
        same(true, Passwords::verify($pw, $hash));
        same(true, Passwords::verify($pw, strtoupper($hash)), 'hexadecimal en mayúsculas');
        same(false, Passwords::verify($pw . 'x', $hash));
        same(true, Passwords::needsRehash($hash));
    }
});

test('PrestaShop: bcrypt normal por verify y por verifyPrestashopLegacy', function (): void {
    $pw = 'Contraseña ñ 123';
    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
    same(true, Passwords::verify($pw, $hash));
    same(false, Passwords::verify('otra', $hash));
    same(true, Passwords::verifyPrestashopLegacy($pw, $hash, 'cualquiera'));
    same(false, Passwords::verifyPrestashopLegacy('otra', $hash, 'cualquiera'));
    same(true, Passwords::needsRehash($hash), 'un bcrypt de coste 4 no es el actual');
});

test('PrestaShop antiguo: md5(clave de cookie . contraseña), solo con su clave', function (): void {
    $cookie = 'Zz9ñ?clave-de-cookie/larga&con"cosas';
    foreach (aifecTestPasswords() as $pw) {
        $hash = md5($cookie . $pw);
        same(true, Passwords::verifyPrestashopLegacy($pw, $hash, $cookie), $pw);
        same(false, Passwords::verifyPrestashopLegacy($pw . 'x', $hash, $cookie));
        same(false, Passwords::verifyPrestashopLegacy($pw, $hash, $cookie . 'x'), 'otra clave de cookie');
        same(false, Passwords::verifyPrestashopLegacy($pw, $hash, ''), 'sin clave de cookie');
        same(false, Passwords::verify($pw, $hash), 'verify a secas lo toma por md5 de WordPress y no lo acepta');
    }
    same(true, Passwords::needsRehash(md5($cookie . 'x')));
});

test('Argon2id y bcrypt de otras tiendas: se aceptan', function (): void {
    if (defined('PASSWORD_ARGON2ID')) {
        $argon = password_hash('clave ñ', PASSWORD_ARGON2ID);
        same(true, Passwords::verify('clave ñ', $argon));
        same(false, Passwords::verify('clave n', $argon));
        same(false, Passwords::needsRehash($argon));
    }
    $weak = password_hash('clave ñ', PASSWORD_BCRYPT, ['cost' => 5]);
    same(true, Passwords::verify('clave ñ', $weak));
    same(true, Passwords::needsRehash($weak));
});

test('entradas raras: vacío, basura, hashes truncados y contraseña de más de 4096 caracteres', function (): void {
    foreach (['', '*', '*0', '*1', 'x', '$', '$P$', '$2y$', '$argon2id$', 'no-es-un-hash', "\0\0\0", '$wp$', '$wp$nada', '{SHA}abc', str_repeat('a', 200)] as $junk) {
        same(false, Passwords::verify('lo que sea', $junk), json_encode($junk));
        same(false, Passwords::verify('', $junk), json_encode($junk));
        same(true, Passwords::needsRehash($junk), json_encode($junk));
        same(false, Passwords::verifyPrestashopLegacy('lo que sea', $junk, 'k'));
    }
    $long = str_repeat('a', 4097);
    same(false, Passwords::verify($long, md5($long)), 'WordPress no admite más de 4096 caracteres');
    same(false, Passwords::verify($long, aifecTestPhpass($long, 'abcdefgh', 7)));
    same(true, Passwords::verify(str_repeat('a', 4096), md5(str_repeat('a', 4096))), 'el límite exacto sí vale');
});
