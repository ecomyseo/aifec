<?php
/**
 * AIFEC — bloque del productor: el origen contado con un mapa dibujado, el nombre del lugar y unas líneas de historia.
 * Los textos son opciones del tema; sin ellas, un texto genérico traducido. Se puede apagar con «producer_show».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

if ((int) theme_option('producer_show', 1) !== 1) {
    return;
}
$ds_title = trim((string) theme_option('producer_title', ''));
$ds_title = $ds_title !== '' ? $ds_title : t('Meet the people behind it');
$ds_place = trim((string) theme_option('producer_place', ''));
$ds_text = trim((string) theme_option('producer_text', ''));
$ds_text = $ds_text !== '' ? $ds_text : t('Every product comes from a small producer we know by name. We choose them for the care they put into the land, the recipe and the harvest.');
?>
<section class="ds-producer af-section" aria-labelledby="ds-producer-title">
    <div class="ds-producer-copy">
        <p class="ds-kicker"><?= despensa_svg('pin', 16) ?><span><?= e(t('Origin')) ?><?= $ds_place !== '' ? ': ' . e($ds_place) : '' ?></span></p>
        <h2 class="ds-producer-title" id="ds-producer-title"><?= e($ds_title) ?></h2>
        <p class="ds-producer-text"><?= e($ds_text) ?></p>
    </div>
    <img class="ds-producer-map" src="<?= e(asset('img/map.svg')) ?>" alt="" width="480" height="360" loading="lazy" decoding="async">
</section>
