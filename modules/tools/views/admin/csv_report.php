<?php
/**
 * AIFEC — avance e informe de una importación de productos.
 *
 * @var array<string,mixed> $state
 * @var string $runUrl
 * @var string $reportUrl
 * @var bool $hasReport
 * @var string $cancelUrl
 * @var bool $inline
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Modules\Tools\CsvController;

$status = (string) $state['status'];
$size = max(1, (int) $state['size']);
$percent = $status === 'done' ? 100 : min(99, (int) floor(((int) $state['offset'] * 100) / $size));
$working = $status === 'queued' || $status === 'running';

?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <p>
            <strong><?= e($state['name']) ?></strong>
            <?= Admin::badge(CsvController::statusLabel($status), CsvController::statusKind($status)) ?>
            <span class="af-muted"><?= e($percent) ?> %</span>
        </p>
        <div class="af-toolbar">
            <?php if ($hasReport) : ?>
                <a class="af-btn af-btn-primary" href="<?= e($reportUrl) ?>"><?= Admin::icon('download') ?><?= e(t('Download the report')) ?></a>
            <?php endif; ?>
            <?php if ($working && $inline) : ?>
                <form method="post" action="<?= e($runUrl) ?>" class="af-inline">
                    <?= Admin::tokenField(app()) ?>
                    <button type="submit" class="af-btn"><?= Admin::icon('refresh') ?><?= e(t('Continue')) ?></button>
                </form>
            <?php elseif ($working) : ?>
                <a class="af-btn" href="<?= e(app()->url()->plain('/admin/tools/csv/' . $state['token'])) ?>"><?= Admin::icon('refresh') ?><?= e(t('Reload')) ?></a>
            <?php endif; ?>
            <?php if ($status === 'done') : ?>
                <form method="post" action="<?= e($cancelUrl) ?>" class="af-inline">
                    <?= Admin::tokenField(app()) ?>
                    <button type="submit" class="af-btn" data-af-confirm="<?= e(t('Delete the file and the report of this import?')) ?>"><?= Admin::icon('trash') ?><?= e(t('Delete this import')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="af-grid">
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Rows read')) ?></div><div class="af-stat-value"><?= e((int) $state['row']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Created')) ?></div><div class="af-stat-value"><?= e((int) $state['created']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Changed')) ?></div><div class="af-stat-value"><?= e((int) $state['updated']) ?></div></div></div>
    <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Errors')) ?></div><div class="af-stat-value"><?= e((int) $state['errors']) ?></div><?php if ((int) $state['warnings'] > 0) : ?><div class="af-stat-note"><?= e(tn('{n} warning', '{n} warnings', (int) $state['warnings'])) ?></div><?php endif; ?></div></div>
</div>
<?php if ($state['error_sample'] !== []) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Rows with errors')) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr><th>#</th><th>sku</th><th><?= e(t('Problem')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($state['error_sample'] as $error) : ?>
                        <tr><td><?= e((int) $error['row']) ?></td><td class="af-mono af-small"><?= e($error['sku']) ?></td><td><?= e($error['message']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) $state['errors'] > count($state['error_sample'])) : ?>
            <div class="af-card-body af-muted af-small"><?= e(t('Only the first rows with errors are listed here. The report has all of them.')) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
