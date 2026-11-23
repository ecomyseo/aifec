<?php
/**
 * AIFEC — la búsqueda no encontró nada que encaje: lo dice con claridad, enseña lo que se entendió, propone quitar el filtro que
 * más estorba (con los productos más parecidos), deja buscar otra vez y da salidas (categorías y lo más buscado) en vez de una
 * rejilla de resultados flojos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                         $page       datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>>              $crumbs
 * @var string                                      $q          lo que se buscó
 * @var string                                      $action     dirección de la búsqueda
 * @var int                                         $max        largo máximo de lo que se busca
 * @var array<int,array{name:string,url:string}>    $categories categorías de primer nivel
 * @var array<int,array{phrase:string,url:string}>  $popular    lo más buscado con resultado
 * @var array<string,mixed>                         $understood datos de la plantilla «understood»
 * @var array<string,mixed>|null                    $relaxed    el filtro que más estorba: key, label, url, total, items (tarjetas)
 * @var array<int,array<string,mixed>>              $related    productos que salen por significado (tarjetas), aparte de lo exacto
 * @var string                                      $related_title lo que se dice delante de ellos («No tenemos nada con esas palabras…»)
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1 class="af-title"><?= e(t('Results for {q}', ['q' => $q])) ?></h1>
    <?= $this->partial('search::front/understood', $understood) ?>
    <?php if ($related !== []) : ?>
        <p class="af-alert af-alert-info" role="status" data-af-search-nothing><?= e($related_title) ?></p>
        <section class="af-card" data-af-search-related>
            <div class="af-card-body af-stack">
                <h2 class="af-subtitle"><?= e(t('Related products')) ?></h2>
                <div class="af-grid af-cols-4">
                    <?php foreach ($related as $af_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php else : ?>
        <p class="af-alert af-alert-info" role="status" data-af-search-nothing><?= e(t('We have not found anything that matches {q}', ['q' => '«' . $q . '»'])) ?>.</p>
    <?php endif; ?>
    <?php if ($relaxed !== null) : ?>
        <div class="af-card" data-af-search-relaxed="<?= e($relaxed['key']) ?>">
            <div class="af-card-body af-stack">
                <p><?= e(tn('Without «{filter}» there is {n} result.', 'Without «{filter}» there are {n} results.', (int) $relaxed['total'], ['filter' => $relaxed['label']])) ?></p>
                <p><a class="af-btn af-btn-primary af-btn-sm" href="<?= e($relaxed['url']) ?>"><?= e(t('Remove «{filter}»', ['filter' => $relaxed['label']])) ?></a></p>
                <div class="af-grid af-cols-4">
                    <?php foreach ($relaxed['items'] as $af_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <form class="af-row" role="search" method="get" action="<?= e($action) ?>"<?= hook('webmcp.form', 'search_products') ?>>
        <label class="af-sr" for="af-search-q"><?= e(t('What are you looking for?')) ?></label>
        <input id="af-search-q" class="af-input" type="search" name="q" value="<?= e($q) ?>" maxlength="<?= e((int) $max) ?>" placeholder="<?= e(t('What are you looking for?')) ?>" autocomplete="off" required<?= hook('webmcp.param', 'search_products', 'q') ?>>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
    </form>
    <p class="af-muted"><?= e(t('Check the spelling or try fewer or different words.')) ?></p>
    <?php if ($categories !== []) : ?>
        <h2 class="af-subtitle"><?= e(t('Browse the shop')) ?></h2>
        <nav class="af-row" aria-label="<?= e(t('Categories')) ?>">
            <?php foreach ($categories as $af_category) : ?>
                <a class="af-btn af-btn-ghost af-btn-sm" href="<?= e($af_category['url']) ?>"><?= e($af_category['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($popular !== []) : ?>
        <h2 class="af-subtitle"><?= e(t('Popular searches')) ?></h2>
        <nav class="af-row" aria-label="<?= e(t('Popular searches')) ?>">
            <?php foreach ($popular as $af_popular) : ?>
                <a class="af-btn af-btn-ghost af-btn-sm" href="<?= e($af_popular['url']) ?>"><?= e($af_popular['phrase']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>
