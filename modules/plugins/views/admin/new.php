<?php
/**
 * AIFEC — asistente que crea un plugin: identificador, nombre y las piezas que se quieren de ejemplo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $values
 * @var string $error
 */

use Aifec\Admin\Admin;
use Aifec\Modules\Plugins\Generator;

$labels = [
    'setting' => t('A setting'),
    'screen' => t('A panel screen with its table'),
    'listener' => t('A listener of a hook'),
    'slot' => t('A slot in the product page'),
    'block' => t('A dynamic block'),
    'job' => t('A scheduled task'),
    'command' => t('A console command'),
    'payment' => t('An example payment method'),
    'carrier' => t('An example carrier'),
];
?>
<?php if ($error !== '') : ?>
    <div class="af-alert af-alert-bad"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(app()->url()->plain('/admin/plugins/new')) ?>">
    <?= Admin::tokenField(app()) ?>
    <div class="af-card">
        <div class="af-card-body af-stack">
            <div class="af-field">
                <label class="af-label" for="pl-id"><?= e(t('Identifier')) ?></label>
                <div class="af-control">
                    <input type="text" id="pl-id" name="id" class="af-input af-input-md" value="<?= e($values['id']) ?>" maxlength="40" pattern="[a-z0-9][a-z0-9-]{1,39}" required>
                    <p class="af-hint"><?= e(t('Lowercase letters, numbers and hyphens. It is the name of the folder.')) ?></p>
                </div>
            </div>
            <div class="af-field">
                <label class="af-label" for="pl-name"><?= e(t('Name')) ?></label>
                <div class="af-control"><input type="text" id="pl-name" name="name" class="af-input af-input-md" value="<?= e($values['name']) ?>" maxlength="60"></div>
            </div>
            <div class="af-field">
                <label class="af-label" for="pl-description"><?= e(t('Description')) ?></label>
                <div class="af-control"><input type="text" id="pl-description" name="description" class="af-input" value="<?= e($values['description']) ?>" maxlength="160"></div>
            </div>
            <div class="af-field">
                <label class="af-label" for="pl-author"><?= e(t('Author')) ?></label>
                <div class="af-control"><input type="text" id="pl-author" name="author" class="af-input af-input-md" value="<?= e($values['author']) ?>" maxlength="80"></div>
            </div>
            <div class="af-field">
                <div class="af-label"><?= e(t('Pieces to include')) ?></div>
                <div class="af-control">
                    <?php foreach (Generator::FEATURES as $feature) : ?>
                        <div><label><input type="checkbox" name="features[]" value="<?= e($feature) ?>"<?= in_array($feature, $values['features'], true) ? ' checked' : '' ?>> <?= e($labels[$feature]) ?></label></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('plus') ?><?= e(t('Create plugin')) ?></button></div>
        </div>
    </div>
</form>
