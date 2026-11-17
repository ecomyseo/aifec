<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El desglose de importes del carrito, del resumen de la caja y de la página del pedido. Cada fila trae su texto ya
 * preparado; el total es el del cálculo, y lleva sus céntimos en data-total para que se pueda comparar con el del botón.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $v lo que devuelve Aifec\Modules\Orders\Front\TotalsView
 */

declare(strict_types=1);

?>
<dl class="af-totals">
    <div class="af-totals-row"><dt><?= e(t('Subtotal')) ?></dt><dd><?= e($v['subtotal_formatted']) ?></dd></div>
<?php foreach ($v['rows'] as $af_row) : ?>
    <div class="af-totals-row af-totals-<?= e($af_row['kind']) ?>"><dt><?= e($af_row['label']) ?></dt><dd><?= e($af_row['value']) ?></dd></div>
<?php endforeach; ?>
    <div class="af-totals-row af-totals-total"><dt><?= e(!empty($v['shipping_pending']) ? t('Total without shipping') : t('Total')) ?></dt><dd data-total="<?= e((int) $v['total']) ?>"><?= e($v['total_formatted']) ?></dd></div>
</dl>
<?php if ($v['note'] !== '') : ?>
<p class="af-small af-muted"><?= e($v['note']) ?></p>
<?php endif; ?>
