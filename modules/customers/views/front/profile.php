<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Mis datos: datos personales, cambio de correo y cambio de contraseña (tres formularios).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var array<string,mixed> $customer
 * @var string $token
 * @var string $active
 * @var array<string,mixed> $form valores del formulario de datos y del correo nuevo
 * @var int $minPassword largo mínimo de la contraseña
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('My details')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
            <form class="af-card" method="post" action="<?= e(route('account.profile')) ?>">
                <h2><?= e(t('Personal details')) ?></h2>
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="profile">
                <div class="af-grid af-cols-2">
                    <?= $this->partial('customers::front/field', ['id' => 'cu-pr-first', 'name' => 'first_name', 'label' => t('First name'), 'value' => $form['first_name'], 'autocomplete' => 'given-name', 'required' => true, 'max' => 80]) ?>
                    <?= $this->partial('customers::front/field', ['id' => 'cu-pr-last', 'name' => 'last_name', 'label' => t('Last name'), 'value' => $form['last_name'], 'autocomplete' => 'family-name', 'max' => 120]) ?>
                </div>
                <div class="af-grid af-cols-2">
                    <?= $this->partial('customers::front/field', ['id' => 'cu-pr-phone', 'name' => 'phone', 'label' => t('Phone'), 'type' => 'tel', 'value' => $form['phone'], 'autocomplete' => 'tel', 'max' => 40]) ?>
                    <?= $this->partial('customers::front/field', ['id' => 'cu-pr-company', 'name' => 'company', 'label' => t('Company'), 'value' => $form['company'], 'autocomplete' => 'organization', 'max' => 160]) ?>
                </div>
                <?= $this->partial('customers::front/field', ['id' => 'cu-pr-vat', 'name' => 'vat_id', 'label' => t('Tax ID'), 'value' => $form['vat_id'], 'max' => 40]) ?>
                <div class="af-field">
                    <label class="af-check" for="cu-pr-marketing"><input id="cu-pr-marketing" type="checkbox" name="marketing" value="1"<?= !empty($form['marketing']) ? ' checked' : '' ?>><span><?= e(t('I want to receive news and offers by email (optional).')) ?></span></label>
                </div>
                <button type="submit" class="af-btn af-btn-primary"><?= e(t('Save')) ?></button>
            </form>

            <form class="af-card" method="post" action="<?= e(route('account.profile')) ?>">
                <h2><?= e(t('Email address')) ?></h2>
                <p class="af-muted"><?= e(t('Your email is {email}. If you change it, you will have to confirm the new one.', ['email' => (string) $customer['email']])) ?></p>
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="email">
                <?= $this->partial('customers::front/field', ['id' => 'cu-em-new', 'name' => 'new_email', 'label' => t('New email'), 'type' => 'email', 'value' => $form['new_email'], 'autocomplete' => 'email', 'required' => true, 'max' => 191]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-em-password', 'name' => 'current_password', 'label' => t('Your current password'), 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
                <button type="submit" class="af-btn"><?= e(t('Change the email')) ?></button>
            </form>

            <form class="af-card" method="post" action="<?= e(route('account.profile')) ?>">
                <h2><?= e(t('Password')) ?></h2>
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="password">
                <?= $this->partial('customers::front/field', ['id' => 'cu-pw-current', 'name' => 'current_password', 'label' => t('Your current password'), 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-pw-new', 'name' => 'new_password', 'label' => t('New password'), 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'min' => $minPassword, 'hint' => t('At least {n} characters.', ['n' => $minPassword])]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-pw-confirm', 'name' => 'new_password_confirm', 'label' => t('Repeat the new password'), 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'min' => $minPassword]) ?>
                <button type="submit" class="af-btn"><?= e(t('Change the password')) ?></button>
            </form>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
