<?php
/**
 * AIFEC — ERP: tabla de avisos al ERP (parcial).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var bool $canEdit
 * @var bool $showConnection
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$kinds = ['sent' => 'ok', 'pending' => 'info', 'sending' => 'info', 'held' => 'warn', 'failed' => 'bad', 'expired' => 'warn'];
$labels = ['sent' => t('Sent'), 'pending' => t('Waiting'), 'sending' => t('Sending'), 'held' => t('Held back'), 'failed' => t('Failed'), 'expired' => t('Expired')];
?>
<?php if ($rows === []) : ?>
    <div class="af-empty"><h3><?= e(t('No messages yet')) ?></h3></div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr>
                    <th><?= e(t('Event')) ?></th>
                    <?php if (!empty($showConnection)) : ?><th><?= e(t('Connection')) ?></th><?php endif; ?>
                    <th><?= e(t('Status')) ?></th>
                    <th class="af-right"><?= e(t('Tries')) ?></th>
                    <th class="af-hide-sm"><?= e(t('Created')) ?> (UTC)</th>
                    <th class="af-hide-sm"><?= e(t('Answer')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $status = (string) $row['status']; ?>
                    <tr>
                        <td class="af-mono af-small"><?= e((string) $row['event']) ?></td>
                        <?php if (!empty($showConnection)) : ?><td><?= e((string) ($row['connection_name'] ?? '')) ?></td><?php endif; ?>
                        <td><?= Admin::badge(isset($labels[$status]) ? $labels[$status] : $status, isset($kinds[$status]) ? $kinds[$status] : '') ?></td>
                        <td class="af-right"><?= e((int) $row['attempts']) ?></td>
                        <td class="af-hide-sm af-nowrap"><?= e((string) $row['created_at']) ?></td>
                        <td class="af-hide-sm af-small"><?= e($row['response_code'] !== null ? (string) $row['response_code'] : '') ?> <?= e((string) ($row['error'] ?? '')) ?></td>
                        <td class="af-right">
                            <?php if (!empty($canEdit) && in_array($status, ['sent', 'failed', 'expired', 'held'], true)) : ?>
                                <form method="post" action="<?= e(app()->url()->plain('/admin/erp/deliveries/' . (int) $row['id'] . '/resend')) ?>" class="af-inline">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Send again')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
