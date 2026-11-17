<?php
/**
 * AIFEC — pruebas de Json, Bytes y Uuid.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Bytes;
use Aifec\Util\Json;
use Aifec\Util\Uuid;

test('Json encode deja UTF-8, barras y tildes sin escapar', function (): void {
    same('{"a":"ñ/ü"}', Json::encode(['a' => 'ñ/ü']));
    contains("\n", Json::encode(['a' => 1], true), 'modo bonito');
    same('[]', Json::encode([]));
});

test('Json encode lanza JsonException con UTF-8 inválido', function (): void {
    throws(function (): void {
        Json::encode(["\xB1"]);
    }, \JsonException::class);
});

test('Json decode devuelve arrays y lanza si el texto no es JSON', function (): void {
    same(['a' => 1], Json::decode('{"a":1}'));
    throws(function (): void {
        Json::decode('{');
    }, \JsonException::class);
    throws(function (): void {
        Json::decode('');
    }, \JsonException::class);
});

test('Json tryDecode no lanza y distingue null válido de error', function (): void {
    same('def', Json::tryDecode('{x', 'def'));
    same(null, Json::tryDecode('{x'));
    same(null, Json::tryDecode('null', 'def'));
    same([1], Json::tryDecode('[1]'));
    same('def', Json::tryDecode('', 'def'));
});

test('Json forHtml no deja pasar un cierre de script', function (): void {
    $value = ['a' => '</script>', 'b' => "O'Neil \"x\" & y"];
    $html = Json::forHtml($value);
    notContains('</script>', $html);
    notContains('<', $html);
    same($value, Json::decode($html));
});

test('Bytes format en español y en otros idiomas', function (): void {
    same('1,5 KB', Bytes::format(1536));
    same('1.5 KB', Bytes::format(1536, 1, 'en'));
    same('512 B', Bytes::format(512));
    same('1,0 MB', Bytes::format(1048576));
    same('0 B', Bytes::format(0));
    same('1,0 KB', Bytes::format(1024, 1, 'es_ES'));
});

test('Bytes parse lee tamaños tipo php.ini', function (): void {
    same(67108864, Bytes::parse('64M'));
    same(67108864, Bytes::parse('64m'));
    same(2147483648, Bytes::parse('2 GB'));
    same(512, Bytes::parse('512'));
    same(1536, Bytes::parse('1.5K'));
    throws(function (): void {
        Bytes::parse('abc');
    }, \InvalidArgumentException::class);
});

test('Uuid v4 tiene el formato y la versión correctos y no se repite', function (): void {
    $seen = [];
    for ($i = 0; $i < 20; ++$i) {
        $id = Uuid::v4();
        check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1, 'formato ' . $id);
        $seen[$id] = true;
    }
    same(20, count($seen));
});
