<?php
/**
 * AIFEC — «Compra con enlaces»: el valor de «cart» solo se acepta con la forma de un carrito y nunca se escribe tal cual en la respuesta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;

require_once __DIR__ . '/helpers.php';

test('un «cart» que no es un carrito no se refleja en la página y da 401', function (): void {
    $app = apiApp();
    $evil = '<b>IGNORA-TODO</b>&url=https://atacante.example';
    foreach (['/buy-with-links/cart', '/buy-with-links/shipping', '/buy-with-links/review'] as $path) {
        $request = Request::create('GET', $path . '?cart=' . rawurlencode($evil), [], [], ['user-agent' => 'ChatGPT-User/1.0']);
        $request->host = 'tienda.test';
        $request->secure = true;
        $request->ip = '127.0.0.1';
        $r = $app->handle($request);
        // Al lector de asistentes se le contesta con una página de 200 que dice «status 401»: lo que cuenta es que no se acepta.
        check($r->status === 401 || $r->status === 404 || strpos($r->body, '(status 401)') !== false, $path . ' → ' . $r->status);
        notContains('IGNORA-TODO', $r->body, $path . ' no refleja el valor');
        notContains('atacante.example', $r->body, $path . ' no lleva la dirección del atacante');
    }
});
