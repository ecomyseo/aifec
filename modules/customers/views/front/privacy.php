<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Privacidad: descargar mis datos y eliminar mi cuenta.
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
 * @var string $word palabra que hay que escribir para confirmar el borrado (ya traducida)
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Privacy')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
            <section class="af-card af-stack" aria-labelledby="cu-pv-data">
                <h2 id="cu-pv-data"><?= e(t('Download my data')) ?></h2>
                <p class="af-muted"><?= e(t('You get a file with everything we keep about you: your details, addresses and consents.')) ?></p>
                <p><a class="af-btn" href="<?= e(route('account.privacy.export')) ?>"><?= e(t('Download my data')) ?></a></p>
            </section>
            <form class="af-card" method="post" action="<?= e(route('account.privacy.delete')) ?>" aria-labelledby="cu-pv-delete">
                <h2 id="cu-pv-delete"><?= e(t('Delete my account')) ?></h2>
                <p class="af-muted"><?= e(t('We erase your personal data and your sessions. Your past orders stay in the shop without your name. This cannot be undone.')) ?></p>
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <?= $this->partial('customers::front/field', ['id' => 'cu-pv-password', 'name' => 'password', 'label' => t('Your current password'), 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-pv-confirm', 'name' => 'confirm', 'label' => t('Type {word} to confirm', ['word' => $word]), 'required' => true, 'max' => 40]) ?>
                <button type="submit" class="af-btn af-btn-primary"><?= e(t('Delete my account')) ?></button>
            </form>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
