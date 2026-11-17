<?php
/**
 * AIFEC — informe del validador de un tema: una fila por comprobación, con su gravedad y el fichero afectado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $row    el tema
 * @var array<string,mixed> $report resultado de Themes::validate()
 * @var bool                $canEdit
 */

use Aifec\Admin\Admin;

$app = app();
?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <h2><?= e($row['name']) ?></h2>
        <p>
            <?= Admin::badge($report['ok'] ? t('Passes the validator') : t('Has blocking errors'), $report['ok'] ? 'ok' : 'bad') ?>
            <span class="af-muted"><?= e(t('Score {score}', ['score' => (string) $report['score']])) ?> · <?= e(tn('{n} blocking error', '{n} blocking errors', (int) $report['errors'])) ?> · <?= e(tn('{n} warning', '{n} warnings', (int) $report['warnings'])) ?></span>
        </p>
        <div class="af-row">
            <?php if ($canEdit && $report['ok'] && !$row['active']) : ?>
                <form method="post" action="<?= e($app->url()->plain('/admin/themes/' . $row['slug'] . '/activate')) ?>">
                    <?= Admin::tokenField($app) ?>
                    <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Activate')) ?></button>
                </form>
            <?php endif; ?>
            <a class="af-btn" href="<?= e($app->url()->plain('/admin/themes')) ?>"><?= e(t('Back to the themes')) ?></a>
        </div>
    </div>
</div>
<div class="af-card">
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr>
                    <th><?= e(t('Result')) ?></th>
                    <th><?= e(t('Check')) ?></th>
                    <th><?= e(t('File')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['checks'] as $check) : ?>
                    <tr data-check="<?= e($check['id']) ?>">
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
                        <td class="af-mono af-small"><?= e($check['file']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
