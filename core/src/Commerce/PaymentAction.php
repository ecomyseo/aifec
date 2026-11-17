<?php
/**
 * AIFEC — lo que la caja tiene que hacer después de crear el pedido, según la forma de pago.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Commerce;

final class PaymentAction
{
    public const REDIRECT = 'redirect';
    public const FORM = 'form';
    public const INSTRUCTIONS = 'instructions';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    /** @var string */
    public $type;

    /** @var string dirección a la que ir (redirect) o a la que enviar el formulario (form) */
    public $url = '';

    /** @var array<string,string> campos ocultos del formulario que se envía solo a la pasarela */
    public $fields = [];

    /** @var string instrucciones ya escapadas (transferencia: a qué cuenta y con qué concepto) */
    public $html = '';

    /** @var string referencia del cobro cuando ya está hecho (paid) */
    public $reference = '';

    /** @var string motivo, para enseñar al cliente (failed) */
    public $message = '';

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    /** Llevar al cliente a la pasarela con un enlace. */
    public static function redirect(string $url): self
    {
        $action = new self(self::REDIRECT);
        $action->url = $url;

        return $action;
    }

    /**
     * Enviar al cliente a la pasarela con un formulario POST que se manda solo.
     *
     * @param array<string,string> $fields
     */
    public static function form(string $url, array $fields): self
    {
        $action = new self(self::FORM);
        $action->url = $url;
        $action->fields = $fields;

        return $action;
    }

    /** El pedido queda pendiente y se le explica al cliente cómo pagar (transferencia, contra reembolso). */
    public static function instructions(string $html): self
    {
        $action = new self(self::INSTRUCTIONS);
        $action->html = $html;

        return $action;
    }

    /** Ya está cobrado (pago simulado, saldo). La caja llama a markPaid con esta referencia. */
    public static function paid(string $reference): self
    {
        $action = new self(self::PAID);
        $action->reference = $reference;

        return $action;
    }

    public static function failed(string $message): self
    {
        $action = new self(self::FAILED);
        $action->message = $message;

        return $action;
    }
}
