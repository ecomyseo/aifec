<?php
/**
 * AIFEC — logotipo y icono de la tienda (ajustes store.logo y store.icon, imágenes de la biblioteca de medios).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $fieldsHtml
 * @var bool   $hasMedia
 * @var bool   $canEdit
 * @var string $action
 */

use Aifec\Admin\Admin;

$app = app();
?>
<?php if (!$hasMedia) : ?>
    <div class="af-alert af-alert-warn" role="status"><?= e(t('Turn on the Media module to choose the logo and the icon from the library.')) ?></div>
<?php else : ?>
    <form method="post" action="<?= e($action) ?>" class="af-form">
        <?= Admin::tokenField($app) ?>
        <?= $fieldsHtml /* revisado: HTML que ya escapó el formulario del panel */ ?>
        <?php if ($canEdit) : ?>
            <div class="af-form-actions">
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
