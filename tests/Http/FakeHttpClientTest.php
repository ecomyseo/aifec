<?php
/**
 * AIFEC — pruebas del cliente HTTP de mentira y del cargador de clases.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;

test('la cola contesta por orden y apunta las peticiones', function (): void {
    $http = new FakeHttpClient();
    $http->queue(new HttpResponse(200, ['Content-Type' => 'application/json'], '{"a":1}'), new HttpResponse(500));

    $r1 = $http->send(HttpRequest::postJson('https://x.test/a', ['q' => 'ñ']));
    $r2 = $http->send(HttpRequest::get('https://x.test/b'));

    same(true, $r1->ok());
    same(['a' => 1], $r1->json());
    same('application/json', $r1->header('content-type'));
    same(false, $r2->ok());
    same(null, $r2->json(), 'un cuerpo vacío no es JSON');
    same(2, count($http->requests));
    same('{"q":"ñ"}', $http->requests[0]->body);
    same('GET', $http->last()->method);
});

test('las reglas van antes que la cola y un fallo de red lanza', function (): void {
    $http = new FakeHttpClient();
    $http->when('POST', '/pagos', function (HttpRequest $r): HttpResponse {
        return new HttpResponse(201, [], (string) $r->body);
    });
    $http->queue(new HttpException('sin red'));

    same(201, $http->send(HttpRequest::postForm('https://x.test/pagos', ['a' => 1]))->status);
    throws(function () use ($http): void {
        $http->send(HttpRequest::get('https://x.test/otra'));
    }, HttpException::class);
    throws(function () use ($http): void {
        $http->send(HttpRequest::get('https://x.test/sin-preparar'));
    }, HttpException::class, 'sin respuesta preparada tiene que lanzar, no inventar');
});

test('el cargador pasa de StudlyCase a kebab-case', function (): void {
    same('cms-pages', Autoload::kebab('CmsPages'));
    same('ai', Autoload::kebab('Ai'));
    same('payments-redsys', Autoload::kebab('PaymentsRedsys'));
    // La raíz es la carpeta que contiene core/, se llame como se llame (en un servidor suele ser public_html).
    check(is_file(Autoload::root() . '/core/bootstrap.php'), 'la raíz del proyecto es la carpeta que contiene core/');
    same(false, strpos(Autoload::root(), '\\'), 'la raíz va siempre con barras normales');
});
