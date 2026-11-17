<?php
/**
 * AIFEC — pantalla de diagnóstico.
 *
 * @var array<int,array{id:string,group:string,status:string,label:string,detail:string,fix_url:string}> $results
 * @var array{ok:int,warn:int,bad:int} $summary
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$groups = [
    'server' => t('Server'), 'files' => t('Files'), 'database' => t('Database'), 'tasks' => t('Background tasks'),
    'mail' => t('Email'), 'shop' => t('Shop'), 'security' => t('Security'), 'other' => t('Other'),
];
$badges = ['ok' => [t('OK'), 'ok'], 'warn' => [t('Warning'), 'warn'], 'bad' => [t('Problem'), 'bad']];
$byGroup = [];
foreach ($results as $row) {
    $byGroup[$row['group']][] = $row;
}

?>
<?php if ($summary['bad'] > 0) : ?>
    <div class="af-alert af-alert-bad"><?= e(tn('{n} check needs your attention.', '{n} checks need your attention.', $summary['bad'])) ?></div>
<?php endif; ?>
<div class="af-grid">
    <div class="af-col-4"><div class="af-stat"><div class="af-stat-label"><?= e(t('OK')) ?></div><div class="af-stat-value"><?= e((int) $summary['ok']) ?></div></div></div>
    <div class="af-col-4"><div class="af-stat"><div class="af-stat-label"><?= e(t('Warnings')) ?></div><div class="af-stat-value"><?= e((int) $summary['warn']) ?></div></div></div>
    <div class="af-col-4"><div class="af-stat"><div class="af-stat-label"><?= e(t('Problems')) ?></div><div class="af-stat-value"><?= e((int) $summary['bad']) ?></div></div></div>
</div>
<?php foreach ($byGroup as $group => $rows) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(isset($groups[$group]) ? $groups[$group] : $group) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php $badge = $badges[$row['status']]; ?>
                        <tr>
                            <td class="af-nowrap"><?= Admin::badge($badge[0], $badge[1]) ?></td>
                            <td><?= e($row['label']) ?></td>
                            <td class="af-muted af-small"><?= e($row['detail']) ?></td>
                            <td class="af-right af-nowrap">
                                <?php if ($row['fix_url'] !== '' && $row['status'] !== 'ok') : ?>
                                    <a class="af-btn af-btn-sm" href="<?= e($row['fix_url']) ?>"><?= e(t('Fix')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
