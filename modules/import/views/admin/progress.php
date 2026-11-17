<?php
/**
 * AIFEC — migración, en curso: barras por tipo, contadores, últimos avisos y los botones de pausar, reanudar y cancelar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<string,mixed> $view
 * @var string $pollUrl
 */

use Aifec\Admin\Admin;

$status = (string) $run['status'];
$kinds = ['running' => 'info', 'paused' => 'warn', 'done' => 'ok', 'failed' => 'bad', 'cancelled' => ''];
$base = app()->url()->plain('/admin/import/' . (int) $run['id']);
?>
<?= $this->partial('import::admin/steps', ['step' => (int) $run['dry_run'] === 1 ? 'simulation' : ($view['closed'] ? 'report' : 'progress'), 'id' => 0]) ?>

<div data-af-import-poll="<?= e($pollUrl) ?>" data-af-import-status="<?= e($status) ?>" data-af-import-open="<?= $status === 'running' ? '1' : '0' ?>">
    <div class="af-card">
        <div class="af-card-body af-toolbar">
            <?= Admin::badge((string) $view['status_label'], isset($kinds[$status]) ? $kinds[$status] : '') ?>
            <?php if ($view['dry_run']) : ?><?= Admin::badge(t('Simulation'), 'info') ?><?php endif; ?>
            <span class="af-muted af-small"><?= e(t('Nothing is sent to anybody while it runs.')) ?></span>
            <span class="af-top-space"></span>
            <?php if ($status === 'running') : ?>
                <form method="post" action="<?= e($base . '/pause') ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm"><?= e(t('Pause')) ?></button></form>
            <?php elseif ($status === 'paused') : ?>
                <form method="post" action="<?= e($base . '/resume') ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Resume')) ?></button></form>
            <?php endif; ?>
            <?php if ($status === 'running' || $status === 'paused') : ?>
                <form method="post" action="<?= e($base . '/cancel') ?>" class="af-inline" data-af-confirm="<?= e(t('Cancel this migration? What was already brought stays here.')) ?>">
                    <?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm af-btn-danger"><?= e(t('Cancel')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($view['closed']) : ?>
                <a class="af-btn af-btn-sm af-btn-primary" href="<?= e($base . '/report') ?>"><?= e(t('See the report')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($status === 'paused' && $view['pause_reason'] !== '') : ?>
        <div class="af-alert af-alert-warn" role="alert"><?= e($view['pause_reason']) ?></div>
    <?php elseif ($status === 'failed' && $view['pause_reason'] !== '') : ?>
        <div class="af-alert af-alert-bad" role="alert"><?= e($view['pause_reason']) ?></div>
    <?php endif; ?>

    <?php if ($view['phase_label'] !== '') : ?>
        <div class="af-alert af-alert-info" role="status"><?= e((string) $view['phase_label']) ?></div>
    <?php endif; ?>
    <div class="af-card"><div class="af-card-body" data-af-import-bars><?= $this->partial('import::admin/bars', ['view' => $view]) ?></div></div>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Latest warnings')) ?></h2></div>
        <div class="af-card-body" data-af-import-notices><?= $this->partial('import::admin/notices', ['view' => $view]) ?></div>
    </div>
</div>
