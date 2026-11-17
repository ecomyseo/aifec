<?php
/**
 * AIFEC — pantalla de direcciones de un cliente: lista y formulario de alta o cambio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows    cada fila: address, edit_url, delete_url
 * @var string $form    formulario ya pintado (HTML de confianza)
 * @var bool   $canEdit puede cambiar direcciones
 * @var bool   $editing se está cambiando una dirección
 * @var string $newUrl  dirección de la pantalla sin dirección elegida
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><div class="af-card">
<?php if ($rows === []) : ?>
    <div class="af-empty"><h3><?= e(t('No addresses yet.')) ?></h3></div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th><?= e(t('Name')) ?></th>
                <th><?= e(t('Address')) ?></th>
                <th class="af-hide-sm"><?= e(t('Phone')) ?></th>
                <th><?= e(t('Default')) ?></th>
                <?php if ($canEdit) : ?><th class="af-cell-actions"><span class="af-sr"><?= e(t('Actions')) ?></span></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $item) : ?>
                <?php $a = $item['address']; ?>
                <tr>
                    <td><strong><?= e(trim((string) $a['first_name'] . ' ' . (string) $a['last_name'])) ?></strong><?= (string) $a['company'] !== '' ? '<br><span class="af-small af-muted">' . e((string) $a['company']) . '</span>' : '' ?></td>
                    <td><?= e((string) $a['line1']) ?><?= (string) $a['line2'] !== '' ? ', ' . e((string) $a['line2']) : '' ?><br>
                        <span class="af-small af-muted"><?= e((string) $a['postcode'] . ' ' . (string) $a['city'] . ' (' . (string) $a['country'] . ')') ?></span></td>
                    <td class="af-hide-sm"><?= e((string) $a['phone']) ?></td>
                    <td>
                        <?= (int) $a['default_shipping'] === 1 ? Admin::badge(t('Shipping'), 'info') : '' ?>
                        <?= (int) $a['default_billing'] === 1 ? Admin::badge(t('Billing'), 'info') : '' ?>
                    </td>
                    <?php if ($canEdit) : ?>
                    <td class="af-cell-actions">
                        <a class="af-btn af-btn-sm af-btn-ghost" href="<?= e($item['edit_url']) ?>"><?= Admin::icon('edit') ?><?= e(t('Edit')) ?></a>
                        <form method="post" action="<?= e($item['delete_url']) ?>" class="af-inline">
                            <?= Admin::tokenField(app()) ?>
                            <button type="submit" class="af-btn af-btn-sm af-btn-ghost af-link-danger" data-af-confirm="<?= e(t('Delete this? It cannot be undone.')) ?>"><?= Admin::icon('trash') ?><?= e(t('Delete')) ?></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<?php if ($canEdit) : ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e($editing ? t('Edit address') : t('New address')) ?></h2></div>
    <div class="af-card-body"><?= $form ?></div>
</div>
<?php endif; ?>
