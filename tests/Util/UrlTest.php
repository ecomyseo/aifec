<?php
/**
 * AIFEC — pruebas de Url (direcciones y rutas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Util\Url;

test('join une trozos con una sola barra y respeta https://', function (): void {
    same('https://tienda.test/tienda/producto', Url::join('https://', 'tienda.test/', '/tienda', 'producto'));
    same('a/b/c', Url::join('a/', '/b/', 'c'));
    same('/x', Url::join('/', 'x'));
    same('', Url::join('', ''));
});

test('withQuery sustituye, añade y quita parámetros conservando el ancla', function (): void {
    same(
        'https://a.test/p?b=1&c=3%204&d=x%26y#top',
        Url::withQuery('https://a.test/p?b=1&c=2#top', ['c' => '3 4', 'd' => 'x&y'])
    );
    same('https://a.test/p?lang=es', Url::withQuery('https://a.test/p', ['lang' => 'es', 'x' => null]));
});

test('withoutQuery quita nombres y conserva el resto y el ancla', function (): void {
    same('https://a.test/p?id=5#f', Url::withoutQuery('https://a.test/p?utm=1&id=5#f', ['utm']));
    same('/p', Url::withoutQuery('/p?utm=1', ['utm']));
});

test('isAbsolute reconoce esquemas, javascript: y rutas de red', function (): void {
    check(Url::isAbsolute('https://x.test'), 'https');
    check(Url::isAbsolute('//x.test'), 'de red');
    check(Url::isAbsolute('javascript:alert(1)'), 'javascript');
    check(!Url::isAbsolute('/x'), 'ruta absoluta del sitio');
    check(!Url::isAbsolute('x/y'), 'relativa');
});

test('isHttp solo acepta http y https', function (): void {
    check(Url::isHttp('HTTP://x.test'), 'HTTP en mayúsculas');
    check(!Url::isHttp('javascript:1'), 'javascript');
    check(!Url::isHttp('ftp://x.test'), 'ftp');
});

test('host devuelve el host en minúsculas o null', function (): void {
    same('tienda.test', Url::host('https://Tienda.Test:8080/x'));
    same(null, Url::host('/relativa'));
    same(null, Url::host('javascript:alert(1)'));
});

test('normalizePath resuelve puntos sin salir de la raíz', function (): void {
    same('/a/c/d/', Url::normalizePath('/a/./b/../c//d/'));
    same('/etc', Url::normalizePath('/../../etc'));
    same('b', Url::normalizePath('a/../../b'));
    same('/', Url::normalizePath('/'));
    same('', Url::normalizePath(''));
});

test('isSafeRedirect acepta rutas del sitio y el mismo host', function (): void {
    check(Url::isSafeRedirect('/cuenta', 'tienda.test'), 'ruta relativa');
    check(Url::isSafeRedirect('/', 'tienda.test'), 'raíz');
    check(Url::isSafeRedirect('https://tienda.test/x', 'tienda.test'), 'mismo host');
    check(Url::isSafeRedirect('HTTPS://TIENDA.test/x', 'tienda.test'), 'mismo host en mayúsculas');
});

test('isSafeRedirect rechaza redirecciones peligrosas', function (): void {
    check(!Url::isSafeRedirect('//evil.test', 'tienda.test'), 'doble barra');
    check(!Url::isSafeRedirect('/\\evil.test', 'tienda.test'), 'barra invertida');
    check(!Url::isSafeRedirect('https://evil.test', 'tienda.test'), 'otro host');
    check(!Url::isSafeRedirect('javascript:alert(1)', 'tienda.test'), 'javascript');
    check(!Url::isSafeRedirect('cuenta', 'tienda.test'), 'sin barra inicial');
    check(!Url::isSafeRedirect('', 'tienda.test'), 'vacío');
    check(!Url::isSafeRedirect("/\tx", 'tienda.test'), 'carácter de control');
    check(!Url::isSafeRedirect('https://tienda.test/x', ''), 'host permitido vacío');
});
