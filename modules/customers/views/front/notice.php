<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Aviso sencillo de la zona de cliente (un enlace que no vale, por ejemplo).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var string $text   el aviso, ya traducido
 * @var string $token  ficha de los formularios (solo si ha entrado)
 * @var bool   $resend se ofrece mandar otra vez el correo de verificación
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e($page['title']) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-card af-stack">
        <p><?= e($text) ?></p>
<?php if (!empty($resend)) : ?>
        <form method="post" action="<?= e(route('account.verify.resend')) ?>">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send me a new confirmation email')) ?></button>
        </form>
<?php endif; ?>
        <p><a class="af-btn" href="<?= e(route('account')) ?>"><?= e(t('Go to my account')) ?></a></p>
    </div>
</div>
