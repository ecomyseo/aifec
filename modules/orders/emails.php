<?php
/**
 * AIFEC — plantillas de correo de los pedidos (cada módulo declara las suyas en su emails.php).
 *
 * Se mandan con el idioma del pedido. Las vistas están en views/emails/ y comparten la tabla de líneas y el desglose
 * (_order.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\OrderMails;

return [
    'order_confirmed' => [
        'subject' => 'Order {number} confirmed - {shop}',
        'view' => 'orders::emails/order_confirmed',
        'system' => false,
        'description' => 'Sent to the customer when the payment is received',
        'sample' => OrderMails::sample(),
    ],
    'order_on_hold' => [
        'subject' => 'Order {number} received: waiting for payment - {shop}',
        'view' => 'orders::emails/order_on_hold',
        'system' => false,
        'description' => 'Sent to the customer when the order waits for a transfer or another manual payment',
        'sample' => OrderMails::sample(),
    ],
    'order_shipped' => [
        'subject' => 'Your order {number} is on its way - {shop}',
        'view' => 'orders::emails/order_shipped',
        'system' => false,
        'description' => 'Sent to the customer when the order is shipped',
        'sample' => OrderMails::sample(),
    ],
    'order_completed' => [
        'subject' => 'Order {number} completed - {shop}',
        'view' => 'orders::emails/order_completed',
        'system' => false,
        'description' => 'Sent to the customer when the order is completed',
        'sample' => OrderMails::sample(),
    ],
    'order_cancelled' => [
        'subject' => 'Order {number} cancelled - {shop}',
        'view' => 'orders::emails/order_cancelled',
        'system' => false,
        'description' => 'Sent to the customer when the order is cancelled',
        'sample' => OrderMails::sample(),
    ],
    'order_refunded' => [
        'subject' => 'Refund for order {number} - {shop}',
        'view' => 'orders::emails/order_refunded',
        'system' => false,
        'description' => 'Sent to the customer when money is refunded',
        'sample' => OrderMails::sample(),
    ],
    'admin_new_order' => [
        'subject' => 'New order {number} - {shop}',
        'view' => 'orders::emails/admin_new_order',
        'system' => false,
        'description' => 'Sent to the shop when an order is paid',
        'sample' => OrderMails::sample(),
    ],
    // El código con el que la persona confirma un pedido que le prepara un asistente (BuyerCode). De sistema: es una
    // comprobación de seguridad que la propia persona está esperando, así que sale aunque el correo esté retenido.
    'buyer_code' => [
        'sensitive' => true,
        'subject' => '{code} is your code to confirm the order - {shop}',
        'view' => 'orders::emails/buyer_code',
        'system' => true,
        'description' => 'Verification code for an order prepared by an AI assistant',
        'farewell' => 'If you are not buying anything, ignore this message: nothing is ordered without this code.',
        'sample' => ['code' => '482916', 'total' => '94,90 €', 'minutes' => 15],
    ],
];
