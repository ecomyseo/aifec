<?php
/**
 * AIFEC — ERP: historial de pasadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $base
 */

declare(strict_types=1);

?>
<div class="af-card">
    <?php if ($rows === []) : ?>
        <div class="af-empty"><h3><?= e(t('No runs yet')) ?></h3></div>
    <?php else : ?>
        <?= $this->partial('erp::admin/runs-table', ['rows' => $rows, 'base' => $base, 'showProfile' => true]) ?>
    <?php endif; ?>
</div>
