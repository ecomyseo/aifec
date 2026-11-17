<?php
/**
 * AIFEC — listado de gmartos: migas, el panel azul con el título de la página, las subcategorías como tarjetas con su
 * imagen y, debajo, el «Mostrando 1–12 de 146 resultados» a la izquierda y el orden a la derecha, la rejilla de cuatro y la
 * paginación en cajas. Los filtros, que en la tienda de origen no existían, van en el panel lateral que abre el botón
 * «Filtros» (en móvil, el de la barra inferior).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $result
 * @var object              $query
 */

$this->layout('layout', ['page' => $page, 'filters' => true]);
$gm_total = (int) $result['total'];
$gm_per = isset($result['per_page']) ? max(1, (int) $result['per_page']) : max(1, count($result['items']));
$gm_from = $gm_total > 0 ? ((int) $result['page'] - 1) * $gm_per + 1 : 0;
$gm_to = $gm_total > 0 ? min($gm_total, $gm_from + count($result['items']) - 1) : 0;
?>
<div class="af-container gm-list">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <header class="gm-list-head">
        <h1 class="gm-list-title af-title"><?= e($heading) ?></h1>
        <?php if ($description !== '' && $query->page === 1) : ?>
            <div class="gm-list-intro af-prose"><?= $description ?></div>
        <?php endif; ?>
    </header>
    <?php if ($children !== []) : ?>
        <nav class="gm-tiles af-grid af-cols-4" aria-label="<?= e(t('Subcategories')) ?>">
            <?php foreach ($children as $gm_child) : ?>
                <a class="gm-tile" href="<?= e((string) $gm_child['url']) ?>">
                    <span class="gm-tile-media"><?= image_tag(isset($gm_child['image']) && is_array($gm_child['image']) ? $gm_child['image'] : null, 'card', ['alt' => '']) ?></span>
                    <span class="gm-tile-name"><?= e((string) $gm_child['name']) ?> <span class="gm-tile-count"><?= e((int) $gm_child['product_count']) ?></span></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="gm-listing af-listing">
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <section class="af-listing-results" aria-label="<?= e(t('Products')) ?>">
            <div class="gm-toolbar">
                <p class="gm-count af-muted" role="status"><?php if ($gm_total === 1) : ?><?= e(t('Showing 1 result')) ?><?php elseif ($gm_total > 1) : ?><?= e(t('Showing {from}–{to} of {total} results', ['from' => $gm_from, 'to' => $gm_to, 'total' => $gm_total])) ?><?php endif; ?></p>
                <button type="button" class="af-btn af-btn-ghost af-btn-sm gm-filter-btn af-hide-sm" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false"><?= gmartos_svg('sliders', 18) ?><span><?= e(t('Filters')) ?></span></button>
                <?php if ($gm_total > 0 && count($result['sorts']) > 1) : ?>
                    <div class="gm-sort">
                        <label class="af-sr" for="af-sort"><?= e(t('Sort by')) ?></label>
                        <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                            <?php foreach ($result['sorts'] as $gm_code => $gm_label) : ?>
                                <option value="<?= e((string) $gm_code) ?>"<?= (string) $gm_code === $query->sort ? ' selected' : '' ?>><?= e((string) $gm_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e($heading) ?></h2>
                <ul class="af-grid af-cols-4 gm-grid" role="list">
                    <?php foreach ($result['items'] as $gm_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $gm_card]) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="gm-empty af-empty af-card">
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
