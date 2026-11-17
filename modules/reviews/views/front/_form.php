<?php
/**
 * AIFEC — formulario de una opinión: nota (cinco estrellas que se pulsan), nombre, correo, título y texto. Lo usan la ficha del producto y la página del enlace
 * del correo (ahí el correo no se pide: sale de la petición). Lleva un campo trampa que una persona no ve.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string              $action dirección a la que se envía
 * @var array<string,string> $values lo que había escrito (vacío la primera vez)
 * @var array<string,string> $errors el error de cada campo
 * @var bool                $locked el correo no se pide (enlace del correo)
 */

declare(strict_types=1);

$af_val = static function (string $key) use ($values): string {
    return isset($values[$key]) && is_scalar($values[$key]) ? (string) $values[$key] : '';
};
$af_err = static function (string $key) use ($errors): string {
    return isset($errors[$key]) ? (string) $errors[$key] : '';
};
$af_rating = (int) $af_val('rating');
?>
<form class="af-rv-form" id="af-rv-form" method="post" action="<?= e($action) ?>" data-af-review-form>
    <div hidden aria-hidden="true">
        <label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
    </div>
    <fieldset class="af-rv-rate">
        <legend><?= e(t('Your rating')) ?></legend>
        <div class="af-rv-stars">
<?php for ($af_n = 1; $af_n <= 5; ++$af_n) : ?>
            <span class="af-rv-star"><input type="radio" id="af-rv-star-<?= (int) $af_n ?>" name="rating" value="<?= (int) $af_n ?>" aria-label="<?= e(tn('{n} star', '{n} stars', $af_n)) ?>"<?= $af_rating === $af_n ? ' checked' : '' ?> required><label for="af-rv-star-<?= (int) $af_n ?>" title="<?= e(tn('{n} star', '{n} stars', $af_n)) ?>"><span aria-hidden="true">&#9733;</span></label></span>
<?php endfor; ?>
        </div>
<?php if ($af_err('rating') !== '') : ?>
        <p class="af-error" role="alert"><?= e($af_err('rating')) ?></p>
<?php endif; ?>
    </fieldset>
    <div class="af-rv-pair">
        <div class="af-field">
            <label for="af-rv-name"><?= e(t('Your name')) ?></label>
            <input class="af-input" id="af-rv-name" type="text" name="name" value="<?= e($af_val('name')) ?>" maxlength="60" required autocomplete="given-name">
<?php if ($af_err('name') !== '') : ?>
            <p class="af-error" role="alert"><?= e($af_err('name')) ?></p>
<?php endif; ?>
        </div>
<?php if (empty($locked)) : ?>
        <div class="af-field">
            <label for="af-rv-email"><?= e(t('Your email')) ?></label>
            <input class="af-input" id="af-rv-email" type="email" name="email" value="<?= e($af_val('email')) ?>" maxlength="191" required autocomplete="email" aria-describedby="af-rv-email-hint">
            <p class="af-hint" id="af-rv-email-hint"><?= e(t('It is not shown. We use it to check that you bought the product.')) ?></p>
<?php if ($af_err('email') !== '') : ?>
            <p class="af-error" role="alert"><?= e($af_err('email')) ?></p>
<?php endif; ?>
        </div>
<?php endif; ?>
    </div>
    <div class="af-field">
        <label for="af-rv-title"><?= e(t('Title (optional)')) ?></label>
        <input class="af-input" id="af-rv-title" type="text" name="title" value="<?= e($af_val('title')) ?>" maxlength="100">
<?php if ($af_err('title') !== '') : ?>
        <p class="af-error" role="alert"><?= e($af_err('title')) ?></p>
<?php endif; ?>
    </div>
    <div class="af-field">
        <label for="af-rv-body"><?= e(t('Your review')) ?></label>
        <textarea class="af-textarea" id="af-rv-body" name="body" rows="3" minlength="10" maxlength="2000" required><?= e($af_val('body')) ?></textarea>
<?php if ($af_err('body') !== '') : ?>
        <p class="af-error" role="alert"><?= e($af_err('body')) ?></p>
<?php endif; ?>
    </div>
    <p><button class="af-btn af-btn-primary" type="submit"><?= e(t('Send my review')) ?></button></p>
</form>
