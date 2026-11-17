<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Mis direcciones: la lista, con cambiar y borrar, y el acceso al alta.
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
 * @var array<int,array<string,mixed>> $rows label, lines, phone, shipping, billing, edit_url, delete_url
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('My addresses')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
<?php if ($rows === []) : ?>
            <div class="af-card af-empty">
                <p class="af-empty-title"><?= e(t('No addresses yet.')) ?></p>
            </div>
<?php else : ?>
            <div class="af-grid af-cols-2">
<?php foreach ($rows as $af_row) : ?>
                <article class="af-card af-stack">
<?php if ($af_row['label'] !== '') : ?>
                    <h2><?= e($af_row['label']) ?></h2>
<?php endif; ?>
                    <?= $this->partial('customers::front/address-block', ['lines' => $af_row['lines']]) ?>
<?php if ($af_row['phone'] !== '') : ?>
                    <p class="af-muted af-small"><?= e($af_row['phone']) ?></p>
<?php endif; ?>
                    <p class="af-row">
<?php if ($af_row['shipping']) : ?>
                        <span class="af-badge"><?= e(t('Default shipping address')) ?></span>
<?php endif; ?>
<?php if ($af_row['billing']) : ?>
                        <span class="af-badge"><?= e(t('Default billing address')) ?></span>
<?php endif; ?>
                    </p>
                    <div class="af-row">
                        <a class="af-btn af-btn-sm" href="<?= e($af_row['edit_url']) ?>"><?= e(t('Edit')) ?></a>
                        <form method="post" action="<?= e($af_row['delete_url']) ?>">
                            <input type="hidden" name="_token" value="<?= e($token) ?>">
                            <button type="submit" class="af-btn af-btn-sm af-btn-ghost"><?= e(t('Delete')) ?></button>
                        </form>
                    </div>
                </article>
<?php endforeach; ?>
            </div>
<?php endif; ?>
            <p><a class="af-btn af-btn-primary" href="<?= e(route('account.address.new')) ?>"><?= e(t('New address')) ?></a></p>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
