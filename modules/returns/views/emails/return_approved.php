<?php
/**
 * AIFEC — correo «return_approved»: la devolución se ha aceptado, con las instrucciones de envío.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $name         nombre del cliente
 * @var string                         $number       número de la devolución
 * @var string                         $order_number número del pedido
 * @var string                         $return_url   enlace a la página de la devolución ('' si no hay)
 * @var string                         $instructions instrucciones de envío
 * @var string                         $payer        quién paga el envío de vuelta
 * @var array<int,array<string,mixed>> $lines        productos y unidades
 */

declare(strict_types=1);

// Lo que escribió la tienda; si no escribió nada, el texto modelo en el idioma de este correo.
$af_instructions = isset($instructions) && trim((string) $instructions) !== '' ? (string) $instructions
    : str_replace('{number}', $number, t('Pack the products in their original packaging and send them to us with the return number {number} written on the outside.'));
$af_payer = isset($payer) && trim((string) $payer) !== '' ? (string) $payer : t('The cost of sending the products back is paid by the customer.');

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Your return {number} for order {order_number} has been accepted.', ['number' => $number, 'order_number' => $order_number])) ?></p>
<?= $this->partial('returns::emails/_lines', ['lines' => $lines]) ?>
<p style="margin:0 0 8px 0;font-weight:bold;"><?= e(t('How to send the products back')) ?></p>
<p style="margin:0 0 8px 0;"><?= nl2br(e($af_instructions)) ?></p>
<p style="margin:0 0 16px 0;"><?= e($af_payer) ?></p>
<?php if ($return_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($return_url) ?>" style="color:#2f5d8a;"><?= e(t('See the state of your return')) ?></a></p>
<?php endif; ?>
