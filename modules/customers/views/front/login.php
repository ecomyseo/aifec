<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Entrar: correo y contraseña, enlace por correo y acceso al registro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var string $email      correo escrito en el intento anterior
 * @var string $next       dirección a la que volver tras entrar (ya comprobada)
 * @var bool   $magic      se puede pedir el enlace por correo
 * @var bool   $canRegister se puede crear una cuenta
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Sign in')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-grid af-cols-2">
        <section class="af-card" aria-labelledby="cu-login-title">
            <h2 id="cu-login-title"><?= e(t('I already have an account')) ?></h2>
            <form method="post" action="<?= e(route('account.login')) ?>">
<?php if ($next !== '') : ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
<?php endif; ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-login-email', 'name' => 'email', 'label' => t('Email'), 'type' => 'email', 'value' => $email, 'autocomplete' => 'username', 'required' => true, 'max' => 191]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-login-password', 'name' => 'password', 'label' => t('Password'), 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
                <div class="af-row">
                    <button type="submit" class="af-btn af-btn-primary"><?= e(t('Sign in')) ?></button>
                    <a href="<?= e(route('account.recover')) ?>"><?= e(t('Forgot your password?')) ?></a>
                </div>
            </form>
<?php if ($magic) : ?>
            <hr>
            <form method="post" action="<?= e(route('account.login.link')) ?>">
                <h3><?= e(t('Or get a link by email')) ?></h3>
                <p class="af-muted af-small"><?= e(t('We send you a link that signs you in once, without a password.')) ?></p>
<?php if ($next !== '') : ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
<?php endif; ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-link-email', 'name' => 'email', 'label' => t('Email'), 'type' => 'email', 'autocomplete' => 'email', 'required' => true, 'max' => 191]) ?>
                <button type="submit" class="af-btn"><?= e(t('Send me the link')) ?></button>
            </form>
<?php endif; ?>
        </section>
<?php if ($canRegister) : ?>
        <section class="af-card" aria-labelledby="cu-new-title">
            <h2 id="cu-new-title"><?= e(t('I am new here')) ?></h2>
            <p><?= e(t('Create an account to follow your orders, download what you buy and keep your addresses.')) ?></p>
            <a class="af-btn" href="<?= e(route('account.register')) ?>"><?= e(t('Create an account')) ?></a>
        </section>
<?php endif; ?>
    </div>
</div>
