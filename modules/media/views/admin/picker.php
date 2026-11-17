<?php
/**
 * AIFEC — fragmento del selector de imágenes (sin marco): buscador, zona de subida, miniaturas y paginación.
 * Los textos que usa el guion van en atributos data-t-*: el guion no lleva ninguno escrito.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items
 * @var string $q
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var bool $multiple
 * @var bool $canEdit
 * @var string $url
 * @var string $uploadUrl
 */

use Aifec\Admin\Admin;

?>
<div class="af-picker" data-af-picker data-multiple="<?= $multiple ? '1' : '0' ?>" data-url="<?= e($url) ?>" data-upload-url="<?= e($uploadUrl) ?>"
    data-page="<?= e((string) $page) ?>" data-q="<?= e($q) ?>"
    data-t-title="<?= e($multiple ? t('Add images') : t('Choose image')) ?>" data-t-cancel="<?= e(t('Cancel')) ?>" data-t-select="<?= e(t('Use selected')) ?>"
    data-t-close="<?= e(t('Close')) ?>" data-t-remove="<?= e(t('Remove')) ?>" data-t-uploading="<?= e(t('Uploading…')) ?>"
    data-t-uploaded="<?= e(t('{n} uploaded')) ?>" data-t-failed="<?= e(t('The upload failed')) ?>" data-t-loading="<?= e(t('Loading…')) ?>">
    <form class="af-picker-search" data-af-picker-search role="search">
        <input class="af-input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Search by name or alternative text')) ?>" aria-label="<?= e(t('Search')) ?>">
        <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Search')) ?></button>
    </form>
    <?php if ($canEdit) : ?>
        <div class="af-dropzone" data-af-dropzone data-upload-url="<?= e($uploadUrl) ?>"
            data-text-uploading="<?= e(t('Uploading…')) ?>" data-text-uploaded="<?= e(t('{n} uploaded')) ?>" data-text-failed="<?= e(t('The upload failed')) ?>">
            <span class="af-dropzone-hint"><?= Admin::icon('upload') ?><?= e(t('Drop images here or choose them')) ?></span>
            <input type="file" accept="image/*" multiple data-af-upload-input aria-label="<?= e(t('Choose images')) ?>">
        </div>
    <?php endif; ?>
    <?php if ($items === []) : ?>
        <p class="af-muted af-picker-empty"><?= e(t('No images yet')) ?></p>
    <?php else : ?>
        <ul class="af-picker-grid">
            <?php foreach ($items as $item) : ?>
                <li>
                    <button type="button" class="af-picker-item" data-af-pick data-id="<?= e((string) $item['id']) ?>" data-thumb="<?= e($item['thumb']) ?>" title="<?= e($item['filename']) ?>" aria-pressed="false">
                        <img src="<?= e($item['thumb']) ?>" alt="<?= e($item['alt']) ?>" loading="lazy">
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($pages > 1) : ?>
        <div class="af-picker-pager">
            <button type="button" class="af-btn af-btn-sm" data-af-picker-page="<?= e((string) ($page - 1)) ?>"<?= $page > 1 ? '' : ' disabled' ?>><?= e(t('Previous')) ?></button>
            <span class="af-small"><?= e(t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
            <button type="button" class="af-btn af-btn-sm" data-af-picker-page="<?= e((string) ($page + 1)) ?>"<?= $page < $pages ? '' : ' disabled' ?>><?= e(t('Next')) ?></button>
        </div>
    <?php endif; ?>
</div>
