<?php
/**
 * AIFEC — GM coming soon: la ficha de una petición de aviso (solo lectura).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $row
 * @var string              $product_name
 */

declare(strict_types=1);

?>
<div class="af-card">
    <dl class="af-kv">
        <dt><?= e(t('Email')) ?></dt><dd><?= e((string) $row['email']) ?></dd>
        <dt><?= e(t('Product')) ?></dt><dd><?= e($product_name) ?></dd>
        <dt><?= e(t('Asked on')) ?></dt><dd><?= e((string) $row['created_at']) ?></dd>
        <dt><?= e(t('Agreed on')) ?></dt><dd><?= e((string) $row['consented_at']) ?></dd>
        <dt><?= e(t('Told on')) ?></dt><dd><?= e($row['notified_at'] !== null ? (string) $row['notified_at'] : '—') ?></dd>
    </dl>
</div>
