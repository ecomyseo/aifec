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
    <h1><?= e(t('Confirm the monthly service')) ?></h1>
    <h2><?= e($plan['name']) ?></h2>
    <p><?= nl2br(e((string) $plan['description'])) ?></p>
<?php if ($addresses === []) : ?>
    <p><?= e(t('Add a billing address to see the final monthly amount.')) ?></p>
    <a class="af-btn" href="<?= e(route('account.addresses')) ?>"><?= e(t('My addresses')) ?></a>
<?php else : ?>
    <form method="get" action="<?= e(route('subscriptions.checkout', ['id' => (int) $plan['id']])) ?>" class="af-stack">
        <label for="sub-address"><?= e(t('Billing address')) ?></label>
        <select name="address_id" id="sub-address">
<?php foreach ($addresses as $address) : ?>
            <option value="<?= (int) $address['id'] ?>"<?= (int) $address['id'] === $addressId ? ' selected' : '' ?>><?= e(trim((string) $address['line1'] . ', ' . (string) $address['postcode'] . ' ' . (string) $address['city'] . ' (' . (string) $address['country'] . ')')) ?></option>
<?php endforeach; ?>
        </select>
        <button type="submit" class="af-btn"><?= e(t('Recalculate for this address')) ?></button>
    </form>
<?php if ($quote !== null) : ?>
    <section class="af-card af-stack">
        <p><strong><?= e(t('Monthly total: {amount}', ['amount' => $app->i18n()->money((int) $quote['amount'], (string) $quote['currency'])])) ?></strong></p>
        <p><?= e(t('Applicable taxes are included. This amount is charged every month until you cancel. No shipping is required.')) ?></p>
        <p><?= e(t('You can cancel from My subscriptions before the next payment. Editing the shop plan does not change the amount you authorise here.')) ?></p>
<?php if ($service->provider() === 'simulated') : ?>
        <p class="af-alert af-alert-info"><?= e(t('Test subscription: no real money is collected.')) ?></p>
<?php endif; ?>
        <form method="post" action="<?= e(route('subscriptions.start', ['id' => (int) $plan['id']])) ?>" class="af-stack">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <input type="hidden" name="nonce" value="<?= e($nonce) ?>">
            <input type="hidden" name="address_id" value="<?= (int) $addressId ?>">
            <input type="hidden" name="amount" value="<?= (int) $quote['amount'] ?>">
            <label><input type="checkbox" name="consent" value="1" required> <?= e(t('I authorise a recurring payment of {amount} every month until I cancel.', ['amount' => $app->i18n()->money((int) $quote['amount'], (string) $quote['currency'])])) ?></label>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Subscribe with monthly payment')) ?></button>
        </form>
    </section>
<?php endif; endif; ?>
    <a href="<?= e(route('subscriptions.plans')) ?>"><?= e(t('Back to monthly plans')) ?></a>
</main>
