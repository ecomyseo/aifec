<?php
/**
 * AIFEC — ficha de una imagen: vista previa, datos, variantes, dónde se usa y formularios.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $row
 * @var array<string,mixed> $img
 * @var array<int,array<string,mixed>> $variants
 * @var string[] $uses
 * @var string[] $folders
 * @var string $size
 * @var bool $canEdit
 * @var string $saveUrl
 * @var string $deleteUrl
 * @var string $regenerateUrl
 */

use Aifec\Admin\Admin;

?>
<div class="af-grid">
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-body af-media-preview" style="--af-dominant: <?= e($row['dominant'] !== null && $row['dominant'] !== '' ? $row['dominant'] : 'transparent') ?>">
                <img src="<?= e($img['large']) ?>" alt="<?= e($img['alt']) ?>"<?= $row['width'] !== null ? ' width="' . e((string) $row['width']) . '" height="' . e((string) $row['height']) . '"' : '' ?>>
            </div>
        </div>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Details')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('File')) ?></dt><dd><?= e($row['filename']) ?></dd>
                    <dt><?= e(t('Type')) ?></dt><dd><?= e($row['mime']) ?></dd>
                    <dt><?= e(t('Dimensions')) ?></dt><dd><?= $row['width'] !== null ? e($row['width'] . ' × ' . $row['height'] . ' px') : '<span class="af-muted">—</span>' ?></dd>
                    <dt><?= e(t('Weight')) ?></dt><dd><?= e($size) ?></dd>
                    <dt><?= e(t('Address')) ?></dt>
                    <dd>
                        <code class="af-mono"><?= e($img['url']) ?></code>
                        <button type="button" class="af-btn af-btn-sm af-btn-ghost" data-af-copy="<?= e($img['url']) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
                    </dd>
                </dl>
            </div>
        </div>
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Sizes')) ?></h2></div>
            <?php if ($variants === []) : ?>
                <div class="af-card-body af-muted"><?= e(t('This file has no smaller copies: it is shown as it was uploaded.')) ?></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr><th><?= e(t('Size')) ?></th><th><?= e(t('Dimensions')) ?></th><th class="af-right"><?= e(t('Address')) ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($variants as $variant) : ?>
                                <tr>
                                    <td><?= e($variant['name']) ?></td>
                                    <td><?= e($variant['width'] . ' × ' . $variant['height'] . ' px') ?></td>
                                    <td class="af-right"><a href="<?= e($variant['url']) ?>" target="_blank" rel="noopener"><?= Admin::icon('external') ?><?= e(t('Open')) ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Used in')) ?></h2></div>
            <div class="af-card-body">
                <?php if ($uses === []) : ?>
                    <span class="af-muted"><?= e(t('Not used anywhere.')) ?></span>
                <?php else : ?>
                    <ul class="af-media-uses">
                        <?php foreach ($uses as $use) : ?><li><?= e($use) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canEdit) : ?>
            <form class="af-card" method="post" action="<?= e($saveUrl) ?>">
                <?= Admin::tokenField(app()) ?>
                <div class="af-card-head"><h2><?= e(t('Description')) ?></h2></div>
                <div class="af-card-body">
                    <div class="af-field af-field-wide">
                        <label class="af-label" for="md-alt"><?= e(t('Alternative text')) ?></label>
                        <input class="af-input" id="md-alt" type="text" name="alt" value="<?= e($row['alt']) ?>" maxlength="500">
                        <span class="af-hint af-muted af-small"><?= e(t('What the image shows, for people who cannot see it and for search engines.')) ?></span>
                    </div>
                    <div class="af-field af-field-wide">
                        <label class="af-label" for="md-title"><?= e(t('Title')) ?></label>
                        <input class="af-input" id="md-title" type="text" name="title" value="<?= e($row['title']) ?>" maxlength="191">
                    </div>
                    <div class="af-field af-field-wide">
                        <label class="af-label" for="md-folder"><?= e(t('Folder')) ?></label>
                        <input class="af-input" id="md-folder" type="text" name="folder" value="<?= e($row['folder']) ?>" maxlength="120" list="md-folders">
                        <datalist id="md-folders">
                            <?php foreach ($folders as $name) : ?><option value="<?= e($name) ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="af-card-body af-form-actions"><button type="submit" class="af-btn af-btn-primary"><?= e(t('Save')) ?></button></div>
            </form>

            <div class="af-card">
                <div class="af-card-body af-media-actions">
                    <form method="post" action="<?= e($regenerateUrl) ?>" class="af-inline">
                        <?= Admin::tokenField(app()) ?>
                        <button type="submit" class="af-btn"><?= Admin::icon('refresh') ?><?= e(t('Rebuild sizes')) ?></button>
                    </form>
                    <form method="post" action="<?= e($deleteUrl) ?>" class="af-inline"
                        data-af-confirm="<?= e($uses === [] ? t('Delete this image and all its files?') : t('This image is in use. Delete it anyway?')) ?>">
                        <?= Admin::tokenField(app()) ?>
                        <?php if ($uses !== []) : ?><input type="hidden" name="force" value="1"><?php endif; ?>
                        <button type="submit" class="af-btn af-btn-danger"><?= Admin::icon('trash') ?><?= e(t('Delete')) ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
