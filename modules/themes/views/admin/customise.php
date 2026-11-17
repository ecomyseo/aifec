<?php
/**
 * AIFEC — personalizador de un tema: a la izquierda el formulario con una pestaña por grupo de opciones; a la derecha la vista
 * previa de la tienda en un <iframe>, con selector de página y de tamaño. «Vista previa» no publica nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $row
 * @var string                         $slug
 * @var string                         $fieldsHtml
 * @var string                         $active        pestaña abierta
 * @var bool                           $isActive      ¿es el tema activo?
 * @var bool                           $canEdit
 * @var array<int,array<string,string>> $pages         páginas que se pueden previsualizar (label, url)
 * @var string                         $previewParam
 * @var string                         $action
 * @var bool                           $hasDraft
 */

use Aifec\Admin\Admin;

$app = app();
$this->push('admin.head', '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'themes', 'admin.css')) . '">');
$this->push('admin.foot', '<script src="' . e($app->url()->asset('m', 'themes', 'admin.js')) . '" defer></script>');
?>
<?php if (!$isActive) : ?>
    <div class="af-alert af-alert-info" role="status"><?= e(t('This theme is not the active one. Activate it to see the preview of your changes.')) ?></div>
<?php elseif ($hasDraft) : ?>
    <div class="af-alert af-alert-info" role="status"><?= e(t('You are seeing a draft: it is not published yet.')) ?></div>
<?php endif; ?>
<div class="af-grid th-custom">
    <div class="af-col-6">
        <?php if ($canEdit) : ?>
            <form method="post" action="<?= e($action) ?>" class="af-form" novalidate>
                <?= Admin::tokenField($app) ?>
                <input type="hidden" name="_tab" value="<?= e($active) ?>" data-af-tab-input>
                <?= $fieldsHtml /* revisado: HTML que ya escapó el formulario del panel */ ?>
                <div class="af-form-actions">
                    <?php if ($isActive) : ?>
                        <button type="submit" name="do" value="preview" class="af-btn"><?= Admin::icon('eye') ?><?= e(t('Preview')) ?></button>
                    <?php endif; ?>
                    <button type="submit" name="do" value="publish" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Publish')) ?></button>
                    <span class="af-top-space"></span>
                    <button type="submit" name="do" value="reset" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Go back to the factory values of this theme?')) ?>"><?= Admin::icon('refresh') ?><?= e(t('Restore factory values')) ?></button>
                </div>
            </form>
        <?php else : ?>
            <?= $fieldsHtml /* revisado: HTML que ya escapó el formulario del panel */ ?>
        <?php endif; ?>
    </div>
    <div class="af-col-6">
        <?php if ($isActive) : ?>
            <div class="af-card">
                <div class="af-card-body af-stack">
                    <div class="af-toolbar">
                        <label class="af-small af-muted" for="th-page"><?= e(t('Page')) ?></label>
                        <select id="th-page" class="af-select" data-th-page>
                            <?php foreach ($pages as $page) : ?>
                                <option value="<?= e($page['url']) ?>"><?= e($page['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="af-small af-muted" for="th-size"><?= e(t('Size')) ?></label>
                        <select id="th-size" class="af-select" data-th-size>
                            <option value="100%"><?= e(t('Desktop')) ?></option>
                            <option value="820px"><?= e(t('Tablet')) ?></option>
                            <option value="390px"><?= e(t('Mobile')) ?></option>
                        </select>
                    </div>
                    <div class="th-frame-wrap">
                        <iframe class="th-frame" data-th-frame data-param="<?= e($previewParam) ?>" title="<?= e(t('Preview of the shop')) ?>" src="<?= e(url('/', [$previewParam => 1])) ?>"></iframe>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
