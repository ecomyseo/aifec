<?php
/**
 * AIFEC — filtros de un listado en taller: barra lateral de filtros técnicos con una faceta plegable por característica,
 * contador de valores y de marcados, el precio y las existencias. Las facetas largas enseñan seis valores y esconden el
 * resto tras «Ver más». En móvil es el panel «af-filters» que abre el botón Filtros de la barra inferior. Es un formulario
 * GET igual que el del tema base: mismos nombres de campo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                    $action    dirección a la que se envía (la del listado)
 * @var array<int,array<string,mixed>>            $facets    facetas de catalog->listing()
 * @var \Aifec\Modules\Catalog\Front\ListingQuery $query     lo que trae la dirección
 * @var array<int,mixed>                          $range     [precio mínimo, precio máximo] en céntimos
 * @var string                                    $clear_url dirección sin filtros
 */

$tl_range = isset($range) && is_array($range) ? array_values($range) : [];
$tl_min_hint = isset($tl_range[0]) && is_numeric($tl_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $tl_range[0]) : '0';
$tl_max_hint = isset($tl_range[1]) && is_numeric($tl_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $tl_range[1]) : '';
$tl_price_on = $query->min !== null || $query->max !== null;
$tl_flags = ($query->stock ? 1 : 0) + ($query->sale ? 1 : 0);
$tl_shown = 6;
?>
<aside class="tl-filters af-listing-filters af-drawer" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="af-icon-btn af-show-sm" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= taller_svg('close') ?></button>
    </div>
    <form class="tl-filters-form af-drawer-body" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($facets as $tl_n => $tl_facet) : ?>
            <?php
            $tl_picked = 0;
            foreach ($tl_facet['values'] as $tl_value) {
                if ($query->selected((string) $tl_facet['code'], (string) $tl_value['code'])) {
                    ++$tl_picked;
                }
            }
            $tl_values = array_values($tl_facet['values']);
            $tl_head = array_slice($tl_values, 0, $tl_shown);
            $tl_rest = array_slice($tl_values, $tl_shown);
            ?>
            <details class="tl-facet af-filter"<?= ($tl_picked > 0 || $tl_n < 4) ? ' open' : '' ?>>
                <summary class="tl-facet-head"><span class="af-filter-title"><?= e((string) $tl_facet['name']) ?></span><span class="tl-facet-count"><?= e(count($tl_values)) ?></span><?= $tl_picked > 0 ? '<span class="tl-facet-picked">' . e($tl_picked) . '</span>' : '' ?></summary>
                <div class="tl-facet-body">
                    <?php foreach ($tl_head as $tl_value) : ?>
                        <label class="tl-opt af-check">
                            <input type="checkbox" name="f[<?= e((string) $tl_facet['code']) ?>][]" value="<?= e((string) $tl_value['code']) ?>"<?= $query->selected((string) $tl_facet['code'], (string) $tl_value['code']) ? ' checked' : '' ?>>
                            <span class="tl-opt-label"><?= e((string) $tl_value['label']) ?></span>
                            <span class="tl-opt-n"><?= e((int) $tl_value['count']) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($tl_rest !== []) : ?>
                        <details class="tl-more">
                            <summary><?= e(t('Show {n} more', ['n' => count($tl_rest)])) ?></summary>
                            <?php foreach ($tl_rest as $tl_value) : ?>
                                <label class="tl-opt af-check">
                                    <input type="checkbox" name="f[<?= e((string) $tl_facet['code']) ?>][]" value="<?= e((string) $tl_value['code']) ?>"<?= $query->selected((string) $tl_facet['code'], (string) $tl_value['code']) ? ' checked' : '' ?>>
                                    <span class="tl-opt-label"><?= e((string) $tl_value['label']) ?></span>
                                    <span class="tl-opt-n"><?= e((int) $tl_value['count']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <details class="tl-facet af-filter" open>
            <summary class="tl-facet-head"><span class="af-filter-title"><?= e(t('Price')) ?></span><?= $tl_price_on ? '<span class="tl-facet-picked">1</span>' : '' ?></summary>
            <div class="tl-facet-body tl-price-range">
                <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($tl_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <span class="tl-price-dash" aria-hidden="true">–</span>
                <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($tl_max_hint) ?>" inputmode="decimal" autocomplete="off">
            </div>
        </details>
        <details class="tl-facet af-filter" open>
            <summary class="tl-facet-head"><span class="af-filter-title"><?= e(t('Availability')) ?></span><?= $tl_flags > 0 ? '<span class="tl-facet-picked">' . e($tl_flags) . '</span>' : '' ?></summary>
            <div class="tl-facet-body">
                <label class="tl-opt af-check"><input type="checkbox" name="stock" value="1"<?= $query->stock ? ' checked' : '' ?>><span class="tl-opt-label"><?= e(t('Only in stock')) ?></span></label>
                <label class="tl-opt af-check"><input type="checkbox" name="sale" value="1"<?= $query->sale ? ' checked' : '' ?>><span class="tl-opt-label"><?= e(t('On sale')) ?></span></label>
            </div>
        </details>
        <div class="tl-filters-go">
            <button type="submit" class="af-btn af-btn-primary af-btn-block"><?= e(t('Apply')) ?></button>
            <?php if ($query->hasFilters()) : ?>
                <a class="tl-clear af-small" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</aside>
