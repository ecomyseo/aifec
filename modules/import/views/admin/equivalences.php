<?php
/**
 * AIFEC — migración, paso 4: equivalencias entre lo del origen y lo de aquí.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<string,array<int,array{id:string,name:string,meaning:string}>> $source
 * @var array<string,array<string,string>> $local
 * @var array<string,array<string,string>> $chosen
 * @var string|null $error
 */

use Aifec\Admin\Admin;

$titles = [
    'order_state' => t('Order states'), 'tax_class' => t('Tax classes'), 'customer_group' => t('Customer groups'), 'payment_method' => t('Payment methods'),
];
$shown = 0;
?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>

<?php if ($error !== null) : ?>
    <div class="af-alert af-alert-bad" role="alert"><?= e(t($error)) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(app()->url()->plain('/admin/import/' . (int) $run['id'] . '/equivalences')) ?>" class="af-form">
    <?= Admin::tokenField(app()) ?>
    <?php foreach ($titles as $kind => $title) : ?>
        <?php if (!isset($source[$kind]) || $source[$kind] === []) : ?>
            <?php continue; ?>
        <?php endif; ?>
        <?php ++$shown; ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e($title) ?></h2></div>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead><tr><th><?= e(t('In the source')) ?></th><th><?= e(t('Here')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($source[$kind] as $value) : ?>
                            <?php $picked = isset($chosen[$kind][$value['id']]) ? (string) $chosen[$kind][$value['id']] : ''; ?>
                            <tr>
                                <td>
                                    <?= e($value['name']) ?>
                                    <input type="hidden" name="eq_src[<?= e($kind) ?>][]" value="<?= e($value['id']) ?>">
                                </td>
                                <td>
                                    <select class="af-select af-input-md" name="eq_to[<?= e($kind) ?>][]" aria-label="<?= e($value['name']) ?>">
                                        <option value=""><?= e(t('Decide by its meaning')) ?></option>
                                        <?php foreach ($local[$kind] as $code => $label) : ?>
                                            <option value="<?= e((string) $code) ?>"<?= (string) $code === $picked ? ' selected' : '' ?>><?= e(t((string) $label)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($shown === 0 && $error === null) : ?>
        <div class="af-card"><div class="af-empty"><h3><?= e(t('Nothing to match: the source has no states, classes, groups or payment methods to read.')) ?></h3></div></div>
    <?php endif; ?>
    <div class="af-form-actions">
        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save and continue')) ?></button>
    </div>
</form>
