<?php
/**
 * AIFEC — confirmar la restauración de una copia.
 *
 * @var string $name
 * @var array<string,mixed> $manifest
 * @var bool $otherSite
 * @var bool $hasFiles
 * @var string $shop
 * @var string $action
 * @var string $backUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$db = $manifest['database'];

?>
<div class="af-alert af-alert-warn" role="alert"><?= e(t('This replaces the database and the files of the shop with those of the backup. A copy of what is there is made first.')) ?></div>
<?php if ($otherSite) : ?>
    <div class="af-alert af-alert-bad" role="alert"><?= e(t('The backup is from another site ({url}). Restoring it moves a shop here; the address of this site is not changed.', ['url' => (string) $manifest['site']['url']])) ?></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <p><strong class="af-mono"><?= e($name) ?></strong></p>
        <p class="af-muted">
            <?= e(t('AIFEC {version}, made on {date} (UTC).', ['version' => (string) $manifest['version'], 'date' => (string) $manifest['created_at']])) ?>
            <?= e(t('{tables} tables and {rows} rows.', ['tables' => (int) $db['tables'], 'rows' => (int) $db['rows']])) ?>
            <?= e($hasFiles ? t('{files} files.', ['files' => isset($manifest['files']) ? (int) $manifest['files'] : 0]) : t('It has no files, only the database.')) ?>
        </p>
        <form method="post" action="<?= e($action) ?>" class="af-form" novalidate>
            <?= Admin::tokenField(app()) ?>
            <div class="af-field">
                <label class="af-label" for="af-restore-only"><?= e(t('What to restore')) ?></label>
                <div class="af-control">
                    <select class="af-input af-input-md" id="af-restore-only" name="only">
                        <option value=""><?= e($hasFiles ? t('Everything: database and files') : t('The database')) ?></option>
                        <?php if ($hasFiles) : ?>
                            <option value="db"><?= e(t('Only the database')) ?></option>
                            <option value="files"><?= e(t('Only the files')) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="af-field">
                <label class="af-label" for="af-restore-shop"><?= e(t('Type the name of the shop to confirm: {name}', ['name' => $shop])) ?></label>
                <div class="af-control"><input class="af-input" id="af-restore-shop" type="text" name="shop" autocomplete="off" required></div>
            </div>
            <div class="af-field">
                <label class="af-label" for="af-restore-password"><?= e(t('Your password')) ?></label>
                <div class="af-control"><input class="af-input" id="af-restore-password" type="password" name="password" autocomplete="current-password" required></div>
            </div>
            <?php if ($otherSite) : ?>
                <div class="af-field">
                    <label class="af-check"><input type="checkbox" name="other_site" value="1"><span><?= e(t('I know it is a backup of another site and I want to move that shop here')) ?></span></label>
                </div>
            <?php endif; ?>
            <div class="af-form-actions">
                <button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Restore this backup now? The shop goes into maintenance mode while it is restored.')) ?>"><?= Admin::icon('refresh') ?><?= e(t('Restore this backup')) ?></button>
                <a class="af-btn" href="<?= e($backUrl) ?>"><?= e(t('Cancel')) ?></a>
            </div>
        </form>
    </div>
</div>
