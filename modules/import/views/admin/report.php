<?php
/**
 * AIFEC — migración, informe: contadores por tipo, lo que se revisa a mano, lo que no tiene equivalente y las notas.
 * La misma plantilla sirve a la página imprimible.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $run
 * @var array<string,mixed> $report
 * @var bool $print
 * @var string $result
 * @var array<int,array<string,mixed>> $notes
 */

use Aifec\Admin\Admin;
use Aifec\Modules\Import\Report;

$base = app()->url()->plain('/admin/import/' . (int) $run['id']);
$status = (string) $run['status'];
$names = ['draft' => t('Draft'), 'running' => t('Running'), 'paused' => t('Paused'), 'done' => t('Done'), 'failed' => t('Failed'), 'cancelled' => t('Cancelled')];
$kinds = ['running' => 'info', 'paused' => 'warn', 'done' => 'ok', 'failed' => 'bad'];
$origins = ['source' => t('In the source'), 'here' => t('Here')];
?>
<div class="af-imp-report<?= $print ? ' af-imp-print' : '' ?>">
    <div class="af-card">
        <div class="af-card-body af-toolbar">
            <strong>#<?= (int) $run['id'] ?> · <?= e(ucfirst((string) $run['source'])) ?> (<?= e((string) $run['mode']) ?>)</strong>
            <?= Admin::badge(isset($names[$status]) ? $names[$status] : $status, isset($kinds[$status]) ? $kinds[$status] : '') ?>
            <?php if ((int) $run['dry_run'] === 1) : ?><?= Admin::badge(t('Simulation'), 'info') ?><?php endif; ?>
            <span class="af-top-space"></span>
            <?php if ($print) : ?>
                <button type="button" class="af-btn af-btn-sm af-btn-primary" data-af-import-print><?= Admin::icon('file') ?><?= e(t('Print')) ?></button>
            <?php else : ?>
                <a class="af-btn af-btn-sm" href="<?= e($base . '/report.csv') ?>"><?= Admin::icon('download') ?><?= e(t('Download the list (CSV)')) ?></a>
                <a class="af-btn af-btn-sm" href="<?= e($base . '/print') ?>" target="_blank" rel="noopener"><?= Admin::icon('file') ?><?= e(t('Printable page')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($status === 'failed' && $run['pause_reason'] !== null && $run['pause_reason'] !== '') : ?>
        <div class="af-alert af-alert-bad" role="alert"><?= e(t((string) $run['pause_reason'])) ?></div>
    <?php endif; ?>

    <?php if ($report['review'] !== []) : ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Check by hand')) ?></h2></div>
            <div class="af-card-body">
                <?php foreach ($report['review'] as $item) : ?>
                    <div class="af-alert af-alert-<?= e((string) $item['level']) ?>">
                        <?= e(t((string) $item['text'], (array) $item['vars'])) ?>
                        <?php if (isset($item['why'])) : ?><span class="af-muted">(<?= e(t((string) $item['why'], (array) $item['why_vars'])) ?>)</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($report['verify'] !== null) : ?>
        <?php $verify = $report['verify']; ?>
        <div class="af-card">
            <div class="af-card-head">
                <h2><?= e(t('Check against the source')) ?></h2>
                <?php if (!$print) : ?>
                    <a class="af-btn af-btn-sm" href="<?= e($base . '/report.csv?part=verify') ?>"><?= Admin::icon('download') ?><?= e(t('Download the check (CSV)')) ?></a>
                <?php endif; ?>
            </div>
            <div class="af-card-body">
                <?php if ($verify['summary']['error'] !== null) : ?>
                    <div class="af-alert af-alert-bad" role="alert"><?= e(t((string) $verify['summary']['error'])) ?></div>
                <?php elseif ($verify['summary']['wrong'] > 0) : ?>
                    <div class="af-alert af-alert-bad" role="alert"><?= e(t('{wrong} of {n} things checked do not match what the source had.', ['wrong' => $verify['summary']['wrong'], 'n' => $verify['summary']['checked']])) ?></div>
                <?php else : ?>
                    <div class="af-alert af-alert-ok"><?= e(t('Everything matches what the source had: {n} things checked.', ['n' => $verify['summary']['checked']])) ?></div>
                <?php endif; ?>
            </div>
            <?php foreach ($verify['sections'] as $section) : ?>
                <div class="af-card-body"><h3><?= e((string) $section['title']) ?></h3></div>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr><th><?= e(t('Check')) ?></th><th class="af-num"><?= e(t('Source')) ?></th><th class="af-num"><?= e(t('Here')) ?></th><th><?= e(t('Result')) ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($section['rows'] as $row) : ?>
                                <tr<?= $row['ok'] ? '' : ' class="af-imp-wrong"' ?>>
                                    <td><?= e((string) $row['label']) ?>
                                        <?php foreach ($row['notes'] as $note) : ?><div class="af-muted af-small"><?= e((string) $note) ?></div><?php endforeach; ?>
                                        <?php if (!$print && isset($row['link']) && $row['link'] === 'notes') : ?><div class="af-small"><a href="<?= e($base . '/report.csv') ?>"><?= e(t('See the notes')) ?></a></div><?php endif; ?>
                                    </td>
                                    <td class="af-num"><?= e((string) $row['source']) ?></td>
                                    <td class="af-num"><?= e((string) $row['here']) ?></td>
                                    <td><?= Admin::badge($row['ok'] ? t('Matches') : t('Does not match'), $row['ok'] ? 'ok' : 'bad') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('By type')) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Type')) ?></th><th class="af-num"><?= e(t('Read')) ?></th><th class="af-num"><?= e(t('Created')) ?></th><th class="af-num"><?= e(t('Updated')) ?></th>
                        <th class="af-num"><?= e(t('Unchanged')) ?></th><th class="af-num"><?= e(t('Conflicts')) ?></th><th class="af-num"><?= e(t('Skipped')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['types'] as $row) : ?>
                        <tr>
                            <td><?= e(t((string) $row['label'])) ?></td>
                            <td class="af-num"><?= (int) $row['counters']['read'] ?></td><td class="af-num"><?= (int) $row['counters']['created'] ?></td>
                            <td class="af-num"><?= (int) $row['counters']['updated'] ?></td><td class="af-num"><?= (int) $row['counters']['unchanged'] ?></td>
                            <td class="af-num"><?= (int) $row['counters']['conflict'] ?></td><td class="af-num"><?= (int) $row['counters']['skipped'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><?= e(t('Total')) ?></th>
                        <th class="af-num"><?= (int) $report['totals']['read'] ?></th><th class="af-num"><?= (int) $report['totals']['created'] ?></th><th class="af-num"><?= (int) $report['totals']['updated'] ?></th>
                        <th class="af-num"><?= (int) $report['totals']['unchanged'] ?></th><th class="af-num"><?= (int) $report['totals']['conflict'] ?></th><th class="af-num"><?= (int) $report['totals']['skipped'] ?></th>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($report['unmapped'] !== []) : ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Without an equivalent')) ?></h2></div>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr><th><?= e(t('What')) ?></th><th><?= e(t('Kind')) ?></th><th class="af-num"><?= e(t('How many')) ?></th><th class="af-hide-sm"><?= e(t('Note')) ?></th><th><?= e(t('Where')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($report['unmapped'] as $item) : ?>
                            <tr>
                                <td><?= e(Report::translateNote((string) $item['name'])) ?></td><td><?= e((string) $item['kind']) ?></td><td class="af-num"><?= (int) $item['count'] ?></td>
                                <td class="af-hide-sm af-small"><?= e(Report::translateNote((string) $item['note'])) ?></td><td><?= e($origins[(string) $item['origin']]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($report['diffs'] !== []) : ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Final check')) ?></h2></div>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr><th><?= e(t('Figure')) ?></th><th class="af-num"><?= e(t('Source')) ?></th><th class="af-num"><?= e(t('Here')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($report['diffs'] as $diff) : ?>
                            <tr><td class="af-mono"><?= e((string) $diff['key']) ?></td><td class="af-num"><?= (int) $diff['source'] ?></td><td class="af-num"><?= (int) $diff['dest'] ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="af-card">
        <div class="af-card-head">
            <h2><?= e(t('Notes')) ?></h2>
            <?php if (!$print) : ?>
                <div class="af-toolbar">
                    <a class="af-btn af-btn-sm<?= $result === '' ? ' af-btn-primary' : '' ?>" href="<?= e($base . '/report') ?>"><?= e(t('All')) ?></a>
                    <?php foreach (['skipped' => t('Skipped'), 'conflict' => t('Conflicts'), 'note' => t('Notes'), 'error' => t('Errors')] as $key => $label) : ?>
                        <a class="af-btn af-btn-sm<?= $result === $key ? ' af-btn-primary' : '' ?>" href="<?= e($base . '/report?result=' . $key) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($notes === []) : ?>
            <div class="af-empty"><h3><?= e(t('Nothing to report.')) ?></h3></div>
        <?php else : ?>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr><th><?= e(t('Type')) ?></th><th><?= e(t('Id')) ?></th><th><?= e(t('Result')) ?></th><th><?= e(t('Reason')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($notes as $note) : ?>
                            <tr>
                                <td class="af-mono af-small"><?= e((string) $note['type']) ?></td><td class="af-mono af-small"><?= e((string) $note['source_id']) ?></td>
                                <td><?= Admin::badge((string) $note['result'], $note['result'] === 'conflict' ? 'warn' : ($note['result'] === 'error' ? 'bad' : '')) ?></td>
                                <td class="af-small"><?= e(Report::translateNote((string) $note['reason'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="af-muted af-small af-card-body"><?= e(t('Only the first 200 are shown here. The CSV has them all.')) ?></p>
        <?php endif; ?>
    </div>
</div>
