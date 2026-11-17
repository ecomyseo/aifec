<?php
/**
 * AIFEC — instalador, pantalla final: los dos enlaces.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var callable $t
 * @var string $shopUrl
 * @var string $adminUrl
 * @var string $adminEmail
 */

$this->layout('install::layout');
?>
<div class="af-alert af-alert-ok" role="status"><?= e($t('Your shop is ready. Sign in with the email and password you just chose.')) ?></div>
<p><?= e($t('Your sign-in email')) ?>: <strong><?= e($adminEmail) ?></strong></p>
<div class="af-install-actions">
    <a class="af-btn af-btn-primary" href="<?= e($adminUrl) ?>"><?= e($t('Open the panel')) ?></a>
    <a class="af-btn" href="<?= e($shopUrl) ?>"><?= e($t('Open the shop')) ?></a>
</div>
<p class="af-muted af-small" style="margin-top:1rem"><?= e($t('The installer is now closed: this address shows your shop.')) ?></p>
