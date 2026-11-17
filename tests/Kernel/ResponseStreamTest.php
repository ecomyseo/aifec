<?php
/**
 * AIFEC — la respuesta que se escribe por partes (Response::stream). El escritor corre DESPUÉS de las cabeceras: quien la devuelve
 * deja antes encolado lo que necesite cookie. Aquí se prueba la pieza suelta; lo del carrito, en modules/assistant/tests/StreamCartTest.php.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Response;

test('Response::stream no escribe nada al crearse: el escritor espera a que alguien lo ejecute', function (): void {
    $calls = 0;
    $response = Response::stream(static function (callable $emit) use (&$calls): void {
        $calls++;
        $emit("uno\n");
    }, ['Content-Type' => 'application/x-ndjson; charset=UTF-8'], 200);

    same(0, $calls, 'crear la respuesta no ejecuta nada');
    same('', $response->body);
    same(200, $response->status);
    same('application/x-ndjson; charset=UTF-8', $response->contentType());
    check(is_callable($response->writer));
});

test('drain() ejecuta el escritor una sola vez y deja los trozos, en orden, en el cuerpo', function (): void {
    $calls = 0;
    $response = Response::stream(static function (callable $emit) use (&$calls): void {
        $calls++;
        $emit("{\"status\":\"a\"}\n");
        $emit("{\"status\":\"b\"}\n");
        $emit("{\"done\":true}\n");
    });

    $response->drain();
    same("{\"status\":\"a\"}\n{\"status\":\"b\"}\n{\"done\":true}\n", $response->body);
    same(null, $response->writer, 'el escritor ya se ha gastado');

    $response->drain();
    same(1, $calls, 'una segunda llamada no vuelve a ejecutarlo');
});

test('drain() en una respuesta normal no toca nada', function (): void {
    $response = Response::html('<p>hola</p>');
    $response->drain();
    same('<p>hola</p>', $response->body);
});

test('send() escribe los trozos según llegan y no añade el cuerpo detrás', function (): void {
    $response = Response::stream(static function (callable $emit): void {
        $emit("uno\n");
        $emit("dos\n");
    }, ['Content-Type' => 'text/plain; charset=UTF-8']);
    $response->body = 'NO DEBE SALIR';

    // Dos búferes: send() vacía el de arriba hacia el de abajo tras cada trozo, como el servidor lo vaciaría hacia la red.
    ob_start();
    ob_start();
    $response->send();
    $inner = (string) ob_get_clean();
    $outer = (string) ob_get_clean();

    same("uno\ndos\n", $outer . $inner);
    same("uno\ndos\n", $outer, 'cada trozo se vació en cuanto se escribió');
    same(null, $response->writer);
});

test('las cookies de la respuesta se cierran antes de escribir: lo que el escritor quisiera añadir ya no está en ella al enviarse', function (): void {
    $response = Response::stream(static function (callable $emit): void {
        $emit('x');
    });
    $response->cookie('af_antes', '1', ['expires' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    $before = array_keys($response->cookies);

    $response->drain();

    same(['af_antes'], $before);
    same($before, array_keys($response->cookies), 'escribir el cuerpo no cambia las cookies de la respuesta');
});
