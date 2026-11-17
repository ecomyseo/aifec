<?php
/**
 * AIFEC — GM coming soon: la respuesta al «avísame» (gracias, o el formulario con sus errores).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $page
 * @var array<string,mixed>  $product
 * @var bool                 $done
 * @var string               $email
 * @var array<string,string> $errors
 * @var string               $title
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e($title) ?></h1>
<?php if ($done) : ?>
    <div class="af-alert af-alert-ok" role="status">
        <p><?= e(t('Thank you. If the address is correct, we will write to you once when {product} is available.', ['product' => (string) $product['name']])) ?></p>
    </div>
<?php else : ?>
    <form class="af-card af-stack" method="post" action="<?= e(route('gm.soon.notify')) ?>">
        <input type="hidden" name="product_id" value="<?= e((int) $product['id']) ?>">
        <p><?= e((string) $product['name']) ?></p>
        <div class="af-field<?= e(isset($errors['email']) ? ' has-error' : '') ?>">
            <label for="gm-soon-email"><?= e(t('Email')) ?></label>
            <input id="gm-soon-email" class="af-input" type="email" name="email" value="<?= e($email) ?>" maxlength="191" autocomplete="email" required>
<?php if (isset($errors['email'])) : ?>
            <span class="af-error" role="alert"><?= e($errors['email']) ?></span>
<?php endif; ?>
        </div>
        <div class="af-field<?= e(isset($errors['consent']) ? ' has-error' : '') ?>">
            <label class="af-check">
                <input type="checkbox" name="consent" value="1" required>
                <span><?= e(t('I agree that you use my email once, to tell me when this product is available.')) ?></span>
            </label>
<?php if (isset($errors['consent'])) : ?>
            <span class="af-error" role="alert"><?= e($errors['consent']) ?></span>
<?php endif; ?>
        </div>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Tell me')) ?></button>
    </form>
<?php endif; ?>
    <p><a href="<?= e((string) $product['url']) ?>"><?= e(t('Back to the product')) ?></a></p>
</div>
