<?php
/**
 * AIFEC — pruebas de la composición de mensajes MIME.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Mail\MailException;
use Aifec\Mail\MimeMessage;
use Aifec\Tests\Mail\MailTestKit;

require_once __DIR__ . '/MailTestKit.php';

test('un asunto con tildes, símbolos y emoji se decodifica de vuelta al original', function (): void {
    $subject = 'Año nuevo: ¡ofertas & más! 🎉';
    $raw = MailTestKit::message()->subject($subject)->toString();

    same($subject, MailTestKit::decodedHeader($raw, 'Subject'));
    notContains('Año', explode("\r\n\r\n", $raw)[0], 'el asunto no puede ir en crudo en las cabeceras');
});

test('un asunto largo no ASCII se parte en palabras codificadas de 75 caracteres como máximo', function (): void {
    $subject = str_repeat('Ñandú ñoño 🎉 pingüino ', 12);
    $raw = MailTestKit::message()->subject($subject)->toString();

    $head = explode("\r\n\r\n", $raw)[0];
    check(preg_match_all('/=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=/', $head, $m) > 1, 'tiene que haber varias palabras');
    foreach ($m[0] as $word) {
        check(strlen($word) <= 75, 'palabra de ' . strlen($word) . ' caracteres');
    }
    same(trim($subject), trim(MailTestKit::decodedHeader($raw, 'Subject')));
    foreach (explode("\r\n", $head) as $line) {
        check(strlen($line) <= 78, 'línea de cabecera de ' . strlen($line) . ' caracteres');
    }
});

test('un asunto ASCII largo se pliega sin perder ni una palabra', function (): void {
    $subject = trim(str_repeat('pedido confirmado y enviado hoy ', 6));
    $raw = MailTestKit::message()->subject($subject)->toString();

    same($subject, MailTestKit::decodedHeader($raw, 'Subject'));
    foreach (explode("\r\n", explode("\r\n\r\n", $raw)[0]) as $line) {
        check(strlen($line) <= 78, 'línea de ' . strlen($line));
    }
});

test('solo texto: un único text/plain, sin multipart', function (): void {
    $raw = MailTestKit::message()->toString();
    $p = MailTestKit::parse($raw);

    same('text/plain', MailTestKit::shape($p));
    same('1.0', $p['headers']['mime-version']);
    same('quoted-printable', $p['headers']['content-transfer-encoding']);
    contains('charset=UTF-8', $p['headers']['content-type']);
    same("Cuerpo\r\n", MailTestKit::decodeBody($p));
});

test('texto y HTML: multipart/alternative con el texto primero', function (): void {
    $m = MailTestKit::message()->html('<p>Hola <b>mundo</b></p>');
    $p = MailTestKit::parse($m->toString());

    same('multipart/alternative(text/plain,text/html)', MailTestKit::shape($p));
    same("Cuerpo\r\n", MailTestKit::decodeBody($p['parts'][0]));
    same("<p>Hola <b>mundo</b></p>\r\n", MailTestKit::decodeBody($p['parts'][1]));
});

test('solo HTML: se genera la versión de texto quitando las etiquetas', function (): void {
    $m = (new MimeMessage())->from('a@b.es')->to('c@d.es')->date(0)
        ->html('<style>p{color:red}</style><h1>Título</h1><p>Compra &amp; ahorra&nbsp;50 %</p>'
            . '<ul><li>Uno</li><li>Dos</li></ul><a href="https://tienda.es/x">Ver oferta</a><br>Adiós');
    $p = MailTestKit::parse($m->toString());

    same('multipart/alternative(text/plain,text/html)', MailTestKit::shape($p));
    $text = MailTestKit::decodeBody($p['parts'][0]);
    notContains('<', $text);
    notContains('color:red', $text);
    contains('Título', $text);
    contains('Compra & ahorra 50 %', $text);
    contains('- Uno', $text);
    contains('Ver oferta (https://tienda.es/x)', $text);
    contains('Adiós', $text);
});

test('imágenes en línea: multipart/related dentro del alternative, con su Content-ID', function (): void {
    $m = MailTestKit::message()->html('<img src="cid:logo">')->embed('logo', 'PNGDATA', 'image/png');
    $p = MailTestKit::parse($m->toString());

    same('multipart/alternative(text/plain,multipart/related(text/html,image/png))', MailTestKit::shape($p));
    $img = $p['parts'][1]['parts'][1];
    same('<logo>', $img['headers']['content-id']);
    same('inline', $img['headers']['content-disposition']);
    same('PNGDATA', MailTestKit::decodeBody($img));
});

test('con adjuntos: multipart/mixed por fuera; con todo, mixed > alternative > related', function (): void {
    $solo = MailTestKit::message()->attach('factura.pdf', '%PDF-1.4', 'application/pdf');
    same('multipart/mixed(text/plain,application/pdf)', MailTestKit::shape(MailTestKit::parse($solo->toString())));

    $todo = MailTestKit::message()->html('<img src="cid:a">')->embed('a', 'x', 'image/gif')
        ->attach('a.txt', 'hola', 'text/plain')->attach('b.zip', 'zz');
    same(
        'multipart/mixed(multipart/alternative(text/plain,multipart/related(text/html,image/gif)),text/plain,application/octet-stream)',
        MailTestKit::shape(MailTestKit::parse($todo->toString()))
    );
});

test('los adjuntos van en base64 con líneas de 76 y se recuperan idénticos', function (): void {
    $content = '';
    for ($i = 0; $i < 700; ++$i) {
        $content .= chr(($i * 37 + 11) % 256);
    }
    $raw = MailTestKit::message()->attach('datos.bin', $content)->toString();
    $att = MailTestKit::parse($raw)['parts'][1];

    same('base64', $att['headers']['content-transfer-encoding']);
    $lines = explode("\r\n", $att['body']);
    check(count($lines) > 5);
    foreach (array_slice($lines, 0, -1) as $line) {
        same(76, strlen($line));
    }
    check(strlen($lines[count($lines) - 1]) <= 76);
    same($content, MailTestKit::decodeBody($att));
    contains('filename="datos.bin"', $att['headers']['content-disposition']);
    contains('name="datos.bin"', $att['headers']['content-type']);
});

test('un adjunto vacío y un nombre con tildes y comillas no rompen la estructura', function (): void {
    $raw = MailTestKit::message()->attach('Año "nuevo" ñ.txt', '', 'text/plain')->toString();
    $att = MailTestKit::parse($raw)['parts'][1];

    same('', MailTestKit::decodeBody($att));
    contains("filename*=UTF-8''" . rawurlencode('Año "nuevo" ñ.txt'), $att['headers']['content-disposition']);
    notContains('Año', $att['headers']['content-disposition']);
});

test('Bcc no aparece en las cabeceras pero sí entre los destinatarios, sin repetir', function (): void {
    $m = MailTestKit::message()->to('ANA@cliente.es')->cc('luis@cliente.es')->bcc('secreto@oculto.es')->bcc('luis@cliente.es');
    $raw = $m->toString();

    notContains('Bcc', $raw);
    notContains('secreto@oculto.es', $raw);
    contains('Cc: luis@cliente.es', $raw);
    same(['ana@cliente.es', 'luis@cliente.es', 'secreto@oculto.es'], $m->recipients());
    same('tienda@ejemplo.es', $m->sender());
    same('Hola', $m->getSubject());
});

test('un intento de inyección de cabeceras lanza MailException en todos los campos', function (): void {
    $malo = "a@b.es\r\nBcc: x@y.es";
    throws(function () use ($malo): void {
        (new MimeMessage())->to($malo);
    }, MailException::class);
    throws(function () use ($malo): void {
        (new MimeMessage())->from($malo);
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->to('a@b.es', "Ana\r\nBcc: x@y.es");
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->subject("Hola\r\nBcc: x@y.es");
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->subject("Hola\nBcc: x@y.es");
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->header('X-Algo', "v\r\nBcc: x@y.es");
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->header("X-Algo\r\nBcc", 'v');
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->attach("a.txt\r\nBcc: x@y.es", 'x');
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->attach('a.txt', 'x', "text/plain\r\nBcc: x@y.es");
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->embed("a>\r\nBcc: x", 'x', 'image/png');
    }, MailException::class);
    throws(function (): void {
        (new MimeMessage())->messageId("a@b\r\nBcc: x@y.es");
    }, MailException::class);
});

test('direcciones inválidas y cabeceras reservadas se rechazan', function (): void {
    foreach (['', 'sin-arroba', 'a@', '@b.es', 'a b@c.es', 'ñ@c.es'] as $mal) {
        throws(function () use ($mal): void {
            (new MimeMessage())->to($mal);
        }, MailException::class, 'dirección «' . $mal . '»');
    }
    foreach (['Bcc', 'To', 'From', 'subject', 'Content-Type', 'MIME-Version', 'Message-ID', 'Date'] as $res) {
        throws(function () use ($res): void {
            (new MimeMessage())->header($res, 'x');
        }, MailException::class, 'cabecera ' . $res);
    }
    throws(function (): void {
        (new MimeMessage())->attach('', 'x');
    }, MailException::class);
});

test('sin remitente no se puede componer ni pedir el emisor', function (): void {
    $m = (new MimeMessage())->to('a@b.es')->text('x');

    throws(function () use ($m): void {
        $m->toString();
    }, MailException::class);
    throws(function () use ($m): void {
        $m->sender();
    }, MailException::class);
});

test('todo el mensaje usa CRLF: ningún salto suelto, ni con texto con LF, CR y CRLF mezclados', function (): void {
    $m = MailTestKit::message()->text("uno\ndos\rtres\r\ncuatro")->html("<p>a</p>\n<p>b</p>")
        ->embed('i', "bin\ncon\nsaltos", 'image/png')->attach('a.txt', "x\ny", 'text/plain');
    $raw = $m->toString();

    check(preg_match('/(?<!\r)\n/', $raw) === 0, 'LF suelto');
    check(preg_match('/\r(?!\n)/', $raw) === 0, 'CR suelto');
    foreach (explode("\r\n", $raw) as $line) {
        check(strlen($line) <= 998, 'línea demasiado larga');
    }
    $p = MailTestKit::parse($raw);
    same("uno\r\ndos\r\ntres\r\ncuatro\r\n", MailTestKit::decodeBody($p['parts'][0]['parts'][0]));
});

test('Date y Message-ID: se pueden fijar y por defecto se generan con el dominio del remitente', function (): void {
    $p = MailTestKit::parse(MailTestKit::message()->date(86400 * 365)->toString());
    same('Fri, 01 Jan 1971 00:00:00 +0000', $p['headers']['date']);
    same('<fijo123@ejemplo.es>', $p['headers']['message-id']);

    $auto = (new MimeMessage())->from('x@mi-tienda.com')->to('y@z.es')->text('t');
    $id = MailTestKit::parse($auto->toString())['headers']['message-id'];
    check(preg_match('/^<[0-9a-f]{24}@mi-tienda\.com>$/', $id) === 1, 'Message-ID «' . $id . '»');
    same($id, MailTestKit::parse($auto->toString())['headers']['message-id'], 'estable entre dos serializaciones');
    $fecha = MailTestKit::parse($auto->toString())['headers']['date'];
    check(preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} \+0000$/', $fecha) === 1, 'Date «' . $fecha . '»');
});

test('nombres de dirección: ASCII con comas entre comillas, no ASCII codificados', function (): void {
    $raw = MailTestKit::message()
        ->to('b@c.es', 'Pérez, Ana')
        ->to('d@e.es', 'Smith, John "Jr"')
        ->to('f@g.es', 'Luis Gómez')
        ->replyTo('r@s.es', 'Soporte')
        ->toString();
    $p = MailTestKit::parse($raw);
    $to = MailTestKit::decodeWords($p['headers']['to']);

    contains('Pérez, Ana <b@c.es>', $to);
    contains('"Smith, John \"Jr\"" <d@e.es>', $to);
    contains('Luis Gómez <f@g.es>', $to);
    same('Soporte <r@s.es>', $p['headers']['reply-to']);
    same('Tienda <tienda@ejemplo.es>', $p['headers']['from']);
    notContains('Pérez', $p['headers']['to']);
});

test('cuerpo quoted-printable: tildes, eñes, emoji, «=» y líneas largas ida y vuelta, líneas de 76', function (): void {
    $text = "Ñandú = 3 & más 🎉 «comillas»\r\n" . str_repeat('palabra ', 40) . "\r\n.punto al inicio\r\nfin  ";
    $raw = MailTestKit::message()->text($text)->toString();
    $p = MailTestKit::parse($raw);

    foreach (explode("\r\n", $p['body']) as $line) {
        check(strlen($line) <= 76, 'línea QP de ' . strlen($line));
    }
    same($text . "\r\n", MailTestKit::decodeBody($p));
});

test('casos límite: mensaje vacío, texto vacío, asunto vacío y cabecera propia', function (): void {
    $vacio = (new MimeMessage())->from('a@b.es')->to('c@d.es')->date(0)->messageId('<x@b.es>');
    $p = MailTestKit::parse($vacio->toString());
    same('text/plain', MailTestKit::shape($p));
    same('', MailTestKit::decodeBody($p));
    same('', $p['headers']['subject']);

    $custom = MailTestKit::message()->header('X-Pedido', 'Nº 42 ñ')->header('List-Unsubscribe', '<mailto:baja@e.es>');
    $q = MailTestKit::parse($custom->toString());
    same('Nº 42 ñ', MailTestKit::decodeWords($q['headers']['x-pedido']));
    same('<mailto:baja@e.es>', $q['headers']['list-unsubscribe']);
});

test('un asunto con UTF-8 inválido lanza en lugar de generar basura', function (): void {
    $m = MailTestKit::message()->subject("roto \xC3\x28 fin");

    throws(function () use ($m): void {
        $m->toString();
    }, MailException::class);
});
