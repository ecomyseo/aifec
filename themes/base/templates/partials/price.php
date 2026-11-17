<?php
/**
 * AIFEC — bloque de precio: el actual, el anterior tachado y legible, el porcentaje y la nota de impuestos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $price bloque de precio (amount, compare, discount_percent, with_tax, from, currency)
 * @var string              $size  sm, md o lg
 * @var bool                $live  true en la ficha: pinta siempre el precio anterior y el porcentaje (ocultos si no hay oferta)
 *                                 para que theme.js pueda actualizarlos al cambiar de variante
 */

$af_price = isset($price) && is_array($price) ? $price : [];
if (!empty($af_price['hidden'])) {
    // Un plugin ha dicho que el precio de este producto no se enseña (disponibilidad con show_price = false).
    return;
}
$af_size = isset($size) && in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
$af_live = !empty($live);
$af_amount = isset($af_price['amount']) ? (int) $af_price['amount'] : 0;
$af_compare = isset($af_price['compare']) ? (int) $af_price['compare'] : 0;
$af_currency = isset($af_price['currency']) && is_string($af_price['currency']) && $af_price['currency'] !== '' ? $af_price['currency'] : null;
$af_sale = $af_compare > $af_amount && $af_compare > 0;
$af_percent = 0;
if ($af_sale) {
    $af_percent = isset($af_price['discount_percent']) && (int) $af_price['discount_percent'] > 0
        ? (int) $af_price['discount_percent']
        : intdiv(($af_compare - $af_amount) * 100 + intdiv($af_compare, 2), $af_compare);
}
?>
<span class="af-price af-price-<?= e($af_size) ?><?= $af_sale ? ' is-sale' : '' ?>">
    <?php if (!empty($af_price['from'])) : ?>
        <span class="af-price-note af-price-from"><?= e(t('From')) ?></span>
    <?php endif; ?>
    <span class="af-price-now"<?= $af_live ? ' data-af-price-now' : '' ?>><?= e(money($af_amount, $af_currency)) ?></span>
    <?php if ($af_sale || $af_live) : ?>
        <s class="af-price-old"<?= $af_live ? ' data-af-price-old' : '' ?><?= $af_sale ? '' : ' hidden' ?>><?php if ($af_sale) : ?><span class="af-sr"><?= e(t('Regular price')) ?> </span><?= e(money($af_compare, $af_currency)) ?><?php endif; ?></s>
        <span class="af-badge af-badge-sale af-price-off"<?= $af_live ? ' data-af-price-off' : '' ?><?= $af_sale ? '' : ' hidden' ?>><?= $af_sale ? e('−' . $af_percent . "\u{a0}%") : '' ?></span>
    <?php endif; ?>
    <?php if ($af_size !== 'sm' && array_key_exists('with_tax', $af_price)) : ?>
        <span class="af-price-note"><?= e(!empty($af_price['with_tax']) ? t('Tax included') : t('Tax excluded')) ?></span>
    <?php endif; ?>
</span>
