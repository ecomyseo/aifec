<?php
/**
 * AIFEC — correo «return_requested_shop»: aviso a la tienda de una devolución nueva. Sin direcciones postales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $number       número de la devolución
 * @var string                         $order_number número del pedido
 * @var string                         $kind         return o withdrawal
 * @var string                         $reason       motivo (texto de serie en inglés o el del comerciante)
 * @var string                         $comment      comentario del cliente
 * @var string                         $panel_url    enlace a la ficha en el panel
 * @var array<int,array<string,mixed>> $lines        productos y unidades
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e($kind === 'withdrawal'
    ? t('A customer has requested a withdrawal for order {order_number}.', ['order_number' => $order_number])
    : t('A customer has requested a return for order {order_number}.', ['order_number' => $order_number])) ?></p>
<p style="margin:0 0 8px 0;"><?= e(t('Return number: {number}', ['number' => $number])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Reason: {reason}', ['reason' => t($reason)])) ?></p>
<?= $this->partial('returns::emails/_lines', ['lines' => $lines]) ?>
<?php if (isset($comment) && $comment !== '') : ?>
<p style="margin:0 0 16px 0;"><?= e(t('Comment from the customer: {comment}', ['comment' => $comment])) ?></p>
<?php endif; ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($panel_url) ?>" style="color:#2f5d8a;"><?= e(t('Open the return in the panel')) ?></a></p>
