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

/** No envía nada: guarda los mensajes. Sirve para las pruebas y para el modo «retener correo». */
final class ArrayTransport implements Transport
{
    /** @var MimeMessage[] mensajes «enviados», en orden */
    public array $sent = [];

    public function send(MimeMessage $message): void
    {
        // Se compone una vez para que un mensaje inválido falle igual que fallaría con un transporte real,
        // y se guarda una copia: el llamador puede seguir modificando su objeto.
        $message->toString();
        $this->sent[] = clone $message;
    }
}
