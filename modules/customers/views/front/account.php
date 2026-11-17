<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Mi cuenta: saludo, aviso de correo sin verificar, últimos pedidos y accesos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var array<string,mixed> $customer
 * @var string $name        nombre de pila (o el correo si no hay nombre)
 * @var string $token       ficha de los formularios
 * @var string $active      clave del menú
 * @var array<int,array<string,mixed>> $recent los últimos pedidos
 * @var int    $hidden      pedidos anteriores al registro que siguen ocultos
 * @var bool   $unverified  el correo no está verificado
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Hello {name}', ['name' => $name])) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
<?php if ($unverified) : ?>
            <div class="af-alert af-alert-warn" role="alert">
                <p><?= e(t('Your email address is not confirmed yet. Use the link we sent you to confirm it.')) ?></p>
                <form method="post" action="<?= e(route('account.verify.resend')) ?>">
                    <input type="hidden" name="_token" value="<?= e($token) ?>">
                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Send me the email again')) ?></button>
                </form>
            </div>
<?php endif; ?>
<?php if ($hidden > 0) : ?>
            <div class="af-alert af-alert-info"><?= e(tn('{n} earlier order will show up when you confirm your email address.', '{n} earlier orders will show up when you confirm your email address.', $hidden)) ?></div>
<?php endif; ?>
<?php if ($account->hasOrders()) : ?>
            <section class="af-card af-stack" aria-labelledby="cu-recent-title">
                <h2 id="cu-recent-title"><?= e(t('Latest orders')) ?></h2>
<?php if ($recent === []) : ?>
                <p class="af-muted"><?= e(t('You have no orders yet.')) ?></p>
<?php else : ?>
                <?= $this->partial('customers::front/orders-table', ['rows' => $recent]) ?>
                <p><a href="<?= e(route('account.orders')) ?>"><?= e(t('See all my orders')) ?></a></p>
<?php endif; ?>
            </section>
<?php endif; ?>
            <?= hook('account.dashboard', $customer) ?>
            <section class="af-grid af-cols-2" aria-label="<?= e(t('Quick links')) ?>">
                <a class="af-card" href="<?= e(route('account.addresses')) ?>"><strong><?= e(t('My addresses')) ?></strong><br><span class="af-muted af-small"><?= e(t('Where we send your orders and where we bill them.')) ?></span></a>
                <a class="af-card" href="<?= e(route('account.profile')) ?>"><strong><?= e(t('My details')) ?></strong><br><span class="af-muted af-small"><?= e(t('Your name, email and password.')) ?></span></a>
            </section>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
