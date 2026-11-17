<?php
/**
 * AIFEC — árbol de categorías del panel: buscador y una fila por categoría, sangrada bajo su madre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows    id, depth, name, active, in_menu, product_count, edit_url, delete_url
 * @var string $q        texto buscado
 * @var bool   $canEdit  puede crear y borrar
 * @var string $listUrl  dirección de esta pantalla
 * @var string $newUrl   dirección del alta
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><div class="af-card">
    <form class="af-toolbar" method="get" action="<?= e($listUrl) ?>" role="search">
        <input class="af-input af-search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Search…')) ?>" aria-label="<?= e(t('Search')) ?>">
        <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Filter')) ?></button>
        <?php if ($q !== '') : ?><a class="af-btn af-btn-ghost" href="<?= e($listUrl) ?>"><?= e(t('Clear')) ?></a><?php endif; ?>
    </form>
<?php if ($rows === []) : ?>
    <div class="af-empty">
        <h3><?= e($q !== '' ? t('No results') : t('Nothing here yet')) ?></h3>
        <?php if ($q === '' && $canEdit) : ?><a class="af-btn af-btn-primary" href="<?= e($newUrl) ?>"><?= Admin::icon('plus') ?><?= e(t('New')) ?></a><?php endif; ?>
    </div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th scope="col"><?= e(t('Name')) ?></th>
                <th scope="col" class="af-num af-hide-sm"><?= e(t('Products')) ?></th>
                <th scope="col" class="af-hide-sm"><?= e(t('Show in the menu')) ?></th>
                <th scope="col"><?= e(t('Active')) ?></th>
                <?php if ($canEdit) : ?><th class="af-cell-actions"><span class="af-sr"><?= e(t('Actions')) ?></span></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td>
                        <?php if ((int) $row['depth'] > 0 && $q === '') : ?><span class="af-muted" aria-hidden="true"><?= e(str_repeat('— ', (int) $row['depth'])) ?></span><?php endif; ?>
                        <?php if ($canEdit) : ?>
                            <a href="<?= e($row['edit_url']) ?>"><strong><?= e($row['name'] !== '' ? $row['name'] : '#' . $row['id']) ?></strong></a>
                        <?php else : ?>
                            <strong><?= e($row['name'] !== '' ? $row['name'] : '#' . $row['id']) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td class="af-num af-hide-sm"><?= e((string) $row['product_count']) ?></td>
                    <td class="af-hide-sm"><?= $row['in_menu'] ? Admin::badge(t('Yes'), 'ok') : '<span class="af-muted">' . e(t('No')) . '</span>' ?></td>
                    <td><?= $row['active'] ? Admin::badge(t('Yes'), 'ok') : Admin::badge(t('No'), 'warn') ?></td>
                    <?php if ($canEdit) : ?>
                    <td class="af-cell-actions">
                        <form method="post" action="<?= e($row['delete_url']) ?>" class="af-inline">
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
    <div class="af-pagination"><span class="af-muted af-small"><?= e(tn('{n} category', '{n} categories', count($rows))) ?></span></div>
<?php endif; ?>
</div>
