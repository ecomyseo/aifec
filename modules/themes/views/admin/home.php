<?php
/**
 * AIFEC — portada: ordenar y ocultar las secciones que entregan los módulos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{id:string,visible:bool}> $sections
 * @var bool                                     $canEdit
 * @var string                                   $action
 */

use Aifec\Admin\Admin;

$app = app();
?>
<?php if ($sections === []) : ?>
    <div class="af-empty">
        <h3><?= e(t('No sections yet')) ?></h3>
        <p class="af-muted"><?= e(t('The modules that add content to the home page will list their sections here.')) ?></p>
    </div>
<?php else : ?>
    <form method="post" action="<?= e($action) ?>" class="af-form">
        <?= Admin::tokenField($app) ?>
        <div class="af-card">
            <div class="af-table-wrap">
                <table class="af-table" data-home-sections>
                    <thead>
                        <tr>
                            <th><?= e(t('Show')) ?></th>
                            <th><?= e(t('Section')) ?></th>
                            <th class="af-right"><?= e(t('Order')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $section) : ?>
                            <tr data-section="<?= e($section['id']) ?>">
                                <td>
                                    <input type="hidden" name="order[]" value="<?= e($section['id']) ?>">
                                    <label><input type="checkbox" name="show[]" value="<?= e($section['id']) ?>"<?= $section['visible'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <span class="af-sr"><?= e(t('Show')) ?></span></label>
                                </td>
                                <td>
                                    <?= e(isset($section['title']) && $section['title'] !== '' ? $section['title'] : $section['id']) ?>
                                    <?php if (isset($section['title']) && $section['title'] !== '' && $section['title'] !== $section['id']) : ?>
                                        <br><span class="af-mono af-muted"><?= e($section['id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="af-right af-nowrap">
                                    <?php if ($canEdit) : ?>
                                        <button type="submit" name="move" value="<?= e($section['id']) ?>:up" class="af-btn af-btn-sm" aria-label="<?= e(t('Move up')) ?>">↑</button>
                                        <button type="submit" name="move" value="<?= e($section['id']) ?>:down" class="af-btn af-btn-sm" aria-label="<?= e(t('Move down')) ?>">↓</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($canEdit) : ?>
            <div class="af-form-actions">
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
