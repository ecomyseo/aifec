<?php
/**
 * AIFEC — listado de productos de Lookbook (sustituye `catalog::front/listing`), el mismo para una categoría, una marca
 * y una búsqueda, compuesto como un capítulo de revista: título centrado en serifa cursiva con las subcategorías como
 * palabras; una línea de índice entre dos filetes (los filtros son palabras que abren su lista a todo el ancho, y a la
 * derecha van el recuento y el orden); y la secuencia de piezas, que alterna dos grandes con cuatro. Sin columna
 * lateral. La descripción cierra el capítulo. En móvil los filtros son el panel «af-filters» que abre la barra inferior.
 * Conserva todos los datos de la plantilla original.
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
$lb_total = (int) $result['total'];
$lb_even = theme_option('listing_rhythm', 'sequence') === 'even';
if ($kind === 'search') {
    $lb_over = t('Search results');
} elseif ($kind === 'brand') {
    $lb_over = t('Brand');
} else {
    $lb_over = t('Collection');
}
?>
<div class="af-container lb-collection">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <section class="af-listing lb-listing">
        <header class="lb-chapter">
            <p class="lb-over"><?= e($lb_over) ?></p>
            <h1 class="af-title lb-chapter-title"><?= e($heading) ?></h1>
            <?php if ($children !== []) : ?>
                <nav class="lb-parts" aria-label="<?= e(t('Subcategories')) ?>">
                    <?php foreach ($children as $lb_child) : ?>
                        <a class="lb-word" href="<?= e((string) $lb_child['url']) ?>"><?= e((string) $lb_child['name']) ?> <sup><?= e((int) $lb_child['product_count']) ?></sup></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </header>
        <div class="lb-contents">
            <?= $this->partial('catalog::front/partials/filters', [
                'action' => $base,
                'facets' => $facets,
                'query' => $query,
                'range' => $result['price_range'],
                'clear_url' => $clear_url,
            ]) ?>
            <dl class="lb-tally">
                <dt class="af-sr"><?= e(t('Products')) ?></dt>
                <dd role="status"><?= e(tn('{n} product', '{n} products', $lb_total)) ?></dd>
                <?php if ($lb_total > 0 && count($result['sorts']) > 1) : ?>
                    <dt><label for="af-sort"><?= e(t('Sort by')) ?></label></dt>
                    <dd>
                        <select id="af-sort" class="af-select lb-order" name="sort" form="af-filters-form" data-af-autosubmit>
                            <?php foreach ($result['sorts'] as $lb_code => $lb_label) : ?>
                                <option value="<?= e((string) $lb_code) ?>"<?= (string) $lb_code === $query->sort ? ' selected' : '' ?>><?= e((string) $lb_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="af-btn af-btn-sm" form="af-filters-form"><?= e(t('Sort')) ?></button></noscript>
                    </dd>
                <?php endif; ?>
            </dl>
        </div>
        <div class="af-listing-results lb-results">
            <?php if ($result['items'] !== []) : ?>
                <h2 class="af-sr"><?= e(t('Products')) ?></h2>
                <ol class="lb-sequence<?= ($lb_even ? ' lb-sequence-even' : '') ?>">
                    <?php foreach ($result['items'] as $lb_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $lb_card]) ?></li>
                    <?php endforeach; ?>
                </ol>
                <?= $this->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'url' => $url]) ?>
            <?php else : ?>
                <div class="af-empty lb-blank">
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
        <?php if ($description !== '' && $query->page === 1) : ?>
            <footer class="lb-chapter-note af-prose"><?= ($description) ?></footer>
        <?php endif; ?>
    </section>
</div>
