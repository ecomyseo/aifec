<?php
/**
 * AIFEC — migración, paso 6: el resumen y la confirmación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<string,mixed>|null $sim
 * @var array<string,mixed>|null $view
 * @var array<string,mixed>|null $open
 * @var array<string,mixed> $options
 */

use Aifec\Admin\Admin;
use Aifec\Modules\Import\Types;

$base = app()->url()->plain('/admin/import/' . (int) $run['id']);
$labels = Types::labels();
$chosen = isset($run['options']['types']) && is_array($run['options']['types']) ? $run['options']['types'] : [];
$personal = array_values(array_intersect($chosen, Types::PERSONAL));
?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>

<?php if ($open !== null) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Another migration is in progress.')) ?></div>
<?php endif; ?>
<?php if ($view === null) : ?>
    <div class="af-alert af-alert-info">
        <?= e(t('You have not run the simulation yet.')) ?>
        <a href="<?= e($base . '/simulation') ?>"><?= e(t('Try it first')) ?></a>
    </div>
<?php endif; ?>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Summary')) ?></h2></div>
    <div class="af-card-body">
        <dl class="af-kv">
            <dt><?= e(t('Source')) ?></dt><dd><?= e(ucfirst((string) $run['source'])) ?> (<?= e((string) $run['mode']) ?>)</dd>
            <dt><?= e(t('What is brought')) ?></dt>
            <dd><?php foreach ($chosen as $type) : ?><span class="af-badge"><?= e(t(isset($labels[$type]) ? $labels[$type] : (string) $type)) ?></span> <?php endforeach; ?></dd>
            <dt><?= e(t('Old addresses')) ?></dt><dd><?= e($options['keep_urls'] ? t('Kept') : t('Redirected')) ?></dd>
            <dt><?= e(t('Images')) ?></dt><dd><?= e($options['import_images'] ? t('Brought') : t('Not brought')) ?></dd>
            <?php if ($options['since'] !== '') : ?><dt><?= e(t('Only what changed since')) ?></dt><dd><?= e($options['since']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($view !== null) : ?>
            <p class="af-small af-muted">
                <?= e(tn('The simulation read {n} record.', 'The simulation read {n} records.', (int) $view['totals']['read'], ['n' => (int) $view['totals']['read']])) ?>
                <?= e(t('{n} would be left alone because they were edited here.', ['n' => (int) $view['totals']['conflict']])) ?>
            </p>
        <?php endif; ?>
        <?php if ($personal !== []) : ?>
            <div class="af-alert af-alert-warn"><?= e(t('This migration brings data about people: customers, orders or team members.')) ?></div>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e($base . '/confirm') ?>" class="af-form">
    <?= Admin::tokenField(app()) ?>
    <div class="af-card">
        <div class="af-card-body">
            <label class="af-check">
                <input type="checkbox" name="ack" value="1" required>
                <?= e(t('I have read that no email will be sent and that the payment methods stay switched off.')) ?>
            </label>
        </div>
    </div>
    <div class="af-form-actions">
        <button type="submit" class="af-btn af-btn-primary"<?= $open !== null ? ' disabled' : '' ?>><?= Admin::icon('bolt') ?><?= e(t('Start the migration')) ?></button>
    </div>
</form>
