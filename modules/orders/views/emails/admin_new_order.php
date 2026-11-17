<?php
/**
 * AIFEC — correo «admin_new_order»: aviso a la tienda de un pedido pagado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string              $name   nombre del cliente
 * @var string              $number número de pedido
 * @var array<string,mixed> $order  el pedido (OrderMails::payload)
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('New paid order {number} from {name} ({email}) for {total}.', ['number' => $number, 'name' => $name, 'email' => $order['email'], 'total' => money((int) $order['total'], (string) $order['currency'])])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Payment: {name}', ['name' => $order['payment_title']])) ?></p>
<?php if ($order['customer_note'] !== '') : ?>
<p style="margin:0 0 16px 0;"><strong><?= e(t('Note from the customer')) ?></strong><br><?= nl2br(e($order['customer_note'])) ?></p>
<?php endif; ?>
<?= $this->partial('orders::emails/_order', ['order' => $order]) ?>
<?= $this->partial('orders::emails/_addresses', ['order' => $order]) ?>
