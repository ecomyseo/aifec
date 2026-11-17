<?php
/**
 * AIFEC — ERP: una conexión con sus avisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $connection
 * @var array<string,mixed>|null $hook
 * @var string $form
 * @var bool $canEdit
 * @var bool $outbound
 * @var array<int,array<string,mixed>> $deliveries
 * @var array<string,string> $scopes
 * @var string $base
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$id = (int) $connection['id'];
?>
<?php if ($hook !== null && $hook['paused']) : ?>
    <div class="af-alert af-alert-bad"><?= e(t('The messages are paused after five failures in a row.')) ?> <?= e((string) $hook['paused_reason']) ?></div>
<?php elseif ($hook !== null && !$outbound) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Outgoing messages are off in the settings: the messages wait here.')) ?></div>
<?php endif; ?>
<div class="af-grid">
    <div class="af-col-4">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Key')) ?></h2></div>
            <div class="af-card-body af-stack">
                <dl class="af-kv">
                    <dt><?= e(t('Key')) ?></dt><dd class="af-mono">aifec_erp_…<?= e((string) $connection['key_hint']) ?></dd>
                    <dt><?= e(t('Status')) ?></dt>
                    <dd>
                        <?php if ($connection['revoked_at'] !== null) : ?>
                            <?= Admin::badge(t('Revoked'), 'bad') ?>
                        <?php elseif ($connection['active']) : ?>
                            <?= Admin::badge(t('Active'), 'ok') ?>
                        <?php else : ?>
                            <?= Admin::badge(t('Off'), 'warn') ?>
                        <?php endif; ?>
                    </dd>
                    <dt><?= e(t('Last used')) ?></dt><dd><?= e($connection['last_used_at'] !== null ? (string) $connection['last_used_at'] . ' UTC' : '—') ?></dd>
                    <dt><?= e(t('Scopes')) ?></dt>
                    <dd>
                        <?php foreach ($connection['scopes'] as $scope) : ?>
                            <div class="af-small"><code class="af-mono"><?= e((string) $scope) ?></code></div>
                        <?php endforeach; ?>
                    </dd>
                </dl>
                <?php if ($canEdit && $connection['revoked_at'] === null) : ?>
                    <div class="af-toolbar">
                        <form method="post" action="<?= e($base . '/connections/' . $id . '/toggle') ?>">
                            <?= Admin::tokenField(app()) ?>
                            <button type="submit" class="af-btn"><?= e($connection['active'] ? t('Switch off') : t('Switch on')) ?></button>
                        </form>
                        <form method="post" action="<?= e($base . '/connections/' . $id . '/revoke') ?>" data-af-confirm="<?= e(t('Revoke this key? It will never work again.')) ?>">
                            <?= Admin::tokenField(app()) ?>
                            <button type="submit" class="af-btn af-btn-danger"><?= e(t('Revoke')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="af-col-8">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Messages to the ERP')) ?></h2></div>
            <div class="af-card-body af-stack">
                <?php if ($hook !== null) : ?>
                    <div class="af-toolbar">
                        <?= $hook['paused'] ? Admin::badge(t('Paused'), 'bad') : ($hook['active'] ? Admin::badge(t('On'), 'ok') : Admin::badge(t('Off'), 'warn')) ?>
                        <span class="af-muted af-small"><?= e(t('Only what happens after {date} UTC is sent.', ['date' => (string) $hook['since_at']])) ?></span>
                    </div>
                    <?php if ($canEdit) : ?>
                        <div class="af-toolbar">
                            <form method="post" action="<?= e($base . '/webhooks/' . (int) $hook['id'] . '/test') ?>">
                                <?= Admin::tokenField(app()) ?>
                                <button type="submit" class="af-btn"><?= Admin::icon('bolt') ?><?= e(t('Test')) ?></button>
                            </form>
                            <?php if ($hook['paused']) : ?>
                                <form method="post" action="<?= e($base . '/webhooks/' . (int) $hook['id'] . '/reactivate') ?>">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-primary"><?= e(t('Switch on again')) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e($base . '/connections/' . $id . '/secret') ?>" data-af-confirm="<?= e(t('Make a new secret? The old one stops working.')) ?>">
                                <?= Admin::tokenField(app()) ?>
                                <button type="submit" class="af-btn"><?= e(t('New secret')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($canEdit) : ?>
                    <?= $form ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($hook !== null) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Latest messages')) ?></h2></div>
        <?= $this->partial('erp::admin/deliveries-table', ['rows' => $deliveries, 'canEdit' => $canEdit, 'showConnection' => false]) ?>
    </div>
<?php endif; ?>
<details class="af-help">
    <summary><?= e(t('How a message is signed')) ?></summary>
    <p class="af-small"><?= e(t('Each message is a POST with a JSON body and the headers X-Aifec-Event, X-Aifec-Delivery, X-Aifec-Timestamp and X-Aifec-Signature (sha256= plus the HMAC-SHA256 of "<timestamp>.<body>" with the secret). Any answer 2xx counts as received. If it fails it is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours; five failures in a row pause the messages.')) ?></p>
</details>
