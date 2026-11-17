<?php
/**
 * AIFEC — filtros de un listado en atelier: sin barra lateral, una fila horizontal de desplegables (uno por faceta, el
 * precio y las existencias u ofertas) sobre la rejilla. En móvil la misma fila es el panel «af-filters» que abre el botón
 * Filtros de la barra inferior. Es un formulario GET igual que el del tema base: mismos nombres de campo.
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

$at_range = isset($range) && is_array($range) ? array_values($range) : [];
$at_min_hint = isset($at_range[0]) && is_numeric($at_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $at_range[0]) : '0';
$at_max_hint = isset($at_range[1]) && is_numeric($at_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $at_range[1]) : '';
$at_price_on = $query->min !== null || $query->max !== null;
$at_flags = ($query->stock ? 1 : 0) + ($query->sale ? 1 : 0);
?>
<aside class="at-filters af-listing-filters af-drawer" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <div class="af-drawer-head af-show-sm">
        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <form class="at-filters-form af-drawer-body" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($facets as $at_facet) : ?>
            <?php
            $at_picked = 0;
            foreach ($at_facet['values'] as $at_value) {
                if ($query->selected((string) $at_facet['code'], (string) $at_value['code'])) {
                    ++$at_picked;
                }
            }
            ?>
            <details class="at-dd af-filter">
                <summary class="at-dd-btn"><span class="af-filter-title"><?= e((string) $at_facet['name']) ?></span><?= ($at_picked > 0 ? ' <span class="at-dd-n">' . e($at_picked) . '</span>' : '') ?></summary>
                <div class="at-dd-panel">
                    <?php foreach ($at_facet['values'] as $at_value) : ?>
                        <label class="af-check">
                            <input type="checkbox" name="f[<?= e((string) $at_facet['code']) ?>][]" value="<?= e((string) $at_value['code']) ?>"<?= ($query->selected((string) $at_facet['code'], (string) $at_value['code']) ? ' checked' : '') ?>>
                            <span><?= e((string) $at_value['label']) ?> <span class="af-muted af-small">(<?= e((int) $at_value['count']) ?>)</span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <details class="at-dd af-filter">
            <summary class="at-dd-btn"><span class="af-filter-title"><?= e(t('Price')) ?></span><?= ($at_price_on ? ' <span class="at-dd-n">1</span>' : '') ?></summary>
            <div class="at-dd-panel at-dd-price">
                <label class="af-sr" for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($at_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <label class="af-sr" for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($at_max_hint) ?>" inputmode="decimal" autocomplete="off">
            </div>
        </details>
        <details class="at-dd af-filter">
            <summary class="at-dd-btn"><span class="af-filter-title"><?= e(t('Availability')) ?></span><?= ($at_flags > 0 ? ' <span class="at-dd-n">' . e($at_flags) . '</span>' : '') ?></summary>
            <div class="at-dd-panel">
                <label class="af-check"><input type="checkbox" name="stock" value="1"<?= ($query->stock ? ' checked' : '') ?>><span><?= e(t('Only in stock')) ?></span></label>
                <label class="af-check"><input type="checkbox" name="sale" value="1"<?= ($query->sale ? ' checked' : '') ?>><span><?= e(t('On sale')) ?></span></label>
            </div>
        </details>
        <div class="at-filters-go">
            <button type="submit" class="af-btn af-btn-primary af-btn-sm"><?= e(t('Apply')) ?></button>
            <?php if ($query->hasFilters()) : ?>
                <a class="af-small" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</aside>
