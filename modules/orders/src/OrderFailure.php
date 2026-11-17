<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

/**
 * Un motivo por el que no se puede crear el pedido. Se lanza dentro de la transacción de place() para deshacerlo todo
 * (existencias, contador, cliente, cupones) y se recoge fuera para devolver el error al que llamó. Nunca sale de este módulo.
 */
final class OrderFailure extends \RuntimeException
{
    /** @var array<string,string> campo => mensaje */
    private $errors;

    /**
     * @param array<string,string> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
