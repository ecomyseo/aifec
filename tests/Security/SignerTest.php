<?php
/**
 * AIFEC — pruebas de las fichas firmadas con caducidad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Signer;

/** Una ficha con firma válida pero con el cuerpo que se quiera (para probar lo que pasa DESPUÉS de la firma). */
function aifecTestSignedBody(string $key, string $body): string
{
    $b64 = static function (string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };
    $encoded = $b64($body);

    return $encoded . '.' . $b64(hash_hmac('sha256', $encoded, $key, true));
}

test('Signer: ida y vuelta con tildes, eñes, «&», comillas, emojis y datos anidados', function (): void {
    $signer = new Signer('clave de firma ñ');
    $payload = ['user' => 42, 'name' => 'Ñandú & "Co" <b>', 'emoji' => "\u{1F600}", 'nested' => ['a' => [1, 2, 3], 'b' => null, 'c' => 1.5, 'd' => true], 'path' => '/a/b'];
    $token = $signer->sign($payload, 2000);
    same($payload, $signer->verify($token, 1000));
    same($payload, $signer->verify($token, 1999));
    same(1, preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $token), 'cabe en una dirección web sin escapar nada');
});

test('Signer: datos vacíos y lista; los datos del llamante no pueden pisar la caducidad', function (): void {
    $signer = new Signer('k');
    same([], $signer->verify($signer->sign([], 100), 50));
    same([5, 6], $signer->verify($signer->sign([5, 6], 100), 50));
    $forged = ['e' => 9999999999, 'exp' => 9999999999, 'p' => ['x' => 1]];
    same($forged, $signer->verify($signer->sign($forged, 100), 50));
    same(null, $signer->verify($signer->sign($forged, 100), 100), 'sigue caducando a su hora');
});

test('Signer: caduca en el instante de expiresAt y antes de él todavía vale', function (): void {
    $signer = new Signer('k');
    $token = $signer->sign(['a' => 1], 1000);
    same(['a' => 1], $signer->verify($token, 999));
    same(null, $signer->verify($token, 1000));
    same(null, $signer->verify($token, 1001));
    same(null, $signer->verify($token, PHP_INT_MAX));
});

test('Signer: cambiar cualquier carácter de la ficha la invalida (uno a uno, todos los lugares)', function (): void {
    $signer = new Signer('k');
    $token = $signer->sign(['id' => 7, 'to' => 'a@b.es'], 5000);
    same(['id' => 7, 'to' => 'a@b.es'], $signer->verify($token, 10));
    $changed = 0;
    for ($i = 0; $i < strlen($token); ++$i) {
        if ($token[$i] === '.') {
            continue;
        }
        $other = $token;
        $other[$i] = $token[$i] === 'A' ? 'B' : 'A';
        same(null, $signer->verify($other, 10), 'posición ' . $i);
        ++$changed;
    }
    check($changed > 60);
});

test('Signer: otra clave, un trozo recortado o unido con otro, y el punto cambiado de sitio → null', function (): void {
    $a = new Signer('clave A');
    $b = new Signer('clave B');
    $token = $a->sign(['id' => 1], 5000);
    same(null, $b->verify($token, 10));
    [$body, $mac] = explode('.', $token);
    same(null, $a->verify(substr($body, 0, -1) . '.' . $mac, 10));
    same(null, $a->verify($body . '.' . substr($mac, 0, -1), 10));
    same(null, $a->verify($body . '.' . $mac . 'A', 10));
    same(null, $a->verify($mac . '.' . $body, 10));
    same(null, $a->verify($body . $mac, 10));
    $other = $a->sign(['id' => 2], 5000);
    same(null, $a->verify(explode('.', $other)[0] . '.' . $mac, 10), 'cuerpo de una ficha con la firma de otra');
});

test('Signer: basura y entradas raras devuelven null sin lanzar', function (): void {
    $signer = new Signer('k');
    $junk = ['', '.', '..', 'a.b.c', 'a.b', '.x', 'x.', 'a', "\0", "\0.\0", random_bytes(64), random_bytes(64) . '.' . random_bytes(32), str_repeat('a', 100000), str_repeat('a', 50000) . '.' . str_repeat('b', 50000), "ñ.é", 'eyJlIjoxfQ.', '%41.%42', 'a b.c d', "a\n.b"];
    foreach ($junk as $i => $token) {
        same(null, $signer->verify($token, 10), 'basura nº ' . $i);
    }
});

test('Signer: una ficha bien firmada pero con un cuerpo que no es de las nuestras → null', function (): void {
    $key = 'k';
    $signer = new Signer($key);
    $bodies = ['', 'no es json', '[]', '{}', '[1,2]', '"texto"', '5', 'null', '{"e":"1000","p":{}}', '{"e":1000}', '{"p":{}}', '{"e":1000,"p":5}', '{"e":1.5,"p":{}}', "\xff\xfe", '{"e":1000,"p":{}'];
    foreach ($bodies as $i => $body) {
        same(null, $signer->verify(aifecTestSignedBody($key, $body), 10), 'cuerpo nº ' . $i);
    }
    same(['a' => 1], $signer->verify(aifecTestSignedBody($key, '{"e":1000,"p":{"a":1}}'), 10), 'el control: un cuerpo bueno sí pasa');
});

test('Signer: una clave vacía es un error de quien lo usa', function (): void {
    throws(function (): void {
        new Signer('');
    }, \InvalidArgumentException::class);
    throws(function (): void {
        (new Signer('k'))->sign(['mal' => "\xff"], 100);
    }, \Throwable::class, 'un dato que no se puede pasar a JSON no se firma en silencio');
});
