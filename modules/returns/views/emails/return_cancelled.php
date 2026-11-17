<?php
/**
 * AIFEC — correo «return_cancelled»: la devolución se ha cancelado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name         nombre del cliente
 * @var string $number       número de la devolución
 * @var string $order_number número del pedido
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Your return {number} for order {order_number} has been cancelled.', ['number' => $number, 'order_number' => $order_number])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('If you did not ask for this, reply to this email and we will help you.')) ?></p>
