<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página del pedido, a la que se llega con el número y la ficha de la dirección (sin cuenta): el estado en palabras
 * claras, las instrucciones de pago, el seguimiento, las líneas con su desglose, las direcciones, las descargas y un
 * enlace para crear la cuenta o entrar. Mientras el pago de una pasarela no se confirma, la página se refresca sola
 * (meta refresh con un contador en la dirección, con tope). Es privada: no se indexa y no se guarda en ninguna caché.
 *
 * Hueco: order.thanks (recibe el pedido y first, verdadero solo la primera vez que se enseña ya pagado).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $order Orders::findBySecret()
 * @var array<string,mixed> $v     lo que prepara OrderPageController::show()
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
if ($v['refresh'] !== '') {
    $this->push('styles', '<meta http-equiv="refresh" content="' . e((int) $v['refresh_seconds'] . ';url=' . $v['refresh']) . '">' . "\n");
}
$af_badge = $v['status']['class'] === 'ok' ? 'af-badge af-badge-new' : ($v['status']['class'] === 'bad' ? 'af-badge af-badge-out' : 'af-badge');
?>
<div class="af-container af-section" data-af-order="<?= e((string) $order['number']) ?>">
    <h1 class="af-title"><?= e(t('Order {number}', ['number' => (string) $order['number']])) ?></h1>
    <section class="af-card af-stack" aria-labelledby="af-order-status-h">
        <h2 id="af-order-status-h"><?= e(t('Status')) ?></h2>
        <p class="af-row">
            <span class="<?= e($af_badge) ?>" data-af-order-status><?= e($v['status']['title']) ?></span>
            <span class="af-muted"><?= e(t('Placed on {date}', ['date' => $v['placed']])) ?></span>
        </p>
<?php if ($v['status']['text'] !== '') : ?>
        <p><?= e($v['status']['text']) ?></p>
<?php endif; ?>
<?php if ($v['refresh'] !== '') : ?>
        <p class="af-small af-muted" role="status"><?= e(t('This page refreshes by itself until the payment is confirmed.')) ?></p>
<?php elseif ($v['exhausted']) : ?>
        <p class="af-alert af-alert-info" role="status"><?= e(t('The confirmation is taking longer than usual. Reload this page in a few minutes; we will email you as soon as the payment arrives.')) ?></p>
<?php endif; ?>
<?php if (!empty($v['pay_url'])) : ?>
        <p><a class="af-btn af-btn-primary af-btn-lg" href="<?= e((string) $v['pay_url']) ?>" rel="nofollow" data-af-pay><?= e(t('Pay this order now')) ?></a></p>
<?php endif; ?>
<?php if ($v['instructions'] !== '') : ?>
        <div class="af-prose" data-af-instructions>
            <h3><?= e(t('How to pay')) ?></h3>
            <?= $v['instructions'] /* solo etiquetas de texto y sin atributos: OrderView::cleanHtml() */ ?>
        </div>
<?php endif; ?>
<?php if ($order['carrier_title'] !== null && (string) $order['carrier_title'] !== '') : ?>
        <p><?= e(t('Carrier: {name}', ['name' => (string) $order['carrier_title']])) ?></p>
<?php endif; ?>
<?php if ($v['tracking']['code'] !== '') : ?>
        <p data-af-tracking>
            <?= e(t('Tracking code: {code}', ['code' => $v['tracking']['code']])) ?>
<?php if ($v['tracking']['url'] !== '') : ?>
            <a href="<?= e($v['tracking']['url']) ?>" target="_blank" rel="noopener"><?= e(t('Track the shipment')) ?></a>
<?php endif; ?>
        </p>
<?php endif; ?>
    </section>

    <?= hook('order.thanks', $order, $v['first']) ?>
    <p class="af-muted af-small" data-af-invoice-note><?= e(\Aifec\Modules\Orders\InvoiceNotice::text(app())) ?></p>

    <div class="af-split">
        <div class="af-stack">
            <section class="af-stack" aria-labelledby="af-order-items-h">
                <h2 id="af-order-items-h"><?= e(t('Products')) ?></h2>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('Product')) ?></th>
                            <th scope="col"><?= e(t('Quantity')) ?></th>
                            <th scope="col"><?= e(t('Total')) ?></th>
                        </tr></thead>
                        <tbody>
<?php foreach ($v['lines'] as $af_line) : ?>
                            <tr>
                                <td><?= e($af_line['name']) ?><?php if ($af_line['variant'] !== '') : ?><br><span class="af-muted af-small"><?= e($af_line['variant']) ?></span><?php endif; ?></td>
                                <td><?= e($af_line['qty']) ?></td>
                                <td><?= e($af_line['total']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
<?php if ($v['note'] !== '') : ?>
            <section class="af-card">
                <h3><?= e(t('Your note')) ?></h3>
                <p><?= e($v['note']) ?></p>
            </section>
<?php endif; ?>
            <section class="af-grid af-cols-2" aria-label="<?= e(t('Addresses')) ?>">
<?php if ($v['shipping'] !== []) : ?>
                <div class="af-card">
                    <h3><?= e(t('Shipping address')) ?></h3>
                    <address>
<?php foreach ($v['shipping'] as $af_text) : ?>
                        <?= e($af_text) ?><br>
<?php endforeach; ?>
                    </address>
                </div>
<?php endif; ?>
<?php if ($v['billing'] !== []) : ?>
                <div class="af-card">
                    <h3><?= e(t('Billing address')) ?></h3>
                    <address>
<?php foreach ($v['billing'] as $af_text) : ?>
                        <?= e($af_text) ?><br>
<?php endforeach; ?>
                    </address>
                </div>
<?php endif; ?>
            </section>
<?php if ($v['downloads'] !== []) : ?>
            <section class="af-card af-stack" aria-labelledby="af-order-downloads-h" data-af-downloads>
                <h2 id="af-order-downloads-h"><?= e(t('Your downloads')) ?></h2>
                <ul class="af-stack">
<?php foreach ($v['downloads'] as $af_download) : ?>
                    <li class="af-row" data-af-download="<?= e((int) $af_download['id']) ?>">
                        <span><?= e($af_download['name']) ?> <span class="af-muted af-small">(<?= e($af_download['item']) ?>)</span></span>
<?php if (!empty($af_download['available'])) : ?>
                        <form method="post" action="<?= e($af_download['action']) ?>">
                            <button type="submit" class="af-btn af-btn-sm"><?= e(t('Download')) ?></button>
                        </form>
<?php else : ?>
                        <span class="af-muted af-small"><?= e(!empty($af_download['blocked']) && (string) $af_download['reason'] !== '' ? (string) $af_download['reason'] : t('This download is no longer available.')) ?></span>
<?php endif; ?>
                    </li>
<?php endforeach; ?>
                </ul>
            </section>
<?php endif; ?>
        </div>
        <aside class="af-summary af-card af-stack" aria-labelledby="af-order-summary-h">
            <h2 id="af-order-summary-h"><?= e(t('Order summary')) ?></h2>
            <?= $this->partial('orders::front/totals', ['v' => $v['totals']]) ?>
<?php if ($v['account']['url'] !== '') : ?>
            <a class="af-btn af-btn-block" href="<?= e($v['account']['url']) ?>"><?= e($v['account']['label']) ?></a>
<?php endif; ?>
        </aside>
    </div>
</div>
