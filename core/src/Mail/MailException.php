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

/** Cualquier fallo al componer o enviar un correo: dato inválido, red, o respuesta inesperada del servidor. */
final class MailException extends \RuntimeException
{
}
