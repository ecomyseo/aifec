<?php
/**
 * AIFEC — instalador, pantalla 3: la tienda y el acceso de quien la administra.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var callable $t
 * @var string $action
 * @var string $state
 * @var array<string,string> $input
 * @var array<string,string> $errors
 */

$this->layout('install::layout');
$field = static function (string $name, string $label, string $type, string $value, string $extra = '', string $hint = '') use ($errors): string {
    return '<div class="af-field' . (isset($errors[$name]) ? ' af-field-invalid' : '') . '">'
        . '<label class="af-label" for="af-' . e($name) . '">' . e($label) . '</label>'
        . '<div class="af-control"><input class="af-input" id="af-' . e($name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '>'
        . ($hint !== '' ? '<div class="af-hint">' . e($hint) . '</div>' : '')
        . (isset($errors[$name]) ? '<div class="af-error">' . e($errors[$name]) . '</div>' : '')
        . '</div></div>';
};
?>
<p class="af-muted"><?= e($t('Name your shop and choose how you will sign in to the panel.')) ?></p>

<form method="post" action="<?= e($action) ?>" novalidate>
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="state" value="<?= e($state) ?>">

    <?= $field('shop_name', $t('Shop name'), 'text', $input['shop_name'], ' autofocus autocomplete="organization"') ?>
    <?= $field('shop_email', $t('Contact email'), 'email', $input['shop_email'], ' autocomplete="email"') ?>

    <h2 class="af-install-section"><?= e($t('Your access')) ?></h2>
    <?= $field('admin_name', $t('Your name'), 'text', $input['admin_name'], ' autocomplete="name"') ?>
    <?= $field('admin_email', $t('Your email'), 'email', $input['admin_email'], ' autocomplete="username"') ?>
    <?= $field('admin_password', $t('Password'), 'password', '', ' autocomplete="new-password" minlength="10"', $t('Use at least {n} characters', ['n' => 10])) ?>
    <?= $field('admin_password2', $t('Repeat it'), 'password', '', ' autocomplete="new-password"') ?>

    <div class="af-install-actions">
        <button type="submit" class="af-btn af-btn-primary" name="go" value="next"><?= e($t('Install')) ?></button>
        <button type="submit" class="af-btn" name="go" value="back" formnovalidate><?= e($t('Back')) ?></button>
    </div>
</form>
