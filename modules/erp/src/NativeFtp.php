<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

/**
 * FTP y FTPS con la extensión «ftp» de PHP. Si la extensión no está, available() lo dice y el destino no se ofrece.
 */
final class NativeFtp implements FtpTransport
{
    private const TIMEOUT = 15;

    public static function supported(bool $secure = false): bool
    {
        return function_exists('ftp_connect') && (!$secure || function_exists('ftp_ssl_connect'));
    }

    public function available(bool $secure): bool
    {
        return self::supported($secure);
    }

    public function put(array $connection, string $remotePath, string $contents): void
    {
        $ftp = $this->open($connection);
        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open a temporary stream');
            }
            fwrite($stream, $contents);
            rewind($stream);
            $ok = ftp_fput($ftp, $remotePath, $stream, FTP_BINARY);
            fclose($stream);
            if (!$ok) {
                throw new \RuntimeException('The file could not be uploaded');
            }
        } finally {
            ftp_close($ftp);
        }
    }

    public function get(array $connection, string $remotePath): string
    {
        $ftp = $this->open($connection);
        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open a temporary stream');
            }
            if (!ftp_fget($ftp, $stream, $remotePath, FTP_BINARY)) {
                fclose($stream);
                throw new \RuntimeException('The file could not be downloaded');
            }
            rewind($stream);
            $text = (string) stream_get_contents($stream);
            fclose($stream);

            return $text;
        } finally {
            ftp_close($ftp);
        }
    }

    public function names(array $connection, string $remoteDir): array
    {
        $ftp = $this->open($connection);
        try {
            $list = ftp_nlist($ftp, $remoteDir);
            if (!is_array($list)) {
                throw new \RuntimeException('The folder could not be listed');
            }

            return array_values(array_map('basename', $list));
        } finally {
            ftp_close($ftp);
        }
    }

    public function rename(array $connection, string $from, string $to): void
    {
        $ftp = $this->open($connection);
        try {
            if (!ftp_rename($ftp, $from, $to)) {
                throw new \RuntimeException('The file could not be moved');
            }
        } finally {
            ftp_close($ftp);
        }
    }

    /** La huella SHA-256 de un certificado (en DER) frente a la que se ha fijado: sin dos puntos, sin mayúsculas. */
    public static function fingerprintMatches(string $der, string $pin): bool
    {
        $pin = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $pin));

        return $pin !== '' && hash_equals(hash('sha256', $der), $pin);
    }

    /**
     * Comprueba el certificado de un servidor FTPS (AUTH TLS en el puerto de control): con una huella fijada, tiene que ser
     * ese certificado; sin ella, tiene que estar firmado por una autoridad conocida y ser del nombre del servidor.
     *
     * @throws \RuntimeException si no se puede comprobar o no es de fiar
     */
    public static function verifyTls(string $host, int $port, string $pin = ''): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $pin === '', 'verify_peer_name' => $pin === '', 'allow_self_signed' => false,
            'peer_name' => $host, 'capture_peer_cert' => true, 'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException('Could not connect to the FTP server');
        }
        try {
            stream_set_timeout($socket, self::TIMEOUT);
            self::readReply($socket);
            fwrite($socket, "AUTH TLS\r\n");
            if (strpos(self::readReply($socket), '234') !== 0) {
                throw new \RuntimeException('The FTP server does not offer TLS');
            }
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new \RuntimeException('The certificate of the FTP server is not valid');
            }
            if ($pin !== '') {
                $params = stream_context_get_params($socket);
                $cert = isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null;
                $der = '';
                if ($cert !== null && @openssl_x509_export($cert, $pem) && is_string($pem)) {
                    $der = (string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);
                }
                if ($der === '' || !self::fingerprintMatches($der, $pin)) {
                    throw new \RuntimeException('The certificate of the FTP server is not the one you pinned');
                }
            }
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function readReply($socket): string
    {
        $first = '';
        for ($i = 0; $i < 20; ++$i) {
            $line = fgets($socket, 512);
            if ($line === false) {
                break;
            }
            if ($first === '') {
                $first = $line;
            }
            if (preg_match('/^\d{3} /', $line) === 1) {
                return $first;
            }
        }

        return $first;
    }

    /**
     * @param array<string,mixed> $connection
     *
     * @return resource|\FTP\Connection
     */
    private function open(array $connection)
    {
        $secure = !empty($connection['secure']);
        if (!self::supported($secure)) {
            throw new \RuntimeException('The ftp extension of PHP is not installed');
        }
        $host = (string) ($connection['host'] ?? '');
        $port = (int) ($connection['port'] ?? 21);
        if ($secure) {
            // La extensión «ftp» no comprueba el certificado del servidor: se comprueba antes, con una conexión TLS aparte.
            self::verifyTls($host, $port, (string) ($connection['fingerprint'] ?? ''));
        }
        $ftp = $secure ? @ftp_ssl_connect($host, $port, self::TIMEOUT) : @ftp_connect($host, $port, self::TIMEOUT);
        if ($ftp === false) {
            throw new \RuntimeException('Could not connect to the FTP server');
        }
        if (!@ftp_login($ftp, (string) ($connection['user'] ?? ''), (string) ($connection['password'] ?? ''))) {
            ftp_close($ftp);
            throw new \RuntimeException('The FTP user or password was refused');
        }
        ftp_pasv($ftp, !isset($connection['passive']) || !empty($connection['passive']));

        return $ftp;
    }
}
