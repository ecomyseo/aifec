<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);
?>
<div class="af-stack">
    <p><a class="af-btn" href="<?= e(url('/admin/r/subscription_plans')) ?>"><?= e(t('Monthly plans')) ?></a> <a class="af-btn" href="<?= e(url('/admin/settings/subscriptions')) ?>"><?= e(t('Subscription settings')) ?></a> <a href="<?= e(route('subscriptions.plans')) ?>"><?= e(t('See monthly services')) ?></a></p>
    <p><?= e(t('PayPal notification address')) ?>: <code><?= e(app()->url()->origin() . app()->url()->route('subscriptions.notify', ['environment' => 'sandbox'], '')) ?></code></p>
    <p><?= e(t('Register a separate notification address for production by replacing sandbox with live.')) ?></p>
    <?= $table ?>
</div>
