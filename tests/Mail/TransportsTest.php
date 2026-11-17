<?php
/**
 * AIFEC — pruebas de los transportes sencillos (en memoria y mail()) y de la conexión real por sockets.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Mail\ArrayTransport;
use Aifec\Mail\MailException;
use Aifec\Mail\MailFunctionTransport;
use Aifec\Mail\MimeMessage;
use Aifec\Mail\SmtpTransport;
use Aifec\Mail\StreamSmtpConnection;
use Aifec\Mail\Transport;
use Aifec\Tests\Mail\MailTestKit;

require_once __DIR__ . '/MailTestKit.php';

test('ArrayTransport guarda una copia de cada mensaje y valida al enviar', function (): void {
    $t = new ArrayTransport();
    check($t instanceof Transport);
    $m = MailTestKit::message();

    $t->send($m);
    $m->subject('cambiado después');
    $t->send($m);

    same(2, count($t->sent));
    same('Hola', $t->sent[0]->getSubject(), 'la copia no cambia con el original');
    same('cambiado después', $t->sent[1]->getSubject());
    throws(function () use ($t): void {
        $t->send((new MimeMessage())->to('a@b.es'));
    }, MailException::class);
    same(2, count($t->sent), 'un mensaje inválido no se retiene');
});

test('mail(): To y Subject van como parámetros, Bcc se queda en las cabeceras y el cuerpo llega entero', function (): void {
    $llamadas = [];
    $t = new MailFunctionTransport(function (string $to, string $subject, string $body, string $headers) use (&$llamadas): bool {
        $llamadas[] = [$to, $subject, $body, $headers];

        return true;
    });
    $m = MailTestKit::message()->subject('Año nuevo: ¡ofertas! 🎉')->to('b@c.es', 'Pérez, Ana')->cc('cc@x.es')->bcc('oculto@x.es');

    $t->send($m);

    same(1, count($llamadas));
    [$to, $subject, $body, $headers] = $llamadas[0];
    same('Año nuevo: ¡ofertas! 🎉', MailTestKit::decodeWords($subject));
    notContains("\r\n", $subject, 'el asunto va en una sola línea');
    contains('ana@cliente.es', $to);
    notContains("\r\n", $to);
    notContains('To:', $headers);
    notContains('Subject:', $headers);
    contains("Bcc: oculto@x.es", $headers);
    contains("Cc: cc@x.es", $headers);
    contains('From: Tienda <tienda@ejemplo.es>', $headers);
    contains('MIME-Version: 1.0', $headers);
    contains('Content-Type: text/plain; charset=UTF-8', $headers);
    same("Cuerpo\r\n", quoted_printable_decode($body));
});

test('mail(): si la función devuelve false, o no hay destinatarios, lanza', function (): void {
    $t = new MailFunctionTransport(static function (): bool {
        return false;
    });
    throws(function () use ($t): void {
        $t->send(MailTestKit::message());
    }, MailException::class);
    throws(function () use ($t): void {
        $t->send((new MimeMessage())->from('a@b.es')->text('x'));
    }, MailException::class);

    // Un mensaje solo con Bcc usa un destinatario genérico en To.
    $visto = '';
    $ok = new MailFunctionTransport(function (string $to) use (&$visto): bool {
        $visto = $to;

        return true;
    });
    $ok->send((new MimeMessage())->from('a@b.es')->bcc('c@d.es')->text('x'));
    same('undisclosed-recipients:;', $visto);
});

test('StreamSmtpConnection habla de verdad con un servidor local: saludo, orden, respuesta y cierre', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    check(is_resource($server), 'no se pudo abrir el servidor local: ' . $errstr);
    $name = stream_socket_get_name($server, false);
    $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    check($port > 0, 'puerto local ' . $name);

    $conn = new StreamSmtpConnection();
    try {
        $conn->open('127.0.0.1', $port, false, 3.0);
        $peer = stream_socket_accept($server, 3.0);
        check(is_resource($peer), 'el servidor no recibió la conexión');
        stream_set_timeout($peer, 3);

        fwrite($peer, "220-hola\r\n220 listo\r\n");
        same('220-hola', $conn->readLine());
        same('220 listo', $conn->readLine());

        $conn->write("EHLO prueba\r\n");
        same("EHLO prueba\r\n", fgets($peer));

        // Una escritura mayor que el trozo de escritura llega entera.
        $grande = str_repeat("0123456789abcdef\r\n", 4000);
        $conn->write($grande);
        $recibido = '';
        while (strlen($recibido) < strlen($grande)) {
            $parte = fread($peer, 65536);
            if ($parte === false || $parte === '') {
                break;
            }
            $recibido .= $parte;
        }
        same(strlen($grande), strlen($recibido));
        same(md5($grande), md5($recibido));

        // El servidor cuelga: la lectura siguiente lanza en lugar de devolver vacío.
        fclose($peer);
        throws(function () use ($conn): void {
            $conn->readLine();
        }, MailException::class);
    } finally {
        $conn->close();
        $conn->close();
        fclose($server);
    }

    throws(function () use ($conn): void {
        $conn->write('x');
    }, MailException::class, 'tras cerrar no se puede escribir');
});

test('StreamSmtpConnection: sin servidor, o con un puerto cerrado, lanza MailException y no un aviso de PHP', function (): void {
    // Se reserva un puerto libre y se cierra: nadie escucha ahí.
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = (string) stream_socket_get_name($s, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($s);

    $conn = new StreamSmtpConnection();
    throws(function () use ($conn, $port): void {
        $conn->open('127.0.0.1', $port, false, 2.0);
    }, MailException::class);
    throws(function () use ($conn): void {
        $conn->readLine();
    }, MailException::class);
    throws(function () use ($conn): void {
        $conn->startTls();
    }, MailException::class);
});

test('SmtpTransport con la conexión real envía a un servidor SMTP local de mentira', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    check(is_resource($server), 'no se pudo abrir el servidor local');
    $name = (string) stream_socket_get_name($server, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);

    // La conexión del cliente se abre y el «servidor» se atiende a mano, en el mismo proceso, con el
    // cliente en un sub-flujo: se usa un envoltorio que atiende el diálogo cuando el cliente escribe.
    $conn = new class($server) implements \Aifec\Mail\SmtpConnection {
        /** @var resource */
        private $server;

        /** @var resource|null */
        private $peer = null;

        private StreamSmtpConnection $real;

        private bool $inData = false;

        /** @var string[] */
        public array $seen = [];

        /** @param resource $server */
        public function __construct($server)
        {
            $this->server = $server;
            $this->real = new StreamSmtpConnection();
        }

        public function open(string $host, int $port, bool $implicitTls, float $timeout): void
        {
            $this->real->open($host, $port, $implicitTls, $timeout);
            $this->peer = stream_socket_accept($this->server, 3.0);
            fwrite($this->peer, "220 local ESMTP\r\n");
        }

        public function write(string $data): void
        {
            $this->real->write($data);
            if ($this->inData) {
                // Es el cuerpo del mensaje: se lee hasta la línea con solo un punto.
                while (($l = fgets($this->peer)) !== false) {
                    if ($l === ".\r\n") {
                        break;
                    }
                    $this->seen[] = rtrim($l, "\r\n");
                }
                $this->inData = false;
                fwrite($this->peer, "250 queued\r\n");

                return;
            }
            $line = rtrim((string) fgets($this->peer), "\r\n");
            $this->seen[] = $line;
            if (strpos($line, 'EHLO') === 0) {
                fwrite($this->peer, "250-local\r\n250 8BITMIME\r\n");
            } elseif ($line === 'DATA') {
                $this->inData = true;
                fwrite($this->peer, "354 ok\r\n");
            } elseif ($line === 'QUIT') {
                fwrite($this->peer, "221 bye\r\n");
            } else {
                fwrite($this->peer, "250 ok\r\n");
            }
        }

        public function readLine(): string
        {
            return $this->real->readLine();
        }

        public function startTls(): void
        {
            $this->real->startTls();
        }

        public function close(): void
        {
            $this->real->close();
            if (is_resource($this->peer)) {
                fclose($this->peer);
            }
        }
    };

    try {
        (new SmtpTransport('127.0.0.1', $port, 'none', '', '', 3.0, $conn))->setLocalName('cliente.test')
            ->send(MailTestKit::message()->text('Línea uno'));
    } finally {
        fclose($server);
    }

    same('EHLO cliente.test', $conn->seen[0]);
    same('MAIL FROM:<tienda@ejemplo.es>', $conn->seen[1]);
    same('RCPT TO:<ana@cliente.es>', $conn->seen[2]);
    same('DATA', $conn->seen[3]);
    contains('Subject: Hola', implode("\n", $conn->seen));
    same('QUIT', $conn->seen[count($conn->seen) - 1]);
});

test('StreamSmtpConnection: un fallo pasajero del DNS se reintenta; un nombre que no existe, no (17/11/2026)', function (): void {
    check(\Aifec\Mail\StreamSmtpConnection::transientDns('php_network_getaddresses: getaddrinfo for mail.gmartos.es failed: Temporary failure in name resolution'), 'el fallo de esta noche es pasajero');
    check(!\Aifec\Mail\StreamSmtpConnection::transientDns('php_network_getaddresses: getaddrinfo for x.invalid failed: Name or service not known'), 'un nombre que no existe no lo es');
    check(!\Aifec\Mail\StreamSmtpConnection::transientDns('Connection refused'), 'ni una conexión rechazada');
    $start = microtime(true);
    try {
        (new \Aifec\Mail\StreamSmtpConnection())->open('no-existe.invalid', 25, false, 2.0);
        check(false, 'tenía que fallar');
    } catch (\Aifec\Mail\MailException $e) {
        contains('Could not connect to no-existe.invalid', $e->getMessage());
    }
    check(microtime(true) - $start < 0.9, 'y un nombre que no existe no espera a los reintentos');
});
