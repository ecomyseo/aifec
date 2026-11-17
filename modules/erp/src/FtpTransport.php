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
 * Lo que el módulo necesita de un servidor FTP o FTPS. El servicio «erp.ftp» (por defecto NativeFtp, con la extensión ftp de PHP)
 * se puede sustituir: las pruebas ponen uno de mentira.
 *
 * $connection: host, port, user, password, secure (true = FTPS), passive. Todo error lanza \RuntimeException con un mensaje en inglés.
 */
interface FtpTransport
{
    /**
     * ¿Se puede usar? (NativeFtp: solo si PHP tiene la extensión «ftp»; con FTPS, además la conexión segura.) Si no, el destino no se ofrece.
     */
    public function available(bool $secure): bool;

    /**
     * @param array<string,mixed> $connection
     */
    public function put(array $connection, string $remotePath, string $contents): void;

    /**
     * @param array<string,mixed> $connection
     */
    public function get(array $connection, string $remotePath): string;

    /**
     * Los nombres de los ficheros de una carpeta.
     *
     * @param array<string,mixed> $connection
     *
     * @return string[]
     */
    public function names(array $connection, string $remoteDir): array;

    /**
     * @param array<string,mixed> $connection
     */
    public function rename(array $connection, string $from, string $to): void;
}
