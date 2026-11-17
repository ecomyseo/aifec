<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Registro: nombre, correo, contraseña y las casillas de privacidad y publicidad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var array<string,mixed> $form  first_name, last_name, email, marketing, privacy
 * @var int $minPassword largo mínimo de la contraseña
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Create an account')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <form class="af-card" method="post" action="<?= e(route('account.register')) ?>">
        <div class="af-grid af-cols-2">
            <?= $this->partial('customers::front/field', ['id' => 'cu-reg-first', 'name' => 'first_name', 'label' => t('First name'), 'value' => $form['first_name'], 'autocomplete' => 'given-name', 'required' => true, 'max' => 80]) ?>
            <?= $this->partial('customers::front/field', ['id' => 'cu-reg-last', 'name' => 'last_name', 'label' => t('Last name'), 'value' => $form['last_name'], 'autocomplete' => 'family-name', 'max' => 120]) ?>
        </div>
        <?= $this->partial('customers::front/field', ['id' => 'cu-reg-email', 'name' => 'email', 'label' => t('Email'), 'type' => 'email', 'value' => $form['email'], 'autocomplete' => 'email', 'required' => true, 'max' => 191]) ?>
        <?= $this->partial('customers::front/field', ['id' => 'cu-reg-password', 'name' => 'password', 'label' => t('Password'), 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'min' => $minPassword, 'hint' => t('At least {n} characters.', ['n' => $minPassword])]) ?>
        <div class="af-field">
            <label class="af-check" for="cu-reg-privacy"><input id="cu-reg-privacy" type="checkbox" name="accept_privacy" value="1"<?= !empty($form['privacy']) ? ' checked' : '' ?> required><span><?= e(t('I have read and accept the privacy policy.')) ?></span></label>
            <label class="af-check" for="cu-reg-marketing"><input id="cu-reg-marketing" type="checkbox" name="marketing" value="1"<?= !empty($form['marketing']) ? ' checked' : '' ?>><span><?= e(t('I want to receive news and offers by email (optional).')) ?></span></label>
        </div>
        <div class="af-row">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Create an account')) ?></button>
            <a href="<?= e(route('account.login')) ?>"><?= e(t('I already have an account')) ?></a>
        </div>
    </form>
</div>
