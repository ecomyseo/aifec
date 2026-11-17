<?php
/**
 * AIFEC — filtros del listado de Café (sustituye `catalog::front/partials/filters`): en escritorio, una fila sobre la
 * rejilla, sin columna lateral; en el móvil, el panel que abre el botón Filtros de la barra inferior. El filtro del
 * sistema (para qué cafetera vale) va el primero y abierto, como fichas con su pictograma; los demás, cada uno en su
 * desplegable; al final el precio, «solo con existencias» y «en oferta». Mismos campos y mismo formulario que el original.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                    $action    dirección del listado sin parámetros
 * @var array<int,array<string,mixed>>            $facets
 * @var \Aifec\Modules\Catalog\Front\ListingQuery $query
 * @var array<int,int>|null                       $range     precio mínimo y máximo de lo que se enseña, en céntimos
 * @var string                                    $clear_url dirección sin filtros
 */

$cf_range = isset($range) && is_array($range) ? array_values($range) : [];
$cf_min_hint = isset($cf_range[0]) && is_numeric($cf_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $cf_range[0]) : '0';
$cf_max_hint = isset($cf_range[1]) && is_numeric($cf_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $cf_range[1]) : '';
$cf_system = strtolower(trim((string) theme_option('system_attr', '')));
$cf_price_on = $query->min !== null || $query->max !== null;
?>
<aside class="af-listing-filters af-drawer cf-filters" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="af-drawer-head af-show-sm">
        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= cafe_svg('close') ?></button>
    </div>
    <form class="af-drawer-body cf-filters-form" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <ul class="cf-filter-row">
            <?php foreach ($facets as $cf_facet) : ?>
                <?php
                $cf_code = (string) $cf_facet['code'];
                $cf_picked = 0;
                foreach ($cf_facet['values'] as $cf_value) {
                    if ($query->selected($cf_code, (string) $cf_value['code'])) {
                        ++$cf_picked;
                    }
                }
                ?>
                <?php if (cafe_attr_kind($cf_facet, $cf_system) === 'system') : ?>
                    <li class="af-filter cf-filter-sys">
                        <fieldset>
                            <legend class="af-filter-title"><?= e((string) $cf_facet['name']) ?></legend>
                            <?php foreach ($cf_facet['values'] as $cf_value) : ?>
                                <label class="af-check cf-pick">
                                    <input type="checkbox" name="f[<?= e($cf_code) ?>][]" value="<?= e((string) $cf_value['code']) ?>"<?php if ($query->selected($cf_code, (string) $cf_value['code'])) : ?> checked<?php endif; ?> data-af-autosubmit>
                                    <span><?= cafe_svg(cafe_pict((string) $cf_value['code'], (string) $cf_value['label']), 18) ?><?= e((string) $cf_value['label']) ?> <small class="af-muted">(<?= e((int) $cf_value['count']) ?>)</small></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    </li>
                <?php else : ?>
                    <li class="af-filter">
                        <details<?php if ($cf_picked > 0) : ?> open<?php endif; ?>>
                            <summary><span class="af-filter-title"><?= e((string) $cf_facet['name']) ?></span><?php if ($cf_picked > 0) : ?> <b class="cf-filter-n"><?= e($cf_picked) ?></b><?php endif; ?></summary>
                            <ul class="cf-filter-list">
                                <?php foreach ($cf_facet['values'] as $cf_value) : ?>
                                    <li>
                                        <label class="af-check">
                                            <input type="checkbox" name="f[<?= e($cf_code) ?>][]" value="<?= e((string) $cf_value['code']) ?>"<?php if ($query->selected($cf_code, (string) $cf_value['code'])) : ?> checked<?php endif; ?>>
                                            <span><?= e((string) $cf_value['label']) ?> <small class="af-muted">(<?= e((int) $cf_value['count']) ?>)</small></span>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="af-filter">
                <details<?php if ($cf_price_on) : ?> open<?php endif; ?>>
                    <summary><span class="af-filter-title"><?= e(t('Price')) ?></span></summary>
                    <p class="cf-filter-price">
                        <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                        <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($cf_min_hint) ?>" inputmode="decimal" autocomplete="off">
                        <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                        <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($cf_max_hint) ?>" inputmode="decimal" autocomplete="off">
                    </p>
                </details>
            </li>
            <li class="af-filter cf-filter-flags">
                <label class="af-check"><input type="checkbox" name="stock" value="1"<?php if ($query->stock) : ?> checked<?php endif; ?>><span><?= e(t('Only in stock')) ?></span></label>
                <label class="af-check"><input type="checkbox" name="sale" value="1"<?php if ($query->sale) : ?> checked<?php endif; ?>><span><?= e(t('On sale')) ?></span></label>
            </li>
        </ul>
        <p class="cf-filter-go">
            <button type="submit" class="af-btn af-btn-primary af-btn-sm"><?= e(t('Apply')) ?></button>
            <?php if ($query->hasFilters()) : ?>
                <a class="af-small" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a>
            <?php endif; ?>
        </p>
    </form>
</aside>
