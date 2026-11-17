<?php
/**
 * AIFEC — pruebas del transporte SMTP con una conexión de guion.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Mail\MailException;
use Aifec\Mail\MimeMessage;
use Aifec\Mail\SmtpTransport;
use Aifec\Tests\Mail\MailTestKit;
use Aifec\Tests\Mail\ScriptedConnection;

require_once __DIR__ . '/MailTestKit.php';
require_once __DIR__ . '/ScriptedConnection.php';

/** Guion de un envío completo con STARTTLS y AUTH LOGIN con $rcpt destinatarios. @return array<int,string|string[]> */
function smtpScriptStartTls(int $rcpt = 1): array
{
    $script = [
        '220 smtp.test ESMTP listo',
        ['250-smtp.test saluda', '250-SIZE 35882577', '250-STARTTLS', '250 8BITMIME'],
        '220 2.0.0 Ready to start TLS',
        ['250-smtp.test saluda', '250-AUTH LOGIN PLAIN', '250 8BITMIME'],
        '334 VXNlcm5hbWU6',
        '334 UGFzc3dvcmQ6',
        '235 2.7.0 Authentication successful',
        '250 2.1.0 Sender OK',
    ];
    for ($i = 0; $i < $rcpt; ++$i) {
        $script[] = '250 2.1.5 Recipient OK';
    }
    $script[] = '354 Start mail input';
    $script[] = '250 2.0.0 Queued';
    $script[] = '221 Bye';

    return $script;
}

function smtpTransport(ScriptedConnection $conn, string $enc = 'starttls', string $user = 'usuario@ejemplo.es', string $pass = 'S3cr3to-ñ!'): SmtpTransport
{
    return (new SmtpTransport('smtp.test', $enc === 'tls' ? 465 : 587, $enc, $user, $pass, 5.0, $conn))->setLocalName('cliente.test');
}

test('envío completo con STARTTLS y AUTH LOGIN: orden exacto y credenciales en base64', function (): void {
    $conn = new ScriptedConnection(smtpScriptStartTls(3));
    $m = MailTestKit::message()->cc('luis@cliente.es')->bcc('oculto@cliente.es');

    smtpTransport($conn)->send($m);

    same([
        'EHLO cliente.test',
        'STARTTLS',
        'EHLO cliente.test',
        'AUTH LOGIN',
        base64_encode('usuario@ejemplo.es'),
        base64_encode('S3cr3to-ñ!'),
        'MAIL FROM:<tienda@ejemplo.es>',
        'RCPT TO:<ana@cliente.es>',
        'RCPT TO:<luis@cliente.es>',
        'RCPT TO:<oculto@cliente.es>',
        'DATA',
        '<data>',
        'QUIT',
    ], $conn->commands());
    same(
        ['open smtp.test:587', 'cmd EHLO cliente.test', 'cmd STARTTLS', 'starttls', 'cmd EHLO cliente.test'],
        array_slice($conn->events, 0, 5)
    );
    same('close', $conn->events[count($conn->events) - 1]);
    check($conn->closed, 'la conexión tiene que quedar cerrada');

    $data = $conn->dataPayload();
    same("\r\n.\r\n", substr($data, -5));
    contains("Message-ID: <fijo123@ejemplo.es>\r\n", $data);
    notContains('Bcc', $data);
    notContains('oculto@cliente.es', $data);
});

test('las respuestas de varias líneas se leen enteras y el AUTH PLAIN se usa si no hay LOGIN', function (): void {
    $conn = new ScriptedConnection([
        ['220-smtp.test', '220-segunda línea del saludo', '220 fin del saludo'],
        ['250-smtp.test', '250-auth plain', '250-AUTH=PLAIN CRAM-MD5', '250 OK'],
        '235 2.7.0 ok',
        '250 ok',
        '250 ok',
        '354 adelante',
        '250 hecho',
        '221 adiós',
    ]);

    smtpTransport($conn, 'none', 'u', 'p')->send(MailTestKit::message());

    same([
        'EHLO cliente.test',
        'AUTH PLAIN ' . base64_encode("\0u\0p"),
        'MAIL FROM:<tienda@ejemplo.es>',
        'RCPT TO:<ana@cliente.es>',
        'DATA',
        '<data>',
        'QUIT',
    ], $conn->commands());
    same('open smtp.test:587', $conn->events[0]);
});

test('sin usuario no se autentica, y con «tls» la conexión se abre ya cifrada', function (): void {
    $conn = new ScriptedConnection([
        '220 hola',
        ['250-hola', '250 AUTH LOGIN'],
        '250 ok',
        '250 ok',
        '354 ok',
        '250 ok',
        '221 ok',
    ]);

    smtpTransport($conn, 'tls', '', '')->send(MailTestKit::message());

    same('open smtp.test:465 tls', $conn->events[0]);
    notContains('AUTH', implode('|', $conn->commands()));
    notContains('starttls', implode('|', $conn->events));
});

