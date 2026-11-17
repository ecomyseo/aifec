<?php
/**
 * AIFEC — migración: el histórico de migraciones y simulacros.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $runs
 * @var array<string,mixed>|null $open
 */

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;

$kinds = ['draft' => '', 'running' => 'info', 'paused' => 'warn', 'done' => 'ok', 'failed' => 'bad', 'cancelled' => ''];
$names = ['draft' => t('Draft'), 'running' => t('Running'), 'paused' => t('Paused'), 'done' => t('Done'), 'failed' => t('Failed'), 'cancelled' => t('Cancelled')];
$when = static function ($sql): string {
    $time = Clock::parse((string) $sql);

    return $time > 0 ? app()->i18n()->date($time, 'medium', app()->site()->timezone()) : '';
};
?>
<?php if ($open !== null) : ?>
    <div class="af-alert af-alert-info">
        <?= e(t('A migration is in progress.')) ?>
        <a href="<?= e(app()->url()->plain('/admin/import/' . (int) $open['id'] . '/progress')) ?>"><?= e(t('See how it goes')) ?></a>
    </div>
<?php endif; ?>
<div class="af-card">
    <?php if ($runs === []) : ?>
        <div class="af-empty">
            <h3><?= e(t('No migrations yet')) ?></h3>
            <p><?= e(t('Bring a whole shop from WooCommerce or PrestaShop.')) ?></p>
        </div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Migration')) ?></th>
                        <th><?= e(t('Source')) ?></th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Started')) ?></th>
                        <th class="af-hide-sm af-num"><?= e(t('Records')) ?></th>
                        <th class="af-right"><?= e(t('Actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run) : ?>
                        <?php
                        $status = (string) $run['status'];
                        $to = $status === 'draft' ? 'connection' : (in_array($status, ['done', 'failed', 'cancelled'], true) ? 'report' : 'progress');
                        $totals = isset($run['_totals']) ? $run['_totals'] : ['read' => 0];
                        ?>
                        <tr>
                            <td>#<?= (int) $run['id'] ?><?= $run['label'] !== null && $run['label'] !== '' ? ' · ' . e(t((string) $run['label'])) : '' /* revisado: escapado con e() */ ?></td>
                            <td><?= e(ucfirst((string) $run['source'])) ?> <span class="af-muted af-small">(<?= e((string) $run['mode']) ?>)</span></td>
                            <td>
                                <?= Admin::badge(isset($names[$status]) ? $names[$status] : $status, isset($kinds[$status]) ? $kinds[$status] : '') ?>
                                <?php if ((int) $run['dry_run'] === 1) : ?><?= Admin::badge(t('Simulation'), 'info') ?><?php endif; ?>
                            </td>
                            <td class="af-hide-sm"><?= e($when($run['started_at'] !== null ? $run['started_at'] : $run['created_at'])) ?></td>
                            <td class="af-hide-sm af-num"><?= (int) $totals['read'] ?></td>
                            <td class="af-cell-actions af-right">
                                <a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/import/' . (int) $run['id'] . '/' . $to)) ?>"><?= e($status === 'draft' ? t('Continue') : t('Open')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
