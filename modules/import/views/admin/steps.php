<?php
/**
 * AIFEC — migración: la barra de pasos del asistente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step clave del paso actual
 * @var int $id migración (0 en el primer paso)
 */

use Aifec\Modules\Import\ImportController;

$labels = ImportController::STEPS;
$order = array_keys($labels);
$current = (int) array_search($step, $order, true);
$back = ['connection', 'types', 'equivalences', 'simulation', 'confirm'];
?>
<ol class="af-imp-steps" aria-label="<?= e(t('Steps')) ?>">
    <?php foreach ($order as $i => $key) : ?>
        <?php
        $state = $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
        $link = $id > 0 && $i < $current && in_array($key, $back, true) && in_array($step, $back, true) ? app()->url()->plain('/admin/import/' . $id . '/' . $key) : '';
        ?>
        <li class="af-imp-step af-imp-step-<?= e($state) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
            <span class="af-imp-step-n"><?= (int) ($i + 1) ?></span>
            <?php if ($link !== '') : ?>
                <a href="<?= e($link) ?>"><?= e(t($labels[$key])) ?></a>
            <?php else : ?>
                <span><?= e(t($labels[$key])) ?></span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
