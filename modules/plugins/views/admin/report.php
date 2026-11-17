<?php
/**
 * AIFEC — informe del Contrato AIFEC de un plugin: cada regla con su resultado, el fichero y la línea, y las cifras medidas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $row
 * @var array<string,mixed>|null $report
 * @var array<string,string> $titles
 * @var bool $canEdit
 */

use Aifec\Admin\Admin;

?>
<?php if (!is_array($report)) : ?>
    <div class="af-card">
        <div class="af-empty">
            <h3><?= e(t('This plugin has not been validated yet')) ?></h3>
            <?php if ($canEdit && $row['kind'] === 'plugin') : ?>
                <form method="post" action="<?= e(app()->url()->plain('/admin/plugins/' . rawurlencode((string) $row['id']) . '/validate')) ?>">
                    <?= Admin::tokenField(app()) ?>
                    <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('shield') ?><?= e(t('Validate')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php else : ?>
    <div class="af-grid">
        <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Score')) ?></div><div class="af-stat-value"><?= (int) $report['score'] ?> / 100</div></div></div>
        <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Result')) ?></div><div class="af-stat-value"><?= $report['ok'] ? Admin::badge(t('Passes'), 'ok') : Admin::badge(t('Does not pass'), 'bad') ?></div></div></div>
        <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Version')) ?></div><div class="af-stat-value"><?= e($report['version']) ?></div></div></div>
        <div class="af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e(t('Date')) ?></div><div class="af-stat-value af-small"><?= e($report['date']) ?></div></div></div>
    </div>
    <?php if (empty($report['dynamic'])) : ?>
        <div class="af-alert af-alert-warn"><?= e(t('The checks that run the plugin were not done.')) ?></div>
    <?php endif; ?>
    <div class="af-card">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Rule')) ?></th>
                        <th><?= e(t('Result')) ?></th>
                        <th><?= e(t('Detail')) ?></th>
                        <th class="af-hide-sm"><?= e(t('File')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['checks'] as $check) : ?>
                        <tr>
                            <td class="af-nowrap"><strong><?= e($check['rule']) ?></strong> <span class="af-muted af-small"><?= e(isset($titles[$check['rule']]) ? $titles[$check['rule']] : '') ?></span></td>
                            <td class="af-nowrap">
                                <?php if ($check['ok']) : ?>
                                    <?= Admin::badge(t('OK'), 'ok') ?>
                                <?php elseif ($check['level'] === 'error') : ?>
                                    <?= Admin::badge(t('Error'), 'bad') ?>
                                <?php else : ?>
                                    <?= Admin::badge(t('Warning'), 'warn') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($check['message']) ?></td>
                            <td class="af-hide-sm af-mono af-small"><?= e($check['file'] !== '' ? $check['file'] . ($check['line'] > 0 ? ':' . $check['line'] : '') : '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <details class="af-help">
        <summary><?= e(t('Measured figures')) ?></summary>
        <pre class="af-mono af-small"><?= e((string) json_encode($report['measured'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
<?php endif; ?>
