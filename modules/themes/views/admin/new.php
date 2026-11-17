<?php
/**
 * AIFEC — asistente de creación de un tema hijo: puntos de partida de estructura, colores, tipografía y, si hay IA, una
 * propuesta de paleta que la persona revisa antes de crear.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string   $fieldsHtml
 * @var string   $error
 * @var string[] $notes
 * @var string   $info
 * @var bool     $aiAvailable
 * @var string   $action
 */

use Aifec\Admin\Admin;

$app = app();
?>
<?php if ($error !== '') : ?>
    <div class="af-alert af-alert-bad" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($info !== '') : ?>
    <div class="af-alert af-alert-info" role="status"><?= e($info) ?></div>
<?php endif; ?>
<?php foreach ($notes as $note) : ?>
    <div class="af-alert af-alert-warn" role="status"><?= e($note) ?></div>
<?php endforeach; ?>
<form method="post" action="<?= e($action) ?>" class="af-form" novalidate>
    <?= Admin::tokenField($app) ?>
    <?= $fieldsHtml /* revisado: HTML que ya escapó el formulario del panel */ ?>
    <div class="af-form-actions">
        <button type="submit" name="do" value="create" class="af-btn af-btn-primary"><?= Admin::icon('plus') ?><?= e(t('Create theme')) ?></button>
        <?php if ($aiAvailable) : ?>
            <button type="submit" name="do" value="suggest" class="af-btn"><?= Admin::icon('sparkles') ?><?= e(t('Suggest a palette with AI')) ?></button>
        <?php endif; ?>
    </div>
</form>
