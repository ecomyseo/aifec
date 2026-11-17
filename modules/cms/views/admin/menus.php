<?php
/**
 * AIFEC — lista de menús: una fila por cada ubicación que declara el tema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{code:string,name:string,count:int,url:string}> $locations
 */

use Aifec\Admin\Admin;

?>
<?php if ($locations === []) : ?>
    <div class="af-empty">
        <h3><?= e(t('This theme has no menus')) ?></h3>
        <p><?= e(t('A theme declares its menus in theme.json.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-card">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr><th><?= e(t('Menu')) ?></th><th class="af-hide-sm"><?= e(t('Code')) ?></th><th class="af-num"><?= e(t('Links')) ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($locations as $location) : ?>
                    <tr>
                        <td><a href="<?= e($location['url']) ?>"><strong><?= e($location['name']) ?></strong></a></td>
                        <td class="af-hide-sm af-mono"><?= e($location['code']) ?></td>
                        <td class="af-num"><?= e((string) $location['count']) ?></td>
                        <td class="af-right"><a class="af-btn af-btn-sm" href="<?= e($location['url']) ?>"><?= Admin::icon('edit') ?><?= e(t('Edit')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
