<?php
/**
 * AIFEC — listado de productos de Métrica (sustituye `catalog::front/listing`), «matriz de filtros»: a todo el ancho y sin
 * columna lateral. La cabecera lleva el nombre, su texto y las subcategorías en fichas; debajo, la matriz de filtros (una
 * fila por filtro, ver partials/filters); al pie de la matriz, el recuento y el orden, y después la rejilla de cinco
 * columnas. En el móvil la matriz es el panel lateral que abre el botón Filtros de la barra inferior. Conserva todos los
 * datos de la plantilla original.
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
$mt_total = (int) $result['total'];
$mt_cols = (string) theme_option('listing_cols', '5') === '4' ? 4 : 5;
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <section class="af-listing mt-listing">
        <header class="mt-shelf-head">
            <h1 class="af-title"><?= e($heading) ?></h1>
            <?php if ($description !== '' && $query->page === 1) : ?>
                <div class="af-prose"><?= $description ?></div>
            <?php endif; ?>
            <?php if ($children !== []) : ?>
                <nav class="mt-subs" aria-label="<?= e(t('Subcategories')) ?>">
                    <ul>
                        <?php foreach ($children as $mt_child) : ?>
                            <li><a href="<?= e((string) $mt_child['url']) ?>"><?= e((string) $mt_child['name']) ?> <small><?= e((int) $mt_child['product_count']) ?></small></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </header>
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <div class="af-listing-results">
            <div class="mt-shelf-bar">
                <p class="mt-shelf-count" role="status"><?= e(tn('{n} product', '{n} products', $mt_total)) ?></p>
                <?php if ($mt_total > 0 && count($result['sorts']) > 1) : ?>
                    <label class="mt-shelf-sort" for="af-sort"><?= e(t('Sort by')) ?></label>
                    <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                        <?php foreach ($result['sorts'] as $mt_code => $mt_label) : ?>
                            <option value="<?= e((string) $mt_code) ?>"<?= (string) $mt_code === $query->sort ? ' selected' : '' ?>><?= e((string) $mt_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                <?php endif; ?>
            </div>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e(t('Products')) ?></h2>
                <ul class="mt-grid mt-cols-<?= e($mt_cols) ?>">
                    <?php foreach ($result['items'] as $mt_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $mt_card]) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="af-empty mt-empty">
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
    </section>
</div>
