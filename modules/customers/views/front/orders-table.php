<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Tabla de pedidos de la zona de cliente (el listado y los últimos pedidos de la portada).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows number, placed, status_name, status_class, total, url
 */

declare(strict_types=1);

?>
<div class="af-table-wrap">
    <table class="af-table">
        <thead><tr>
            <th scope="col"><?= e(t('Order')) ?></th>
            <th scope="col"><?= e(t('Date')) ?></th>
            <th scope="col"><?= e(t('Status')) ?></th>
            <th scope="col"><?= e(t('Total')) ?></th>
            <th scope="col"><span class="af-sr"><?= e(t('Actions')) ?></span></th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $af_row) : ?>
            <tr>
                <td><a href="<?= e($af_row['url']) ?>"><?= e($af_row['number']) ?></a></td>
                <td><?= e($af_row['placed']) ?></td>
                <td><span class="<?= e($af_row['status_class']) ?>"><?= e($af_row['status_name']) ?></span></td>
                <td><?= e($af_row['total']) ?></td>
                <td><?php if ((string) $af_row['return_url'] !== '') : ?><a href="<?= e($af_row['return_url']) ?>" data-af-order-return><?= e(t('Return or withdraw')) ?></a><?php endif; ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
