<?php
/**
 * AIFEC — listado de productos en atelier: el mismo para una categoría, una marca y una búsqueda, pero sin barra lateral.
 * Título grande en serif, subcategorías como enlaces de texto, una fila de filtros desplegables sobre la rejilla, el orden a
 * la derecha del recuento y una rejilla de cuatro columnas (dos en móvil) con mucha separación. El botón Filtros de la barra
 * inferior del móvil abre esa misma fila como panel.
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
$at_total = (int) $result['total'];
$at_cols = (string) theme_option('listing_cols', '4') === '3' ? 3 : 4;
?>
<div class="at-list-wrap af-container">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <header class="at-list-head">
        <h1 class="at-list-title af-title"><?= e($heading) ?></h1>
        <?php if ($description !== '' && $query->page === 1) : ?>
            <div class="at-list-intro af-prose"><?= ($description) ?></div>
        <?php endif; ?>
        <?php if ($children !== []) : ?>
            <nav class="at-subcats" aria-label="<?= e(t('Subcategories')) ?>">
                <ul>
                    <?php foreach ($children as $at_child) : ?>
                        <li><a href="<?= e((string) $at_child['url']) ?>"><?= e((string) $at_child['name']) ?> <span class="af-muted">(<?= e((int) $at_child['product_count']) ?>)</span></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </header>

    <div class="at-listing af-listing">
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <div class="at-results af-listing-results">
            <div class="at-results-bar">
                <p class="at-count af-muted" role="status"><?= e(tn('{n} product', '{n} products', $at_total)) ?></p>
                <?php if ($at_total > 0 && count($result['sorts']) > 1) : ?>
                    <div class="at-sort">
                        <label class="at-sort-label" for="af-sort"><?= e(t('Sort by')) ?></label>
                        <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                            <?php foreach ($result['sorts'] as $at_code => $at_label) : ?>
                                <option value="<?= e((string) $at_code) ?>"<?= (string) $at_code === $query->sort ? ' selected' : '' ?>><?= e((string) $at_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e($heading) ?></h2>
                <div class="at-grid af-grid at-cols-<?= e($at_cols) ?>">
                    <?php foreach ($result['items'] as $at_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $at_card]) ?>
                    <?php endforeach; ?>
                </div>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="at-empty af-empty">
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
