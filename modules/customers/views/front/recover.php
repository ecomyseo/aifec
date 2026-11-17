<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Recuperar la contraseña: pide el correo y contesta siempre lo mismo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var bool $sent ya se ha pedido el enlace (se enseña el aviso y no el formulario)
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Recover your password')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
<?php if ($sent) : ?>
    <p><a class="af-btn" href="<?= e(route('account.login')) ?>"><?= e(t('Back to sign in')) ?></a></p>
<?php else : ?>
    <form class="af-card" method="post" action="<?= e(route('account.recover')) ?>">
        <p class="af-muted"><?= e(t('Write the email of your account and we will send you a link to choose a new password.')) ?></p>
        <?= $this->partial('customers::front/field', ['id' => 'cu-rec-email', 'name' => 'email', 'label' => t('Email'), 'type' => 'email', 'autocomplete' => 'email', 'required' => true, 'max' => 191]) ?>
        <div class="af-row">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send me the link')) ?></button>
            <a href="<?= e(route('account.login')) ?>"><?= e(t('Back to sign in')) ?></a>
        </div>
    </form>
<?php endif; ?>
</div>
