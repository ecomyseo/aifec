<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Subscriptions\Labels;
use Aifec\Modules\Subscriptions\Subscriptions;

$this->layout('layout', ['page' => $page]);
?>
<main class="af-container af-section af-stack">
    <h1><?= e(t('My subscriptions')) ?></h1>
    <p><a href="<?= e(route('subscriptions.plans')) ?>"><?= e(t('See monthly services')) ?></a> · <a href="<?= e(route('account')) ?>"><?= e(t('My account')) ?></a></p>
<?php if ($subscriptions === []) : ?>
    <p><?= e(t('You have no monthly subscriptions yet.')) ?></p>
<?php endif; ?>
<?php foreach ($subscriptions as $sub) : $snapshot = Subscriptions::snapshot($sub); ?>
    <section class="af-card af-stack">
        <h2><?= e($snapshot['name']) ?></h2>
        <p><?= e(Labels::status((string) $sub['status'])) ?> · <?= e($app->i18n()->money((int) $snapshot['amount'], (string) $snapshot['currency'])) ?> <?= e(t('per month')) ?></p>
        <p class="af-muted"><?= e(Labels::paymentMode((string) $sub['provider'], (string) $sub['environment'])) ?></p>
        <p><?= e(t('Next payment')) ?>: <?= e(!empty($sub['next_payment_at']) ? $app->i18n()->date(\Aifec\Kernel\Clock::parse($sub['next_payment_at']), 'short', $app->site()->timezone()) : t('Not scheduled')) ?></p>
<?php if ($sub['status'] === 'pending' && !empty($sub['approval_url'])) : ?>
        <a class="af-btn af-btn-primary" href="<?= e($sub['approval_url']) ?>"><?= e(t('Approve this subscription in PayPal')) ?></a>
<?php endif; ?>
        <h3><?= e(t('Payments and orders')) ?></h3>
<?php $payments = $customerPayments[(int) $sub['id']] ?? []; if ($payments === []) : ?>
        <p><?= e(t('No confirmed payments yet. Approval is not a payment.')) ?></p>
<?php else : ?>
        <ul>
<?php foreach ($payments as $payment) : ?>
            <li><?= e($payment['paid_at']) ?> · <?= e($app->i18n()->money((int) $payment['amount'], $payment['currency'])) ?> · <?= e(t('Order')) ?> #<?= (int) $payment['order_id'] ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php if (!in_array($sub['status'], ['cancelled', 'expired', 'starting'], true)) : ?>
        <form method="post" action="<?= e(route('subscriptions.cancel', ['id' => (int) $sub['id']])) ?>">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button type="submit" class="af-btn"><?= e(t('Cancel future monthly payments')) ?></button>
        </form>
<?php endif; ?>
    </section>
<?php endforeach; ?>
</main>
