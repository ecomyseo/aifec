<?php
/**
 * AIFEC — listado de productos: el mismo para una categoría, una marca y una búsqueda. Título, descripción, subcategorías,
 * filtros (columna en escritorio, panel lateral en móvil), orden, rejilla de tarjetas y paginación. La barra inferior del móvil
 * enseña el botón Filtros porque esta página los tiene.
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
$af_total = (int) $result['total'];
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1 class="af-title"><?= e($heading) ?></h1>
    <?php if ($description !== '' && $query->page === 1) : ?>
        <div class="af-prose"><?= $description ?></div>
    <?php endif; ?>
    <?php if ($children !== []) : ?>
        <nav class="af-row" aria-label="<?= e(t('Subcategories')) ?>">
            <?php foreach ($children as $af_child) : ?>
                <a class="af-btn af-btn-ghost af-btn-sm" href="<?= e((string) $af_child['url']) ?>"><?= e((string) $af_child['name']) ?> <span class="af-muted">(<?= e((int) $af_child['product_count']) ?>)</span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="af-listing">
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <div class="af-listing-results">
            <div class="af-row">
                <p class="af-muted" role="status"><?= e(tn('{n} product', '{n} products', $af_total)) ?></p>
                <?php if ($af_total > 0 && count($result['sorts']) > 1) : ?>
                    <label class="af-sr" for="af-sort"><?= e(t('Sort by')) ?></label>
                    <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                        <?php foreach ($result['sorts'] as $af_code => $af_label) : ?>
                            <option value="<?= e((string) $af_code) ?>"<?= (string) $af_code === $query->sort ? ' selected' : '' ?>><?= e((string) $af_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                <?php endif; ?>
            </div>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e(t('Products')) ?></h2>
                <div class="af-grid af-cols-4">
                    <?php foreach ($result['items'] as $af_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
                    <?php endforeach; ?>
                </div>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="af-card af-empty">
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
