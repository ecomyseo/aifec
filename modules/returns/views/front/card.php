<?php
/**
 * AIFEC — tarjeta «Devoluciones» de la tienda: el estado de las devoluciones de un pedido (con el botón de pedir una) o las últimas
 * del cliente y los pedidos de los que puede pedir una.
 *
 * Huecos: order.thanks y account.dashboard.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,string>> $rows   cada una: number, type, status, class, text, date
 * @var string                          $url    dirección de la página de devolución del pedido ('' si no hay)
 * @var bool                            $canAsk se puede pedir una devolución ahora
 * @var array<int,array<string,string>> $orders pedidos de los que se puede pedir una: number, url
 * @var string                          $notice por qué no se puede pedir ninguna ('' si se puede o no hay nada que decir)
 */

declare(strict_types=1);

?>
<section class="af-card af-stack" aria-labelledby="af-returns-card-h" data-af-returns-card>
    <h2 id="af-returns-card-h"><?= e(t('Returns')) ?></h2>
<?php if ($rows !== []) : ?>
    <ul class="af-stack">
<?php foreach ($rows as $af_row) : ?>
        <li>
            <strong><?= e($af_row['number']) ?></strong>
            <span class="<?= e($af_row['class']) ?>"><?= e($af_row['status']) ?></span>
            <span class="af-muted af-small"><?= e($af_row['type']) ?> · <?= e($af_row['date']) ?></span><br>
            <span class="af-small"><?= e($af_row['text']) ?></span>
        </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if (!empty($notice)) : ?>
    <p class="af-small" data-af-returns-notice><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($url !== '') : ?>
    <p><a class="af-btn<?= $canAsk ? '' : ' af-btn-ghost' ?>" href="<?= e($url) ?>"><?= e($canAsk ? (!empty($unshipped) ? t('Withdraw from the purchase') : t('Return products')) : t('See my returns')) ?></a></p>
<?php endif; ?>
<?php if ($orders !== []) : ?>
    <p class="af-small af-muted"><?= e(t('You can ask for a return of these orders:')) ?></p>
    <ul class="af-stack">
<?php foreach ($orders as $af_order) : ?>
        <li><a href="<?= e($af_order['url']) ?>"><?= e(t('Return products of order {number}', ['number' => $af_order['number']])) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
</section>
