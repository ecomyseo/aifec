<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Un campo de texto de la caja: etiqueta, control, ayuda y el error de ese campo junto a él. Cada campo se comprueba al
 * salir de él (checkout.js manda lo escrito a la caja y pone aquí el aviso que devuelve el servidor).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $id           identificador del control (único en la página)
 * @var string $name         nombre del campo
 * @var string $label        etiqueta ya traducida
 * @var string $value        valor actual
 * @var string $error        error de este campo (vacío = ninguno)
 * @var string $type         text (por defecto), email, tel…
 * @var string $autocomplete valor de autocomplete (por defecto «off»)
 * @var string $inputmode    teclado que pide el móvil (email, tel, numeric…); vacío = el de siempre
 * @var bool   $required     el navegador exige el campo
 * @var int    $max          largo máximo
 * @var string $hint         ayuda corta bajo el control
 * @var bool   $update       al cambiar, la caja se vuelve a calcular (data-af-update)
 */

declare(strict_types=1);

$af_error = isset($error) ? (string) $error : '';
$af_hint = isset($hint) ? (string) $hint : '';
$af_describe = trim(($af_hint !== '' ? $id . '-hint ' : '') . ($af_error !== '' ? $id . '-error' : ''));
?>
<div class="af-field<?= $af_error !== '' ? ' has-error' : '' ?>">
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
    <input id="<?= e($id) ?>" class="af-input" type="<?= e(isset($type) && $type !== '' ? $type : 'text') ?>" name="<?= e($name) ?>" value="<?= e(isset($value) ? (string) $value : '') ?>" autocomplete="<?= e(isset($autocomplete) ? $autocomplete : 'off') ?>"<?= !empty($inputmode) ? ' inputmode="' . e($inputmode) . '"' : '' ?><?= !empty($required) ? ' required aria-required="true"' : '' ?><?= !empty($max) ? ' maxlength="' . e((int) $max) . '"' : '' ?><?= !empty($update) ? ' data-af-update' : '' ?><?= $af_error !== '' ? ' aria-invalid="true"' : '' ?><?= $af_describe !== '' ? ' aria-describedby="' . e($af_describe) . '"' : '' ?>>
<?php if ($af_hint !== '') : ?>
    <span class="af-hint" id="<?= e($id) ?>-hint"><?= e($af_hint) ?></span>
<?php endif; ?>
<?php if ($af_error !== '') : ?>
    <span class="af-error" id="<?= e($id) ?>-error" role="alert"><?= e($af_error) ?></span>
<?php endif; ?>
</div>
