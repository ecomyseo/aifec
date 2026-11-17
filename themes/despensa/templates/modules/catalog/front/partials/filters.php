<?php
/**
 * AIFEC — el panel de filtros de un listado en despensa: un panel lateral que se abre desde un botón (en escritorio, el de
 * la cabecera del listado; en móvil, el de la barra inferior). Un formulario GET con un grupo plegable por faceta, cada
 * valor como una pastilla con su cuenta, precio mínimo y máximo, «solo con existencias» y «en oferta».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                    $action    dirección a la que se envía (la del listado)
 * @var array<int,array<string,mixed>>            $facets    facetas de catalog->listing()
 * @var \Aifec\Modules\Catalog\Front\ListingQuery $query     lo que trae la dirección
 * @var array<int,mixed>                          $range     [precio mínimo, precio máximo] en céntimos, como los da el catálogo
 * @var string                                    $clear_url dirección sin filtros
 */

$ds_range = isset($range) && is_array($range) ? array_values($range) : [];
$ds_min_hint = isset($ds_range[0]) && is_numeric($ds_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $ds_range[0]) : '0';
$ds_max_hint = isset($ds_range[1]) && is_numeric($ds_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $ds_range[1]) : '';
?>
<aside class="ds-filters af-listing-filters af-drawer" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="ds-filters-head af-drawer-head">
        <p class="ds-filters-title af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="ds-tool" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= despensa_svg('close') ?></button>
    </div>
    <form class="ds-filters-body af-drawer-body" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($facets as $ds_facet) : ?>
            <details class="ds-fgroup af-filter" open>
                <summary class="ds-fgroup-title af-filter-title"><?= e((string) $ds_facet['name']) ?></summary>
                <div class="ds-chips">
                    <?php foreach ($ds_facet['values'] as $ds_value) : ?>
                        <label class="ds-chip af-check">
                            <input type="checkbox" name="f[<?= e((string) $ds_facet['code']) ?>][]" value="<?= e((string) $ds_value['code']) ?>"<?= $query->selected((string) $ds_facet['code'], (string) $ds_value['code']) ? ' checked' : '' ?>>
                            <span class="ds-chip-text"><?= e((string) $ds_value['label']) ?> <span class="ds-chip-count">(<?= e((int) $ds_value['count']) ?>)</span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <details class="ds-fgroup af-filter" open>
            <summary class="ds-fgroup-title af-filter-title"><?= e(t('Price')) ?></summary>
            <div class="ds-price-range">
                <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($ds_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <span aria-hidden="true">–</span>
                <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($ds_max_hint) ?>" inputmode="decimal" autocomplete="off">
            </div>
        </details>
        <div class="ds-chips ds-chips-switches af-filter">
            <label class="ds-chip af-check"><input type="checkbox" name="stock" value="1"<?= $query->stock ? ' checked' : '' ?>><span class="ds-chip-text"><?= e(t('Only in stock')) ?></span></label>
            <label class="ds-chip af-check"><input type="checkbox" name="sale" value="1"<?= $query->sale ? ' checked' : '' ?>><span class="ds-chip-text"><?= e(t('On sale')) ?></span></label>
        </div>
        <button type="submit" class="af-btn af-btn-primary af-btn-block"><?= e(t('Apply')) ?></button>
        <?php if ($query->hasFilters()) : ?>
            <p><a class="af-small" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a></p>
        <?php endif; ?>
    </form>
</aside>
