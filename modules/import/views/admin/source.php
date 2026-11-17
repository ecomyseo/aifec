<?php
/**
 * AIFEC — migración, paso 1: de dónde viene la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,array<string,mixed>> $readers
 * @var array<string,mixed>|null $open
 */

use Aifec\Admin\Admin;

?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>
<?php if ($open !== null) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Another migration is in progress: you can prepare this one, but not start it until the other ends.')) ?></div>
<?php endif; ?>
<?php if ($readers === []) : ?>
    <div class="af-card"><div class="af-empty"><h3><?= e(t('No readers are available')) ?></h3></div></div>
<?php else : ?>
    <form method="post" action="<?= e(app()->url()->plain('/admin/import/new')) ?>">
        <?= Admin::tokenField(app()) ?>
        <div class="af-imp-cards">
            <?php foreach ($readers as $key => $card) : ?>
                <label class="af-card af-imp-card">
                    <span class="af-card-body">
                        <input type="radio" name="reader" value="<?= e((string) $key) ?>" required>
                        <strong><?= e(t((string) $card['label'])) ?></strong>
                        <span class="af-muted af-small"><?= e(t((string) (isset($card['help']) ? $card['help'] : ''))) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="af-card">
            <div class="af-card-body">
                <label class="af-label" for="imp-label"><?= e(t('Name (optional)')) ?></label>
                <input class="af-input af-input-md" id="imp-label" type="text" name="label" maxlength="120" placeholder="<?= e(t('For example: the old shop')) ?>">
            </div>
        </div>
        <div class="af-form-actions">
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Continue')) ?></button>
        </div>
    </form>
<?php endif; ?>
