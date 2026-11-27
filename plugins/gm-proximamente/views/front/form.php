<?php
/**
 * AIFEC — GM coming soon: el formulario «avísame» de la ficha. Va en una página que se guarda en caché: no lleva nada del visitante.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int    $product_id
 * @var string $intro
 */

declare(strict_types=1);

?>
<form id="gm-soon-form-<?= (int) $product_id ?>" class="af-card af-stack gm-soon-form" method="post" action="<?= e(route('gm.soon.notify')) ?>">
    <input type="hidden" name="product_id" value="<?= e($product_id) ?>">
    <p><strong><?= e(t('Tell me when it is available')) ?></strong></p>
    <p class="af-small af-muted"><?= e($intro) ?></p>
    <div class="af-field">
        <label for="gm-soon-email-<?= e($product_id) ?>"><?= e(t('Email')) ?></label>
        <input id="gm-soon-email-<?= e($product_id) ?>" class="af-input" type="email" name="email" maxlength="191" autocomplete="email" required>
    </div>
    <div class="af-sr" aria-hidden="true">
        <label for="gm-soon-web-<?= e($product_id) ?>"><?= e(t('Leave this field empty')) ?></label>
        <input id="gm-soon-web-<?= e($product_id) ?>" type="text" name="website" value="" tabindex="-1" autocomplete="off">
    </div>
    <label class="af-check">
        <input type="checkbox" name="consent" value="1" required>
        <span><?= e(t('I agree that you use my email once, to tell me when this product is available.')) ?></span>
    </label>
    <button type="submit" class="af-btn"><?= e(t('Tell me')) ?></button>
</form>
