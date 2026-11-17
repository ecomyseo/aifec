<?php
/**
 * AIFEC — avance de una copia que se hace por tandas.
 *
 * @var array{token:string,mode:string,stage:string,done:int,total:int,percent:int,name:string,bytes_total:int,skipped:int} $state
 * @var string $runUrl
 * @var string $cancelUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <p>
            <strong><?= e($state['mode'] === 'full' ? t('Complete backup') : t('Backup of the database')) ?></strong>
            <span class="af-muted"><?= e($state['percent']) ?> %</span>
        </p>
        <progress max="100" value="<?= e($state['percent']) ?>" aria-label="<?= e(t('Progress of the backup')) ?>"></progress>
        <?php if ($state['total'] > 0) : ?>
            <p class="af-muted af-small"><?= e(t('{done} of {total} files', ['done' => $state['done'], 'total' => $state['total']])) ?></p>
        <?php endif; ?>
        <div class="af-toolbar">
            <form method="post" action="<?= e($runUrl) ?>" class="af-inline">
                <?= Admin::tokenField(app()) ?>
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('refresh') ?><?= e(t('Continue')) ?></button>
            </form>
            <form method="post" action="<?= e($cancelUrl) ?>" class="af-inline">
                <?= Admin::tokenField(app()) ?>
                <button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Cancel this backup?')) ?>"><?= Admin::icon('trash') ?><?= e(t('Cancel')) ?></button>
            </form>
        </div>
    </div>
</div>
