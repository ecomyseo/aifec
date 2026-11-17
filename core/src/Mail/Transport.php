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

/** Forma de sacar un mensaje del sistema: SMTP, la función mail() o una lista en memoria. */
interface Transport
{
    /**
     * Envía el mensaje a todos sus destinatarios (to, cc y bcc).
     *
     * @throws MailException si no se puede entregar
     */
    public function send(MimeMessage $message): void;
}
