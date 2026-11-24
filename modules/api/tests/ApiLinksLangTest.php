<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;

require_once __DIR__ . '/helpers.php';

/*
 * La página «Compra con enlaces» sale en el idioma de la tienda (auditoría externa del 24/11/2026: salía en inglés, con lang="en", en
 * una tienda en español). El JSON de los programas no se traduce.
 */

function langOpen(\Aifec\Kernel\App $app, string $path, array $headers = []): \Aifec\Kernel\Response
{
    $request = Request::create('GET', $path, [], [], $headers);
    $request->host = 'tienda.test';
    $request->secure = true;
    $request->ip = '127.0.0.1';

    return $app->handle($request);
}

test('en una tienda en castellano la guía sale en castellano, con lang="es", y no queda ninguna frase de las instrucciones en inglés', function (): void {
    $app = apiApp();
    $map = require dirname(__DIR__) . '/lang/links-es.php';
    foreach (['/buy-with-links', '/buy-with-links/start'] as $path) {
        $r = langOpen($app, $path);
        same(200, $r->status, $path);
        contains('<html lang="es">', $r->body, $path . ': el idioma de la página');
        contains('<title>Compra con enlaces</title>', $r->body, $path . ': el título');
        notContains('<html lang="en">', $r->body);
        notContains('<title>Buying with links</title>', $r->body);
        foreach ($map as $english => $spanish) {
            if (strlen($english) >= 40) {
                notContains($english, $r->body, $path . ': frase sin traducir «' . substr($english, 0, 50) . '…»');
            }
        }
    }
    // Con las instrucciones para el asistente (los pasos con su descripción).
    $guide = langOpen($app, '/buy-with-links')->body;
    contains('Tú nunca pagas ni ves datos de pago', $guide);
    contains('Poner un producto en un carrito nuevo', $guide);
});

test('la respuesta de añadir al carrito sale entera en castellano, también las frases con direcciones dentro; el JSON no se traduce', function (): void {
    // Verificación del 24/11/2026: /buy-with-links/add mezclaba castellano e inglés («where they type their name…»).
    $app = apiApp();
    $id = assistantShop($app)['silla_roja'];
    foreach (['Claude-User/1.0', 'ChatGPT-User/1.0'] as $agent) {
        $r = langOpen($app, '/buy-with-links/add?product_id=' . $id . '&qty=1', ['user-agent' => $agent]);
        same(200, $r->status, $agent);
        contains('El producto está en el carrito.', $r->body, $agent);
        foreach (['where they type', 'If you can open several addresses', 'The product is in the cart', 'this page shows what is still missing', 'the person types who receives it'] as $english) {
            notContains($english, $r->body, $agent . ': frase sin traducir «' . $english . '»');
        }
    }
});

test('en una tienda en inglés la guía sale en inglés, con lang="en", y el JSON de los programas nunca se traduce', function (): void {
    $app = apiApp(['i18n.languages' => ['en']]);
    $r = langOpen($app, '/buy-with-links');
    same(200, $r->status);
    contains('<html lang="en">', $r->body);
    contains('<title>Buying with links</title>', $r->body);
    contains('You never pay and you never see payment details', $r->body);
    notContains('Tú nunca pagas', $r->body);
    // El JSON, en el idioma de la tienda que sea, trae las instrucciones en inglés.
    $es = apiApp();
    $json = langOpen($es, '/buy-with-links', ['accept' => 'application/json']);
    contains('You never pay and you never see payment details', $json->body, 'el JSON se queda en inglés');
});
