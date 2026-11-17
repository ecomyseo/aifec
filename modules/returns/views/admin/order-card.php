<?php
/**
 * AIFEC — tarjeta «Devoluciones» de la ficha de un pedido en el panel (hueco admin.order.side).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,string>> $rows cada una: number, type, status, kind, date, refunded (con moneda), url
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<section class="af-card af-no-print" data-af-returns>
    <div class="af-card-head"><h2><?= e(t('Returns')) ?></h2></div>
    <div class="af-card-body">
<?php if ($rows === []) : ?>
        <p class="af-small af-muted"><?= e(t('This order has no returns.')) ?></p>
<?php else : ?>
        <dl class="af-kv">
<?php foreach ($rows as $row) : ?>
            <dt><a href="<?= e($row['url']) ?>"><?= e($row['number']) ?></a> <?= Admin::badge($row['status'], $row['kind']) ?></dt>
            <dd><?= e(\Aifec\Util\Str::trim($row['type'] . ' · ' . $row['date'] . ($row['refunded'] !== '' ? ' · ' . $row['refunded'] : ''), ' ·')) ?></dd>
<?php endforeach; ?>
        </dl>
<?php endif; ?>
    </div>
</section>
