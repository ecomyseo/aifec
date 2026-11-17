<?php
/**
 * AIFEC — correo «return_received»: han llegado los productos devueltos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $name         nombre del cliente
 * @var string                         $number       número de la devolución
 * @var string                         $order_number número del pedido
 * @var string                         $return_url   enlace a la página de la devolución ('' si no hay)
 * @var array<int,array<string,mixed>> $lines        productos y unidades
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('We have received the products of your return {number} for order {order_number}.', ['number' => $number, 'order_number' => $order_number])) ?></p>
<?= $this->partial('returns::emails/_lines', ['lines' => $lines]) ?>
<p style="margin:0 0 16px 0;"><?= e(t('We are checking them and we will refund your money soon.')) ?></p>
<?php if ($return_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($return_url) ?>" style="color:#2f5d8a;"><?= e(t('See the state of your return')) ?></a></p>
<?php endif; ?>
