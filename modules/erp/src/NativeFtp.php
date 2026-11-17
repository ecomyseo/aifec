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
