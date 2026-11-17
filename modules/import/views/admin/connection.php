<?php
/**
 * AIFEC — migración, paso 2: los datos de conexión y la prueba.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<string,mixed> $card
 * @var array<string,string> $values
 * @var array<string,bool> $hasSecret
 * @var array<string,string> $dbValues
 * @var bool $hasDbPassword
 * @var array<string,string> $errors
 * @var array<string,mixed>|null $probe
 */

use Aifec\Admin\Admin;
use Aifec\Modules\Import\Types;

$error = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<div class="af-error" role="alert">' . e(t($errors[$name])) . '</div>' : '';
};
$labels = Types::labels();
?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>

<?php if ($probe !== null) : ?>
    <div class="af-alert af-alert-<?= !empty($probe['ok']) ? 'ok' : 'bad' ?>" role="status">
        <strong><?= e(!empty($probe['ok']) ? t('The connection works.') : t('The connection did not work.')) ?></strong>
        <?= e(t((string) $probe['message'])) ?>
    </div>
    <?php if (!empty($probe['ok'])) : ?>
        <div class="af-card">
            <div class="af-card-body">
                <dl class="af-kv">
                    <?php if (!empty($probe['version'])) : ?><dt><?= e(t('Version')) ?></dt><dd><?= e((string) $probe['version']) ?></dd><?php endif; ?>
                    <?php if (!empty($probe['currency'])) : ?><dt><?= e(t('Currency')) ?></dt><dd><?= e((string) $probe['currency']) ?></dd><?php endif; ?>
                    <?php if (!empty($probe['languages'])) : ?><dt><?= e(t('Languages')) ?></dt><dd><?= e(implode(', ', array_map('strval', (array) $probe['languages']))) ?></dd><?php endif; ?>
                    <?php if (!empty($probe['shops'])) : ?>
                        <dt><?= e(t('Shops')) ?></dt>
                        <dd><?php foreach ((array) $probe['shops'] as $shop) : ?><span class="af-badge"><?= e((string) $shop['name']) ?> (<?= e((string) $shop['id']) ?>)</span> <?php endforeach; ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($probe['counts'])) : ?>
                    <div class="af-table-wrap">
                        <table class="af-table">
                            <thead><tr><th><?= e(t('What')) ?></th><th class="af-num"><?= e(t('How many')) ?></th></tr></thead>
                            <tbody>
                                <?php foreach ((array) $probe['counts'] as $type => $count) : ?>
                                    <tr>
                                        <td><?= e(t(isset($labels[$type]) ? $labels[$type] : (string) $type)) ?></td>
                                        <td class="af-num"><?= $count === null ? '—' : (int) $count /* revisado: entero */ ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php foreach ((array) (isset($probe['warnings']) ? $probe['warnings'] : []) as $warning) : ?>
                    <div class="af-alert af-alert-warn"><?= e(t((string) $warning)) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post" action="<?= e(app()->url()->plain('/admin/import/' . (int) $run['id'] . '/connection')) ?>" class="af-form" novalidate autocomplete="off">
    <?= Admin::tokenField(app()) ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t((string) $card['label'])) ?></h2></div>
        <div class="af-card-body">
            <?php foreach ($card['fields'] as $name => $field) : ?>
                <?php
                $name = (string) $name;
                $type = isset($field['type']) ? (string) $field['type'] : 'text';
                $inputId = 'imp-f-' . $name;
                ?>
                <div class="af-field<?= isset($errors[$name]) ? ' af-field-invalid' : '' ?>">
                    <label class="af-label" for="<?= e($inputId) ?>"><?= e(t((string) $field['label'])) ?><?= !empty($field['required']) ? ' *' : '' ?></label>
                    <div class="af-control">
                        <?php if ($type === 'select') : ?>
                            <select class="af-select af-input-md" id="<?= e($inputId) ?>" name="<?= e($name) ?>">
                                <?php $current = $values[$name] !== '' ? $values[$name] : (isset($field['default']) ? (string) $field['default'] : ''); ?>
                                <?php foreach ((array) (isset($field['options']) ? $field['options'] : []) as $value => $text) : ?>
                                    <option value="<?= e((string) $value) ?>"<?= (string) $value === $current ? ' selected' : '' ?>><?= e(t((string) $text)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($type === 'secret') : ?>
                            <input class="af-input af-input-md" id="<?= e($inputId) ?>" type="password" name="<?= e($name) ?>" value="" autocomplete="new-password"
                                placeholder="<?= e(!empty($hasSecret[$name]) ? t('Saved: leave it empty to keep it') : '') ?>">
                        <?php else : ?>
                            <input class="af-input af-input-md" id="<?= e($inputId) ?>" type="<?= $type === 'url' ? 'url' : 'text' ?>" name="<?= e($name) ?>" value="<?= e($values[$name]) ?>"
                                autocomplete="off" placeholder="<?= e(isset($field['hint']) ? t((string) $field['hint']) : '') ?>">
                        <?php endif; ?>
                        <?php if (isset($field['hint']) && $field['hint'] !== '' && $type !== 'text' && $type !== 'url') : ?>
                            <div class="af-hint"><?= e(t((string) $field['hint'])) ?></div>
                        <?php endif; ?>
                        <?= $error($name) /* revisado: error() escapa con e() */ ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($run['mode'] === 'db') : ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Database of the copy')) ?></h2></div>
            <div class="af-card-body">
                <div class="af-field<?= isset($errors['db_host']) ? ' af-field-invalid' : '' ?>">
                    <label class="af-label" for="imp-db-host"><?= e(t('Server')) ?> *</label>
                    <div class="af-control"><input class="af-input af-input-md" id="imp-db-host" type="text" name="db_host" value="<?= e($dbValues['db_host']) ?>" autocomplete="off"><?= $error('db_host') /* revisado: error() escapa con e() */ ?></div>
                </div>
                <div class="af-field<?= isset($errors['db_port']) ? ' af-field-invalid' : '' ?>">
                    <label class="af-label" for="imp-db-port"><?= e(t('Port')) ?></label>
                    <div class="af-control"><input class="af-input af-input-sm" id="imp-db-port" type="text" name="db_port" value="<?= e($dbValues['db_port']) ?>" autocomplete="off"><?= $error('db_port') /* revisado: error() escapa con e() */ ?></div>
                </div>
                <div class="af-field<?= isset($errors['db_name']) ? ' af-field-invalid' : '' ?>">
                    <label class="af-label" for="imp-db-name"><?= e(t('Database')) ?> *</label>
                    <div class="af-control"><input class="af-input af-input-md" id="imp-db-name" type="text" name="db_name" value="<?= e($dbValues['db_name']) ?>" autocomplete="off"><?= $error('db_name') /* revisado: error() escapa con e() */ ?></div>
                </div>
                <div class="af-field<?= isset($errors['db_user']) ? ' af-field-invalid' : '' ?>">
                    <label class="af-label" for="imp-db-user"><?= e(t('User')) ?> *</label>
                    <div class="af-control"><input class="af-input af-input-md" id="imp-db-user" type="text" name="db_user" value="<?= e($dbValues['db_user']) ?>" autocomplete="off"><?= $error('db_user') /* revisado: error() escapa con e() */ ?></div>
                </div>
                <div class="af-field">
                    <label class="af-label" for="imp-db-password"><?= e(t('Password')) ?></label>
                    <div class="af-control">
                        <input class="af-input af-input-md" id="imp-db-password" type="password" name="db_password" value="" autocomplete="new-password"
                            placeholder="<?= e($hasDbPassword ? t('Saved: leave it empty to keep it') : '') ?>">
                        <div class="af-hint"><?= e(t('The connection is read-only: nothing is ever written to that database.')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="af-form-actions">
        <button type="submit" name="probe" value="1" class="af-btn"><?= Admin::icon('refresh') ?><?= e(t('Test the connection')) ?></button>
        <span class="af-top-space"></span>
        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save and continue')) ?></button>
    </div>
</form>
