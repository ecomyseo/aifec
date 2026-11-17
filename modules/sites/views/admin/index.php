<?php
/**
 * AIFEC — la pantalla «Sites and demos» del panel maestro: crear una demostración y la tabla de sitios.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>>  $sites           los sitios (y las demostraciones que aún no tienen sitio)
 * @var array<string,array<string,mixed>> $packages      paquetes de demos/ (nombre => package.json, o ['error' => …])
 * @var bool                            $nightly         el restablecimiento de cada noche está encendido
 * @var string                          $defaultPassword contraseña de demostración que se usa si no se escribe otra
 * @var bool                            $jobs            hay módulo de tareas (crear y restablecer van en segundo plano)
 */

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;

$usable = [];
foreach ($packages as $name => $meta) {
    if (!isset($meta['error'])) {
        $usable[(string) $name] = $meta;
    }
}
$states = ['queued' => [t('Queued'), 'info'], 'running' => [t('Building'), 'info'], 'done' => [t('Ready'), 'ok'], 'failed' => [t('Failed'), 'bad']];
$timezone = (string) app()->site()->timezone();
?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Create a demo')) ?></h2></div>
    <div class="af-card-body">
        <?php if ($usable === []) : ?>
            <div class="af-empty"><h3><?= e(t('There are no demo packages')) ?></h3><p><?= e(t('Put a package in the demos folder to create a demo from it.')) ?></p></div>
        <?php else : ?>
            <form method="post" action="<?= e(app()->url()->plain('/admin/sites/create')) ?>" class="af-toolbar">
                <?= Admin::tokenField(app()) ?>
                <label>
                    <span class="af-small af-muted"><?= e(t('Package')) ?></span>
                    <select name="package" required>
                        <?php foreach ($usable as $name => $meta) : ?>
                            <option value="<?= e($name) ?>"><?= e((string) $meta['name']) ?> (<?= e($name) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="af-small af-muted"><?= e(t('Folder')) ?></span>
                    <input type="text" name="slug" required maxlength="30" pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" placeholder="moda">
                </label>
                <label>
                    <span class="af-small af-muted"><?= e(t('Shop name')) ?></span>
                    <input type="text" name="name" maxlength="120">
                </label>
                <label class="af-check"><input type="checkbox" name="public_admin" value="1"> <?= e(t('Include a full administration demo')) ?></label>
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('plus') ?><?= e(t('Create demo')) ?></button>
            </form>
            <p class="af-small af-muted"><?= e(t('The demo opens at /demos/<folder>. Its panel password is {password}.', ['password' => $defaultPassword])) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Sites')) ?></h2></div>
    <?php if ($sites === []) : ?>
        <div class="af-empty"><h3><?= e(t('No sites yet')) ?></h3></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Site')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Address')) ?></th>
                        <th><?= e(t('Type')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Package')) ?></th>
                        <th><?= e(t('State')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Last reset')) ?></th>
                        <th class="af-num af-hide-sm"><?= e(t('Orders today')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $site) : ?>
                    <tr>
                        <td><strong><?= e((string) $site['name']) ?></strong><br><span class="af-small af-muted af-mono"><?= e((string) $site['id']) ?></span></td>
                        <td class="af-hide-sm af-mono af-small"><?= e((string) $site['path']) ?></td>
                        <td>
                            <?php if ($site['master']) : ?>
                                <?= Admin::badge(t('Master'), 'info') ?>
                            <?php elseif ($site['demo']) : ?>
                                <?= Admin::badge(t('Demo'), 'warn') ?>
                            <?php else : ?>
                                <?= Admin::badge(t('Shop'), 'ok') ?>
                            <?php endif; ?>
                        </td>
                        <td class="af-hide-sm"><?= e((string) $site['package']) ?></td>
                        <td>
                            <?php if ($site['state'] !== '' && isset($states[$site['state']])) : ?>
                                <?= Admin::badge($states[$site['state']][0], $states[$site['state']][1]) ?>
                                <?php if ($site['state'] === 'failed' && $site['error'] !== '') : ?>
                                    <br><span class="af-small af-muted"><?= e((string) $site['error']) ?></span>
                                <?php endif; ?>
                            <?php elseif ($site['exists']) : ?>
                                <?= Admin::badge(t('Ready'), 'ok') ?>
                            <?php endif; ?>
                        </td>
                        <td class="af-hide-sm af-small"><?= $site['last_reset_at'] !== '' ? e(app()->i18n()->date(Clock::parse((string) $site['last_reset_at']), 'medium', $timezone)) : '—' ?></td>
                        <td class="af-num af-hide-sm"><?= $site['orders_today'] === null ? '—' : e((string) $site['orders_today']) ?></td>
                        <td class="af-cell-actions af-nowrap">
                            <?php if ($site['exists']) : ?>
                                <a class="af-btn af-btn-sm af-btn-ghost" href="<?= e((string) $site['shop_url']) ?>" target="_blank" rel="noopener"><?= e(t('Open shop')) ?></a>
                                <a class="af-btn af-btn-sm af-btn-ghost" href="<?= e(!empty($site['demo']) && empty($site['master']) ? app()->url()->plain('/admin/sites/open/' . $site['id']) : (string) $site['admin_url']) ?>" target="_blank" rel="noopener"><?= e(t('Open panel')) ?></a>
                            <?php endif; ?>
                            <?php if (!$site['master'] && $site['demo']) : ?>
                                <form method="post" action="<?= e(app()->url()->plain('/admin/sites/reset/' . $site['id'])) ?>" style="display:inline" data-af-confirm="<?= e(t('Delete the demo and build it again from its package?')) ?>">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Reset')) ?></button>
                                </form>
                                <form method="post" action="<?= e(app()->url()->plain('/admin/sites/delete/' . $site['id'])) ?>" style="display:inline" data-af-confirm="<?= e(t('Delete the demo and everything in it? This cannot be undone.')) ?>">
                                    <?= Admin::tokenField(app()) ?>
                                    <button type="submit" class="af-btn af-btn-sm af-btn-danger"><?= e(t('Delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="af-card">
    <div class="af-card-body">
        <form method="post" action="<?= e(app()->url()->plain('/admin/sites/settings')) ?>" class="af-toolbar">
            <?= Admin::tokenField(app()) ?>
            <label class="af-check">
                <input type="hidden" name="nightly" value="0">
                <input type="checkbox" name="nightly" value="1"<?= $nightly ? ' checked' : '' ?>>
                <span><?= e(t('Reset every demo each night at 04:30')) ?></span>
            </label>
            <label class="af-check">
                <input type="hidden" name="show_panel" value="0">
                <input type="checkbox" name="show_panel" value="1"<?= !empty($showPanel) ? ' checked' : '' ?>>
                <span><?= e(t('Show the panel button and its password on the public demos page')) ?></span>
            </label>
            <button type="submit" class="af-btn"><?= e(t('Save')) ?></button>
        </form>
        <p class="af-small af-muted"><?= e(t('You sign in to the panel of every demo with your own email and password, as an administrator. The demo account of each one is limited on purpose.')) ?></p>
        <?php if (!$jobs) : ?>
            <p class="af-small af-muted"><?= e(t('The tasks module is off: creating and resetting will run while you wait.')) ?></p>
        <?php endif; ?>
    </div>
</div>
