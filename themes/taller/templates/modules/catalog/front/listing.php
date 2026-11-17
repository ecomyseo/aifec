<?php
/**
 * AIFEC — listado de productos en taller: el mismo para una categoría, una marca y una búsqueda. Barra lateral de
 * filtros técnicos a la izquierda; arriba de los resultados, el recuento, el orden y un conmutador rejilla / lista (la
 * lista pinta filas anchas con las características en columnas); rejilla de cuatro columnas. El botón Filtros de la
 * barra inferior del móvil abre la barra lateral como panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                       $page      datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>>            $crumbs
 * @var string                                    $kind      category, brand o search
 * @var string                                    $heading   título de la página
 * @var string                                    $description HTML limpio de la descripción (puede ir vacío)
 * @var array<int,array<string,mixed>>            $children  subcategorías: name, url, image, product_count
 * @var array<string,mixed>                       $result    lo que da catalog->listing()
 * @var array<int,array<string,mixed>>            $facets
 * @var \Aifec\Modules\Catalog\Front\ListingQuery $query
 * @var string                                    $base      dirección del listado sin parámetros
 * @var string                                    $clear_url dirección sin filtros
 * @var callable                                  $url       recibe un número de página y da su dirección
 */

$this->layout('layout', ['page' => $page, 'filters' => true]);
$tl_total = (int) $result['total'];
$tl_cols = (string) theme_option('listing_cols', '4') === '3' ? 3 : 4;
$tl_view = theme_option('listing_view', 'grid') === 'list' ? 'list' : 'grid';
?>
<div class="tl-list af-container">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <header class="tl-list-head">
        <h1 class="tl-list-title af-title"><?= e($heading) ?></h1>
        <p class="tl-list-count af-small"><?= e(tn('{n} product', '{n} products', $tl_total)) ?></p>
        <?php if ($description !== '' && $query->page === 1) : ?>
            <div class="tl-list-intro af-prose"><?= $description ?></div>
        <?php endif; ?>
        <?php if ($children !== []) : ?>
            <nav class="tl-chips" aria-label="<?= e(t('Subcategories')) ?>">
                <?php foreach ($children as $tl_child) : ?>
                    <a class="tl-chip" href="<?= e((string) $tl_child['url']) ?>"><?= e((string) $tl_child['name']) ?> <span class="tl-chip-n"><?= e((int) $tl_child['product_count']) ?></span></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <div class="tl-listing af-listing">
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <section class="tl-results af-listing-results" data-tl-view="<?= e($tl_view) ?>" aria-label="<?= e(t('Products')) ?>">
            <div class="tl-toolbar">
                <p class="tl-count af-muted" role="status"><?= e(tn('{n} product', '{n} products', $tl_total)) ?></p>
                <?php if ($tl_total > 0 && count($result['sorts']) > 1) : ?>
                    <div class="tl-sort">
                        <label class="tl-sort-label" for="af-sort"><?= e(t('Sort by')) ?></label>
                        <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                            <?php foreach ($result['sorts'] as $tl_code => $tl_label) : ?>
                                <option value="<?= e((string) $tl_code) ?>"<?= (string) $tl_code === $query->sort ? ' selected' : '' ?>><?= e((string) $tl_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                    </div>
                <?php endif; ?>
                <div class="tl-view" role="group" aria-label="<?= e(t('View')) ?>">
                    <button type="button" class="tl-view-btn" data-tl-view-set="grid" aria-pressed="<?= $tl_view === 'grid' ? 'true' : 'false' ?>" aria-label="<?= e(t('Grid view')) ?>"><?= taller_svg('grid', 20) ?></button>
                    <button type="button" class="tl-view-btn" data-tl-view-set="list" aria-pressed="<?= $tl_view === 'list' ? 'true' : 'false' ?>" aria-label="<?= e(t('List view')) ?>"><?= taller_svg('list', 20) ?></button>
                </div>
            </div>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e($heading) ?></h2>
                <div class="tl-grid af-grid tl-cols-<?= e($tl_cols) ?>">
                    <?php foreach ($result['items'] as $tl_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $tl_card]) ?>
                    <?php endforeach; ?>
                </div>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="tl-empty af-empty">
                    <p class="af-empty-title"><?= e(t('No products found')) ?></p>
                    <?php if ($query->hasFilters()) : ?>
                        <p class="af-muted"><?= e(t('Try removing some filters.')) ?></p>
                        <p><a class="af-btn" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a></p>
                    <?php elseif ($kind === 'search') : ?>
                        <p class="af-muted"><?= e(t('Check the spelling or try fewer or different words.')) ?></p>
                    <?php else : ?>
                        <p class="af-muted"><?= e(t('There are no products here yet. Come back soon.')) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
