<?php
/**
 * AIFEC — trozo común de los correos de devoluciones: la tabla con los productos y las unidades que se devuelven.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $lines cada una: name, variant_label, qty
 */

declare(strict_types=1);

$cell = 'padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top;';

?><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">
<tr>
<th align="left" style="<?= e($cell) ?>font-weight:bold;"><?= e(t('Product')) ?></th>
<th align="center" style="<?= e($cell) ?>font-weight:bold;"><?= e(t('Qty')) ?></th>
</tr>
<?php foreach ($lines as $line) : ?>
<tr>
<td style="<?= e($cell) ?>"><?= e($line['name']) ?><?php if ($line['variant_label'] !== '') : ?><br><span style="color:#6b7280;font-size:13px;"><?= e($line['variant_label']) ?></span><?php endif; ?></td>
<td align="center" style="<?= e($cell) ?>"><?= (int) $line['qty'] ?></td>
</tr>
<?php endforeach; ?>
</table>
