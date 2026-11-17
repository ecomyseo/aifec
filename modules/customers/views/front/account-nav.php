<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Menú de la zona de cliente (común a todas sus páginas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                $active  clave de la página actual: account, orders, downloads, addresses, profile, privacy
 * @var string                $token   ficha de los formularios
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$af_active = isset($active) ? (string) $active : '';
$af_items = [['account', t('Overview'), route('account')]];
if ($account->hasOrders()) {
    $af_items[] = ['orders', t('Orders'), route('account.orders')];
    $af_items[] = ['downloads', t('Downloads'), route('account.downloads')];
}
$af_items[] = ['addresses', t('Addresses'), route('account.addresses')];
$af_items[] = ['profile', t('My details'), route('account.profile')];
$af_items[] = ['privacy', t('Privacy'), route('account.privacy')];
?>
<nav class="af-card af-stack" aria-label="<?= e(t('Account menu')) ?>">
<?php foreach ($af_items as $af_item) : ?>
    <a class="af-btn af-btn-block <?= $af_item[0] === $af_active ? 'af-btn-primary' : 'af-btn-ghost' ?>" href="<?= e($af_item[2]) ?>"<?= $af_item[0] === $af_active ? ' aria-current="page"' : '' ?>><?= e($af_item[1]) ?></a>
<?php endforeach; ?>
    <form method="post" action="<?= e(route('account.logout')) ?>">
        <input type="hidden" name="_token" value="<?= e(isset($token) ? $token : '') ?>">
        <button type="submit" class="af-btn af-btn-block"><?= e(t('Sign out')) ?></button>
    </form>
</nav>
