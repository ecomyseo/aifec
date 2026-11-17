<?php
/**
 * AIFEC — plantillas de correo de las devoluciones (cada módulo declara las suyas en su emails.php).
 *
 * Las del cliente salen con el idioma del pedido; la de la tienda, con el idioma principal del sitio. Las vistas están en
 * views/emails/.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Returns\ReturnMails;

return [
    'return_requested' => [
        'subject' => 'We have received your return request {number} - {shop}',
        'view' => 'returns::emails/return_requested',
        'system' => false,
        'description' => 'Sent to the customer when a return or withdrawal is requested',
        'sample' => ReturnMails::sample(),
    ],
    'return_requested_shop' => [
        'subject' => 'New return request {number} for order {order_number} - {shop}',
        'view' => 'returns::emails/return_requested_shop',
        'system' => false,
        'description' => 'Sent to the shop when a customer requests a return or withdrawal',
        'sample' => ReturnMails::sample(),
    ],
    'return_approved' => [
        'subject' => 'Return {number} accepted: how to send it back - {shop}',
        'view' => 'returns::emails/return_approved',
        'system' => false,
        'description' => 'Sent to the customer when the return is accepted, with the shipping instructions',
        'sample' => ReturnMails::sample(),
    ],
    'return_rejected' => [
        'subject' => 'Return {number} not accepted - {shop}',
        'view' => 'returns::emails/return_rejected',
        'system' => false,
        'description' => 'Sent to the customer when the return is rejected, with the reason',
        'sample' => ReturnMails::sample(),
    ],
    'return_received' => [
        'subject' => 'We have received your return {number} - {shop}',
        'view' => 'returns::emails/return_received',
        'system' => false,
        'description' => 'Sent to the customer when the returned products arrive',
        'sample' => ReturnMails::sample(),
    ],
    'return_refunded' => [
        'subject' => 'Return {number} completed: refund issued - {shop}',
        'view' => 'returns::emails/return_refunded',
        'system' => false,
        'description' => 'Sent to the customer when the money of the return is refunded',
        'sample' => ReturnMails::sample(),
    ],
    'return_cancelled' => [
        'subject' => 'Return {number} cancelled - {shop}',
        'view' => 'returns::emails/return_cancelled',
        'system' => false,
        'description' => 'Sent to the customer when the return is cancelled',
        'sample' => ReturnMails::sample(),
    ],
];
