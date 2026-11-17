<?php
/**
 * AIFEC — correo «return_refunded»: la devolución está cerrada y el dinero devuelto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name         nombre del cliente
 * @var string $number       número de la devolución
 * @var string $order_number número del pedido
 * @var string $currency     moneda del pedido
 * @var int    $amount       lo devuelto, en céntimos
 * @var string $return_url   enlace a la página de la devolución ('' si no hay)
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Your return {number} for order {order_number} is complete. We have refunded {amount}.', ['number' => $number, 'order_number' => $order_number, 'amount' => money((int) $amount, $currency)])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('It can take a few days to show in your account.')) ?></p>
<?php if ($return_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($return_url) ?>" style="color:#2f5d8a;"><?= e(t('See the state of your return')) ?></a></p>
<?php endif; ?>
