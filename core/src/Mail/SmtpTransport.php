<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Mail;

/**
 * Envío por SMTP (RFC 5321) sin librerías: EHLO/HELO, STARTTLS, AUTH LOGIN o PLAIN, MAIL FROM, RCPT TO, DATA.
 *
 * $encryption: «none» (sin cifrar), «starttls» (puerto 587; si el servidor no lo ofrece se aborta, nunca se
 * sigue en claro) o «tls» (cifrado desde el primer byte, puerto 465).
 */
final class SmtpTransport implements Transport
{
    /** Tope de líneas de una respuesta, para no quedarse leyendo sin fin a un servidor defectuoso. */
    private const MAX_REPLY_LINES = 200;

    private string $host;

    private int $port;

    private string $encryption;

    private string $user;

    private string $password;

    private float $timeout;

    private ?SmtpConnection $connection;

    private string $localName = '';

    /** Conexión de la ficha de envío en curso. */
    private ?SmtpConnection $active = null;

    private bool $supportsStartTls = false;

    /** @var string[] mecanismos AUTH anunciados por el servidor, en mayúsculas */
    private array $authMethods = [];

    public function __construct(
        string $host,
        int $port = 587,
        string $encryption = 'starttls',
        string $user = '',
        string $password = '',
        float $timeout = 15.0,
        ?SmtpConnection $connection = null
    ) {
        $encryption = strtolower($encryption);
        if (!in_array($encryption, ['none', 'starttls', 'tls'], true)) {
            throw new MailException('Invalid SMTP encryption: use none, starttls or tls');
        }
        if ($host === '') {
            throw new MailException('The SMTP host is empty');
        }
        if ($port < 1 || $port > 65535) {
            throw new MailException('Invalid SMTP port');
        }
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->user = $user;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->connection = $connection;
    }

    /** Nombre con el que nos presentamos en EHLO (por defecto, el del equipo). */
    public function setLocalName(string $name): self
    {
        if ($name !== '' && !$this->validHeloName($name)) {
            throw new MailException('Invalid local name for EHLO');
        }
        $this->localName = $name;

        return $this;
    }

