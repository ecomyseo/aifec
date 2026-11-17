<?php
/**
 * AIFEC — migración, paso 3: qué se trae.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step
 * @var int $id
 * @var array<string,mixed> $run
 * @var array<int,array<string,mixed>> $rows
 * @var string|null $error
 * @var array<string,mixed> $options
 */

use Aifec\Admin\Admin;

$groups = ['shop' => [], 'people' => []];
foreach ($rows as $row) {
    $groups[$row['personal'] ? 'people' : 'shop'][] = $row;
}
$table = static function (array $list): string {
    ob_start();
    ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <tbody>
                <?php foreach ($list as $row) : ?>
                    <?php $off = $row['why'] !== null; ?>
                    <tr<?= $off ? ' class="af-muted"' : '' ?>>
                        <td>
                            <label class="af-check">
                                <input type="checkbox" name="types[]" value="<?= e((string) $row['type']) ?>"<?= $row['checked'] ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>>
                                <?= e(t((string) $row['label'])) ?>
                            </label>
                        </td>
                        <td class="af-num"><?= $off || $row['count'] === null ? '' : (int) $row['count'] /* revisado: entero */ ?></td>
                        <td class="af-small"><?= $off ? e(t((string) $row['why']['text'], (array) $row['why']['vars'])) : '' /* revisado: escapado con e() */ ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php

    return (string) ob_get_clean();
};
?>
<?= $this->partial('import::admin/steps', ['step' => $step, 'id' => $id]) ?>

<?php if ($error !== null) : ?>
    <div class="af-alert af-alert-bad" role="alert">
        <?= e(t($error)) ?>
        <a href="<?= e(app()->url()->plain('/admin/import/' . (int) $run['id'] . '/connection')) ?>"><?= e(t('Check the connection')) ?></a>
    </div>
<?php else : ?>
    <form method="post" action="<?= e(app()->url()->plain('/admin/import/' . (int) $run['id'] . '/types')) ?>" class="af-form">
        <?= Admin::tokenField(app()) ?>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Shop data')) ?></h2></div>
            <div class="af-card-body"><?= $table($groups['shop']) /* revisado: tabla ya escapada con e() */ ?></div>
        </div>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Data about people')) ?></h2></div>
            <div class="af-card-body">
                <div class="af-alert af-alert-warn"><?= e(t('Names, emails, addresses and orders of real people. No email is sent to anybody. Bring them only if you are allowed to.')) ?></div>
                <?= $table($groups['people']) /* revisado: tabla ya escapada con e() */ ?>
            </div>
        </div>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Options')) ?></h2></div>
            <div class="af-card-body">
                <div class="af-field">
                    <span class="af-label"><?= e(t('Old addresses')) ?></span>
                    <div class="af-control">
                        <input type="hidden" name="keep_urls" value="0">
                        <label class="af-check"><input type="checkbox" name="keep_urls" value="1"<?= $options['keep_urls'] ? ' checked' : '' ?>><?= e(t('Keep the old addresses working')) ?></label>
                        <div class="af-hint"><?= e(t('Off: the old addresses redirect to the new ones.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <span class="af-label"><?= e(t('Images')) ?></span>
                    <div class="af-control">
                        <input type="hidden" name="import_images" value="0">
                        <label class="af-check"><input type="checkbox" name="import_images" value="1"<?= $options['import_images'] ? ' checked' : '' ?>><?= e(t('Bring the images')) ?></label>
                    </div>
                </div>
                <div class="af-field">
                    <span class="af-label"><?= e(t('Edited here')) ?></span>
                    <div class="af-control">
                        <input type="hidden" name="overwrite_local" value="0">
                        <label class="af-check"><input type="checkbox" name="overwrite_local" value="1"<?= $options['overwrite_local'] ? ' checked' : '' ?>><?= e(t('Overwrite what was edited here after being brought')) ?></label>
                    </div>
                </div>
                <div class="af-field">
                    <label class="af-label" for="imp-since"><?= e(t('Only what changed since')) ?></label>
                    <div class="af-control">
                        <input class="af-input af-input-sm" id="imp-since" type="text" name="since" value="<?= e($options['since']) ?>" placeholder="YYYY-MM-DD" maxlength="10">
                        <div class="af-hint"><?= e(t('To repeat a migration: leave it empty to bring everything.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <label class="af-label" for="imp-shop"><?= e(t('Shop of the source')) ?></label>
                    <div class="af-control">
                        <input class="af-input af-input-sm" id="imp-shop" type="text" name="shop" value="<?= e($options['shop']) ?>" maxlength="40">
                        <div class="af-hint"><?= e(t('Only for a multistore source: the identifier of the shop to bring.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <label class="af-label" for="imp-host"><?= e(t('Domain of the old shop')) ?></label>
                    <div class="af-control">
                        <input class="af-input af-input-sm" id="imp-host" type="text" name="source_host" value="<?= e($options['source_host']) ?>" maxlength="190" placeholder="shop.example">
                        <div class="af-hint"><?= e(t('It is taken from the connection. Change it if the old shop lived at another domain: it is how its images and links are recognised.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <label class="af-label" for="imp-prefix"><?= e(t('Order numbers')) ?></label>
                    <div class="af-control">
                        <input class="af-input af-input-sm" id="imp-prefix" type="text" name="order_prefix" value="<?= e($options['order_prefix']) ?>" maxlength="20" placeholder="<?= e(t('Prefix for the order numbers that already exist here')) ?>">
                        <div class="af-hint"><?= e(t('Only needed if an order number of the source already exists here: that order comes as the prefix followed by the number.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <span class="af-label"><?= e(t('Team')) ?></span>
                    <div class="af-control">
                        <input type="hidden" name="team_admins" value="0">
                        <label class="af-check"><input type="checkbox" name="team_admins" value="1"<?= $options['team_admins'] ? ' checked' : '' ?>><?= e(t('Bring the administrators as administrators')) ?></label>
                        <div class="af-hint"><?= e(t('Off: they come as store managers. In both cases they arrive switched off and without a password.')) ?></div>
                    </div>
                </div>
                <div class="af-field">
                    <span class="af-label"><?= e(t('Prices')) ?></span>
                    <div class="af-control af-small"><?= e($options['prices_include_tax_here'] ? t('Prices are written here with tax, as the tax settings of this shop say.') : t('Prices are written here without tax, as the tax settings of this shop say.')) ?></div>
                </div>
            </div>
        </div>
        <div class="af-form-actions">
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save and continue')) ?></button>
        </div>
    </form>
<?php endif; ?>
