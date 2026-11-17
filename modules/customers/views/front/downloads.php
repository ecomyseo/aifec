<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Mis descargas: los ficheros de los pedidos pagados.
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
 * @var array<int,array<string,mixed>> $groups number, placed, url, items
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('My downloads')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
<?php if ($groups === []) : ?>
            <div class="af-card af-empty">
                <p class="af-empty-title"><?= e(t('You have nothing to download yet.')) ?></p>
                <p class="af-muted"><?= e(t('The files of your digital products appear here once the order is paid.')) ?></p>
            </div>
<?php else : ?>
<?php foreach ($groups as $af_group) : ?>
            <section class="af-stack" aria-label="<?= e(t('Order {number}', ['number' => $af_group['number']])) ?>">
                <h2><a href="<?= e($af_group['url']) ?>"><?= e(t('Order {number}', ['number' => $af_group['number']])) ?></a> <span class="af-muted af-small"><?= e($af_group['placed']) ?></span></h2>
                <?= $this->partial('customers::front/downloads-list', ['items' => $af_group['items'], 'token' => $token]) ?>
            </section>
<?php endforeach; ?>
<?php endif; ?>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
