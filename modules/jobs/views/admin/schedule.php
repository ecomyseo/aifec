<?php
/**
 * AIFEC — tareas programadas: cuándo corrió cada una y botón para ejecutarla ahora.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tasks
 * @var bool $canEdit
 * @var string $webUrl
 * @var string $cronLine
 * @var int|null $lastRun
 */

use Aifec\Admin\Admin;
use Aifec\Modules\Jobs\JobsAdmin;

?>
<?php if ($canEdit) : ?>
    <div class="af-card">
        <div class="af-card-body af-stack">
            <div>
                <strong><?= e(t('Web address')) ?></strong>
                <code class="af-mono"><?= e($webUrl) ?></code>
                <button type="button" class="af-btn af-btn-sm af-btn-ghost" data-af-copy="<?= e($webUrl) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
            </div>
            <div>
                <strong><?= e(t('Server cron, every minute')) ?></strong>
                <code class="af-mono"><?= e($cronLine) ?></code>
                <button type="button" class="af-btn af-btn-sm af-btn-ghost" data-af-copy="<?= e($cronLine) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="af-card">
    <?php if ($tasks === []) : ?>
        <div class="af-empty"><h3><?= e(t('No scheduled tasks')) ?></h3></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Task')) ?></th>
                        <th><?= e(t('How often')) ?></th>
                        <th><?= e(t('Last run')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Last success')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Error')) ?></th>
                        <?php if ($canEdit) : ?><th class="af-right"><?= e(t('Actions')) ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task) : ?>
                        <tr>
                            <td>
                                <?= e($task['description'] !== '' ? t((string) $task['description']) : (string) $task['task']) ?>
                                <?php if ($task['description'] !== '') : ?><br><span class="af-muted af-small af-mono"><?= e((string) $task['task']) ?></span><?php endif; ?>
                            </td>
                            <td><?= e(JobsAdmin::everyLabel((string) $task['every'])) ?></td>
                            <td><?= JobsAdmin::when($task['last_run_at'] !== null ? (string) $task['last_run_at'] : null, app()) ?></td>
                            <td class="af-hide-sm"><?= JobsAdmin::when($task['last_ok_at'] !== null ? (string) $task['last_ok_at'] : null, app()) ?></td>
                            <td class="af-hide-sm">
                                <?php if ($task['last_error'] !== null && $task['last_error'] !== '') : ?>
                                    <?= Admin::badge(t('Failed'), 'bad') ?> <span class="af-small"><?= e(mb_strlen((string) $task['last_error']) > 120 ? mb_substr((string) $task['last_error'], 0, 120) . '…' : (string) $task['last_error']) ?></span>
                                <?php else : ?>
                                    <span class="af-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEdit) : ?>
                                <td class="af-cell-actions af-right">
                                    <form method="post" action="<?= e(app()->url()->plain('/admin/jobs/run/' . rawurlencode((string) $task['task']))) ?>" class="af-inline">
                                        <?= Admin::tokenField(app()) ?>
                                        <button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('bolt') ?><?= e(t('Run now')) ?></button>
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
