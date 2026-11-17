<?php
/**
 * AIFEC — páginas legales de partida: qué páginas hay, cuáles faltan y el botón que crea las que faltan como borradores.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,array{title:string,id:int,status:string}> $pages
 * @var string[]                                               $missing  datos de la tienda que faltan (en inglés)
 * @var bool                                                   $canEdit
 * @var string                                                 $createUrl
 * @var string                                                 $settingsUrl
 */

use Aifec\Admin\Admin;

$missingPages = 0;
foreach ($pages as $page) {
    if ($page['id'] === 0) {
        ++$missingPages;
    }
}
?>
<div class="af-alert af-alert-warn" role="note">
    <?= e(t('These pages are only a starting point. Review them, complete them and have them checked before you publish them.')) ?>
</div>
<?php if ($missing !== []) : ?>
    <div class="af-alert af-alert-info" role="note">
        <?= e(t('Some data of the shop is empty and will not appear in the legal pages (they always show what is in the settings):')) ?>
        <?= e(implode(', ', array_map('t', $missing))) ?>.
        <a href="<?= e($settingsUrl) ?>"><?= e(t('Complete it in the settings')) ?></a>
    </div>
<?php endif; ?>

<div class="af-card">
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr><th><?= e(t('Page')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pages as $code => $page) : ?>
                <tr>
                    <td><?= e($page['title']) ?></td>
                    <td>
                        <?php if ($page['id'] === 0) : ?>
                            <?= Admin::badge(t('Not created'), '') ?>
                        <?php elseif ($page['status'] === 'published') : ?>
                            <?= Admin::badge(t('Published'), 'ok') ?>
                        <?php elseif ($page['status'] === 'scheduled') : ?>
                            <?= Admin::badge(t('Scheduled'), 'info') ?>
                        <?php else : ?>
                            <?= Admin::badge(t('Draft'), 'warn') ?>
                        <?php endif; ?>
                    </td>
                    <td class="af-right">
                        <?php if ($page['id'] > 0) : ?>
                            <a class="af-btn af-btn-sm" href="<?= e(Admin::resourceUrl(app(), 'pages', $page['id'])) ?>"><?= Admin::icon('edit') ?><?= e(t('Edit')) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit && $missingPages > 0) : ?>
    <form method="post" action="<?= e($createUrl) ?>" class="af-form-actions">
        <?= Admin::tokenField(app()) ?>
        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('plus') ?><?= e(t('Create the missing pages as drafts')) ?></button>
    </form>
<?php endif; ?>
<?php if ($canEdit && $missingPages < count($pages)) : ?>
    <form method="post" action="<?= e($createUrl) ?>" class="af-form-actions">
        <?= Admin::tokenField(app()) ?>
        <input type="hidden" name="refresh" value="1">
        <button type="submit" class="af-btn"><?= e(t('Rewrite the legal pages with the current texts')) ?></button>
    </form>
<?php endif; ?>
