<?php
/**
 * AIFEC — biblioteca de medios: buscador, zona de subida y rejilla de miniaturas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $q
 * @var string $folder
 * @var array<string,int> $folders
 * @var bool $canEdit
 * @var string $uploadUrl
 * @var string $baseUrl
 */

use Aifec\Admin\Admin;

$link = static function (array $query) use ($baseUrl, $q, $folder): string {
    return app()->url()->plain('/admin/media', $query + ['q' => $q, 'folder' => $folder]);
};
?>
<?php if ($canEdit) : ?>
    <div class="af-card af-dropzone" data-af-dropzone data-upload-url="<?= e($uploadUrl) ?>" data-reload="1"
        data-text-uploading="<?= e(t('Uploading…')) ?>" data-text-uploaded="<?= e(t('{n} uploaded')) ?>" data-text-failed="<?= e(t('The upload failed')) ?>">
        <form class="af-card-body af-media-upload" method="post" action="<?= e($uploadUrl) ?>" enctype="multipart/form-data">
            <?= Admin::tokenField(app()) ?>
            <span class="af-dropzone-hint"><?= Admin::icon('upload') ?><?= e(t('Drop images here or choose them')) ?></span>
            <input type="file" name="files[]" accept="image/*" multiple data-af-upload-input aria-label="<?= e(t('Choose images')) ?>">
            <noscript><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('upload') ?><?= e(t('Upload')) ?></button></noscript>
        </form>
    </div>
<?php endif; ?>

<div class="af-card">
    <form class="af-card-body af-toolbar" method="get" action="<?= e($baseUrl) ?>">
        <input class="af-input af-input-md" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Search by name or alternative text')) ?>" aria-label="<?= e(t('Search')) ?>">
        <?php if ($folders !== []) : ?>
            <select class="af-select" name="folder" aria-label="<?= e(t('Folder')) ?>" data-af-autosubmit>
                <option value=""><?= e(t('All folders')) ?></option>
                <?php foreach ($folders as $name => $count) : ?>
                    <option value="<?= e($name) ?>"<?= $name === $folder ? ' selected' : '' ?>><?= e($name) ?> (<?= e((string) $count) ?>)</option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Search')) ?></button>
    </form>

    <?php if ($items === []) : ?>
        <div class="af-empty"><h3><?= e(t('No images yet')) ?></h3></div>
    <?php else : ?>
        <ul class="af-media-grid">
            <?php foreach ($items as $item) : ?>
                <?php $row = $item['row']; $img = $item['img']; ?>
                <li>
                    <a class="af-media-card" href="<?= e(app()->url()->plain('/admin/media/' . $row['id'])) ?>">
                        <span class="af-media-thumb"><img src="<?= e($img['thumb']) ?>" alt="<?= e($img['alt']) ?>" loading="lazy"></span>
                        <span class="af-media-name"><?= e($row['filename']) ?></span>
                        <span class="af-muted af-small"><?= e($row['width'] !== null ? $row['width'] . '×' . $row['height'] . ' · ' : '') ?><?= e($item['size']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($pages > 1) : ?>
        <div class="af-pagination">
            <span class="af-muted af-small"><?= e(tn('{n} image', '{n} images', $total)) ?></span>
            <span class="af-top-space"></span>
            <?php if ($page > 1) : ?><a class="af-btn af-btn-sm" href="<?= e($link(['p' => $page - 1])) ?>"><?= e(t('Previous')) ?></a><?php endif; ?>
            <span class="af-small"><?= e(t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
            <?php if ($page < $pages) : ?><a class="af-btn af-btn-sm" href="<?= e($link(['p' => $page + 1])) ?>"><?= e(t('Next')) ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
