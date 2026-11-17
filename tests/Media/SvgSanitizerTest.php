<?php
/**
 * AIFEC — pruebas del limpiador de SVG.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Media\MediaException;
use Aifec\Media\SvgSanitizer;

test('un SVG con script y onload sale limpio y sigue siendo un SVG válido', function (): void {
    $dirty = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="alert(1)">'
        . '<script>alert(2)</script><rect width="10" height="10" fill="red" onclick="x()"/></svg>';
    $clean = SvgSanitizer::clean($dirty);
    notContains('script', $clean);
    notContains('onload', $clean);
    notContains('onclick', $clean);
    notContains('alert', $clean);
    contains('<rect', $clean);
    contains('fill="red"', $clean);
    $xml = simplexml_load_string($clean);
    check($xml !== false, 'la salida debe ser XML válido');
    same('svg', $xml->getName());
});

test('quita foreignObject, iframe y otros elementos activos con su contenido', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">hola</div></foreignObject>'
        . '<iframe src="x"/><object data="y"/><embed src="z"/><circle r="3"/></svg>';
    $clean = SvgSanitizer::clean($svg);
    notContains('foreignObject', $clean);
    notContains('hola', $clean);
    notContains('iframe', $clean);
    notContains('object', $clean);
    notContains('embed', $clean);
    contains('<circle', $clean);
});

test('los enlaces solo pueden ser anclas internas o imágenes data:image', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
        . '<a href="javascript:alert(1)"><rect id="a"/></a>'
        . '<a xlink:href=" JaVaScRiPt:alert(2)"><rect/></a>'
        . '<a href="https://malo.example/x"><rect/></a>'
        . '<use xlink:href="#a"/>'
        . '<use href="#a"/>'
        . '<image href="data:image/png;base64,iVBORw0KGgo="/>'
        . '<image href="data:text/html;base64,PHNjcmlwdD4="/>'
        . '</svg>';
    $clean = SvgSanitizer::clean($svg);
    notContains('javascript', strtolower($clean));
    notContains('malo.example', $clean);
    notContains('text/html', $clean);
    contains('xlink:href="#a"', $clean);
    contains('href="#a"', $clean);
    contains('data:image/png;base64,iVBORw0KGgo=', $clean);
    check(simplexml_load_string($clean) !== false, 'sigue siendo XML válido');
});

test('quita DOCTYPE y entidades externas sin leer nada del disco', function (): void {
    $secret = tmpDir() . '/secreto.txt';
    file_put_contents($secret, 'CONTENIDO-SECRETO');
    $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file://' . str_replace('\\', '/', $secret) . '">]>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';
    $leaked = false;
    try {
        $clean = SvgSanitizer::clean($svg);
        $leaked = strpos($clean, 'CONTENIDO-SECRETO') !== false;
        notContains('DOCTYPE', $clean);
        notContains('ENTITY', $clean);
    } catch (MediaException $e) {
        // también vale rechazarlo: lo que no puede pasar es que el contenido del fichero salga
    }
    check(!$leaked, 'el contenido del fichero local no puede salir en el SVG');

    $plain = SvgSanitizer::clean('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
    notContains('DOCTYPE', $plain);
    contains('<rect', $plain);
});

test('limpia las hojas de estilo: sin @import ni url() externas', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://malo.example/a.css); .a{fill:url(#g)} .b{background:url(https://malo.example/x.png)}</style>'
        . '<rect style="fill:url(javascript:alert(1));stroke:blue" width="1" height="1"/>'
        . '<animate attributeName="href" values="javascript:alert(1)"/><animate attributeName="opacity" values="0;1"/>'
        . '<set attributeName="onmouseover" to="alert(1)"/></svg>';
    $clean = SvgSanitizer::clean($svg);
    notContains('malo.example', $clean);
    notContains('@import', $clean);
    notContains('javascript', $clean);
    notContains('onmouseover', $clean);
    contains('fill:url(#g)', $clean);
    contains('stroke:blue', $clean);
    contains('attributeName="opacity"', $clean);
});

test('conserva el texto con tildes, eñes, emojis y el signo &', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>Canción &amp; niño 😀</title><text x="1" y="2">Ñandú &lt;3</text></svg>';
    $clean = SvgSanitizer::clean($svg);
    contains('Canción &amp; niño 😀', $clean);
    contains('Ñandú &lt;3', $clean);
    contains('viewBox="0 0 10 10"', $clean);
});

test('lo que no es un SVG bien formado lanza MediaException', function (): void {
    foreach ([
        '',
        '   ',
        'esto no es xml',
        '<svg><rect></svg>',
        '<html><body>hola</body></html>',
        '<?xml version="1.0"?><otra-cosa/>',
        '<svg xmlns="http://www.w3.org/2000/svg"><text>&noexiste;</text></svg>',
    ] as $bad) {
        throws(function () use ($bad): void {
            SvgSanitizer::clean($bad);
        }, MediaException::class, 'entrada «' . $bad . '»');
    }
});
