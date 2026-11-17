<?php
/**
 * AIFEC — migración: una barra de progreso por tipo y los contadores. La repinta import.js cada 5 segundos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $view lo que devuelve ImportController::view()
 */

$names = ['done' => t('Done'), 'current' => t('Running'), 'waiting' => t('Waiting')];
?>
<div class="af-imp-total">
    <progress class="af-imp-progress" max="100" value="<?= (int) $view['percent'] ?>" data-af-import-percent></progress>
    <span data-af-import-percent-text><?= (int) $view['percent'] ?> %</span>
</div>
<?php foreach ($view['types'] as $row) : ?>
    <div class="af-imp-row" data-af-import-type="<?= e((string) $row['type']) ?>">
        <div class="af-imp-label"><?= e((string) $row['label']) ?> <span class="af-muted af-small" data-af-import-state><?= e($names[$row['state']]) ?></span></div>
        <progress class="af-imp-progress" max="100"<?= $row['percent'] !== null ? ' value="' . (int) $row['percent'] . '"' : '' /* revisado: entero */ ?>></progress>
        <div class="af-imp-nums af-small af-muted" data-af-import-nums>
            <?= (int) $row['counters']['read'] ?><?= $row['total'] !== null ? ' / ' . (int) $row['total'] : '' /* revisado: entero */ ?>
            · <?= e(t('created')) ?> <?= (int) $row['counters']['created'] ?>
            · <?= e(t('updated')) ?> <?= (int) $row['counters']['updated'] ?>
            · <?= e(t('unchanged')) ?> <?= (int) $row['counters']['unchanged'] ?>
            · <?= e(t('conflicts')) ?> <?= (int) $row['counters']['conflict'] ?>
            · <?= e(t('skipped')) ?> <?= (int) $row['counters']['skipped'] ?>
        </div>
    </div>
<?php endforeach; ?>
