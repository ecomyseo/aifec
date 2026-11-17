<?php
/**
 * AIFEC — tarjeta «Facturas» de la ficha de un pedido en el panel (hueco admin.order.side).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows        cada una: number, credit (bool), date, total (ya con moneda), pdf
 * @var bool                           $canIssue    sin factura todavía y con permiso para emitirla
 * @var string                         $issueUrl    dirección del envío de «emitir»
 * @var string                         $orderNumber número del pedido
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<section class="af-card af-no-print" data-af-invoices>
    <div class="af-card-head"><h2><?= e(t('Invoices')) ?></h2></div>
    <div class="af-card-body">
<?php if ($rows === []) : ?>
        <p class="af-small af-muted"><?= e(t('This order has no invoice yet.')) ?></p>
<?php else : ?>
        <dl class="af-kv">
<?php foreach ($rows as $row) : ?>
            <dt><a href="<?= e((string) $row['pdf']) ?>"><?= e($row['credit'] ? t('Credit note {number}', ['number' => (string) $row['number']]) : t('Invoice {number}', ['number' => (string) $row['number']])) ?></a></dt>
            <dd><?= e(\Aifec\Util\Str::trim((string) $row['date'] . ' · ' . (string) $row['total'], ' ·')) ?></dd>
<?php endforeach; ?>
        </dl>
<?php endif; ?>
<?php if ($canIssue) : ?>
        <form method="post" action="<?= e($issueUrl) ?>">
            <?= Admin::tokenField(app()) ?>
            <input type="hidden" name="order" value="<?= e($orderNumber) ?>">
            <input type="hidden" name="back" value="order">
            <button type="submit" class="af-btn"><?= e(t('Issue the invoice')) ?></button>
        </form>
<?php endif; ?>
    </div>
</section>
