<?php
/**
 * AIFEC — ERP: una pasada con su informe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $run
 * @var array<string,mixed> $report
 * @var bool $canEdit
 * @var string $downloadUrl
 * @var bool $available
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$errors = isset($report['lines']) && is_array($report['lines']) && $report['lines'] !== [] ? $report['lines'] : (isset($report['errors']) && is_array($report['errors']) ? $report['errors'] : []);
?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <dl class="af-kv">
            <dt><?= e(t('Profile')) ?></dt><dd><?= e((string) ($run['profile_name'] ?? '')) ?></dd>
            <dt><?= e(t('Status')) ?></dt><dd><?= e((string) $run['status']) ?></dd>
            <dt><?= e(t('Records')) ?></dt><dd><?= e((int) $run['records']) ?></dd>
            <dt><?= e(t('Started')) ?></dt><dd><?= e((string) $run['started_at']) ?> UTC</dd>
            <dt><?= e(t('File')) ?></dt><dd><?= e((string) ($run['file_name'] ?? '—')) ?> <?= (int) $run['bytes'] > 0 ? '(' . e((int) $run['bytes']) . ' B)' : '' ?></dd>
            <dt><?= e(t('Destination')) ?></dt><dd><?= e((string) ($run['destination'] ?? '')) ?><?= isset($report['note']) ? ' — ' . e((string) $report['note']) : '' ?></dd>
            <?php if (!empty($run['error'])) : ?>
                <dt><?= e(t('Problem')) ?></dt><dd><?= e((string) $run['error']) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($canEdit && $available) : ?>
            <div><a class="af-btn af-btn-primary" href="<?= e($downloadUrl) ?>"><?= Admin::icon('download') ?><?= e(t('Download the file')) ?></a></div>
        <?php elseif ((string) $run['file_name'] !== '' && !$available) : ?>
            <p class="af-muted af-small"><?= e(t('The file is no longer available.')) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php if (isset($report['total'])) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Report of the file')) ?></h2></div>
        <div class="af-card-body af-stack">
            <p><?= e(t('{total} lines, {applied} applied, {bad} bad.', ['total' => (string) (int) $report['total'], 'applied' => (string) (int) $report['applied'], 'bad' => (string) (int) $report['bad']])) ?></p>
            <?php if ($errors !== []) : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr><th><?= e(t('Line')) ?></th><th>SKU</th><th><?= e(t('Status')) ?></th><th><?= e(t('What happened')) ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($errors as $row) : ?>
                                <tr><td><?= e((int) $row['line']) ?></td><td class="af-mono"><?= e((string) $row['sku']) ?></td><td><?= e((string) $row['status']) ?></td><td><?= e((string) $row['message']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
