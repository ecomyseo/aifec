<?php
/**
 * AIFEC — correo «order_on_hold»: pedido recibido que espera el pago, con las instrucciones de la forma de pago.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string              $name         nombre del cliente
 * @var string              $number       número de pedido
 * @var string              $order_url    enlace para ver el pedido ('' si no hay)
 * @var string              $instructions instrucciones de pago ya escapadas por la forma de pago (HTML)
 * @var array<string,mixed> $order        el pedido (OrderMails::payload)
 */

declare(strict_types=1);

$instructions = isset($instructions) && is_string($instructions) ? $instructions : '';

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('We have received your order {number}. We will process it as soon as we receive your payment.', ['number' => $number])) ?></p>
<?php if ($instructions !== '') : ?>
<p style="margin:0 0 8px 0;"><strong><?= e(t('How to pay')) ?></strong></p>
<div style="margin:0 0 20px 0;"><?= $instructions ?></div>
<?php endif; ?>
<?= $this->partial('orders::emails/_order', ['order' => $order]) ?>
<?php if ($order_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($order_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('View your order')) ?></a></p>
<?php endif; ?>