    public function send(MimeMessage $message): void
    {
        $recipients = $message->recipients();
        if ($recipients === []) {
            throw new MailException('The message has no recipients');
        }
        // Se compone antes de abrir la conexión: un mensaje inválido no debe gastar una sesión SMTP.
        $sender = $message->sender();
        $data = $message->toString();

        $conn = $this->connection !== null ? $this->connection : new StreamSmtpConnection();
        $this->active = $conn;
        $opened = false;
        try {
            $conn->open($this->host, $this->port, $this->encryption === 'tls', $this->timeout);
            $opened = true;
            $this->expect($this->readReply(), [220], 'greeting');

            $this->hello();
            if ($this->encryption === 'starttls') {
                if (!$this->supportsStartTls) {
                    throw new MailException('The server does not offer STARTTLS; refusing to continue without encryption');
                }
                $this->command('STARTTLS', [220], 'STARTTLS');
                $conn->startTls();
                $this->hello();
            }
            $this->authenticate();

            $this->command('MAIL FROM:<' . $sender . '>', [250], 'MAIL FROM');
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251], 'RCPT TO');
            }
            $this->command('DATA', [354], 'DATA');
            $conn->write($this->dotStuff($data));
            $this->expect($this->readReply(), [250], 'message body');
        } finally {
            if ($opened) {
                $this->quitQuietly();
            }
            try {
                $conn->close();
            } catch (\Throwable $e) {
                // Cerrar nunca debe tapar el error que ya viene en camino.
            }
            $this->active = null;
        }
    }

    // ---------------------------------------------------------------- diálogo

    private function hello(): void
    {
        $this->supportsStartTls = false;
        $this->authMethods = [];
        $name = $this->heloName();

        $this->conn()->write('EHLO ' . $name . "\r\n");
        $reply = $this->readReply();
        if ($reply['code'] >= 500) {
            // Servidor antiguo que no entiende EHLO: se recurre a HELO, sin extensiones.
            $this->conn()->write('HELO ' . $name . "\r\n");
            $this->expect($this->readReply(), [250], 'HELO');

            return;
        }
        $this->expect($reply, [250], 'EHLO');

        foreach (array_slice($reply['lines'], 1) as $line) {
            $cap = strtoupper(trim($line));
            if ($cap === 'STARTTLS') {
                $this->supportsStartTls = true;
            } elseif (preg_match('/^AUTH[ =](.+)$/', $cap, $m) === 1) {
                $methods = preg_split('/\s+/', trim($m[1]));
                $this->authMethods = array_merge($this->authMethods, is_array($methods) ? $methods : []);
            }
        }
    }

    private function authenticate(): void
    {
        if ($this->user === '') {
            return;
        }
        if (in_array('LOGIN', $this->authMethods, true)) {
            $this->command('AUTH LOGIN', [334], 'AUTH LOGIN');
            $this->command(base64_encode($this->user), [334], 'AUTH LOGIN (user name)');
            $this->command(base64_encode($this->password), [235], 'AUTH LOGIN (password)');

            return;
        }
        if (in_array('PLAIN', $this->authMethods, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->password), [235], 'AUTH PLAIN');

            return;
        }

        throw new MailException('The server does not offer AUTH LOGIN or AUTH PLAIN');
    }

    /**
     * Escribe una orden y comprueba el código de la respuesta. $label es lo que se cita en el error:
     * nunca la orden, porque en AUTH es la contraseña.
     *
     * @param int[] $expected
     *
     * @return array{code:int,lines:string[]}
     */
    private function command(string $line, array $expected, string $label): array
    {
        $this->conn()->write($line . "\r\n");

        return $this->expect($this->readReply(), $expected, $label);
    }

    /**
     * @param array{code:int,lines:string[]} $reply
     * @param int[]                          $expected
     *
     * @return array{code:int,lines:string[]}
     */
    private function expect(array $reply, array $expected, string $label): array
    {
        if (in_array($reply['code'], $expected, true)) {
            return $reply;
        }
        $text = trim(implode(' ', $reply['lines']));

        throw new MailException('SMTP error ' . $reply['code'] . ' at ' . $label . ($text !== '' ? ': ' . $this->clean($text) : ''));
    }

    /** @return array{code:int,lines:string[]} */
    private function readReply(): array
    {
        $lines = [];
        for ($i = 0; $i < self::MAX_REPLY_LINES; ++$i) {
            $line = rtrim($this->conn()->readLine(), "\r\n");
            if (preg_match('/^(\d{3})(?:([ -])(.*))?$/s', $line, $m) !== 1) {
                throw new MailException('Malformed SMTP reply: ' . $this->clean($line));
            }
            $lines[] = isset($m[3]) ? $m[3] : '';
            if (!isset($m[2]) || $m[2] === ' ') {
                return ['code' => (int) $m[1], 'lines' => $lines];
            }
        }

        throw new MailException('The SMTP reply is too long');
    }

    /** Se despide sin esperar nada y sin dejar escapar ningún fallo. */
    private function quitQuietly(): void
    {
        try {
            $this->conn()->write("QUIT\r\n");
            $this->conn()->readLine();
        } catch (\Throwable $e) {
            // Ya da igual: la conexión se cierra a continuación.
        }
    }

    /** Relleno de puntos (RFC 5321 §4.5.2) y terminador. Todo salto de línea se normaliza a CRLF. */
    private function dotStuff(string $data): string
    {
        $n = preg_replace('/\r\n|\r|\n/', "\r\n", $data);
        $data = $n === null ? $data : $n;
        $s = preg_replace('/^\./m', '..', $data);
        $data = $s === null ? $data : $s;
        if (substr($data, -2) !== "\r\n") {
            $data .= "\r\n";
        }

        return $data . ".\r\n";
    }

    /** Texto del servidor listo para un mensaje de error: sin la contraseña, aunque la eche de vuelta, y acotado. */
    private function clean(string $text): string
    {
        if ($this->password !== '') {
            $plain = "\0" . $this->user . "\0" . $this->password;
            $text = str_replace(
                [base64_encode($plain), base64_encode($this->password), $this->password],
                '***',
                $text
            );
        }
        if (strlen($text) > 300) {
            $text = substr($text, 0, 300) . '…';
        }

        return $text;
    }

    private function conn(): SmtpConnection
    {
        if ($this->active === null) {
            throw new MailException('There is no open SMTP connection');
        }

        return $this->active;
    }

    private function heloName(): string
    {
        if ($this->localName !== '') {
            return $this->localName;
        }
        $host = gethostname();
        if (is_string($host) && $this->validHeloName($host)) {
            return $host;
        }

        return '[127.0.0.1]';
    }

    private function validHeloName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $name) === 1
            || preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $name) === 1;
    }
}
