<?php
/**
 * AIFEC — ficha de un pedido del panel: líneas, desglose, cobros, historial y, a la derecha, el estado con sus acciones,
 * el cliente, las direcciones, el envío, el origen y la nota del cliente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $order el pedido completo (Orders::find)
 * @var array<int,array<string,mixed>> $lines líneas de producto, cada una con su image (dirección o cadena vacía)
 * @var array<string,array<int,array<string,mixed>>> $extras discounts, shipping y fees
 * @var array<int,array<string,mixed>> $refundsByPayment devolución de cada movimiento de dinero
 * @var array<string,string> $names código del estado => nombre
 * @var array<int,array{code:string,name:string}> $choices estados que se pueden elegir
 * @var array<int,string> $users identificador de persona => nombre
 * @var bool $canEdit puede cambiar estados, seguimiento y cobros
 * @var bool $canNote puede añadir notas
 * @var bool $canRefund puede devolver dinero
 * @var bool $closed el pedido está cancelado o devuelto del todo
 * @var bool $canMarkPaid se puede dar por cobrado a mano
 * @var bool $canCancel se puede cancelar
 * @var bool $canRefundNow hay algo cobrado que devolver
 * @var int $refundable lo que queda por devolver, en céntimos
 * @var bool $gatewayRefund la forma de pago devuelve por la pasarela
 * @var array<string,mixed> $customer el cliente: name, email, phone, guest, url, previous, previous_url, spent
 * @var string $thanksUrl dirección de «ver como el cliente» (vacía si no existe)
 * @var array<string,string> $urls dirección de cada acción
 * @var string $stateMail plantilla de correo del estado actual (vacía si no manda ninguno)
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;
use Aifec\Modules\Orders\Admin\PaymentsAdmin;

$app = app();
$currency = (string) $order['currency'];
$tz = $app->site()->timezone();
$money = static function (int $cents) use ($currency): string {
    return money($cents, $currency);
};
$when = static function (?string $sql) use ($app, $tz): string {
    $time = Clock::parse($sql);

    return $time > 0 ? $app->i18n()->date($time, 'medium', $tz) : '';
};
$stateLabel = static function (?string $code) use ($names): string {
    $code = (string) $code;

    return t(isset($names[$code]) ? $names[$code] : $code);
};
$stateColor = (string) (isset($order['state']['color']) ? $order['state']['color'] : '');
$field = static function (array $data, string $key): string {
    return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
};
// Con nombres, no con códigos: «Valencia» y «España», no «46» y «ES».
$address = static function (array $a): array {
    return \Aifec\Geo\Address::lines($a, app()->lang(), ['phone' => false]);
};
$billing = is_array($order['billing']) ? $order['billing'] : [];
$shipping = is_array($order['shipping']) ? $order['shipping'] : [];
$needsShipping = (string) $order['fulfillment_status'] !== 'not_needed';
$sources = ['web' => 'Web shop', 'assistant' => 'AI assistant', 'api' => 'API', 'mcp' => 'MCP', 'ucp' => 'AI platform (UCP)', 'admin' => 'Admin panel', 'import' => 'Import'];
$attribution = is_array($order['attribution']) ? $order['attribution'] : [];
$kindTitles = ['payment' => 'Payment', 'shipment' => 'Shipment', 'refund' => 'Refund', 'mail' => 'Email', 'system' => 'System'];

?><style>
.aif-form > * + * { margin-top: var(--s2); }
.aif-form .af-label { display: block; padding-top: 0; margin-bottom: var(--s1); }
.aif-act { border-top: 1px solid var(--bg); padding: var(--s3) var(--s4); }
.aif-act:first-of-type { border-top: 0; }
.aif-act > summary { cursor: pointer; font-weight: 600; }
.aif-act[open] > summary { margin-bottom: var(--s3); }
.aif-sum { width: 100%; border-collapse: collapse; }
.aif-sum td { padding: var(--s1) var(--s4); }
.aif-sum td:last-child { text-align: right; white-space: nowrap; }
.aif-sum .aif-total td { border-top: 2px solid var(--muted); font-weight: 700; font-size: var(--f3); padding-top: var(--s2); }
.aif-timeline { list-style: none; margin: 0; padding: var(--s2) var(--s4) var(--s4) var(--s5); }
.aif-tl-item { position: relative; padding: var(--s2) 0 var(--s2) var(--s4); border-left: 2px solid var(--bg); }
.aif-tl-item::before { content: ''; position: absolute; left: -6px; top: .95rem; width: 10px; height: 10px; border-radius: 50%; background: var(--muted); }
.aif-tl-public::before { background: var(--muted); }
.aif-tl-private::before { background: var(--accent); }
.aif-tl-text { margin-top: var(--s1); overflow-wrap: anywhere; }
.aif-tl-private .aif-tl-text { background: var(--bg); border-radius: var(--radius-sm); padding: var(--s1) var(--s2); }
.aif-tl-public .aif-tl-text { background: var(--bg); border-radius: var(--radius-sm); padding: var(--s1) var(--s2); }
.aif-addr { margin: 0 0 var(--s3); }
.aif-addr:last-child { margin-bottom: 0; }
.aif-addr address { font-style: normal; overflow-wrap: anywhere; }
.aif-lines td:first-child { width: 56px; }
@media print {
    .af-side, .af-top, .af-head-actions, .af-crumbs, .af-no-print, [data-af-flash] { display: none !important; }
    .af-shell, .af-grid { display: block !important; }
    .af-main { display: block !important; }
    .af-content { max-width: none; padding: 0; }
    .af-card { box-shadow: none; break-inside: avoid; }
}
</style>
<div class="af-grid">
<div class="af-col-8 af-stack">

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Items')) ?></h2><?= !empty($order['test']) ? Admin::badge(t('Test order'), 'warn') : '' ?></div>
        <div class="af-table-wrap"><table class="af-table aif-lines">
            <thead><tr>
                <th><span class="af-sr"><?= e(t('Image')) ?></span></th>
                <th><?= e(t('Product')) ?></th>
                <th class="af-num"><?= e(t('Qty')) ?></th>
                <th class="af-num"><?= e(t('Price')) ?></th>
                <th class="af-num"><?= e(t('Total')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $line) : ?>
                <tr>
                    <td><?php if ((string) $line['image'] !== '') : ?><img class="af-thumb" src="<?= e($line['image']) ?>" alt="" loading="lazy" width="44" height="44" style="width:44px;height:44px"><?php endif; ?></td>
                    <td>
                        <strong><?= e((string) $line['name']) ?></strong>
                        <?php if ((string) $line['variant_label'] !== '') : ?><br><span class="af-small af-muted"><?= e((string) $line['variant_label']) ?></span><?php endif; ?>
                        <?php if ((string) $line['sku'] !== '') : ?><br><span class="af-small af-muted af-mono"><?= e((string) $line['sku']) ?></span><?php endif; ?>
                        <?php if ((int) $line['refunded_qty'] > 0) : ?><br><?= Admin::badge(tn('{n} unit refunded', '{n} units refunded', (int) $line['refunded_qty']), 'warn') ?><?php endif; ?>
                    </td>
                    <td class="af-num"><?= (int) $line['qty'] ?></td>
                    <td class="af-num af-nowrap"><?= e($money((int) $line['unit_price'])) ?></td>
                    <td class="af-num af-nowrap"><?= e($money((int) $line['unit_price'] * (int) $line['qty'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <table class="aif-sum">
            <tr><td><?= e(t('Subtotal')) ?></td><td><?= e($money((int) $order['items_total'])) ?></td></tr>
            <?php foreach ($extras['discounts'] as $discount) : ?>
                <tr><td><?= e(t('Discount: {name}', ['name' => $discount['name']])) ?><?= $discount['code'] !== '' && $discount['code'] !== $discount['name'] ? ' <span class="af-mono af-small af-muted">' . e($discount['code']) . '</span>' : '' ?></td><td>-<?= e($money((int) $discount['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($extras['shipping'] as $row) : ?>
                <tr><td><?= e(t('Shipping: {name}', ['name' => $row['name']])) ?></td><td><?= e($money((int) $row['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($extras['fees'] as $row) : ?>
                <tr><td><?= e($row['name']) ?><?= $row['source'] !== '' ? ' <span class="af-small af-muted">(' . e(t('from {source}', ['source' => $row['source']])) . ')</span>' : '' ?></td><td><?= e($money((int) $row['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($order['taxes'] as $tax) : ?>
                <?php if ((int) $tax['amount'] > 0) : ?>
                    <tr class="af-muted"><td><?= e(!empty($order['prices_include_tax']) ? t('Includes {name}', ['name' => $tax['name']]) : (string) $tax['name']) ?></td><td><?= e($money((int) $tax['amount'])) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <tr class="aif-total"><td><?= e(t('Total')) ?></td><td><?= e($money((int) $order['total'])) ?></td></tr>
            <tr><td><?= e(t('Paid')) ?></td><td><?= e($money((int) $order['paid_total'])) ?></td></tr>
            <?php if ((int) $order['refunded_total'] > 0) : ?>
                <tr><td><?= e(t('Refunded')) ?></td><td>-<?= e($money((int) $order['refunded_total'])) ?></td></tr>
            <?php endif; ?>
        </table>
        <p class="af-small af-muted" style="padding: 0 var(--s4) var(--s3);margin:0"><?= e(!empty($order['prices_include_tax']) ? t('The prices include tax.') : t('The prices do not include tax.')) ?></p>
    </section>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Payments and refunds')) ?></h2></div>
        <?php if ($order['payments'] === []) : ?>
            <div class="af-empty"><p><?= e(t('No payment has been recorded yet.')) ?></p></div>
        <?php else : ?>
            <div class="af-table-wrap"><table class="af-table">
                <thead><tr>
                    <th><?= e(t('Date')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Method')) ?></th><th><?= e(t('Reference')) ?></th>
                    <th class="af-num"><?= e(t('Amount')) ?></th><th><?= e(t('Status')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($order['payments'] as $payment) : ?>
                    <?php
                    $kind = (string) $payment['kind'];
                    $pStatus = (string) $payment['status'];
                    $refund = isset($refundsByPayment[(int) $payment['id']]) ? $refundsByPayment[(int) $payment['id']] : null;
                    ?>
                    <tr>
                        <td class="af-nowrap"><?= e($when((string) $payment['created_at'])) ?></td>
                        <td><?= e($kind === 'refund' ? t('Refund') : ($kind === 'authorization' ? t('Authorization') : t('Payment'))) ?><?= !empty($payment['test']) ? ' ' . Admin::badge(t('Test'), 'warn') : '' ?></td>
                        <td><?= e(PaymentsAdmin::labelFor($app, (string) $payment['method'])) ?></td>
                        <td class="af-mono af-small"><?= e((string) $payment['reference']) ?><?php if ($refund !== null && (string) $refund['reason'] !== '') : ?><br><span class="af-muted"><?= e((string) $refund['reason']) ?></span><?php endif; ?></td>
                        <td class="af-num af-nowrap"><?= $kind === 'refund' ? '-' : '' ?><?= e($money((int) $payment['amount'])) ?></td>
                        <td><?= Admin::badge($pStatus === 'ok' ? t('Done') : ($pStatus === 'failed' ? t('Failed') : t('Pending')), $pStatus === 'ok' ? 'ok' : ($pStatus === 'failed' ? 'bad' : 'warn')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('History')) ?></h2></div>
        <ol class="aif-timeline">
        <?php foreach ($order['history'] as $entry) : ?>
            <?php
            $kind = (string) $entry['kind'];
            $isNote = $kind === 'note';
            $note = (string) $entry['note'];
            if ($kind === 'status') {
                $title = (string) $entry['from_value'] !== ''
                    ? t('Status: {from} → {to}', ['from' => $stateLabel($entry['from_value']), 'to' => $stateLabel($entry['to_value'])])
                    : t('Status: {to}', ['to' => $stateLabel($entry['to_value'])]);
            } elseif ($isNote) {
                $title = !empty($entry['is_public']) ? t('Note for the customer') : t('Private note');
            } elseif ($kind === 'refund' && ctype_digit((string) $entry['to_value'])) {
                $title = t('Refund of {amount}', ['amount' => $money((int) $entry['to_value'])]);
            } elseif ($kind === 'system' && (string) $entry['to_value'] === 'thanks_shown') {
                $title = t('The customer saw the confirmation page');
            } elseif ($kind === 'system' && $note === '') {
                // Una marca interna sin texto no le dice nada a quien mira el pedido.
                continue;
            } else {
                $title = isset($kindTitles[$kind]) ? t($kindTitles[$kind]) : $kind;
            }
            if ($entry['user_id'] !== null) {
                $who = isset($users[(int) $entry['user_id']]) ? $users[(int) $entry['user_id']] : t('Team member');
            } else {
                $who = t('System');
            }
            ?>
            <li class="aif-tl-item<?= $isNote ? (!empty($entry['is_public']) ? ' aif-tl-public' : ' aif-tl-private') : '' ?>">
                <div><strong><?= e($title) ?></strong> <span class="af-small af-muted">· <?= e($who) ?> · <?= e($when((string) $entry['created_at'])) ?></span></div>
                <?php if ($note !== '') : ?>
                    <div class="aif-tl-text"><?= nl2br(e($isNote ? $note : t($note)), false) ?></div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
        <?php if ($canNote) : ?>
        <form method="post" action="<?= e($urls['note']) ?>" class="aif-form af-no-print" style="padding: 0 var(--s4) var(--s4)">
            <?= Admin::tokenField($app) ?>
            <label class="af-label" for="aif-note"><?= e(t('Add a note')) ?></label>
            <textarea class="af-textarea" id="aif-note" name="note" rows="2" maxlength="4000" required></textarea>
            <select class="af-select" name="visibility" aria-label="<?= e(t('Who can see the note')) ?>" style="max-width:340px">
                <option value="private"><?= e(t('Private: only the team')) ?></option>
                <option value="public"><?= e(t('Public: the customer sees it')) ?></option>
            </select>
            <div><button type="submit" class="af-btn"><?= Admin::icon('plus') ?><?= e(t('Save note')) ?></button></div>
        </form>
        <?php endif; ?>
    </section>
</div>

<div class="af-col-4 af-stack">

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Status')) ?></h2><?= Admin::badge($stateLabel((string) $order['status']), $stateColor) ?></div>
        <div class="af-card-body">
            <dl class="af-kv">
                <dt><?= e(t('Payment')) ?></dt><dd><?= \Aifec\Modules\Orders\Admin\OrdersAdmin::paymentBadge((string) $order['payment_status']) ?></dd>
                <dt><?= e(t('Shipping')) ?></dt><dd><?= \Aifec\Modules\Orders\Admin\OrdersAdmin::fulfillmentBadge((string) $order['fulfillment_status']) ?></dd>
                <dt><?= e(t('Placed')) ?></dt><dd><?= e($when((string) $order['placed_at'])) ?></dd>
                <dt><?= e(t('Payment method')) ?></dt><dd><?= e(PaymentsAdmin::labelFor($app, (string) $order['payment_method'], (string) $order['payment_title'])) ?></dd>
            </dl>
        </div>
        <?php if ($canEdit && !$closed) : ?>
        <div class="af-card-body af-no-print" style="border-top:1px solid var(--bg)">
            <form method="post" action="<?= e($urls['status']) ?>" class="aif-form" data-af-confirm="<?= e(t('Change the status of this order?')) ?>">
                <?= Admin::tokenField($app) ?>
                <label class="af-label" for="aif-status"><?= e(t('Change the status')) ?></label>
                <select class="af-select" id="aif-status" name="status">
                    <?php foreach ($choices as $choice) : ?>
                        <option value="<?= e($choice['code']) ?>"><?= e(t($choice['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea class="af-textarea" name="note" rows="2" maxlength="4000" placeholder="<?= e(t('Note for the customer (optional)')) ?>" aria-label="<?= e(t('Note for the customer (optional)')) ?>"></textarea>
                <label class="af-check"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                <div><button type="submit" class="af-btn af-btn-primary"><?= e(t('Change status')) ?></button></div>
            </form>
            <?php if ($stateMail !== '') : ?>
            <form method="post" action="<?= e($urls['resend']) ?>" class="aif-form" data-af-confirm="<?= e(t('Send the email of the current status again?')) ?>" style="margin-top: var(--s3)">
                <?= Admin::tokenField($app) ?>
                <button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('mail') ?><?= e(t('Send the status email again')) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Customer')) ?></h2><?= !empty($customer['guest']) ? Admin::badge(t('Guest'), 'info') : '' ?></div>
        <div class="af-card-body">
            <dl class="af-kv">
                <dt><?= e(t('Name')) ?></dt><dd><?php if ($customer['url'] !== '' && $customer['name'] !== '') : ?><a href="<?= e($customer['url']) ?>"><?= e($customer['name']) ?></a><?php else : ?><?= e($customer['name'] !== '' ? $customer['name'] : '—') ?><?php endif; ?></dd>
                <dt><?= e(t('Email')) ?></dt><dd><a href="mailto:<?= e($customer['email']) ?>"><?= e($customer['email']) ?></a></dd>
                <?php if ($customer['phone'] !== '') : ?><dt><?= e(t('Phone')) ?></dt><dd><?= e($customer['phone']) ?></dd><?php endif; ?>
                <dt><?= e(t('Other orders')) ?></dt><dd><?php if ((int) $customer['previous'] > 0) : ?><a href="<?= e($customer['previous_url']) ?>"><?= e((string) (int) $customer['previous']) ?></a><?php else : ?>0<?php endif; ?><?php if (!empty($customer['previous_test'])) : ?> <span class="af-muted">· <?= e(tn('{n} test order, which does not count', '{n} test orders, which do not count', (int) $customer['previous_test'])) ?></span><?php endif; ?></dd>
                <dt><?= e(t('Total spent')) ?></dt><dd><?= e(money((int) $customer['spent'], $currency)) ?></dd>
            </dl>
            <?php if ($customer['url'] !== '') : ?><p style="margin: var(--s3) 0 0" class="af-no-print"><a class="af-btn af-btn-sm" href="<?= e($customer['url']) ?>"><?= Admin::icon('user') ?><?= e(t('Customer record')) ?></a></p><?php endif; ?>
        </div>
    </section>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Addresses')) ?></h2></div>
        <div class="af-card-body">
            <?php if ($needsShipping) : ?>
                <?php $shipLines = $address($shipping !== [] ? $shipping : $billing); ?>
                <div class="aif-addr">
                    <strong><?= e(t('Shipping address')) ?></strong><?= $shipping === [] ? ' <span class="af-small af-muted">(' . e(t('same as billing')) . ')</span>' : '' ?>
                    <address><?= nl2br(e(implode("\n", $shipLines)), false) ?></address>
                    <button type="button" class="af-btn af-btn-sm af-no-print" data-af-copy="<?= e(implode("\n", $shipLines)) ?>" data-af-copied="<?= e(t('Address copied')) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
                </div>
            <?php endif; ?>
            <?php $billLines = $address($billing); ?>
            <div class="aif-addr">
                <strong><?= e(t('Billing address')) ?></strong>
                <address><?= nl2br(e(implode("\n", $billLines)), false) ?></address>
                <?php if ($field($billing, 'vat_id') !== '') : ?><div class="af-small af-muted"><?= e(t('Tax ID')) ?>: <span class="af-mono"><?= e($field($billing, 'vat_id')) ?></span></div><?php endif; ?>
                <button type="button" class="af-btn af-btn-sm af-no-print" data-af-copy="<?= e(implode("\n", $billLines)) ?>" data-af-copied="<?= e(t('Address copied')) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
            </div>
        </div>
    </section>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Shipping')) ?></h2></div>
        <div class="af-card-body">
            <dl class="af-kv">
                <dt><?= e(t('Carrier')) ?></dt><dd><?= e((string) $order['carrier_title'] !== '' ? (string) $order['carrier_title'] : '—') ?></dd>
                <dt><?= e(t('Tracking code')) ?></dt><dd class="af-mono"><?= e((string) $order['tracking_code'] !== '' ? (string) $order['tracking_code'] : '—') ?></dd>
                <dt><?= e(t('Shipped')) ?></dt><dd><?= e($when(isset($order['shipped_at']) ? (string) $order['shipped_at'] : null) !== '' ? $when((string) $order['shipped_at']) : '—') ?></dd>
            </dl>
        </div>
        <?php if ($canEdit && !$closed && $needsShipping) : ?>
        <div class="af-card-body af-no-print" style="border-top:1px solid var(--bg)">
            <form method="post" action="<?= e($urls['tracking']) ?>" class="aif-form" data-af-confirm="<?= e(t('Save the shipping details?')) ?>">
                <?= Admin::tokenField($app) ?>
                <label class="af-label" for="aif-tracking"><?= e(t('Tracking code')) ?></label>
                <input class="af-input" id="aif-tracking" type="text" name="tracking_code" maxlength="80" value="<?= e((string) $order['tracking_code']) ?>">
                <label class="af-check"><input type="checkbox" name="ship" value="1"> <?= e(t('Mark as shipped')) ?></label>
                <label class="af-check"><input type="checkbox" name="notify" value="1" checked> <?= e(t('Email the customer')) ?></label>
                <div><button type="submit" class="af-btn"><?= Admin::icon('truck') ?><?= e(t('Save')) ?></button></div>
            </form>
        </div>
        <?php endif; ?>
    </section>

    <?php // Hueco para otros módulos (las facturas del pedido): cada oyente devuelve su tarjeta, ya escapada. ?>
    <?= hook('admin.order.side', $order) ?>

    <?php if ($canEdit || $canRefund) : ?>
    <section class="af-card af-no-print">
        <div class="af-card-head"><h2><?= e(t('Payment actions')) ?></h2></div>
        <?php if ($canEdit && $canMarkPaid) : ?>
        <details class="aif-act">
            <summary><?= e(t('Mark as paid')) ?></summary>
            <form method="post" action="<?= e($urls['paid']) ?>" class="aif-form" data-af-confirm="<?= e(t('Mark this order as paid?')) ?>">
                <?= Admin::tokenField($app) ?>
                <label class="af-label" for="aif-ref"><?= e(t('Payment reference')) ?></label>
                <input class="af-input" id="aif-ref" type="text" name="reference" maxlength="120" required>
                <p class="af-small af-muted" style="margin:0"><?= e(t('For payments that do not go through a gateway, such as a bank transfer. The full total is recorded.')) ?></p>
                <div><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Mark as paid')) ?></button></div>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($canRefund && $canRefundNow) : ?>
        <details class="aif-act">
            <summary><?= e(t('Refund')) ?></summary>
            <form method="post" action="<?= e($urls['refund']) ?>" class="aif-form" data-af-confirm="<?= e(t('Refund this money? It cannot be undone.')) ?>">
                <?= Admin::tokenField($app) ?>
                <p class="af-small af-muted" style="margin:0"><?= e(t('Left to refund: {amount}', ['amount' => $money($refundable)])) ?></p>
                <label class="af-label" for="aif-amount"><?= e(t('Amount')) ?></label>
                <input class="af-input af-input-sm" id="aif-amount" type="text" inputmode="decimal" name="amount" placeholder="0,00">
                <?php $any = false; ?>
                <?php foreach ($lines as $line) : ?>
                    <?php $left = (int) $line['qty'] - (int) $line['refunded_qty']; ?>
                    <?php if ($left > 0) : ?>
                        <?php if (!$any) : ?><p class="af-small af-muted" style="margin:0"><?= e(t('Or choose the units to refund (the amount is worked out from the lines):')) ?></p><?php $any = true; ?><?php endif; ?>
                        <label class="af-small" style="display:flex;gap: var(--s2);align-items:center"><input class="af-input af-input-sm" style="width:72px" type="number" name="items[<?= (int) $line['id'] ?>]" min="0" max="<?= $left ?>" value="0"> <span><?= e((string) $line['name']) ?> <span class="af-muted">/ <?= $left ?></span></span></label>
                    <?php endif; ?>
                <?php endforeach; ?>
                <label class="af-label" for="aif-reason"><?= e(t('Reason')) ?></label>
                <input class="af-input" id="aif-reason" type="text" name="reason" maxlength="255">
                <?php if ($gatewayRefund) : ?><label class="af-check"><input type="checkbox" name="gateway" value="1" checked> <?= e(t('Refund through the payment gateway')) ?></label><?php endif; ?>
                <label class="af-check"><input type="checkbox" name="restock" value="1"> <?= e(t('Put the units back in stock')) ?></label>
                <div><button type="submit" class="af-btn af-btn-danger"><?= e(t('Refund')) ?></button></div>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($canEdit && $canCancel) : ?>
        <details class="aif-act">
            <summary><?= e(t('Cancel the order')) ?></summary>
            <form method="post" action="<?= e($urls['cancel']) ?>" class="aif-form" data-af-confirm="<?= e(t('Cancel this order? It cannot be undone.')) ?>">
                <?= Admin::tokenField($app) ?>
                <label class="af-label" for="aif-cancel"><?= e(t('Reason')) ?></label>
                <input class="af-input" id="aif-cancel" type="text" name="reason" maxlength="255">
                <label class="af-check"><input type="checkbox" name="restock" value="1" checked> <?= e(t('Put the units back in stock')) ?></label>
                <div><button type="submit" class="af-btn af-btn-danger"><?= e(t('Cancel the order')) ?></button></div>
            </form>
        </details>
        <?php endif; ?>
        <?php if (!($canEdit && $canMarkPaid) && !($canRefund && $canRefundNow) && !($canEdit && $canCancel)) : ?>
            <div class="af-card-body"><p class="af-muted" style="margin:0"><?= e(t('There is nothing to do with the payment of this order.')) ?></p></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Origin')) ?></h2></div>
        <div class="af-card-body">
            <dl class="af-kv">
                <dt><?= e(t('Channel')) ?></dt><dd><?= e(t(isset($sources[(string) $order['source']]) ? $sources[(string) $order['source']] : (string) $order['source'])) ?></dd>
                <?php if ($field($attribution, 'source') !== '') : ?>
                    <dt><?= e(t('Came from')) ?></dt><dd><?= Admin::badge(t('Came from {source}', ['source' => $field($attribution, 'source')]), 'info') ?></dd>
                    <?php if ($field($attribution, 'landing') !== '') : ?><dt><?= e(t('Landing page')) ?></dt><dd class="af-mono af-small"><?= e($field($attribution, 'landing')) ?></dd><?php endif; ?>
                    <?php if ($field($attribution, 'at') !== '') : ?><dt><?= e(t('Visit')) ?></dt><dd><?= e($when($field($attribution, 'at')) !== '' ? $when($field($attribution, 'at')) : $field($attribution, 'at')) ?></dd><?php endif; ?>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <?php if ((string) $order['customer_note'] !== '') : ?>
    <section class="af-card">
        <div class="af-card-head"><h2><?= e(t('Note from the customer')) ?></h2></div>
        <div class="af-card-body"><?= nl2br(e((string) $order['customer_note']), false) ?></div>
    </section>
    <?php endif; ?>
</div>
</div>
