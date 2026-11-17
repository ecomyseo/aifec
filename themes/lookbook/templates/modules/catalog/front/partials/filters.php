<?php
/**
 * AIFEC — filtros de un listado en Lookbook: no hay columna lateral. Cada faceta (y el precio, y la disponibilidad) es
 * una palabra de la línea de índice; al pulsarla, su lista se abre bajo la línea a todo el ancho, y sus valores también
 * son palabras (las elegidas, subrayadas). En móvil el mismo formulario es el panel «af-filters» que abre el botón
 * Filtros de la barra inferior. Es un formulario GET igual que el del catálogo: mismos nombres de campo. Sin JavaScript
 * funciona igual: las palabras son <details>.
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

$lb_range = isset($range) && is_array($range) ? array_values($range) : [];
$lb_min_hint = isset($lb_range[0]) && is_numeric($lb_range[0]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $lb_range[0]) : '0';
$lb_max_hint = isset($lb_range[1]) && is_numeric($lb_range[1]) ? \Aifec\Modules\Catalog\Front\Support::formatCents((int) $lb_range[1]) : '';
$lb_price_on = $query->min !== null || $query->max !== null;
$lb_flags = ($query->stock ? 1 : 0) + ($query->sale ? 1 : 0);
?>
<aside class="af-listing-filters af-drawer lb-toc" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
    <p class="af-drawer-head af-show-sm">
        <strong class="af-drawer-title"><?= e(t('Filters')) ?></strong>
        <button type="button" class="lb-word" data-af-drawer-close><?= e(t('Close')) ?></button>
    </p>
    <form class="af-drawer-body lb-toc-form" id="af-filters-form" method="get" action="<?= e($action) ?>">
        <?php if ($query->q !== '') : ?>
            <input type="hidden" name="q" value="<?= e($query->q) ?>">
        <?php endif; ?>
        <?php foreach ($facets as $lb_facet) : ?>
            <?php
            $lb_picked = 0;
            foreach ($lb_facet['values'] as $lb_value) {
                if ($query->selected((string) $lb_facet['code'], (string) $lb_value['code'])) {
                    ++$lb_picked;
                }
            }
            ?>
            <details class="lb-toc-entry<?= ($lb_picked > 0 ? ' is-set' : '') ?>" name="lb-toc">
                <summary class="lb-toc-word"><?= e((string) $lb_facet['name']) ?><?php if ($lb_picked > 0) : ?> <sup><?= e($lb_picked) ?></sup><?php endif; ?></summary>
                <fieldset class="lb-toc-sheet">
                    <legend class="af-sr"><?= e((string) $lb_facet['name']) ?></legend>
                    <?php foreach ($lb_facet['values'] as $lb_value) : ?>
                        <label class="lb-toc-option">
                            <input type="checkbox" name="f[<?= e((string) $lb_facet['code']) ?>][]" value="<?= e((string) $lb_value['code']) ?>"<?= ($query->selected((string) $lb_facet['code'], (string) $lb_value['code']) ? ' checked' : '') ?>>
                            <span><?= e((string) $lb_value['label']) ?></span>
                            <small>(<?= e((int) $lb_value['count']) ?>)</small>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="lb-toc-apply"><?= e(t('Apply')) ?></button>
                </fieldset>
            </details>
        <?php endforeach; ?>
        <details class="lb-toc-entry<?= ($lb_price_on ? ' is-set' : '') ?>" name="lb-toc">
            <summary class="lb-toc-word"><?= e(t('Price')) ?><?php if ($lb_price_on) : ?> <sup>1</sup><?php endif; ?></summary>
            <fieldset class="lb-toc-sheet lb-toc-price">
                <legend class="af-sr"><?= e(t('Price')) ?></legend>
                <label for="af-price-min"><?= e(t('Minimum price')) ?></label>
                <input id="af-price-min" class="af-input" type="text" name="min" value="<?= e($query->min !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->min) : '') ?>" placeholder="<?= e($lb_min_hint) ?>" inputmode="decimal" autocomplete="off">
                <label for="af-price-max"><?= e(t('Maximum price')) ?></label>
                <input id="af-price-max" class="af-input" type="text" name="max" value="<?= e($query->max !== null ? \Aifec\Modules\Catalog\Front\Support::formatCents($query->max) : '') ?>" placeholder="<?= e($lb_max_hint) ?>" inputmode="decimal" autocomplete="off">
                <button type="submit" class="lb-toc-apply"><?= e(t('Apply')) ?></button>
            </fieldset>
        </details>
        <details class="lb-toc-entry<?= ($lb_flags > 0 ? ' is-set' : '') ?>" name="lb-toc">
            <summary class="lb-toc-word"><?= e(t('Availability')) ?><?php if ($lb_flags > 0) : ?> <sup><?= e($lb_flags) ?></sup><?php endif; ?></summary>
            <fieldset class="lb-toc-sheet">
                <legend class="af-sr"><?= e(t('Availability')) ?></legend>
                <label class="lb-toc-option"><input type="checkbox" name="stock" value="1"<?= ($query->stock ? ' checked' : '') ?>><span><?= e(t('Only in stock')) ?></span></label>
                <label class="lb-toc-option"><input type="checkbox" name="sale" value="1"<?= ($query->sale ? ' checked' : '') ?>><span><?= e(t('On sale')) ?></span></label>
                <button type="submit" class="lb-toc-apply"><?= e(t('Apply')) ?></button>
            </fieldset>
        </details>
        <?php if ($query->hasFilters()) : ?>
            <a class="lb-toc-reset" href="<?= e($clear_url) ?>"><?= e(t('Clear filters')) ?></a>
        <?php endif; ?>
    </form>
</aside>
