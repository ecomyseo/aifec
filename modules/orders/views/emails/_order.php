<?php
/**
 * AIFEC — trozo común de los correos de pedido: tabla de líneas y desglose (subtotal, descuentos, envío, impuestos por tipo, total).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $order el pedido tal como lo prepara OrderMails::payload()
 */

declare(strict_types=1);

$currency = (string) $order['currency'];
$cell = 'padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top;';
$sum = 'padding:4px 0;font-size:14px;';

?><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">
<tr>
<th align="left" style="<?= e($cell) ?>font-weight:bold;"><?= e(t('Product')) ?></th>
<th align="center" style="<?= e($cell) ?>font-weight:bold;"><?= e(t('Qty')) ?></th>
<th align="right" style="<?= e($cell) ?>font-weight:bold;"><?= e(t('Total')) ?></th>
</tr>
<?php foreach ($order['lines'] as $line) : ?>
<tr>
<td style="<?= e($cell) ?>"><?= e($line['name']) ?><?php if ($line['variant_label'] !== '') : ?><br><span style="color:#6b7280;font-size:13px;"><?= e($line['variant_label']) ?></span><?php endif; ?></td>
<td align="center" style="<?= e($cell) ?>"><?= (int) $line['qty'] ?></td>
<td align="right" style="<?= e($cell) ?>"><?= e(money((int) $line['amount'], $currency)) ?></td>
</tr>
<?php endforeach; ?>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">
<tr><td style="<?= e($sum) ?>"><?= e(t('Subtotal')) ?></td><td align="right" style="<?= e($sum) ?>"><?= e(money((int) $order['items_total'], $currency)) ?></td></tr>
<?php foreach ($order['discounts'] as $discount) : ?>
<tr><td style="<?= e($sum) ?>"><?= e(t('Discount: {name}', ['name' => $discount['name']])) ?></td><td align="right" style="<?= e($sum) ?>">-<?= e(money((int) $discount['amount'], $currency)) ?></td></tr>
<?php endforeach; ?>
<?php foreach ($order['extras'] as $extra) : ?>
<tr><td style="<?= e($sum) ?>"><?= e($extra['kind'] === 'shipping' ? t('Shipping: {name}', ['name' => $extra['name']]) : $extra['name']) ?></td><td align="right" style="<?= e($sum) ?>"><?= e(money((int) $extra['amount'], $currency)) ?></td></tr>
<?php endforeach; ?>
<?php foreach ($order['taxes'] as $tax) : ?>
<?php if ((int) $tax['amount'] > 0) : ?>
<tr><td style="<?= e($sum) ?>color:#6b7280;"><?= e(!empty($order['prices_include_tax']) ? t('Includes {name}', ['name' => $tax['name']]) : (string) $tax['name']) ?></td><td align="right" style="<?= e($sum) ?>color:#6b7280;"><?= e(money((int) $tax['amount'], $currency)) ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
<tr><td style="padding:10px 0 0 0;border-top:2px solid #1f2933;font-size:16px;font-weight:bold;"><?= e(t('Total')) ?></td><td align="right" style="padding:10px 0 0 0;border-top:2px solid #1f2933;font-size:16px;font-weight:bold;"><?= e(money((int) $order['total'], $currency)) ?></td></tr>
</table>
<?php foreach (isset($order['blocks']) && is_array($order['blocks']) ? $order['blocks'] : [] as $af_block) : ?>
<?php if ($af_block['title'] !== '') : ?>
<p style="margin:0 0 6px 0;font-weight:bold;"><?= e($af_block['title']) ?></p>
<?php endif; ?>
<?php foreach ($af_block['lines'] as $af_line) : ?>
<p style="margin:0 0 8px 0;"><?= e($af_line) ?></p>
<?php endforeach; ?>
<?php endforeach; ?>