test('si el servidor no entiende EHLO se recurre a HELO', function (): void {
    $conn = new ScriptedConnection([
        '220 viejo',
        '502 5.5.2 command not recognized',
        '250 viejo.test',
        '250 ok',
        '250 ok',
        '354 ok',
        '250 ok',
        '221 ok',
    ]);

    smtpTransport($conn, 'none', '', '')->send(MailTestKit::message());

    same(['EHLO cliente.test', 'HELO cliente.test', 'MAIL FROM:<tienda@ejemplo.es>'], array_slice($conn->commands(), 0, 3));
});

test('un destinatario rechazado (550) lanza con el código y el texto, y la conexión queda cerrada', function (): void {
    $script = smtpScriptStartTls(1);
    $script[8] = '550 5.1.1 <ana@cliente.es> user unknown';
    $conn = new ScriptedConnection($script);

    $msg = '';
    try {
        smtpTransport($conn)->send(MailTestKit::message());
    } catch (MailException $e) {
        $msg = $e->getMessage();
    }

    contains('550', $msg);
    contains('user unknown', $msg);
    contains('RCPT TO', $msg);
    check($conn->closed, 'la conexión tiene que quedar cerrada tras el fallo');
    notContains('DATA', implode('|', $conn->commands()), 'no se pide DATA tras el rechazo');
});

test('el servidor que cae al saludar, o responde basura, lanza y cierra', function (): void {
    foreach (['554 5.3.2 no hay servicio', 'esto no es SMTP', '421 4.3.2 volviendo'] as $saludo) {
        $conn = new ScriptedConnection([$saludo]);
        throws(function () use ($conn): void {
            smtpTransport($conn)->send(MailTestKit::message());
        }, MailException::class, $saludo);
        check($conn->closed, 'cerrada tras «' . $saludo . '»');
    }

    $conn = new ScriptedConnection([]);
    throws(function () use ($conn): void {
        smtpTransport($conn)->send(MailTestKit::message());
    }, MailException::class, 'sin saludo');
    check($conn->closed, 'cerrada sin saludo');
});

test('relleno de puntos: toda línea que empieza por «.» se envía con «..»', function (): void {
    $conn = new ScriptedConnection([
        '220 hola', ['250-hola', '250 OK'], '250 ok', '250 ok', '354 ok', '250 ok', '221 ok',
    ]);
    $m = MailTestKit::message()->text(".punto\r\n..dos puntos\r\nlínea normal\r\n.");

    smtpTransport($conn, 'none', '', '')->send($m);
    $data = $conn->dataPayload();

    contains("\r\n..punto\r\n", $data);
    contains("\r\n...dos puntos\r\n", $data);
    notContains("\r\n.punto", $data);
    // La única línea que es un solo punto es el terminador.
    same(1, preg_match_all('/^\.\r\n/m', $data));
    same("\r\n.\r\n", substr($data, -5));
});

test('un cuerpo de texto sin salto final y con salto final termina siempre con un único terminador', function (): void {
    foreach (['sin salto', "con salto\r\n"] as $texto) {
        $conn = new ScriptedConnection(['220 h', ['250-h', '250 OK'], '250 a', '250 b', '354 c', '250 d', '221 e']);
        smtpTransport($conn, 'none', '', '')->send(MailTestKit::message()->text($texto));
        $data = $conn->dataPayload();

        same("\r\n.\r\n", substr($data, -5));
        notContains("\r\n\r\n.\r\n", substr($data, -8), 'sin línea en blanco de más antes del punto');
    }
});

