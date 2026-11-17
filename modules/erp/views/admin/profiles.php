<?php
/**
 * AIFEC — ERP: lista de perfiles de exportación o de importación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $direction
 * @var bool $canEdit
 * @var string $base
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Modules\Erp\Profiles;
use Aifec\Modules\Erp\Sources;

$import = $direction === 'import';
?>
<div class="af-toolbar">
    <?php if ($canEdit) : ?>
        <a class="af-btn af-btn-primary" href="<?= e($base . '/new') ?>"><?= Admin::icon('plus') ?><?= e($import ? t('New import') : t('New export')) ?></a>
    <?php endif; ?>
</div>
<div class="af-card">
    <?php if ($rows === []) : ?>
        <div class="af-empty"><h3><?= e($import ? t('No imports yet') : t('No exports yet')) ?></h3></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Name')) ?></th>
                        <?php if (!$import) : ?><th><?= e(t('Data')) ?></th><?php endif; ?>
                        <th><?= e(t('Format')) ?></th>
                        <th><?= e(t('When')) ?></th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Last run')) ?> (UTC)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><a href="<?= e($base . '/' . (int) $row['id']) ?>"><?= e((string) $row['name']) ?></a></td>
                            <?php if (!$import) : ?><td><?= e(t(isset(Sources::KINDS[(string) $row['kind']]) ? Sources::KINDS[(string) $row['kind']] : (string) $row['kind'])) ?></td><?php endif; ?>
                            <td class="af-mono"><?= e(strtoupper((string) $row['format'])) ?></td>
                            <td><?= e(t(isset(Profiles::SCHEDULES[(string) $row['schedule']]) ? Profiles::SCHEDULES[(string) $row['schedule']] : (string) $row['schedule'])) ?></td>
                            <td><?= $row['enabled'] ? Admin::badge(t('On'), 'ok') : Admin::badge(t('Off'), 'warn') ?></td>
                            <td class="af-hide-sm af-nowrap"><?= e($row['last_run_at'] !== null ? (string) $row['last_run_at'] : '—') ?></td>
                            <td class="af-right"><a class="af-btn af-btn-sm" href="<?= e($base . '/' . (int) $row['id']) ?>"><?= e(t('Open')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
