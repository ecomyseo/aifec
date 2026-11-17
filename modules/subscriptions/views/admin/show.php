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
?>
<div class="af-stack">
    <h2><?= e($snapshot['name']) ?></h2>
    <p><?= e($snapshot['email']) ?> · <?= e(Labels::status((string) $sub['status'])) ?></p>
    <p><?= e(app()->i18n()->money((int) $snapshot['amount'], $snapshot['currency'])) ?> <?= e(t('per month')) ?> · <?= e(Labels::paymentMode((string) $sub['provider'], (string) $sub['environment'])) ?></p>
    <p><?= e(t('Next payment')) ?>: <?= e((string) $sub['next_payment_at']) ?></p>
    <p><?= e(t('Consent recorded at')) ?>: <?= e($sub['consented_at']) ?></p>
<?php if (app()->auth()->can('subscriptions.edit') && !in_array($sub['status'], ['cancelled', 'expired', 'starting'], true)) : ?>
    <div class="af-cluster">
<?php
$actions = ['cancel' => t('Cancel future monthly payments')];
if (in_array($sub['status'], ['active', 'past_due'], true)) {
    $actions['suspend'] = t('Suspend subscription');
}
if (in_array($sub['status'], ['suspended', 'past_due'], true)) {
    $actions['activate'] = t('Resume subscription');
}
foreach ($actions as $action => $label) : ?>
        <form method="post" action="<?= e(route('admin.subscriptions.action', ['id' => (int) $sub['id'], 'action' => $action])) ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="due" value="<?= e((string) $sub['next_payment_at']) ?>"><button type="submit" class="af-btn"><?= e($label) ?></button></form>
<?php endforeach; ?>
<?php if ($sub['provider'] === 'simulated' && in_array($sub['status'], ['active', 'past_due'], true)) : foreach (['paid' => t('Simulate the next monthly payment'), 'failed' => t('Simulate a failed monthly payment')] as $action => $label) : ?>
        <form method="post" action="<?= e(route('admin.subscriptions.action', ['id' => (int) $sub['id'], 'action' => $action])) ?>"><input type="hidden" name="_token" value="<?= e($token) ?>"><input type="hidden" name="due" value="<?= e((string) $sub['next_payment_at']) ?>"><button type="submit" class="af-btn"><?= e($label) ?></button></form>
<?php endforeach; endif; ?>
    </div>
<?php endif; ?>
    <h2><?= e(t('Payments and orders')) ?></h2>
    <ul>
<?php foreach ($payments as $payment) : ?>
        <li><?= e($payment['paid_at']) ?> · <?= e(app()->i18n()->money((int) $payment['amount'], $payment['currency'])) ?> · <a href="<?= e(url('/admin/orders/' . (int) $payment['order_id'])) ?>"><?= e(t('Order')) ?> #<?= (int) $payment['order_id'] ?></a> · <?= e($payment['reference']) ?></li>
<?php endforeach; ?>
    </ul>
</div>
