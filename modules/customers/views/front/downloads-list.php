<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las descargas de un pedido: cada fichero con su estado y un botón que da un enlace nuevo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items id, name, item, available, expired, remaining, expires
 * @var string $token ficha de los formularios
 */

declare(strict_types=1);

?>
<div class="af-table-wrap">
    <table class="af-table">
        <thead><tr>
            <th scope="col"><?= e(t('File')) ?></th>
            <th scope="col"><?= e(t('Product')) ?></th>
            <th scope="col"><?= e(t('Status')) ?></th>
            <th scope="col"><span class="af-sr"><?= e(t('Download')) ?></span></th>
        </tr></thead>
        <tbody>
<?php foreach ($items as $af_item) : ?>
            <tr>
                <td><?= e($af_item['name']) ?></td>
                <td><?= e($af_item['item']) ?></td>
                <td>
<?php if ($af_item['expired']) : ?>
                    <?= e(t('The download period is over.')) ?>
<?php elseif ($af_item['remaining'] === 0) : ?>
                    <?= e(t('No downloads left.')) ?>
<?php else : ?>
                    <?= $af_item['remaining'] === null ? e(t('Available')) : e(tn('{n} download left', '{n} downloads left', (int) $af_item['remaining'])) ?>
                    <?= $af_item['expires'] !== '' ? '<br><span class="af-muted af-small">' . e(t('Until {date}', ['date' => $af_item['expires']])) . '</span>' : '' ?>
<?php endif; ?>
                </td>
                <td>
<?php if ($af_item['available']) : ?>
                    <form method="post" action="<?= e(route('account.downloads.link', ['id' => (int) $af_item['id']])) ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Download')) ?></button>
                    </form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="af-muted af-small"><?= e(t('Each time you press Download you get a new link, and the previous one stops working.')) ?></p>
