<?php
/**
 * AIFEC — bloque de precio de gmartos: el actual en negrita, el anterior más pequeño, tachado y legible, el porcentaje y la
 * nota de impuestos. Con $live (la ficha) pinta siempre el anterior y el porcentaje, ocultos si no hay oferta, para que
 * theme.js pueda actualizarlos al cambiar de variante.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $price bloque de precio (amount, compare, discount_percent, with_tax, from, currency)
 * @var string              $size  sm, md o lg
 * @var bool                $live
 */

$gm_price = isset($price) && is_array($price) ? $price : [];
if (!empty($gm_price['hidden'])) {
    // Un plugin ha dicho que el precio de este producto no se enseña (disponibilidad con show_price = false).
    return;
}
$gm_size = isset($size) && in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
$gm_live = !empty($live);
$gm_amount = isset($gm_price['amount']) ? (int) $gm_price['amount'] : 0;
$gm_compare = isset($gm_price['compare']) ? (int) $gm_price['compare'] : 0;
$gm_currency = isset($gm_price['currency']) && is_string($gm_price['currency']) && $gm_price['currency'] !== '' ? $gm_price['currency'] : null;
$gm_sale = $gm_compare > $gm_amount && $gm_compare > 0;
$gm_percent = 0;
if ($gm_sale) {
    $gm_percent = isset($gm_price['discount_percent']) && (int) $gm_price['discount_percent'] > 0
        ? (int) $gm_price['discount_percent']
        : intdiv(($gm_compare - $gm_amount) * 100 + intdiv($gm_compare, 2), $gm_compare);
}
?>
<span class="af-price af-price-<?= e($gm_size) ?><?= $gm_sale ? ' is-sale' : '' ?>">
    <?php if (!empty($gm_price['from'])) : ?>
        <span class="af-price-note af-price-from"><?= e(t('From')) ?></span>
    <?php endif; ?>
    <?php if ($gm_sale || $gm_live) : ?>
        <s class="af-price-old"<?= $gm_live ? ' data-af-price-old' : '' ?><?= $gm_sale ? '' : ' hidden' ?>><?php if ($gm_sale) : ?><span class="af-sr"><?= e(t('Regular price')) ?> </span><?= e(money($gm_compare, $gm_currency)) ?><?php endif; ?></s>
    <?php endif; ?>
    <span class="af-price-now"<?= $gm_live ? ' data-af-price-now' : '' ?>><?= e(money($gm_amount, $gm_currency)) ?></span>
    <?php if ($gm_sale || $gm_live) : ?>
        <span class="af-badge af-badge-sale af-price-off"<?= $gm_live ? ' data-af-price-off' : '' ?><?= $gm_sale ? '' : ' hidden' ?>><?= $gm_sale ? e('−' . $gm_percent . "\u{a0}%") : '' ?></span>
    <?php endif; ?>
    <?php if ($gm_size !== 'sm' && array_key_exists('with_tax', $gm_price)) : ?>
        <span class="af-price-note"><?= e(!empty($gm_price['with_tax']) ? t('Tax included') : t('Tax excluded')) ?></span>
    <?php endif; ?>
</span>
