<?php
/**
 * AIFEC — instalador, pantalla 2: base de datos (SQLite o MySQL/MariaDB).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var callable $t
 * @var string $action
 * @var string $state
 * @var array<string,string> $input
 * @var array<string,string> $errors
 * @var bool $hasSqlite
 * @var bool $hasMysql
 */

$this->layout('install::layout');
$field = static function (string $name, string $label, string $control, string $hint = '') use ($errors): string {
    return '<div class="af-field' . (isset($errors[$name]) ? ' af-field-invalid' : '') . '">'
        . '<label class="af-label" for="af-' . e($name) . '">' . e($label) . '</label>'
        . '<div class="af-control">' . $control
        . ($hint !== '' ? '<div class="af-hint">' . e($hint) . '</div>' : '')
        . (isset($errors[$name]) ? '<div class="af-error">' . e($errors[$name]) . '</div>' : '')
        . '</div></div>';
};
$text = static function (string $name, string $type = 'text', string $value = '', string $extra = ''): string {
    return '<input class="af-input" id="af-' . e($name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '>';
};
?>
<p class="af-muted"><?= e($t('Choose where AIFEC keeps its data.')) ?></p>

<form method="post" action="<?= e($action) ?>" novalidate>
    <input type="hidden" name="step" value="2">
    <input type="hidden" name="state" value="<?= e($state) ?>">

    <label class="af-install-radio">
        <input type="radio" name="db_driver" value="sqlite"<?= $input['driver'] !== 'mysql' ? ' checked' : '' ?><?= $hasSqlite ? '' : ' disabled' ?>>
        <span><strong><?= e($t('SQLite (nothing to configure)')) ?></strong><br><span class="af-muted af-small"><?= e($t('One file inside the data folder. The easiest choice for a first shop.')) ?></span></span>
    </label>
    <label class="af-install-radio">
        <input type="radio" name="db_driver" value="mysql"<?= $input['driver'] === 'mysql' ? ' checked' : '' ?><?= $hasMysql ? '' : ' disabled' ?>>
        <span><strong><?= e($t('MySQL or MariaDB')) ?></strong><br><span class="af-muted af-small"><?= e($hasMysql ? $t('Fill in the details below. The database must already exist.') : $t('The pdo_mysql extension is not installed on this server.')) ?></span></span>
    </label>
    <?php if (isset($errors['db_driver'])) : ?>
        <div class="af-error"><?= e($errors['db_driver']) ?></div>
    <?php endif; ?>

    <h2 class="af-install-section"><?= e($t('MySQL or MariaDB')) ?></h2>
    <?= $field('db_host', $t('Server'), $text('db_host', 'text', $input['host'], ' autocomplete="off"')) ?>
    <?= $field('db_port', $t('Port'), $text('db_port', 'text', $input['port'], ' inputmode="numeric" autocomplete="off"')) ?>
    <?= $field('db_name', $t('Database name'), $text('db_name', 'text', $input['name'], ' autocomplete="off"')) ?>
    <?= $field('db_user', $t('User'), $text('db_user', 'text', $input['user'], ' autocomplete="username"')) ?>
    <?= $field('db_password', $t('Password'), $text('db_password', 'password', '', ' autocomplete="new-password"'), $t('Leave it empty to keep the one you typed before.')) ?>
    <?= $field('db_prefix', $t('Table prefix'), $text('db_prefix', 'text', $input['prefix'], ' autocomplete="off"'), $t('Lets several shops share one database.')) ?>

    <div class="af-install-actions">
        <button type="submit" class="af-btn af-btn-primary" name="go" value="next"><?= e($t('Continue')) ?></button>
        <button type="submit" class="af-btn" name="go" value="back" formnovalidate><?= e($t('Back')) ?></button>
    </div>
</form>
