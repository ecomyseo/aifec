<?php
/**
 * AIFEC — pantalla de copias de seguridad.
 *
 * @var array<int,array{name:string,bytes:int,created_at:string,kind:string,verified:bool}> $backups
 * @var bool $auto
 * @var int $keep
 * @var string $content
 * @var bool $zip
 * @var bool $canRestore
 * @var string $settingsUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Util\Bytes;

?>
<?php if (!$zip) : ?>
    <div class="af-alert af-alert-warn" role="alert"><?= e(t('The zip extension is not installed: backups only contain the database and cannot be restored from here.')) ?></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-body af-stack">
        <p class="af-muted">
            <?= e($auto ? t('A copy is made every night.') : t('The nightly copy is off.')) ?>
            <?= e(tn('The last {n} copy is kept.', 'The last {n} copies are kept.', $keep)) ?>
        </p>
        <div class="af-toolbar">
            <form method="post" action="<?= e(app()->url()->plain('/admin/tools/backups/create')) ?>" class="af-inline">
                <?= Admin::tokenField(app()) ?>
                <?php if ($zip) : ?>
                    <select class="af-input af-input-md" name="mode" aria-label="<?= e(t('What to copy')) ?>">
                        <option value="full"<?= $content === 'full' ? ' selected' : '' ?>><?= e(t('Complete: database, images and private files')) ?></option>
                        <option value="db"<?= $content === 'db' ? ' selected' : '' ?>><?= e(t('Only the database')) ?></option>
                    </select>
                <?php endif; ?>
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('database') ?><?= e(t('Create a backup now')) ?></button>
            </form>
            <a class="af-btn" href="<?= e($settingsUrl) ?>"><?= Admin::icon('settings') ?><?= e(t('Settings')) ?></a>
        </div>
    </div>
</div>
<div class="af-card">
    <?php if ($backups === []) : ?>
        <div class="af-empty"><h3><?= e(t('There are no backups yet')) ?></h3></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('File')) ?></th>
                        <th><?= e(t('Date')) ?> (UTC)</th>
                        <th class="af-right"><?= e(t('Size')) ?></th>
                        <th><?= e(t('Check')) ?></th>
                        <th class="af-right"><?= e(t('Actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup) : ?>
                        <tr>
                            <td class="af-mono af-small"><?= e($backup['name']) ?></td>
                            <td class="af-nowrap"><?= e($backup['created_at']) ?></td>
                            <td class="af-right af-nowrap"><?= e(Bytes::format($backup['bytes'], 1)) ?></td>
                            <td><?= $backup['verified'] ? Admin::badge(t('Checked'), 'ok') : Admin::badge(t('Not checked'), 'warn') ?></td>
                            <td class="af-cell-actions af-right">
                                <a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/tools/backups/download/' . rawurlencode($backup['name']))) ?>"><?= Admin::icon('download') ?><?= e(t('Download')) ?></a>
                                <form method="post" action="<?= e(app()->url()->plain('/admin/tools/backups/verify/' . rawurlencode($backup['name']))) ?>" class="af-inline">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('check') ?><?= e(t('Check the backup')) ?></button>
                                </form>
                                <?php if ($canRestore && $backup['kind'] === 'zip') : ?>
                                    <a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/tools/backups/restore/' . rawurlencode($backup['name']))) ?>"><?= Admin::icon('refresh') ?><?= e(t('Restore')) ?></a>
                                <?php endif; ?>
                                <form method="post" action="<?= e(app()->url()->plain('/admin/tools/backups/delete/' . rawurlencode($backup['name']))) ?>" class="af-inline">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-sm af-btn-danger" data-af-confirm="<?= e(t('Delete this backup?')) ?>"><?= Admin::icon('trash') ?><?= e(t('Delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
