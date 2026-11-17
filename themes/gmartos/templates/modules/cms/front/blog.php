<?php
/**
 * AIFEC — blog de gmartos: migas, la etiqueta «Conocimiento para tu tienda», el título de la página, las categorías en
 * píldoras y la rejilla de entradas de dos columnas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container gm-editorial">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <header class="gm-archive-head">
        <p class="gm-kicker"><?= e(t('Knowledge for your shop')) ?></p>
        <h1 class="af-title"><?= e($heading) ?></h1>
    </header>
    <?php if ($categories !== []) : ?>
        <nav class="gm-chips" aria-label="<?= e(t('Categories')) ?>">
            <?php foreach ($categories as $gm_category) : ?>
                <?php if ((int) $gm_category['count'] > 0) : ?>
                    <a href="<?= e($gm_category['url']) ?>"><?= e($gm_category['name']) ?> (<?= e($gm_category['count']) ?>)</a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?= $this->partial('cms::front/post-list', ['result' => $result, 'base' => $base]) ?>
    <p class="af-small af-muted"><a href="<?= e($feed) ?>" rel="alternate" type="application/atom+xml"><?= e(t('Subscribe to the news feed')) ?></a></p>
</div>
