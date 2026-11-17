<?php
/**
 * AIFEC — índice del blog: las categorías, la lista de entradas más recientes con su paginación y el enlace al canal de novedades.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page       datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>> $crumbs
 * @var string                         $heading    título de la página
 * @var array<string,mixed>            $result     lo que da Cms::posts()
 * @var string                         $base       dirección del índice
 * @var array<int,array<string,mixed>> $categories categorías con sus entradas
 * @var string                         $feed       dirección del canal de novedades
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1 class="af-title"><?= e($heading) ?></h1>
    <?php if ($categories !== []) : ?>
        <nav class="af-row" aria-label="<?= e(t('Categories')) ?>">
            <?php foreach ($categories as $af_category) : ?>
                <?php if ((int) $af_category['count'] > 0) : ?>
                    <a class="af-badge" href="<?= e($af_category['url']) ?>"><?= e($af_category['name']) ?> (<?= e($af_category['count']) ?>)</a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?= $this->partial('cms::front/post-list', ['result' => $result, 'base' => $base]) ?>
    <p class="af-small af-muted"><a href="<?= e($feed) ?>" rel="alternate" type="application/atom+xml"><?= e(t('Subscribe to the news feed')) ?></a></p>
</div>
