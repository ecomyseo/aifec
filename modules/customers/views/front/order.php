<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Un pedido del cliente: estado, líneas, totales, direcciones, avisos de la tienda y descargas.
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
 * @var array<string,mixed> $order el pedido preparado por OrderList::view()
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Order {number}', ['number' => $order['number']])) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <div class="af-stack">
            <?= $this->partial('customers::front/order-body', ['order' => $order, 'token' => $token]) ?>
            <p><a href="<?= e(route('account.orders')) ?>"><?= e(t('Back to my orders')) ?></a></p>
        </div>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
