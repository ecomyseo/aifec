<?php
/**
 * AIFEC — correo «order_cancelled»: el pedido se ha cancelado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string              $name      nombre del cliente
 * @var string              $number    número de pedido
 * @var string              $order_url enlace para ver el pedido ('' si no hay)
 * @var array<string,mixed> $order     el pedido (OrderMails::payload)
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Your order {number} has been cancelled. You have not been charged.', ['number' => $number])) ?></p>
<?= $this->partial('orders::emails/_order', ['order' => $order]) ?>
<?php if ($order_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($order_url) ?>" style="color:#2f5d8a;"><?= e(t('View your order')) ?></a></p>
<?php endif; ?>
