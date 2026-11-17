<?php
/**
 * AIFEC — el panel de filtros de un listado en gmartos: el del catálogo, pero con la cabecera del panel (título y botón de
 * cerrar) también en escritorio, porque aquí los filtros van siempre en un panel lateral que abre el botón «Filtros» del
 * listado (en móvil, el de la barra inferior).
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

$af_range = isset($range) && is_array($range) ? array_values($range) : [];
$af_min_hint = isset($af_range[0]) && is_numeric($af_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $af_range[0]) : '0';
$af_max_hint = isset($af_range[1]) && is_numeric($af_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $af_range[1]) : '';
?>
<aside class="af-listing-filters af-drawer" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <form class="af-drawer-body" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($facets as $af_facet) : ?>
            <div class="af-filter">
                <p class="af-filter-title"><?= e((string) $af_facet['name']) ?></p>
                <?php foreach ($af_facet['values'] as $af_value) : ?>
                    <label class="af-check">
                        <input type="checkbox" name="f[<?= e((string) $af_facet['code']) ?>][]" value="<?= e((string) $af_value['code']) ?>"<?= $query->selected((string) $af_facet['code'], (string) $af_value['code']) ? ' checked' : '' ?>>
                        <span><?= e((string) $af_value['label']) ?> <span class="af-muted af-small">(<?= e((int) $af_value['count']) ?>)</span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="af-filter">
            <p class="af-filter-title"><?= e(t('Price')) ?></p>
            <div class="af-row">
                <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($af_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($af_max_hint) ?>" inputmode="decimal" autocomplete="off">
            </div>
        </div>
        <div class="af-filter">
            <label class="af-check"><input type="checkbox" name="stock" value="1"<?= $query->stock ? ' checked' : '' ?>><span><?= e(t('Only in stock')) ?></span></label>
            <label class="af-check"><input type="checkbox" name="sale" value="1"<?= $query->sale ? ' checked' : '' ?>><span><?= e(t('On sale')) ?></span></label>
        </div>
        <button type="submit" class="af-btn af-btn-primary af-btn-block"><?= e(t('Apply')) ?></button>
        <?php if ($query->hasFilters()) : ?>
            <p><a class="af-small" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a></p>
        <?php endif; ?>
    </form>
</aside>
