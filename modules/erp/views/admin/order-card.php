<?php
/**
 * AIFEC — ficha del pedido: qué ERP lo ha recibido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows external_id, acked_at, name
 */

declare(strict_types=1);

?>
<div class="af-card" data-erp-card>
    <div class="af-card-head"><h2><?= e(t('ERP')) ?></h2></div>
    <div class="af-card-body">
        <?php foreach ($rows as $row) : ?>
            <dl class="af-kv">
                <dt><?= e((string) $row['name']) ?></dt>
                <dd><span class="af-mono"><?= e((string) $row['external_id']) ?></span> <span class="af-muted af-small">(<?= e((string) $row['acked_at']) ?> UTC)</span></dd>
            </dl>
        <?php endforeach; ?>
    </div>
</div>
