<?php
/**
 * AIFEC — bloque de precio de taller: el actual en grande, el anterior tachado y legible, la insignia cuadrada del
 * porcentaje y, en la ficha («both»), el importe con IVA y sin IVA a la vez en dos líneas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $price bloque de precio (amount, compare, discount_percent, with_tax, net, gross, tax_rate, from, currency)
 * @var string              $size  sm, md o lg
 * @var bool                $live  true en la ficha: pinta siempre el precio anterior y el porcentaje (ocultos si no hay oferta)
 *                                 para que theme.js pueda actualizarlos al cambiar de variante
 * @var bool                $both  true en la ficha: añade las dos líneas «con IVA» y «sin IVA»
 */

$tl_price = isset($price) && is_array($price) ? $price : [];
if (!empty($tl_price['hidden'])) {
    // Un plugin ha dicho que el precio de este producto no se enseña (disponibilidad con show_price = false).
    return;
}
$tl_size = isset($size) && in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
$tl_live = !empty($live);
$tl_amount = isset($tl_price['amount']) ? (int) $tl_price['amount'] : 0;
$tl_compare = isset($tl_price['compare']) ? (int) $tl_price['compare'] : 0;
$tl_currency = isset($tl_price['currency']) && is_string($tl_price['currency']) && $tl_price['currency'] !== '' ? $tl_price['currency'] : null;
$tl_sale = $tl_compare > $tl_amount && $tl_compare > 0;
$tl_percent = 0;
if ($tl_sale) {
    $tl_percent = isset($tl_price['discount_percent']) && (int) $tl_price['discount_percent'] > 0
        ? (int) $tl_price['discount_percent']
        : intdiv(($tl_compare - $tl_amount) * 100 + intdiv($tl_compare, 2), $tl_compare);
}
$tl_both = !empty($both) && isset($tl_price['net'], $tl_price['gross']) && is_numeric($tl_price['net']) && is_numeric($tl_price['gross']);
?>
<span class="tl-price af-price af-price-<?= e($tl_size) ?><?= $tl_sale ? ' is-sale' : '' ?>">
    <?php if (!empty($tl_price['from'])) : ?>
        <span class="af-price-note af-price-from"><?= e(t('From')) ?></span>
    <?php endif; ?>
    <span class="af-price-now"<?= $tl_live ? ' data-af-price-now' : '' ?>><?= e(money($tl_amount, $tl_currency)) ?></span>
    <?php if ($tl_sale || $tl_live) : ?>
        <s class="af-price-old"<?= $tl_live ? ' data-af-price-old' : '' ?><?= $tl_sale ? '' : ' hidden' ?>><?php if ($tl_sale) : ?><span class="af-sr"><?= e(t('Regular price')) ?> </span><?= e(money($tl_compare, $tl_currency)) ?><?php endif; ?></s>
        <span class="af-badge af-badge-sale af-price-off"<?= $tl_live ? ' data-af-price-off' : '' ?><?= $tl_sale ? '' : ' hidden' ?>><?= $tl_sale ? e('−' . $tl_percent . "\u{a0}%") : '' ?></span>
    <?php endif; ?>
    <?php if ($tl_both) : ?>
        <span class="tl-tax" data-tl-tax data-tl-rate="<?= e(isset($tl_price['tax_rate']) ? (int) $tl_price['tax_rate'] : 0) ?>" data-tl-with-tax="<?= e(!empty($tl_price['with_tax']) ? 1 : 0) ?>">
            <span class="tl-tax-row"><span class="tl-tax-k"><?= e(t('Tax included')) ?></span> <strong data-tl-gross><?= e(money((int) $tl_price['gross'], $tl_currency)) ?></strong></span>
            <span class="tl-tax-row"><span class="tl-tax-k"><?= e(t('Tax excluded')) ?></span> <strong data-tl-net><?= e(money((int) $tl_price['net'], $tl_currency)) ?></strong></span>
        </span>
    <?php elseif ($tl_size !== 'sm' && array_key_exists('with_tax', $tl_price)) : ?>
        <span class="af-price-note"><?= e(!empty($tl_price['with_tax']) ? t('Tax included') : t('Tax excluded')) ?></span>
    <?php endif; ?>
</span>
