<?php
/**
 * AIFEC — lo que tiene que saber hacer una forma de pago. Cada módulo de pago registra la suya con
 * $app->service('payments')->register(new MiForma($app)).
 *
 * El dinero no se da por cobrado aquí: el pedido se crea «pendiente de pago», start() dice a la caja qué hacer, y
 * cuando la pasarela confirma (su aviso de servidor a servidor, verificado) el módulo llama a
 * $app->service('orders')->markPaid(…), que solo cuenta la primera vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Commerce;

interface PaymentMethod
{
    /** Código estable en minúsculas: «redsys», «paypal», «transfer». Es el que se guarda en el pedido. */
    public function code(): string;

    /**
     * Textos para la caja, ya traducidos al idioma de la petición.
     *
     * @return array{title:string,description:string,icon:string}
     */
    public function info(): array;

    /**
     * ¿Se ofrece para esta compra?
     *
     * @param array<string,mixed> $context total (céntimos), currency, country, needs_shipping (bool), customer_id, lang
     */
    public function available(array $context): bool;

    /**
     * Recargo de la forma de pago «tal como se escriben los precios» (0 = ninguno). Se enseña como línea aparte.
     *
     * @param array<string,mixed> $context
     */
    public function fee(array $context): int;

    /** ¿Está en modo de pruebas? Un cobro de pruebas se marca como tal en el pedido. */
    public function isTest(): bool;

    /**
     * Empieza el cobro de un pedido ya guardado como pendiente.
     *
     * @param array<string,mixed> $order el pedido completo (id, number, total, currency, email, billing…)
     */
    public function start(array $order): PaymentAction;

    public function supportsRefund(): bool;

    /**
     * Devuelve dinero por la pasarela y da la referencia de la devolución.
     *
     * @param array<string,mixed> $order
     *
     * @throws \RuntimeException si la pasarela la rechaza o la forma de pago no devuelve por este cauce
     */
    public function refund(array $order, int $amount, string $reason): string;
}
