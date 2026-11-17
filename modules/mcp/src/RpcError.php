<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

/**
 * Un error del protocolo JSON-RPC (método que no existe, parámetros que no valen…), con su código.
 */
final class RpcError extends \RuntimeException
{
    /** @var int */
    private $rpcCode;

    public function __construct(int $rpcCode, string $message)
    {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }
}
