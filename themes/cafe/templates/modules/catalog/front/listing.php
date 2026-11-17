<?php
/**
 * AIFEC — listado de productos de Café, «estante por cafetera» (sustituye `catalog::front/listing`). A todo el ancho,
 * sin columna lateral: una cabecera con el nombre en grande y su texto a la izquierda y, a la derecha, la caja de resumen
 * (cuántos productos hay, entre qué precios y para cuántas cafeteras; con una cafetera guardada en el navegador, el guion
 * del tema escribe ahí cuántos le valen, con las cuentas reales de la faceta); debajo, las subcategorías como pestañas de
 * texto, los filtros en fila y la rejilla. En el móvil los filtros son el panel que abre la barra inferior. Conserva todos
 * los datos de la plantilla original.
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
$cf_total = (int) $result['total'];
$cf_cols = (string) theme_option('listing_cols', '4') === '3' ? 3 : 4;
$cf_range = isset($result['price_range']) && is_array($result['price_range']) ? array_values($result['price_range']) : [];
$cf_low = isset($cf_range[0]) && is_numeric($cf_range[0]) ? (int) $cf_range[0] : 0;
$cf_high = isset($cf_range[1]) && is_numeric($cf_range[1]) ? (int) $cf_range[1] : 0;
// La faceta del sistema en esta sección: cuántos cafés hay para cada cafetera (lo que lee el guion del tema).
$cf_system = strtolower(trim((string) theme_option('system_attr', '')));
$cf_makers = null;
foreach ($facets as $cf_facet) {
    if (is_array($cf_facet) && cafe_attr_kind($cf_facet, $cf_system) === 'system') {
        $cf_makers = $cf_facet;
        break;
    }
}
$cf_counts = [];
foreach ($cf_makers !== null ? $cf_makers['values'] : [] as $cf_value) {
    $cf_counts[(string) $cf_value['code']] = (int) $cf_value['count'];
}
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <section class="af-listing cf-shelf">
        <header class="cf-shelf-head">
            <div class="cf-shelf-intro">
                <h1 class="af-title"><?= e($heading) ?></h1>
                <?php if ($description !== '' && $query->page === 1) : ?>
                    <div class="af-prose"><?= $description ?></div>
                <?php endif; ?>
            </div>
            <dl class="cf-shelf-box">
                <dt><?= e(t('In this section')) ?></dt>
                <dd class="cf-shelf-count" role="status"><?= e(tn('{n} product', '{n} products', $cf_total)) ?></dd>
                <?php if ($cf_total > 0 && $cf_high > 0) : ?>
                    <dt><?= e(t('Price')) ?></dt>
                    <dd><?= e($cf_low < $cf_high ? t('From {min} to {max}', ['min' => money($cf_low), 'max' => money($cf_high)]) : money($cf_high)) ?></dd>
                <?php endif; ?>
                <?php if ($cf_counts !== []) : ?>
                    <dt><?= e((string) $cf_makers['name']) ?></dt>
                    <dd data-cf-box data-cf-attr="<?= e((string) $cf_makers['code']) ?>" data-cf-base="<?= e($base) ?>" data-cf-total="<?= e($cf_total) ?>" data-cf-counts="<?= e((string) json_encode($cf_counts)) ?>" data-cf-fit="<?= e(t('For your {maker}: {n} of {total} in this section')) ?>" data-cf-only="<?= e(t('See only mine')) ?>">
                        <?= e(tn('Coffee for {n} coffee maker', 'Coffee for {n} coffee makers', count($cf_counts))) ?>
                    </dd>
                <?php endif; ?>
            </dl>
        </header>
        <?php if ($children !== []) : ?>
            <nav class="cf-shelf-tabs" aria-label="<?= e(t('Subcategories')) ?>">
                <?php foreach ($children as $cf_child) : ?>
                    <a href="<?= e((string) $cf_child['url']) ?>"><?= e((string) $cf_child['name']) ?> <small class="af-muted"><?= e((int) $cf_child['product_count']) ?></small></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?= $this->partial('catalog::front/partials/filters', [
            'action' => $base,
            'facets' => $facets,
            'query' => $query,
            'range' => $result['price_range'],
            'clear_url' => $clear_url,
        ]) ?>
        <div class="af-listing-results">
            <?php if ($cf_total > 0 && count($result['sorts']) > 1) : ?>
                <p class="cf-shelf-sort">
                    <label for="af-sort"><?= e(t('Sort by')) ?></label>
                    <select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>
                        <?php foreach ($result['sorts'] as $cf_code => $cf_label) : ?>
                            <option value="<?= e((string) $cf_code) ?>"<?= (string) $cf_code === $query->sort ? ' selected' : '' ?>><?= e((string) $cf_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                </p>
            <?php endif; ?>
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e(t('Products')) ?></h2>
                <ol class="af-grid af-cols-<?= e($cf_cols) ?> cf-shelf-grid">
                    <?php foreach ($result['items'] as $cf_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $cf_card]) ?></li>
                    <?php endforeach; ?>
                </ol>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="af-empty">
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
