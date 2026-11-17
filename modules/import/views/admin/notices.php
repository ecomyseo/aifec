<?php
/**
 * AIFEC — migración: los últimos avisos de la migración (descartados, choques y notas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $view lo que devuelve ImportController::view()
 */

?>
<?php if ($view['notices'] === []) : ?>
    <p class="af-muted af-small"><?= e(t('No warnings so far.')) ?></p>
<?php else : ?>
    <ul class="af-imp-notices">
        <?php foreach ($view['notices'] as $notice) : ?>
            <li>
                <span class="af-badge"><?= e((string) $notice['result']) ?></span>
                <span class="af-mono af-small"><?= e((string) $notice['type']) ?><?= $notice['source_id'] !== '' ? ' #' . e((string) $notice['source_id']) : '' /* revisado: escapado con e() */ ?></span>
                <?= e(\Aifec\Modules\Import\Report::translateNote((string) $notice['reason'])) ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
