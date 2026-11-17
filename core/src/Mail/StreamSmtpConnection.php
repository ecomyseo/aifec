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

/** Conexión SMTP real con streams de PHP. Con TLS comprueba el certificado y el nombre del servidor. */
final class StreamSmtpConnection implements SmtpConnection
{
    /** Trozo máximo por escritura. */
    private const WRITE_CHUNK = 65536;

    /** Reintentos y pausa (en microsegundos) cuando el nombre del servidor no se ha podido resolver por un fallo pasajero. */
    private const DNS_RETRIES = 2;
    private const DNS_PAUSE_US = 1000000;

    /** Si el error de conexión es un fallo PASAJERO del DNS (el resolvedor no ha contestado), no un nombre que no existe. */
    public static function transientDns(string $error): bool
    {
        return stripos($error, 'Temporary failure in name resolution') !== false
            || (stripos($error, 'getaddrinfo') !== false && stripos($error, 'try again') !== false);
    }

    /** @var resource|null */
    private $stream = null;

    public function open(string $host, int $port, bool $implicitTls, float $timeout): void
    {
        $this->close();

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
                'disable_compression' => true,
            ],
        ]);
        $target = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host;
        $address = ($implicitTls ? 'tls://' : 'tcp://') . $target . ':' . $port;

        for ($try = 0; ; $try++) {
            $errno = 0;
            $errstr = '';
            self::muteErrors();
            try {
                $stream = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            } finally {
                self::unmuteErrors();
            }
            // Un fallo PASAJERO al resolver el nombre del servidor de correo se reintenta tras una pausa corta: el DNS del
            // servidor contesta a ratos que no sabe, y 3 de 26 códigos del comprador no salieron así (17/11/2026).
            if (is_resource($stream) || $try >= self::DNS_RETRIES || !self::transientDns($errstr)) {
                break;
            }
            usleep(self::DNS_PAUSE_US);
        }
        if (!is_resource($stream)) {
            throw new MailException('Could not connect to ' . $host . ':' . $port . ' (' . $errno . ') ' . $errstr);
        }

        $seconds = (int) floor($timeout);
        stream_set_timeout($stream, $seconds, (int) (($timeout - $seconds) * 1000000));
        $this->stream = $stream;
    }

    public function write(string $data): void
    {
        $stream = $this->requireStream();
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            self::muteErrors();
            try {
                $written = fwrite($stream, substr($data, $offset, self::WRITE_CHUNK));
            } finally {
                self::unmuteErrors();
            }
            if ($written === false || $written === 0) {
                throw new MailException('Could not write to the SMTP server');
            }
            $offset += $written;
        }
    }

    public function readLine(): string
    {
        $stream = $this->requireStream();
        self::muteErrors();
        try {
            $line = fgets($stream, 8192);
        } finally {
            self::unmuteErrors();
        }
        if ($line === false) {
            $meta = stream_get_meta_data($stream);

            throw new MailException(!empty($meta['timed_out'])
                ? 'Timed out waiting for the SMTP server'
                : 'The SMTP server closed the connection');
        }

        return rtrim($line, "\r\n");
    }

    public function startTls(): void
    {
        $stream = $this->requireStream();
        self::muteErrors();
        try {
            $ok = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            self::unmuteErrors();
        }
        if ($ok !== true) {
            throw new MailException('TLS negotiation with the SMTP server failed');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            self::muteErrors();
            try {
                fclose($this->stream);
            } finally {
                self::unmuteErrors();
            }
        }
        $this->stream = null;
    }

    /** @return resource */
    private function requireStream()
    {
        if (!is_resource($this->stream)) {
            throw new MailException('The SMTP connection is not open');
        }

        return $this->stream;
    }

    /** Los errores de red se convierten en MailException: los avisos de PHP sobre el socket no deben escapar. */
    private static function muteErrors(): void
    {
        set_error_handler(static function (): bool {
            return true;
        });
    }

    private static function unmuteErrors(): void
    {
        restore_error_handler();
    }
}
