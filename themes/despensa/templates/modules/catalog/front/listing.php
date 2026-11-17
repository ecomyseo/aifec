<?php
/**
 * AIFEC — listado de despensa: sin barra lateral. Las categorías son pastillas en una fila desplazable (las subcategorías o,
 * si no hay, las del menú principal), el orden va a la derecha y los filtros se abren en un panel desde un botón. Rejilla
 * de tarjetas grandes (tres en escritorio, cuatro si el comerciante lo elige). Conserva todos los datos del listado original.
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
$ds_total = (int) $result['total'];
$ds_cols = (string) theme_option('listing_cols', '3') === '4' ? '4' : '3';

// Las pastillas: las subcategorías con su cuenta o, sin ellas, lo que hay en el menú principal.
$ds_pills = [];
foreach ($children as $ds_child) {
    $ds_pills[] = ['label' => (string) $ds_child['name'], 'url' => (string) $ds_child['url'], 'count' => (int) $ds_child['product_count'], 'current' => false];
}
if ($ds_pills === []) {
    foreach (array_slice(despensa_menu(menu('main'), 0), 0, 14) as $ds_entry) {
        if ($ds_entry['url'] !== '') {
            $ds_pills[] = ['label' => $ds_entry['label'], 'url' => $ds_entry['url'], 'count' => 0, 'current' => $ds_entry['current']];
        }
    }
}
?>
<div class="ds-list af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <header class="ds-list-head">
        <h1 class="ds-list-title af-title"><?= e($heading) ?></h1>
        <?php if ($description !== '' && $query->page === 1) : ?>
            <div class="ds-list-intro af-prose"><?= $description ?></div>
        <?php endif; ?>
    </header>
    <div class="ds-controls">
        <?php if ($ds_pills !== []) : ?>
            <nav class="ds-pills" aria-label="<?= e(t('Categories')) ?>">
                <?php foreach ($ds_pills as $ds_pill) : ?>
                    <a class="ds-pill" href="<?= e($ds_pill['url']) ?>"<?= $ds_pill['current'] ? ' aria-current="page"' : '' ?>><?= e($ds_pill['label']) ?><?php if ($ds_pill['count'] > 0) : ?> <span class="ds-pill-count">(<?= e($ds_pill['count']) ?>)</span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="ds-sortbar">
            <button type="button" class="ds-filter-btn af-hide-sm" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false"><?= despensa_svg('filter', 20) ?><span><?= e(t('Filters')) ?></span></button>
            <?php if ($ds_total > 0 && count($result['sorts']) > 1) : ?>
                <label class="af-sr" for="af-sort"><?= e(t('Sort by')) ?></label>
                <select id="af-sort" class="ds-sort af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                    <?php foreach ($result['sorts'] as $ds_code => $ds_label) : ?>
                        <option value="<?= e((string) $ds_code) ?>"<?= (string) $ds_code === $query->sort ? ' selected' : '' ?>><?= e((string) $ds_label) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
            <?php endif; ?>
        </div>
    </div>
    <p class="ds-count af-muted" role="status"><?= e(tn('{n} product', '{n} products', $ds_total)) ?></p>

    <div class="ds-listing af-listing">
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <div class="ds-results af-listing-results">
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e($heading) ?></h2>
                <div class="ds-grid af-grid ds-cols-<?= e($ds_cols) ?>">
                    <?php foreach ($result['items'] as $ds_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $ds_card]) ?>
                    <?php endforeach; ?>
                </div>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="ds-empty af-card af-empty">
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
        </div>
    </div>
</div>