test('la contraseña no aparece en el mensaje de ninguna excepción, ni cuando el servidor la repite', function (): void {
    $pass = 'S3cr3to-ñ!';
    $plain = base64_encode("\0usuario@ejemplo.es\0" . $pass);
    $variantes = [
        'AUTH LOGIN rechazado' => function (array $s) use ($pass): array {
            $s[6] = '535 5.7.8 Authentication failed for password ' . $pass;

            return $s;
        },
        'servidor que repite la contraseña en base64' => function (array $s) use ($pass): array {
            $s[6] = '535 5.7.8 ' . base64_encode($pass) . ' no válida';

            return $s;
        },
        'servidor que corta tras el usuario' => function (array $s): array {
            $s[5] = '454 4.7.0 Temporary authentication failure';

            return $s;
        },
        'MAIL FROM rechazado' => function (array $s): array {
            $s[7] = '553 5.1.8 remitente no permitido';

            return $s;
        },
    ];
    foreach ($variantes as $nombre => $mutar) {
        $conn = new ScriptedConnection($mutar(smtpScriptStartTls(1)));
        $msg = null;
        try {
            smtpTransport($conn)->send(MailTestKit::message());
        } catch (MailException $e) {
            $msg = $e->getMessage();
        }
        check($msg !== null, 'tenía que lanzar: ' . $nombre);
        notContains($pass, (string) $msg, $nombre);
        notContains(base64_encode($pass), (string) $msg, $nombre);
        notContains($plain, (string) $msg, $nombre);
        check($conn->closed, 'cerrada: ' . $nombre);
    }

    // Un fallo de autenticación normal conserva el código del servidor.
    $script = smtpScriptStartTls(1);
    $script[6] = '535 5.7.8 Authentication failed';
    $conn = new ScriptedConnection($script);
    $msg = '';
    try {
        smtpTransport($conn)->send(MailTestKit::message());
    } catch (MailException $e) {
        $msg = $e->getMessage();
    }
    contains('535', $msg);
    contains('Authentication failed', $msg);
});

test('con STARTTLS pedido y no ofrecido se aborta antes de mandar credenciales', function (): void {
    $conn = new ScriptedConnection(['220 hola', ['250-hola', '250 AUTH LOGIN PLAIN'], '334 x', '334 y', '235 z']);

    $msg = '';
    try {
        smtpTransport($conn)->send(MailTestKit::message());
    } catch (MailException $e) {
        $msg = $e->getMessage();
    }

    contains('STARTTLS', $msg);
    same(['EHLO cliente.test', 'QUIT'], $conn->commands());
    check($conn->closed);
});

test('con usuario y sin AUTH anunciado se aborta sin mandar nada más', function (): void {
    $conn = new ScriptedConnection(['220 hola', ['250-hola', '250 8BITMIME'], '250 x']);

    throws(function () use ($conn): void {
        smtpTransport($conn, 'none')->send(MailTestKit::message());
    }, MailException::class);
    same(['EHLO cliente.test', 'QUIT'], $conn->commands());
});

test('una respuesta que no es la esperada en DATA o en el cuerpo lanza y cierra', function (): void {
    $script = smtpScriptStartTls(1);
    $script[9] = '554 5.3.0 transacción fallida';
    $conn = new ScriptedConnection($script);
    throws(function () use ($conn): void {
        smtpTransport($conn)->send(MailTestKit::message());
    }, MailException::class);
    check($conn->closed);

    $script = smtpScriptStartTls(1);
    $script[10] = '452 4.3.1 sin espacio en el servidor';
    $conn = new ScriptedConnection($script);
    $msg = '';
    try {
        smtpTransport($conn)->send(MailTestKit::message());
    } catch (MailException $e) {
        $msg = $e->getMessage();
    }
    contains('452', $msg);
    check($conn->closed);
});

test('mensaje sin destinatarios o sin remitente: falla antes de abrir ninguna conexión', function (): void {
    $conn = new ScriptedConnection(smtpScriptStartTls(1));

    throws(function () use ($conn): void {
        smtpTransport($conn)->send((new MimeMessage())->from('a@b.es')->text('x'));
    }, MailException::class);
    throws(function () use ($conn): void {
        smtpTransport($conn)->send((new MimeMessage())->to('a@b.es')->text('x'));
    }, MailException::class);
    same([], $conn->events, 'no se ha tocado la conexión');
});

test('la configuración inválida se rechaza al construir', function (): void {
    throws(function (): void {
        new SmtpTransport('smtp.test', 587, 'ssl');
    }, MailException::class);
    throws(function (): void {
        new SmtpTransport('', 587);
    }, MailException::class);
    throws(function (): void {
        new SmtpTransport('smtp.test', 70000);
    }, MailException::class);
    throws(function (): void {
        (new SmtpTransport('smtp.test'))->setLocalName("a\r\nb");
    }, MailException::class);
    // El cifrado se acepta sin importar mayúsculas.
    $t = new SmtpTransport('smtp.test', 465, 'TLS');
    check($t instanceof SmtpTransport);
});

test('el mismo transporte se puede reutilizar para varios envíos seguidos', function (): void {
    $conn = new ScriptedConnection(array_merge(smtpScriptStartTls(1), smtpScriptStartTls(2)));
    $t = smtpTransport($conn);

    $t->send(MailTestKit::message());
    $t->send(MailTestKit::message()->to('otra@persona.es'));

    $comandos = $conn->commands();
    same(2, count(array_keys($comandos, 'DATA', true)));
    same(2, count(array_keys($comandos, 'QUIT', true)));
});
