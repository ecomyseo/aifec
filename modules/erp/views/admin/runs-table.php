<?php
/**
 * AIFEC — ERP: tabla de pasadas (parcial).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $base
 * @var bool $showProfile
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$kinds = ['ok' => 'ok', 'empty' => 'info', 'running' => 'info', 'failed' => 'bad', 'blocked' => 'warn', 'rejected' => 'bad'];
$labels = ['ok' => t('Done'), 'empty' => t('Nothing to do'), 'running' => t('Running'), 'failed' => t('Failed'), 'blocked' => t('Blocked'), 'rejected' => t('Rejected')];
?>
<div class="af-table-wrap">
    <table class="af-table">
        <thead>
            <tr>
                <th>#</th>
                <?php if (!empty($showProfile)) : ?><th><?= e(t('Profile')) ?></th><?php endif; ?>
                <th><?= e(t('Type')) ?></th>
                <th><?= e(t('Status')) ?></th>
                <th class="af-right"><?= e(t('Records')) ?></th>
                <th class="af-hide-sm"><?= e(t('Started')) ?> (UTC)</th>
                <th class="af-hide-sm"><?= e(t('Destination')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php $status = (string) $row['status']; ?>
                <tr>
                    <td><?= e((int) $row['id']) ?></td>
                    <?php if (!empty($showProfile)) : ?><td><?= e((string) ($row['profile_name'] ?? '')) ?></td><?php endif; ?>
                    <td><?= e((string) $row['direction'] === 'import' ? t('Import') : t('Export')) ?></td>
                    <td><?= Admin::badge(isset($labels[$status]) ? $labels[$status] : $status, isset($kinds[$status]) ? $kinds[$status] : '') ?></td>
                    <td class="af-right"><?= e((int) $row['records']) ?></td>
                    <td class="af-hide-sm af-nowrap"><?= e((string) $row['started_at']) ?></td>
                    <td class="af-hide-sm"><?= e((string) ($row['destination'] ?? '')) ?></td>
                    <td class="af-right"><a class="af-btn af-btn-sm" href="<?= e($base . '/' . (int) $row['id']) ?>"><?= e(t('Open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
