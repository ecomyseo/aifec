<?php
/**
 * AIFEC — GM licences: «Mis licencias» en la zona de cliente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page
 * @var array<int,array<int,string>>   $messages
 * @var array<string,mixed>            $customer
 * @var string                         $token
 * @var string                         $active
 * @var array<int,array<string,mixed>> $items       license y domains de cada licencia
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('My licences')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
<?php if ($items === []) : ?>
            <div class="af-card af-empty">
                <p class="af-empty-title"><?= e(t('You have no licences yet.')) ?></p>
                <p class="af-muted"><?= e(t('They appear here as soon as an order with a licence is paid.')) ?></p>
            </div>
<?php else : ?>
<?php foreach ($items as $af_item) : ?>
<?php $af_license = $af_item['license']; ?>
            <section class="af-card af-stack" aria-label="<?= e((string) $af_license['product_name']) ?>">
                <h2><?= e((string) $af_license['product_name']) ?>
<?php if ((string) $af_license['status'] === 'revoked') : ?>
                    <span class="af-badge af-badge-out"><?= e(t('Revoked')) ?></span>
<?php elseif ((string) $af_license['status'] === 'pending') : ?>
                    <span class="af-badge"><?= e(t('Pending')) ?></span>
<?php endif; ?>
                </h2>
<?php if ((string) $af_license['status'] === 'pending') : ?>
                <p class="af-muted"><?= e(t('We are preparing this key. We will email you when it is ready.')) ?></p>
<?php else : ?>
                <p class="af-mono"><?= e((string) $af_license['license_key']) ?></p>
<?php endif; ?>
                <dl class="af-facts">
<?php if ((string) $af_license['kind'] === 'domain') : ?>
                    <div><dt><?= e(t('Domains')) ?></dt><dd><?= e(count($af_item['domains'])) ?> / <?= e((int) $af_license['max_domains'] > 0 ? (string) (int) $af_license['max_domains'] : t('Unlimited')) ?></dd></div>
<?php else : ?>
                    <div><dt><?= e(t('Tax ID')) ?></dt><dd><?= e((string) $af_license['holder']) ?></dd></div>
<?php endif; ?>
                    <div><dt><?= e(t('Updates until')) ?></dt><dd><?= e($af_license['updates_until'] !== null ? substr((string) $af_license['updates_until'], 0, 10) : t('No end date')) ?></dd></div>
                </dl>
<?php if ($af_item['domains'] !== []) : ?>
                <ul class="af-small">
<?php foreach ($af_item['domains'] as $af_domain) : ?>
                    <li><?= e((string) $af_domain['domain']) ?></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
<?php endforeach; ?>
<?php endif; ?>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
