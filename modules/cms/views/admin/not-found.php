<?php
/**
 * AIFEC — direcciones que se pidieron y no existen, las más pedidas primero, con el botón de crear la redirección.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows     path, hits, referer, first_at, last_at
 * @var bool                           $canEdit
 * @var string                         $createUrl
 * @var string                         $clearUrl
 */

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;

?>
<?php if ($rows === []) : ?>
    <div class="af-empty">
        <h3><?= e(t('No missing addresses')) ?></h3>
        <p><?= e(t('When a visitor asks for an address that does not exist, it appears here.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-card">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('Address')) ?></th>
                        <th class="af-num"><?= e(t('Times')) ?></th>
                        <th class="af-hide-sm"><?= e(t('Last time')) ?></th>
                        <?php if ($canEdit) : ?><th><?= e(t('Redirect to')) ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td class="af-mono">/<?= e((string) $row['path']) ?>
                            <?php if (isset($row['referer']) && $row['referer'] !== null && $row['referer'] !== '') : ?><br><span class="af-small af-muted"><?= e(t('From')) ?>: <?= e((string) $row['referer']) ?></span><?php endif; ?>
                        </td>
                        <td class="af-num"><?= e((string) (int) $row['hits']) ?></td>
                        <td class="af-hide-sm"><span class="af-nowrap"><?= e(app()->i18n()->date(Clock::parse((string) $row['last_at']), 'medium', app()->site()->timezone())) ?></span></td>
                        <?php if ($canEdit) : ?>
                            <td>
                                <form method="post" action="<?= e($createUrl) ?>" class="af-inline">
                                    <?= Admin::tokenField(app()) ?>
                                    <input type="hidden" name="path" value="<?= e((string) $row['path']) ?>">
                                    <input class="af-input af-input-md" type="text" name="target" placeholder="/new/address" aria-label="<?= e(t('Redirect to')) ?>" required>
                                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Redirect')) ?></button>
                                </form>
                                <form method="post" action="<?= e($clearUrl) ?>" class="af-inline">
                                    <?= Admin::tokenField(app()) ?>
                                    <input type="hidden" name="path" value="<?= e((string) $row['path']) ?>">
                                    <button type="submit" class="af-btn af-btn-sm af-btn-ghost" aria-label="<?= e(t('Dismiss')) ?>"><?= Admin::icon('x') ?></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($canEdit) : ?>
        <form method="post" action="<?= e($clearUrl) ?>" class="af-form-actions">
            <?= Admin::tokenField(app()) ?>
            <button type="submit" class="af-btn af-btn-danger" data-af-confirm="<?= e(t('Clear the whole list?')) ?>"><?= Admin::icon('trash') ?><?= e(t('Clear the list')) ?></button>
        </form>
    <?php endif; ?>
<?php endif; ?>
