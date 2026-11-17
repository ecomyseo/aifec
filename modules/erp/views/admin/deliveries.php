<?php
/**
 * AIFEC — ERP: todos los avisos al ERP.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $status
 * @var bool $canEdit
 * @var string $listUrl
 */

declare(strict_types=1);

$filters = ['' => t('All'), 'pending' => t('Waiting'), 'held' => t('Held back'), 'sent' => t('Sent'), 'failed' => t('Failed'), 'expired' => t('Expired')];
?>
<div class="af-toolbar">
    <?php foreach ($filters as $code => $label) : ?>
        <a class="af-btn af-btn-sm<?= $status === $code ? ' af-btn-primary' : '' ?>" href="<?= e($code === '' ? $listUrl : $listUrl . '?status=' . $code) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<div class="af-card">
    <?= $this->partial('erp::admin/deliveries-table', ['rows' => $rows, 'canEdit' => $canEdit, 'showConnection' => true]) ?>
</div>
