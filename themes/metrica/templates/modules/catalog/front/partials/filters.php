<?php
/**
 * AIFEC — los filtros de un listado en Métrica (sustituye `catalog::front/partials/filters`): una MATRIZ sobre la rejilla.
 * Una fila por filtro, con su rótulo a la izquierda y sus valores en línea, como conmutadores (cada uno es una casilla del
 * mismo formulario GET de siempre, con su recuento); debajo, la fila del precio y la de existencias y ofertas. En el
 * escritorio va siempre abierta; en el móvil es el panel lateral «af-filters» que abre el botón Filtros de la barra inferior.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                              $action    dirección a la que se envía (la del listado)
 * @var array<int,array<string,mixed>>      $facets    facetas de catalog->listing()
 * @var \Aifec\Modules\Catalog\Front\ListingQuery $query lo que trae la dirección
 * @var array<int,mixed>                    $range     [precio mínimo, precio máximo] en céntimos, como los da el catálogo
 * @var string                              $clear_url dirección sin filtros
 */

$mt_range = isset($range) && is_array($range) ? array_values($range) : [];
$mt_min_hint = isset($mt_range[0]) && is_numeric($mt_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $mt_range[0]) : '0';
$mt_max_hint = isset($mt_range[1]) && is_numeric($mt_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $mt_range[1]) : '';
$mt_facets = isset($facets) && is_array($facets) ? $facets : [];
?>
<aside class="af-listing-filters af-drawer mt-matrix" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="af-drawer-head af-show-sm">
        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= metrica_svg('close') ?></button>
    </div>
    <form class="af-drawer-body mt-matrix-form" id="af-filters-form" method="get" action="<?= e($action) ?>" data-mt-matrix>
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($mt_facets as $mt_facet) : ?>
            <?php $mt_code = (string) $mt_facet['code']; ?>
            <div class="af-filter mt-facet" role="group" aria-labelledby="mt-facet-<?= e($mt_code) ?>">
                <p class="af-filter-title" id="mt-facet-<?= e($mt_code) ?>"><?= e((string) $mt_facet['name']) ?></p>
                <ul class="mt-facet-values">
                    <?php foreach ($mt_facet['values'] as $mt_value) : ?>
                        <li>
                            <label class="mt-chip">
                                <input class="af-sr" type="checkbox" name="f[<?= e($mt_code) ?>][]" value="<?= e((string) $mt_value['code']) ?>"<?= ($query->selected($mt_code, (string) $mt_value['code']) ? ' checked' : '') ?>>
                                <span><?= e((string) $mt_value['label']) ?> <small><?= e((int) $mt_value['count']) ?></small></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <div class="af-filter mt-facet" role="group" aria-labelledby="mt-facet-price">
            <p class="af-filter-title" id="mt-facet-price"><?= e(t('Price')) ?></p>
            <p class="mt-facet-range">
                <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($mt_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <span aria-hidden="true">–</span>
                <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($mt_max_hint) ?>" inputmode="decimal" autocomplete="off">
            </p>
        </div>
        <div class="af-filter mt-facet" role="group" aria-labelledby="mt-facet-stock">
            <p class="af-filter-title" id="mt-facet-stock"><?= e(t('Availability')) ?></p>
            <ul class="mt-facet-values">
                <li><label class="mt-chip"><input class="af-sr" type="checkbox" name="stock" value="1"<?= ($query->stock ? ' checked' : '') ?>><span><?= e(t('Only in stock')) ?></span></label></li>
                <li><label class="mt-chip"><input class="af-sr" type="checkbox" name="sale" value="1"<?= ($query->sale ? ' checked' : '') ?>><span><?= e(t('On sale')) ?></span></label></li>
            </ul>
        </div>
        <p class="mt-matrix-foot">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Apply')) ?></button>
            <?php if ($query->hasFilters()) : ?>
                <a class="mt-matrix-clear" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a>
            <?php endif; ?>
        </p>
    </form>
</aside>
