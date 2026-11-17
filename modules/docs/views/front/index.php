<?php
/**
 * AIFEC — el índice de la documentación: todas las secciones y sus páginas. Con la carpeta vacía lo dice y no se rompe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page
 * @var array<int,array<string,mixed>> $tree
 * @var bool                           $empty      no hay ninguna página
 * @var int                            $count      cuántas páginas hay
 * @var string                         $search_url
 * @var string                         $query
 */

$this->layout('layout', ['page' => $page]);
$this->push('styles', '<link rel="stylesheet" href="' . e(app()->url()->asset('m', 'docs', 'docs.css')) . '">');
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $page['breadcrumbs']]) ?>
    <h1 class="af-title"><?= e(t('Documentation')) ?></h1>
    <?php if ($empty) : ?>
        <div class="af-empty" data-docs-empty>
            <p class="af-empty-title"><?= e(t('There is no documentation yet.')) ?></p>
            <p class="af-muted"><?= e(t('Add Markdown files to the docs folder and they will appear here.')) ?></p>
        </div>
    <?php else : ?>
        <p class="af-subtitle"><?= e(t('Guides and references of the project.')) ?></p>
        <?= $this->partial('docs::front/search-form', ['search_url' => $search_url, 'query' => $query]) ?>
        <p class="af-small af-muted" data-docs-count><?= e(tn('{n} page', '{n} pages', (int) $count)) ?></p>
        <nav class="af-docs-index" aria-label="<?= e(t('Documentation pages')) ?>">
            <?= $this->partial('docs::front/tree', ['tree' => $tree, 'current' => '']) ?>
        </nav>
    <?php endif; ?>
</div>
