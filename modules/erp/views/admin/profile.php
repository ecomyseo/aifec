<?php
/**
 * AIFEC — ERP: un perfil de exportación o de importación con sus acciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $form
 * @var array<string,mixed>|null $profile
 * @var string $direction
 * @var bool $canEdit
 * @var array<string,mixed>|null $preview
 * @var array<string,mixed>|null $interval
 * @var array<int,array<string,mixed>> $runs
 * @var string $base
 * @var string $runsUrl
 * @var bool $outbound
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Modules\Erp\Destinations;

$import = $direction === 'import';
$id = $profile !== null ? (int) $profile['id'] : 0;
?>
<?php if ($profile !== null && in_array((string) $profile['destination'], $import ? ['url', 'ftp', 'ftps'] : Destinations::LEAVING, true) && !$outbound) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Outgoing messages are off in the settings: this profile will not run until you switch them on.')) ?></div>
<?php endif; ?>
<?php if ($profile !== null && $canEdit) : ?>
    <div class="af-toolbar">
        <form method="post" action="<?= e($base . '/' . $id . '/run') ?>">
            <?= Admin::tokenField(app()) ?>
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('bolt') ?><?= e($import ? t('Read the files now') : t('Run now')) ?></button>
        </form>
        <?php if (!$import) : ?>
            <a class="af-btn" href="<?= e($base . '/' . $id . '?preview=1') ?>"><?= Admin::icon('eye') ?><?= e(t('Test: first 10 rows')) ?></a>
        <?php endif; ?>
        <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-af-confirm="<?= e(t('Delete this profile? Its runs stay in the history.')) ?>">
            <?= Admin::tokenField(app()) ?>
            <button type="submit" class="af-btn af-btn-danger"><?= Admin::icon('trash') ?><?= e(t('Delete')) ?></button>
        </form>
    </div>
<?php endif; ?>
<?php if ($preview !== null) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Test: first 10 rows')) ?></h2></div>
        <div class="af-card-body">
            <p class="af-muted af-small"><?= e(t('Nothing was saved, sent or marked as exported.')) ?> <?= e(t((string) $preview['note'])) ?></p>
            <?php if ($preview['rows'] === []) : ?>
                <div class="af-empty"><h3><?= e(t('There is nothing to export now')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr><?php foreach ($preview['header'] as $title) : ?><th><?= e((string) $title) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php foreach ($preview['rows'] as $cells) : ?>
                                <tr><?php foreach ($cells as $cell) : ?><td class="af-small"><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php if ($interval !== null && $profile !== null) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Export an interval')) ?></h2></div>
        <div class="af-card-body af-stack">
            <p><?= e(tn('This interval has {n} record.', 'This interval has {n} records.', (int) $interval['count'])) ?></p>
            <form method="post" action="<?= e($base . '/' . $id . '/interval') ?>" class="af-toolbar">
                <?= Admin::tokenField(app()) ?>
                <input type="hidden" name="from" value="<?= e((string) $interval['fromDay']) ?>">
                <input type="hidden" name="to" value="<?= e((string) $interval['toDay']) ?>">
                <input type="hidden" name="confirm" value="<?= e((int) $interval['count']) ?>">
                <button type="submit" class="af-btn af-btn-primary"><?= e(tn('Yes, export these {n} record', 'Yes, export these {n} records', (int) $interval['count'])) ?></button>
                <a class="af-btn" href="<?= e($base . '/' . $id) ?>"><?= e(t('Cancel')) ?></a>
            </form>
        </div>
    </div>
<?php endif; ?>
<div class="af-card"><div class="af-card-body"><?= $form ?></div></div>
<?php if ($profile !== null && !$import && $canEdit) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Export an interval')) ?></h2></div>
        <div class="af-card-body">
            <form method="post" action="<?= e($base . '/' . $id . '/interval') ?>" class="af-toolbar">
                <?= Admin::tokenField(app()) ?>
                <label><span class="af-small af-muted"><?= e(t('From')) ?></span> <input class="af-input" type="date" name="from"></label>
                <label><span class="af-small af-muted"><?= e(t('To')) ?></span> <input class="af-input" type="date" name="to"></label>
                <button type="submit" class="af-btn"><?= e(t('Count the records')) ?></button>
            </form>
            <p class="af-muted af-small"><?= e(t('Old data is exported only like this, by hand, after you confirm how many records there are. It does not move the starting point of «only what is new».')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?php if ($runs !== []) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Latest runs')) ?></h2><a href="<?= e($runsUrl) ?>"><?= e(t('See all')) ?></a></div>
        <?= $this->partial('erp::admin/runs-table', ['rows' => $runs, 'base' => $runsUrl, 'showProfile' => false]) ?>
    </div>
<?php endif; ?>
