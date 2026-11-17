<?php
/**
 * AIFEC — GM licences: las claves del pedido en la página de «gracias por tu compra».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $licenses
 */

declare(strict_types=1);

?>
<section class="af-card af-stack gm-licenses" aria-label="<?= e(t('Your licences')) ?>">
    <h2><?= e(t('Your licences')) ?></h2>
<?php foreach ($licenses as $af_license) : ?>
    <div>
        <p><strong><?= e((string) $af_license['product_name']) ?></strong></p>
<?php if ((string) $af_license['status'] === 'active') : ?>
        <p class="af-mono"><?= e((string) $af_license['license_key']) ?></p>
<?php else : ?>
        <p class="af-muted"><?= e(t('We are preparing this key. We will email you when it is ready.')) ?></p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
    <p class="af-small af-muted"><?= e(t('We have also emailed them to you, and you will always find them in My account.')) ?></p>
</section>
