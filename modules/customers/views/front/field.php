<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Un campo de formulario de la zona de cliente: etiqueta, control y ayuda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string      $id           identificador del control (único en la página)
 * @var string      $name         nombre del campo
 * @var string      $label        etiqueta ya traducida
 * @var string      $type         text (por defecto), email, password, tel…
 * @var string      $value        valor actual (las contraseñas nunca llevan)
 * @var string      $autocomplete valor de autocomplete (por defecto «off»)
 * @var bool        $required     campo obligatorio
 * @var int         $max          largo máximo
 * @var int         $min          largo mínimo (contraseñas)
 * @var string      $hint         ayuda corta bajo el control
 */

declare(strict_types=1);

$af_type = isset($type) && is_string($type) && $type !== '' ? $type : 'text';
$af_value = $af_type === 'password' || !isset($value) ? '' : (string) $value;
$af_hint = isset($hint) ? (string) $hint : '';
?>
<div class="af-field">
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
    <input id="<?= e($id) ?>" class="af-input" type="<?= e($af_type) ?>" name="<?= e($name) ?>" value="<?= e($af_value) ?>" autocomplete="<?= e(isset($autocomplete) ? $autocomplete : 'off') ?>"<?= !empty($required) ? ' required' : '' ?><?= !empty($max) ? ' maxlength="' . e((int) $max) . '"' : '' ?><?= !empty($min) ? ' minlength="' . e((int) $min) . '"' : '' ?><?= $af_hint !== '' ? ' aria-describedby="' . e($id) . '-hint"' : '' ?>>
<?php if ($af_hint !== '') : ?>
    <span class="af-hint" id="<?= e($id) ?>-hint"><?= e($af_hint) ?></span>
<?php endif; ?>
</div>
