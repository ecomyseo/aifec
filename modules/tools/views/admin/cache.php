<?php
/**
 * AIFEC — pantalla de caché y mantenimiento.
 *
 * @var array{data:array{files:int,bytes:int},pages:array{files:int,bytes:int}} $stats
 * @var bool $maintenance
 * @var bool $canEdit
 * @var string $settingsUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Util\Bytes;

?>
<?php if ($maintenance) : ?>
    <div class="af-alert af-alert-warn"><?= e(t('Maintenance mode is on: visitors see a maintenance page.')) ?></div>
<?php endif; ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Maintenance mode')) ?></h2></div>
    <div class="af-card-body af-stack">
        <p class="af-muted"><?= e($maintenance ? t('The shop is closed to visitors. The panel keeps working.') : t('The shop is open.')) ?></p>
        <?php if ($canEdit) : ?>
            <div class="af-toolbar">
                <form method="post" action="<?= e(app()->url()->plain('/admin/tools/cache/maintenance')) ?>" class="af-inline">
                    <?= Admin::tokenField(app()) ?>
                    <input type="hidden" name="state" value="<?= $maintenance ? 'off' : 'on' ?>">
                    <?php if ($maintenance) : ?>
                        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Turn maintenance mode off')) ?></button>
                    <?php else : ?>
                        <button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Visitors will see a maintenance page. Continue?')) ?>"><?= Admin::icon('alert') ?><?= e(t('Turn maintenance mode on')) ?></button>
                    <?php endif; ?>
                </form>
                <a class="af-btn" href="<?= e($settingsUrl) ?>"><?= Admin::icon('settings') ?><?= e(t('Message and settings')) ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="af-grid">
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Data cache')) ?></h2></div>
            <div class="af-card-body af-stack">
                <p><?= e(tn('{n} entry', '{n} entries', $stats['data']['files'])) ?> · <?= e(Bytes::format($stats['data']['bytes'], 1)) ?></p>
                <?php if ($canEdit) : ?>
                    <form method="post" action="<?= e(app()->url()->plain('/admin/tools/cache/clear-data')) ?>" class="af-inline">
                        <?= Admin::tokenField(app()) ?>
                        <button type="submit" class="af-btn"><?= Admin::icon('trash') ?><?= e(t('Empty the data cache')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Page cache')) ?></h2></div>
            <div class="af-card-body af-stack">
                <p><?= e(tn('{n} page', '{n} pages', $stats['pages']['files'])) ?> · <?= e(Bytes::format($stats['pages']['bytes'], 1)) ?></p>
                <?php if ($canEdit) : ?>
                    <form method="post" action="<?= e(app()->url()->plain('/admin/tools/cache/clear-pages')) ?>" class="af-inline">
                        <?= Admin::tokenField(app()) ?>
                        <button type="submit" class="af-btn"><?= Admin::icon('trash') ?><?= e(t('Empty the page cache')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($canEdit) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Static files')) ?></h2></div>
        <div class="af-card-body af-stack">
            <p class="af-muted"><?= e(t('Copies the scripts and styles of the theme and the modules to the public folder.')) ?></p>
            <form method="post" action="<?= e(app()->url()->plain('/admin/tools/cache/publish-assets')) ?>" class="af-inline">
                <?= Admin::tokenField(app()) ?>
                <button type="submit" class="af-btn"><?= Admin::icon('upload') ?><?= e(t('Publish the static files')) ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>
