<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<main class="af-container af-section af-stack">
    <h1><?= e(t('Monthly services')) ?></h1>
    <p><?= e(t('Choose a service. You will review the final monthly amount and billing address before authorising recurring payments.')) ?></p>
<?php if ($plans === []) : ?>
    <p><?= e(t('There are no monthly plans available right now.')) ?></p>
<?php endif; ?>
    <div class="af-grid af-cols-3">
<?php foreach ($plans as $plan) : ?>
        <section class="af-card af-stack">
            <h2><?= e($plan['name']) ?></h2>
            <p><?= nl2br(e((string) $plan['description'])) ?></p>
            <p><?= e($app->i18n()->money((int) $plan['amount'], $app->settings()->str('shop.currency', 'EUR'))) ?> <?= e(t('per month, before billing-address tax adjustments')) ?></p>
            <a class="af-btn af-btn-primary" href="<?= e(route('subscriptions.checkout', ['id' => (int) $plan['id']])) ?>"><?= e(t('Review this monthly plan')) ?></a>
        </section>
<?php endforeach; ?>
    </div>
    <a href="<?= e(route('subscriptions.account')) ?>"><?= e(t('My subscriptions')) ?></a>
</main>
