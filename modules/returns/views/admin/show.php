<?php
/**
 * AIFEC — ficha de una devolución en el panel: líneas, fotos, motivo, historial y las acciones (aceptar, rechazar, marcar recibida,
 * devolver el dinero, cancelar y apuntar una nota), todo en una pantalla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $return              la devolución (Returns::find)
 * @var array<int,array<string,mixed>> $lines               sus líneas, cada una con «amount» (lo propuesto)
 * @var array<string,mixed>            $order               el pedido (Orders::find)
 * @var string                         $orderUrl            la ficha del pedido
 * @var array<int,array<string,string>> $photos              url y name de cada foto
 * @var array<string,mixed>            $proposal            el importe propuesto (ReturnAmounts::propose)
 * @var string                         $reasonText          el motivo ya traducido
 * @var bool                           $canManage           puede tramitar
 * @var bool                           $gateway             la forma de pago devuelve por la pasarela
 * @var string                         $defaultInstructions las instrucciones de envío de serie
 * @var bool                           $open                sigue abierta
 * @var array<string,string>           $actions             dirección de cada acción
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Commerce\Money;
use Aifec\Kernel\Clock;
use Aifec\Modules\Returns\ReturnLabels;

$af_status = (string) $return['status'];
$af_currency = (string) $return['currency'];
$af_date = static function ($sql): string {
    $time = Clock::parse(is_string($sql) ? $sql : null);

    return $time > 0 ? app()->i18n()->date($time, 'medium', app()->site()->timezone()) : '';
};
?>
<div class="af-grid">
    <div class="af-col-8 af-stack">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Return {number}', ['number' => (string) $return['number']])) ?> <?= Admin::badge(ReturnLabels::status($af_status), ReturnLabels::kind($af_status)) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('Type')) ?></dt><dd><?= e(ReturnLabels::type((string) $return['kind'])) ?></dd>
                    <dt><?= e(t('Order')) ?></dt><dd><a href="<?= e($orderUrl) ?>"><?= e((string) $order['number']) ?></a></dd>
                    <dt><?= e(t('Customer')) ?></dt><dd><?= e((string) $return['email']) ?></dd>
                    <dt><?= e(t('Reason')) ?></dt><dd><?= e($reasonText) ?></dd>
                    <dt><?= e(t('Requested')) ?></dt><dd><?= e($af_date($return['requested_at'])) ?></dd>
<?php if ((int) $return['refunded_amount'] > 0) : ?>
                    <dt><?= e(t('Refunded')) ?></dt><dd><?= e(money((int) $return['refunded_amount'], $af_currency)) ?></dd>
<?php endif; ?>
<?php if ((string) $return['reject_reason'] !== '') : ?>
                    <dt><?= e(t('Reason for rejecting')) ?></dt><dd><?= e((string) $return['reject_reason']) ?></dd>
<?php endif; ?>
                </dl>
<?php if ((string) $return['comment'] !== '') : ?>
                <h3><?= e(t('Comment from the customer')) ?></h3>
                <p><?= nl2br(e((string) $return['comment'])) ?></p>
<?php endif; ?>
<?php if ((string) $return['instructions'] !== '') : ?>
                <h3><?= e(t('Shipping instructions given')) ?></h3>
                <p><?= nl2br(e((string) $return['instructions'])) ?></p>
<?php endif; ?>
            </div>
        </section>

        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Products')) ?></h2></div>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr>
                        <th scope="col"><?= e(t('Product')) ?></th>
                        <th scope="col"><?= e(t('Qty')) ?></th>
                        <th scope="col"><?= e(t('Back to stock')) ?></th>
                        <th scope="col" class="af-num"><?= e(t('Proposed refund')) ?></th>
                    </tr></thead>
                    <tbody>
<?php foreach ($lines as $af_line) : ?>
                        <tr data-af-return-line="<?= e((int) $af_line['id']) ?>">
                            <td><?= e((string) $af_line['name']) ?><?php if ((string) $af_line['variant_label'] !== '') : ?><br><span class="af-small af-muted"><?= e((string) $af_line['variant_label']) ?></span><?php endif; ?></td>
                            <td><?= e((int) $af_line['qty']) ?></td>
                            <td><?= $af_status === 'received' || $af_status === 'refunded' ? e(!empty($af_line['restock']) ? t('Yes') : t('No')) : '<span class="af-muted">—</span>' ?></td>
                            <td class="af-num"><?= !empty($af_line['refunded']) ? '<span class="af-muted">' . e(t('Already refunded')) . '</span>' : e(money((int) $af_line['amount'], $af_currency)) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if ((int) $proposal['shipping'] > 0) : ?>
                        <tr>
                            <td colspan="3"><?= e(t('Outgoing shipping')) ?></td>
                            <td class="af-num"><?= e(money((int) $proposal['shipping'], $af_currency)) ?></td>
                        </tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

<?php if ($photos !== []) : ?>
        <section class="af-card" data-af-return-photos>
            <div class="af-card-head"><h2><?= e(t('Photos')) ?></h2></div>
            <div class="af-card-body af-row">
<?php foreach ($photos as $af_photo) : ?>
                <a href="<?= e($af_photo['url']) ?>" target="_blank" rel="noopener"><img src="<?= e($af_photo['url']) ?>" alt="<?= e($af_photo['name']) ?>" width="160" loading="lazy"></a>
<?php endforeach; ?>
            </div>
        </section>
<?php endif; ?>

        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('History')) ?></h2></div>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr>
                        <th scope="col"><?= e(t('Date')) ?></th>
                        <th scope="col"><?= e(t('What happened')) ?></th>
                        <th scope="col"><?= e(t('Who')) ?></th>
                    </tr></thead>
                    <tbody>
<?php foreach ($return['events'] as $af_event) : ?>
                        <tr>
                            <td class="af-nowrap"><?= e($af_date($af_event['created_at'])) ?></td>
                            <td>
<?php if ((string) $af_event['kind'] === 'status') : ?>
                                <?= e(ReturnLabels::status((string) $af_event['to_value'])) ?><?= !empty($af_event['notified']) ? ' <span class="af-small af-muted">(' . e(t('customer notified')) . ')</span>' : '' ?>
<?php elseif ((string) $af_event['kind'] === 'refund') : ?>
                                <?= e(t('Refund of {amount}', ['amount' => money((int) $af_event['to_value'], $af_currency)])) ?>
<?php else : ?>
                                <?= e(t('Note')) ?>
<?php endif; ?>
<?php if ((string) $af_event['note'] !== '') : ?>
                                <br><span class="af-small"><?= nl2br(e((string) $af_event['note'])) ?></span>
<?php endif; ?>
                            </td>
                            <td><?= e((string) $af_event['actor'] === 'customer' ? t('Customer') : ((string) $af_event['actor'] === 'staff' ? t('Shop') : t('System'))) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="af-col-4 af-stack af-no-print">
<?php if ($canManage && $af_status === 'requested') : ?>
        <section class="af-card" data-af-return-action="approve">
            <div class="af-card-head"><h2><?= e(t('Accept the return')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($actions['approve']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <label for="af-rt-instructions"><?= e(t('Shipping instructions')) ?></label>
                    <textarea class="af-input" id="af-rt-instructions" name="instructions" rows="5" maxlength="2000" placeholder="<?= e($defaultInstructions) ?>"></textarea>
                    <span class="af-small af-muted"><?= e(t('Empty sends the standard instructions.')) ?></span>
                    <label class="af-check"><input type="hidden" name="notify" value="0"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                    <button type="submit" class="af-btn af-btn-primary"><?= e(t('Accept')) ?></button>
                </form>
            </div>
        </section>
<?php endif; ?>

<?php if ($canManage && $af_status === 'approved') : ?>
        <section class="af-card" data-af-return-action="receive">
            <div class="af-card-head"><h2><?= e(t('Mark as received')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($actions['receive']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <p class="af-small af-muted"><?= e(t('Tick the lines whose stock goes back to the shelf.')) ?></p>
<?php foreach ($lines as $af_line) : ?>
                    <label class="af-check"><input type="checkbox" name="restock[<?= e((int) $af_line['id']) ?>]" value="1"> <?= e(t('Put back in stock: {name}', ['name' => (string) $af_line['name']])) ?> (<?= e((int) $af_line['qty']) ?>)</label>
<?php endforeach; ?>
                    <label class="af-check"><input type="hidden" name="notify" value="0"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                    <button type="submit" class="af-btn af-btn-primary"><?= e(t('Mark as received')) ?></button>
                </form>
            </div>
        </section>
<?php endif; ?>

<?php if ($canManage && $af_status === 'received') : ?>
        <section class="af-card" data-af-return-action="refund">
            <div class="af-card-head"><h2><?= e(t('Refund the money')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($actions['refund']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <label for="af-rt-amount"><?= e(t('Amount to refund')) ?></label>
                    <input class="af-input" id="af-rt-amount" type="text" name="amount" value="<?= e(Money::decimal((int) $proposal['total'])) ?>" inputmode="decimal" required>
                    <span class="af-small af-muted"><?= e(t('Proposed: {amount}. You can lower it, not raise it. Left to refund in the order: {left}.', ['amount' => money((int) $proposal['total'], $af_currency), 'left' => money((int) $proposal['remaining'], $af_currency)])) ?></span>
<?php if ($gateway) : ?>
                    <label class="af-check"><input type="hidden" name="gateway" value="0"><input type="checkbox" name="gateway" value="1" checked> <?= e(t('Refund through the payment gateway')) ?></label>
<?php else : ?>
                    <p class="af-small af-muted"><?= e(t('This payment method does not refund through the gateway: the refund is only recorded.')) ?></p>
<?php endif; ?>
                    <label class="af-check"><input type="hidden" name="notify" value="0"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                    <button type="submit" class="af-btn af-btn-primary" data-af-confirm="<?= e(t('Refund this money now?')) ?>"><?= e(t('Refund')) ?></button>
                </form>
            </div>
        </section>
<?php endif; ?>

<?php if ($canManage && in_array($af_status, ['requested', 'approved'], true)) : ?>
        <section class="af-card" data-af-return-action="reject">
            <div class="af-card-head"><h2><?= e(t('Reject the return')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($actions['reject']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <label for="af-rt-reject"><?= e(t('Reason for rejecting')) ?></label>
                    <textarea class="af-input" id="af-rt-reject" name="reason" rows="3" maxlength="2000" required></textarea>
                    <span class="af-small af-muted"><?= e(t('The customer receives this reason.')) ?></span>
                    <label class="af-check"><input type="hidden" name="notify" value="0"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                    <button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Reject this return?')) ?>"><?= e(t('Reject')) ?></button>
                </form>
                <hr>
                <form method="post" action="<?= e($actions['cancel']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <label class="af-check"><input type="hidden" name="notify" value="0"><input type="checkbox" name="notify" value="1"> <?= e(t('Email the customer')) ?></label>
                    <button type="submit" class="af-btn" data-af-confirm="<?= e(t('Cancel this return?')) ?>"><?= e(t('Cancel the return')) ?></button>
                </form>
            </div>
        </section>
<?php endif; ?>

<?php if ($canManage) : ?>
        <section class="af-card" data-af-return-action="note">
            <div class="af-card-head"><h2><?= e(t('Internal note')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($actions['note']) ?>" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <textarea class="af-input" name="note" rows="3" maxlength="2000" required aria-label="<?= e(t('Internal note')) ?>"></textarea>
                    <button type="submit" class="af-btn"><?= e(t('Save the note')) ?></button>
                </form>
            </div>
        </section>
<?php endif; ?>
    </div>
</div>
