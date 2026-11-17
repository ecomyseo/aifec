<?php
/**
 * AIFEC — migración, paso 5: el simulacro. Hace todo menos escribir y enseña lo que pasaría.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<string,mixed>|null $sim
 * @var array<string,mixed>|null $view
 * @var array<string,mixed>|null $open
 * @var string $pollUrl
 */

use Aifec\Admin\Admin;

$base = app()->url()->plain('/admin/import/' . (int) $run['id']);
$status = $sim !== null ? (string) $sim['status'] : '';
$canRun = $open === null;
?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>

<?php if ($sim === null) : ?>
    <div class="af-card">
        <div class="af-card-body">
            <p><?= e(t('The simulation reads everything from the source and works out what would happen, without writing anything here.')) ?></p>
            <?php if (!$canRun) : ?><div class="af-alert af-alert-warn"><?= e(t('Another migration is in progress.')) ?></div><?php endif; ?>
            <form method="post" action="<?= e($base . '/simulate') ?>">
                <?= Admin::tokenField(app()) ?>
                <button type="submit" class="af-btn af-btn-primary"<?= $canRun ? '' : ' disabled' ?>><?= Admin::icon('bolt') ?><?= e(t('Run the simulation')) ?></button>
            </form>
        </div>
    </div>
<?php else : ?>
    <div data-af-import-poll="<?= e($pollUrl) ?>" data-af-import-status="<?= e($status) ?>" data-af-import-open="<?= $status === 'running' ? '1' : '0' ?>">
        <div class="af-card">
            <div class="af-card-body af-toolbar">
                <?= Admin::badge((string) $view['status_label'], $status === 'done' ? 'ok' : ($status === 'running' ? 'info' : ($status === 'paused' ? 'warn' : ($status === 'failed' ? 'bad' : '')))) ?>
                <span class="af-top-space"></span>
                <?php if ($status === 'done' || $status === 'failed' || $status === 'cancelled') : ?>
                    <form method="post" action="<?= e($base . '/simulate') ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm"<?= $canRun ? '' : ' disabled' ?>><?= e(t('Run it again')) ?></button></form>
                <?php endif; ?>
                <?php if ($status === 'running' || $status === 'paused') : ?>
                    <form method="post" action="<?= e(app()->url()->plain('/admin/import/' . (int) $sim['id'] . '/cancel')) ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm"><?= e(t('Cancel')) ?></button></form>
                <?php endif; ?>
                <?php if ($status === 'done') : ?>
                    <a class="af-btn af-btn-sm af-btn-primary" href="<?= e($base . '/confirm') ?>"><?= e(t('Continue')) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if (($status === 'paused' || $status === 'failed') && $view['pause_reason'] !== '') : ?>
            <div class="af-alert af-alert-bad" role="alert"><?= e($view['pause_reason']) ?></div>
        <?php endif; ?>
        <div class="af-card"><div class="af-card-body" data-af-import-bars><?= $this->partial('import::admin/bars', ['view' => $view]) ?></div></div>
        <?php if ($status === 'done') : ?>
            <div class="af-card">
                <div class="af-card-head"><h2><?= e(t('What would happen')) ?></h2></div>
                <div class="af-card-body">
                    <p><?= e(tn('{n} record would be read', '{n} records would be read', (int) $view['totals']['read'], ['n' => (int) $view['totals']['read']])) ?></p>
                    <?php if ((int) $view['totals']['conflict'] > 0) : ?>
                        <div class="af-alert af-alert-warn"><?= e(tn('{n} record was edited here and would be left alone', '{n} records were edited here and would be left alone', (int) $view['totals']['conflict'], ['n' => (int) $view['totals']['conflict']])) ?></div>
                    <?php endif; ?>
                    <?php if ((int) $view['totals']['skipped'] > 0) : ?>
                        <div class="af-alert af-alert-warn"><?= e(tn('{n} record could not be brought', '{n} records could not be brought', (int) $view['totals']['skipped'], ['n' => (int) $view['totals']['skipped']])) ?>
                            <a href="<?= e(app()->url()->plain('/admin/import/' . (int) $sim['id'] . '/report')) ?>"><?= e(t('See why')) ?></a></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
