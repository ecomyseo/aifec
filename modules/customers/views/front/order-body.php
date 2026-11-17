<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El cuerpo de un pedido de la zona de cliente (sin envoltorio).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $order el pedido preparado por OrderList::view()
 * @var string $token ficha de los formularios
 */

declare(strict_types=1);

?>
<section class="af-card af-stack" aria-labelledby="cu-order-status">
    <h2 id="cu-order-status"><?= e(t('Status')) ?></h2>
    <p class="af-row">
        <span class="<?= e($order['status_class']) ?>"><?= e($order['status_name']) ?></span>
        <span class="af-muted"><?= e(t('Placed on {date}', ['date' => $order['placed']])) ?></span>
    </p>
<?php if ($order['carrier_title'] !== '' || $order['tracking_code'] !== '') : ?>
    <p>
<?php if ($order['carrier_title'] !== '') : ?>
        <?= e(t('Shipping')) ?>: <?= e($order['carrier_title']) ?><br>
<?php endif; ?>
<?php if ($order['tracking_code'] !== '') : ?>
        <?= e(t('Tracking code')) ?>: <?= e($order['tracking_code']) ?>
<?php endif; ?>
    </p>
<?php endif; ?>
<?php if ($order['payment_title'] !== '') : ?>
    <p><?= e(t('Payment')) ?>: <?= e($order['payment_title']) ?></p>
<?php endif; ?>
<?php if ((string) $order['return_url'] !== '') : ?>
    <p><a class="af-btn af-btn-sm" href="<?= e($order['return_url']) ?>" data-af-order-return><?= e(t('Return or withdraw')) ?></a></p>
<?php endif; ?>
</section>

<section class="af-stack" aria-labelledby="cu-order-items">
    <h2 id="cu-order-items"><?= e(t('Products')) ?></h2>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th scope="col"><?= e(t('Product')) ?></th>
                <th scope="col"><?= e(t('Quantity')) ?></th>
                <th scope="col"><?= e(t('Total')) ?></th>
            </tr></thead>
            <tbody>
<?php foreach ($order['lines'] as $af_line) : ?>
                <tr>
                    <td><?= e($af_line['name']) ?><?= $af_line['variant'] !== '' ? '<br><span class="af-muted af-small">' . e($af_line['variant']) . '</span>' : '' ?></td>
                    <td><?= e($af_line['qty']) ?></td>
                    <td><?= e($af_line['total']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="af-card af-totals">
<?php foreach ($order['totals'] as $af_row) : ?>
        <p class="af-row af-totals-row<?= $af_row[2] ? ' af-totals-total' : '' ?>"><span><?= e($af_row[0]) ?></span> <strong><?= e($af_row[1]) ?></strong></p>
<?php endforeach; ?>
    </div>
</section>

<section class="af-grid af-cols-2" aria-label="<?= e(t('Addresses')) ?>">
<?php if ($order['shipping'] !== []) : ?>
    <div class="af-card">
        <h3><?= e(t('Shipping address')) ?></h3>
        <?= $this->partial('customers::front/address-block', ['lines' => $order['shipping']]) ?>
    </div>
<?php endif; ?>
<?php if ($order['billing'] !== []) : ?>
    <div class="af-card">
        <h3><?= e(t('Billing address')) ?></h3>
        <?= $this->partial('customers::front/address-block', ['lines' => $order['billing']]) ?>
    </div>
<?php endif; ?>
</section>

<?php if ($order['customer_note'] !== '') : ?>
<section class="af-card">
    <h3><?= e(t('Your note')) ?></h3>
    <p><?= e($order['customer_note']) ?></p>
</section>
<?php endif; ?>

<?php if ($order['notes'] !== []) : ?>
<section class="af-card af-stack" aria-labelledby="cu-order-notes">
    <h2 id="cu-order-notes"><?= e(t('Messages from the shop')) ?></h2>
<?php foreach ($order['notes'] as $af_note) : ?>
    <p><span class="af-muted af-small"><?= e($af_note['date']) ?></span><br><?= e($af_note['text']) ?></p>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($order['downloads'] !== []) : ?>
<section class="af-stack" aria-labelledby="cu-order-downloads">
    <h2 id="cu-order-downloads"><?= e(t('Downloads')) ?></h2>
    <?= $this->partial('customers::front/downloads-list', ['items' => $order['downloads'], 'token' => $token]) ?>
</section>
<?php endif; ?>
