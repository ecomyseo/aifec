<?php
/**
 * AIFEC — módulos y plugins: dos pestañas, una fila por módulo con su estado, su nota del contrato y sus acciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var bool $canEdit
 * @var array<string,string> $titles
 */

use Aifec\Admin\Admin;

$tabs = ['plmodules' => ['module', t('Included modules')], 'plplugins' => ['plugin', t('Plugins')]];
$url = static function (string $id, string $action): string {
    return app()->url()->plain('/admin/plugins/' . rawurlencode($id) . '/' . $action);
};
?>
<div class="af-tabs" role="tablist" data-af-tabs data-af-active="plmodules">
    <?php foreach ($tabs as $key => $tab) : ?>
        <button type="button" class="af-tab" role="tab" data-af-tab="<?= e($key) ?>" aria-selected="<?= $key === 'plmodules' ? 'true' : 'false' ?>"><?= e($tab[1]) ?></button>
    <?php endforeach; ?>
</div>
<?php foreach ($tabs as $key => $tab) : ?>
    <div class="af-tabpanel" role="tabpanel" data-af-panel="<?= e($key) ?>"<?= $key === 'plmodules' ? '' : ' hidden' ?>>
        <?php if ($tab[0] === 'plugin' && $canEdit) : ?>
            <div class="af-card">
                <div class="af-card-body">
                    <form method="post" enctype="multipart/form-data" action="<?= e(app()->url()->plain('/admin/plugins/upload')) ?>" class="af-toolbar">
                        <?= Admin::tokenField(app()) ?>
                        <label for="pl-zip"><strong><?= e(t('Upload a plugin')) ?></strong></label>
                        <input type="file" id="pl-zip" name="plugin_zip" accept=".zip,application/zip" class="af-input" required>
                        <button type="submit" class="af-btn"><?= Admin::icon('upload') ?><?= e(t('Upload ZIP')) ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div class="af-card">
            <?php
            $list = array_values(array_filter($rows, static function (array $row) use ($tab): bool {
                return $row['kind'] === $tab[0];
            }));
            ?>
            <?php if ($list === []) : ?>
                <div class="af-empty"><h3><?= e($tab[0] === 'plugin' ? t('No plugins installed') : t('No modules')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead>
                            <tr>
                                <th><?= e(t('Name')) ?></th>
                                <th><?= e(t('Version')) ?></th>
                                <th><?= e(t('Status')) ?></th>
                                <?php if ($tab[0] === 'plugin') : ?><th><?= e(t('Contract')) ?></th><?php endif; ?>
                                <th class="af-hide-sm"><?= e(t('Declares')) ?></th>
                                <th class="af-right"><?= e(t('Actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list as $row) : ?>
                                <?php $id = (string) $row['id']; ?>
                                <tr data-plugin="<?= e($id) ?>">
                                    <td>
                                        <strong><?= e($row['name']) ?></strong>
                                        <?php if ($row['essential']) : ?><?= Admin::badge(t('Essential'), 'info') ?><?php endif; ?>
                                        <br><span class="af-muted af-small"><?= e($row['description']) ?></span>
                                        <?php if ($row['error'] !== '') : ?>
                                            <div class="af-alert af-alert-bad"><?= e($row['error']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="af-nowrap"><?= e($row['version']) ?></td>
                                    <td class="af-nowrap">
                                        <?php if (!$row['valid']) : ?>
                                            <?= Admin::badge(t('Invalid'), 'bad') ?>
                                        <?php elseif ($row['active']) : ?>
                                            <?= Admin::badge(t('Active'), 'ok') ?>
                                        <?php else : ?>
                                            <?= Admin::badge(t('Off')) ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($tab[0] === 'plugin') : ?>
                                        <td class="af-nowrap">
                                            <?php $report = $row['report']; ?>
                                            <?php if (!is_array($report)) : ?>
                                                <span class="af-muted"><?= e(t('Not validated')) ?></span>
                                            <?php else : ?>
                                                <a href="<?= e($url($id, 'report')) ?>"><?= Admin::badge((int) $report['score'] . ' / 100', $report['ok'] ? 'ok' : 'bad') ?></a>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="af-hide-sm af-small">
                                        <?php $contract = $row['contract']; ?>
                                        <?php if ($contract['hosts'] !== []) : ?><div><?= e(t('External services')) ?>: <span class="af-mono"><?= e(implode(', ', $contract['hosts'])) ?></span></div><?php endif; ?>
                                        <?php if ($contract['personal_data'] !== []) : ?><div><?= e(t('Personal data')) ?>: <?= e(implode(', ', $contract['personal_data'])) ?></div><?php endif; ?>
                                        <?php foreach ($contract['capabilities'] as $capability) : ?>
                                            <div>
                                                <?= e(t('Asks for')) ?>: <span class="af-mono"><?= e($capability) ?></span>
                                                <?php if ($row['kind'] === 'plugin') : ?>
                                                    <?= in_array($capability, $row['granted'], true) ? Admin::badge(t('Granted'), 'warn') : Admin::badge(t('Not granted'), 'ok') ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($contract['hosts'] === [] && $contract['personal_data'] === [] && $contract['capabilities'] === []) : ?><span class="af-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="af-cell-actions af-right">
                                        <?php if ($row['valid'] && $row['active'] && $row['has_settings']) : ?>
                                            <a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/settings/' . rawurlencode($id))) ?>"><?= Admin::icon('settings') ?><?= e(t('Configure')) ?></a>
                                        <?php endif; ?>
                                        <?php if ($canEdit && $row['valid']) : ?>
                                            <?php if (!$row['active']) : ?>
                                                <form method="post" action="<?= e($url($id, 'enable')) ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Activate')) ?></button></form>
                                            <?php elseif (!$row['essential']) : ?>
                                                <form method="post" action="<?= e($url($id, 'disable')) ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('x') ?><?= e(t('Deactivate')) ?></button></form>
                                            <?php endif; ?>
                                            <?php if ($row['kind'] === 'plugin') : ?>
                                                <form method="post" action="<?= e($url($id, 'validate')) ?>" class="af-inline"><?= Admin::tokenField(app()) ?><button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('shield') ?><?= e(t('Validate')) ?></button></form>
                                                <?php foreach ($row['contract']['capabilities'] as $capability) : ?>
                                                    <?php $granted = in_array($capability, $row['granted'], true); ?>
                                                    <form method="post" action="<?= e($url($id, 'grant')) ?>" class="af-inline" data-af-confirm="<?= e($granted ? t('Take the capability «{capability}» away from this plugin?', ['capability' => $capability]) : t('Allow this plugin to use the capability «{capability}»? It will be able to change prices, taxes or deadlines of the shop.', ['capability' => $capability])) ?>">
                                                        <?= Admin::tokenField(app()) ?>
                                                        <input type="hidden" name="capability" value="<?= e($capability) ?>">
                                                        <input type="hidden" name="on" value="<?= $granted ? '0' : '1' ?>">
                                                        <button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('lock') ?><?= e($granted ? t('Revoke «{capability}»', ['capability' => $capability]) : t('Grant «{capability}»', ['capability' => $capability])) ?></button>
                                                    </form>
                                                <?php endforeach; ?>
                                                <?php if (!$row['active']) : ?>
                                                    <form method="post" action="<?= e($url($id, 'uninstall')) ?>" class="af-inline" data-af-confirm="<?= e(t('Uninstall this plugin? Its files are deleted.')) ?>">
                                                        <?= Admin::tokenField(app()) ?>
                                                        <label class="af-small"><input type="checkbox" name="keep" value="1" checked> <?= e(t('Keep the data')) ?></label>
                                                        <button type="submit" class="af-btn af-btn-sm af-btn-danger"><?= Admin::icon('trash') ?><?= e(t('Uninstall')) ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
