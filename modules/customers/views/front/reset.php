<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Elegir la contraseña nueva con la ficha del correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var string $token_url dirección de esta misma página (donde se envía el formulario)
 * @var bool   $expired   la ficha no valía: se ofrece pedir otra
 * @var int    $minPassword largo mínimo de la contraseña
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Choose a new password')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
<?php if ($expired) : ?>
    <p><a class="af-btn af-btn-primary" href="<?= e(route('account.recover')) ?>"><?= e(t('Ask for a new link')) ?></a></p>
<?php else : ?>
    <form class="af-card" method="post" action="<?= e($token_url) ?>">
        <?= $this->partial('customers::front/field', ['id' => 'cu-reset-password', 'name' => 'password', 'label' => t('New password'), 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'min' => $minPassword, 'hint' => t('At least {n} characters.', ['n' => $minPassword])]) ?>
        <?= $this->partial('customers::front/field', ['id' => 'cu-reset-confirm', 'name' => 'password_confirm', 'label' => t('Repeat the new password'), 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'min' => $minPassword]) ?>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Save the new password')) ?></button>
    </form>
<?php endif; ?>
</div>
