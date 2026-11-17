<?php
/**
 * AIFEC — vista previa de una importación de productos: no se ha escrito nada todavía.
 *
 * @var array<string,mixed> $state
 * @var array<string,mixed> $preview
 * @var string $runUrl
 * @var string $cancelUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$nothing = (int) $preview['creates'] + (int) $preview['updates'] === 0;

?>
<div class="af-alert af-alert-info"><?= e(t('Nothing has been written yet. Check this preview and confirm.')) ?> <strong><?= e($state['name']) ?></strong></div>
<?php if (!$preview['complete']) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('The file is long: only the first {n} rows were counted here. All of them are imported.', ['n' => (int) $preview['rows']])) ?></div>
<?php endif; ?>
<div class="af-grid">
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Rows')) ?></div><div class="af-stat-value"><?= e((int) $preview['rows']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('To create')) ?></div><div class="af-stat-value"><?= e((int) $preview['creates']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('To change')) ?></div><div class="af-stat-value"><?= e((int) $preview['updates']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('With errors')) ?></div><div class="af-stat-value"><?= e((int) $preview['error_count']) ?></div></div></div>
</div>
<?php if ($preview['unknown'] !== []) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Columns that are not recognised and will be ignored: {list}.', ['list' => implode(', ', $preview['unknown'])])) ?></div>
<?php endif; ?>
<?php if ($preview['new_attributes'] !== []) : ?>
    <div class="af-alert af-alert-info"><?= e(t('Attributes that do not exist and will be created: {list}.', ['list' => implode(', ', $preview['new_attributes'])])) ?></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-body af-toolbar">
        <form method="post" action="<?= e($runUrl) ?>" class="af-inline">
            <?= Admin::tokenField(app()) ?>
            <button type="submit" class="af-btn af-btn-primary"<?= $nothing ? ' disabled' : '' ?>><?= Admin::icon('check') ?><?= e(t('Confirm and import')) ?></button>
        </form>
        <form method="post" action="<?= e($cancelUrl) ?>" class="af-inline">
            <?= Admin::tokenField(app()) ?>
            <button type="submit" class="af-btn"><?= Admin::icon('x') ?><?= e(t('Discard')) ?></button>
        </form>
    </div>
</div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('First rows')) ?></h2></div>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= e(t('Result')) ?></th>
                    <?php foreach ($preview['header'] as $name) : ?><th class="af-mono af-small"><?= e($name) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview['sample'] as $row) : ?>
                    <tr>
                        <td><?= e((int) $row['row']) ?></td>
                        <td class="af-nowrap">
                            <?php if ($row['status'] === 'create') : ?><?= Admin::badge(t('Create'), 'ok') ?>
                            <?php elseif ($row['status'] === 'update') : ?><?= Admin::badge(t('Change'), 'info') ?>
                            <?php else : ?><?= Admin::badge(t('Error'), 'bad') ?> <span class="af-small"><?= e($row['message']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($preview['header'] as $i => $name) : ?><td class="af-small"><?= e(mb_substr(isset($row['cells'][$i]) ? (string) $row['cells'][$i] : '', 0, 80, 'UTF-8')) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($preview['errors'] !== []) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Rows with errors')) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr><th>#</th><th>sku</th><th><?= e(t('Problem')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($preview['errors'] as $error) : ?>
                        <tr><td><?= e((int) $error['row']) ?></td><td class="af-mono af-small"><?= e($error['sku']) ?></td><td><?= e($error['message']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="af-card-body af-muted af-small"><?= e(t('These rows will be skipped; the others are imported.')) ?></div>
    </div>
<?php endif; ?>
