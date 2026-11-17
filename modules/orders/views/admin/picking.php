<?php
/**
 * AIFEC — lista de preparación: lo que hay que coger del almacén para los pedidos por preparar, sumado por producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{name:string,variant:string,sku:string,qty:int,orders:array<int,string>}> $lines
 * @var int                                                                                      $orders cuántos pedidos hay por preparar
 */

declare(strict_types=1);

?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(tn('{n} order to prepare', '{n} orders to prepare', (int) $orders)) ?></h2></div>
<?php if ($lines === []) : ?>
    <div class="af-card-body"><p class="af-muted"><?= e(t('Nothing to prepare: every paid order has been sent.')) ?></p></div>
<?php else : ?>
    <div class="af-table-wrap"><table class="af-table">
        <thead><tr><th scope="col"><?= e(t('Product')) ?></th><th scope="col"><?= e(t('Reference')) ?></th><th scope="col" class="af-num"><?= e(t('Units')) ?></th><th scope="col"><?= e(t('Orders')) ?></th></tr></thead>
        <tbody>
<?php foreach ($lines as $af_line) : ?>
            <tr>
                <td><strong><?= e($af_line['name']) ?></strong><?php if ($af_line['variant'] !== '') : ?><br><span class="af-small af-muted"><?= e($af_line['variant']) ?></span><?php endif; ?></td>
                <td><?= e($af_line['sku']) ?></td>
                <td class="af-num"><strong><?= e((string) (int) $af_line['qty']) ?></strong></td>
                <td><?php $af_first = true; foreach ($af_line['orders'] as $af_id => $af_number) : ?><?= $af_first ? '' : ', ' ?><a href="<?= e(app()->url()->plain('/admin/orders/' . (int) $af_id)) ?>"><?= e($af_number) ?></a><?php $af_first = false; endforeach; ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
