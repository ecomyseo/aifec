<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Mis pedidos: listado paginado de los pedidos del cliente.
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
 * @var array<int,array<string,mixed>> $rows pedidos de esta página
 * @var int    $currentPage página actual
 * @var int    $pages       páginas en total
 * @var int    $hidden      pedidos anteriores al registro que siguen ocultos
 * @var bool   $unverified  el correo no está verificado
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('My orders')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
<?php if ($hidden > 0 || $unverified) : ?>
            <div class="af-alert af-alert-warn" role="alert">
                <p><?= e($hidden > 0
                    ? tn('{n} earlier order is hidden until you confirm your email address.', '{n} earlier orders are hidden until you confirm your email address.', $hidden)
                    : t('Your email address is not confirmed yet. Use the link we sent you to confirm it.')) ?></p>
                <form method="post" action="<?= e(route('account.verify.resend')) ?>">
                    <input type="hidden" name="_token" value="<?= e($token) ?>">
                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Send me the email again')) ?></button>
                </form>
            </div>
<?php endif; ?>
<?php if ($rows === []) : ?>
            <div class="af-card af-empty">
                <p class="af-empty-title"><?= e(t('You have no orders yet.')) ?></p>
                <a class="af-btn af-btn-primary" href="<?= e(url('/')) ?>"><?= e(t('Back to the home page')) ?></a>
            </div>
<?php else : ?>
            <?= $this->partial('customers::front/orders-table', ['rows' => $rows]) ?>
            <?= $this->partial('partials/pagination', ['page' => $currentPage, 'pages' => $pages, 'url' => static function (int $n): string {
                return route('account.orders', ['page' => $n]);
            }]) ?>
<?php endif; ?>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
