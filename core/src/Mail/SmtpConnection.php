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
 * Canal de bajo nivel con un servidor SMTP. Existe para poder sustituirlo por un guion en las pruebas.
 * Todos los métodos lanzan MailException si algo falla.
 */
interface SmtpConnection
{
    /** Abre la conexión; con $implicitTls ya cifrada desde el primer byte (puerto 465). */
    public function open(string $host, int $port, bool $implicitTls, float $timeout): void;

    /** Escribe los bytes tal cual (el llamador añade los CRLF). */
    public function write(string $data): void;

    /** Lee UNA línea del servidor, sin el CRLF final. */
    public function readLine(): string;

    /** Cifra la conexión ya abierta (tras la orden STARTTLS), comprobando el certificado. */
    public function startTls(): void;

    /** Cierra la conexión. Se puede llamar varias veces. */
    public function close(): void;
}
