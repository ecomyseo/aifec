<?php
/**
 * AIFEC — la página de búsqueda sin nada buscado: el formulario. Con una palabra, la página es el listado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page    datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>> $crumbs
 * @var string                         $action  dirección de la búsqueda
 * @var int                            $max     largo máximo de lo que se busca
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1 class="af-title"><?= e(t('Search')) ?></h1>
    <form class="af-row" role="search" method="get" action="<?= e($action) ?>">
        <label class="af-sr" for="af-search-q"><?= e(t('What are you looking for?')) ?></label>
        <input id="af-search-q" class="af-input" type="search" name="q" maxlength="<?= e((int) $max) ?>" placeholder="<?= e(t('What are you looking for?')) ?>" autocomplete="off" required>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
    </form>
    <p class="af-muted"><?= e(t('Type a product, a brand or a category.')) ?></p>
</div>
