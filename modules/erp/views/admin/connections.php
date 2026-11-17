<?php
/**
 * AIFEC — ERP: lista de conexiones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var bool $canEdit
 * @var string $newUrl
 * @var bool $outbound
 * @var string $settingsUrl
 * @var string $apiUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<?php if (!$outbound) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Outgoing messages are off: nothing is sent to the ERP.')) ?> <a href="<?= e($settingsUrl) ?>"><?= e(t('Settings')) ?></a></div>
<?php endif; ?>
<div class="af-toolbar">
    <?php if ($canEdit) : ?>
        <a class="af-btn af-btn-primary" href="<?= e($newUrl) ?>"><?= Admin::icon('plus') ?><?= e(t('New connection')) ?></a>
    <?php endif; ?>
    <a class="af-btn" href="<?= e($apiUrl . '/openapi.json') ?>"><?= Admin::icon('code') ?>openapi.json</a>
    <span class="af-muted af-small"><?= e(t('API address')) ?>: <code class="af-mono"><?= e($apiUrl) ?></code></span>
</div>
<div class="af-card">
    <?php if ($rows === []) : ?>
        <div class="af-empty"><h3><?= e(t('No connections yet')) ?></h3><p><?= e(t('Create one to give an ERP its own key.')) ?></p></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Name')) ?></th>
                        <th><?= e(t('Key')) ?></th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Messages')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Last used')) ?> (UTC)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php $c = $row['connection']; $hook = $row['webhook']; ?>
                        <tr>
                            <td><a href="<?= e($row['url']) ?>"><?= e((string) $c['name']) ?></a></td>
                            <td class="af-mono af-small">aifec_erp_…<?= e((string) $c['key_hint']) ?></td>
                            <td>
                                <?php if ($c['revoked_at'] !== null) : ?>
                                    <?= Admin::badge(t('Revoked'), 'bad') ?>
                                <?php elseif ($c['active']) : ?>
                                    <?= Admin::badge(t('Active'), 'ok') ?>
                                <?php else : ?>
                                    <?= Admin::badge(t('Off'), 'warn') ?>
                                <?php endif; ?>
                            </td>
                            <td class="af-hide-sm">
                                <?php if ($hook === null) : ?>
                                    <span class="af-muted">—</span>
                                <?php elseif ($hook['paused']) : ?>
                                    <?= Admin::badge(t('Paused'), 'bad') ?>
                                <?php elseif ($hook['active']) : ?>
                                    <?= Admin::badge(t('On'), 'ok') ?>
                                <?php else : ?>
                                    <?= Admin::badge(t('Off'), 'warn') ?>
                                <?php endif; ?>
                            </td>
                            <td class="af-hide-sm af-nowrap"><?= e($c['last_used_at'] !== null ? (string) $c['last_used_at'] : '—') ?></td>
                            <td class="af-right"><a class="af-btn af-btn-sm" href="<?= e($row['url']) ?>"><?= e(t('Open')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
