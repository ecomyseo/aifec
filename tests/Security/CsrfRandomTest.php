<?php
/**
 * AIFEC — pruebas de las fichas contra CSRF y de los aleatorios.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Csrf;
use Aifec\Security\Random;

test('Csrf: una ficha emitida vale para su formulario y su sesión', function (): void {
    $token = Csrf::issue('secreto-de-sesión ñ', 'login', 1000);
    same(true, Csrf::check('secreto-de-sesión ñ', 'login', $token, 1000));
    same(true, Csrf::check('secreto-de-sesión ñ', 'login', $token, 1600));
    same(1, preg_match('/^1000\.[A-Za-z0-9_-]{43}$/', $token));
    same($token, Csrf::issue('secreto-de-sesión ñ', 'login', 1000), 'sin estado: el mismo dato da la misma ficha');
});

test('Csrf: la ficha de otro formulario o de otra sesión no vale', function (): void {
    $token = Csrf::issue('sesión A', 'form-a', 1000);
    same(false, Csrf::check('sesión A', 'form-b', $token, 1000));
    same(false, Csrf::check('sesión A', 'form-a ', $token, 1000));
    same(false, Csrf::check('sesión A', '', $token, 1000));
    same(false, Csrf::check('sesión B', 'form-a', $token, 1000));
    same(false, Csrf::check('', 'form-a', $token, 1000));
    same(true, Csrf::check('sesión A', 'form-a', $token, 1000));
});

test('Csrf: caduca a las 2 h por defecto, o al plazo que se pida', function (): void {
    $token = Csrf::issue('s', 'f', 1000);
    same(true, Csrf::check('s', 'f', $token, 1000 + 7200));
    same(false, Csrf::check('s', 'f', $token, 1000 + 7201));
    same(true, Csrf::check('s', 'f', $token, 1000 + 60, 60));
    same(false, Csrf::check('s', 'f', $token, 1000 + 61, 60));
    same(false, Csrf::check('s', 'f', $token, 1000 + 1, 0));
});

test('Csrf: una ficha con el instante manipulado no vale, y una del futuro lejano tampoco', function (): void {
    $token = Csrf::issue('s', 'f', 1000);
    $mac = explode('.', $token)[1];
    same(false, Csrf::check('s', 'f', '2000.' . $mac, 2000), 'el instante cambiado para alargar la vida');
    same(false, Csrf::check('s', 'f', '900.' . $mac, 2000));
    $future = Csrf::issue('s', 'f', 5000);
    same(false, Csrf::check('s', 'f', $future, 1000), 'emitida «mañana»');
    same(true, Csrf::check('s', 'f', $future, 4950), 'con un margen de un minuto por relojes desajustados');
});

test('Csrf: el identificador del formulario no se confunde con el instante (largo delante)', function (): void {
    $a = Csrf::issue('s', 'a|1', 2);
    $b = Csrf::issue('s', 'a', 2);
    check(explode('.', $a)[1] !== explode('.', $b)[1]);
    same(false, Csrf::check('s', 'a', $a, 2));
});

test('Csrf: basura y formatos raros → falso sin lanzar; secreto vacío al emitir → error', function (): void {
    foreach (['', '.', 'abc', '1000', '1000.', '.abc', '1000.abc', '-5.' . str_repeat('A', 43), 'x.' . str_repeat('A', 43), '1000.' . str_repeat('A', 44), '1000.' . str_repeat('!', 43), "1000.\0", str_repeat('9', 100) . '.' . str_repeat('A', 43), random_bytes(50), '1000.' . str_repeat('A', 43)] as $junk) {
        same(false, Csrf::check('s', 'f', $junk, 1000), bin2hex(substr($junk, 0, 20)));
    }
    throws(function (): void {
        Csrf::issue('', 'f', 1);
    }, \InvalidArgumentException::class);
});

test('Random: token es hexadecimal y mide el doble de bytes; dos seguidos difieren', function (): void {
    same(64, strlen(Random::token()));
    same(32, strlen(Random::token(16)));
    same(2, strlen(Random::token(1)));
    same(1, preg_match('/^[0-9a-f]{64}$/', Random::token()));
    check(Random::token() !== Random::token());
});

test('Random: base64url solo usa letras, cifras, guion y guion bajo, sin relleno', function (): void {
    same(43, strlen(Random::base64url()));
    same(22, strlen(Random::base64url(16)));
    for ($i = 0; $i < 50; ++$i) {
        same(1, preg_match('/^[A-Za-z0-9_-]+$/', Random::base64url(33)));
    }
    check(Random::base64url() !== Random::base64url());
});

test('Random: digits y pick respetan longitud y alfabeto; el reparto no deja huecos ni sesgo gordo', function (): void {
    $d = Random::digits(500);
    same(500, strlen($d));
    same(1, preg_match('/^[0-9]{500}$/', $d));
    same(10, count(array_unique(str_split($d))), 'salen las diez cifras');
    $p = Random::pick('ab', 4000);
    $a = substr_count($p, 'a');
    check($a > 1600 && $a < 2400, 'reparto de dos símbolos: ' . $a . ' de 4000');
    same('zzz', Random::pick('z', 3));
    $hex = Random::pick('0123456789abcdef', 64);
    same(1, preg_match('/^[0-9a-f]{64}$/', $hex));
    // Un alfabeto de 3 símbolos (no divide a 256): ninguno debería quedar muy por debajo.
    $t = Random::pick('xyz', 3000);
    foreach (['x', 'y', 'z'] as $c) {
        $n = substr_count($t, $c);
        check($n > 800 && $n < 1200, $c . ' sale ' . $n . ' veces de 3000');
    }
});

test('Random: pick no tiene el sesgo del módulo (alfabeto de 200 símbolos, que no divide a 256)', function (): void {
    $alphabet = '';
    for ($i = 0; $i < 200; ++$i) {
        $alphabet .= chr($i);
    }
    $sample = Random::pick($alphabet, 20000);
    $low = 0;
    foreach (count_chars($sample, 1) as $byte => $times) {
        if ($byte < 56) {
            $low += $times;
        }
    }
    // Sin sesgo, 56 de 200 símbolos son el 28 % (5600). Con `% 200` sobre un byte serían el 43 %.
    check($low > 5000 && $low < 6200, 'los 56 primeros símbolos salen ' . $low . ' veces de 20000');
});

test('Random: longitudes imposibles y alfabeto vacío son errores de quien lo usa', function (): void {
    foreach ([0, -1] as $n) {
        throws(function () use ($n): void {
            Random::token($n);
        }, \InvalidArgumentException::class);
        throws(function () use ($n): void {
            Random::base64url($n);
        }, \InvalidArgumentException::class);
        throws(function () use ($n): void {
            Random::digits($n);
        }, \InvalidArgumentException::class);
    }
    throws(function (): void {
        Random::pick('', 5);
    }, \InvalidArgumentException::class);
});
