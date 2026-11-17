<?php
/**
 * AIFEC — paneles del envoltorio en Métrica: el menú (con las secciones y, debajo, los trabajos del menú «jobs», que en el
 * móvil no caben en la cabecera), la lámina del buscador (la que abre la lupa de la cabecera: es el único buscador de la
 * página, por eso lleva las marcas WebMCP que en el tema base van en la cabecera) y el carrito (vacío: lo rellena theme.js),
 * el telón y la zona de avisos breves. El panel de filtros lo pinta la propia página de listado (id «af-filters»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$mt_main = menu('main');
$mt_jobs = metrica_clean_menu(menu('jobs'));
$mt_legal = menu('legal');
?>
<div class="af-drawer-backdrop" data-af-drawer-close></div>
<aside class="af-drawer" id="af-menu" aria-label="<?= e(t('Menu')) ?>" tabindex="-1">
<div class="af-drawer-head">
<p class="af-drawer-title"><?= e(t('Menu')) ?></p>
<button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= metrica_svg('close') ?></button>
</div>
<div class="af-drawer-body">
<?php if ($mt_main !== []) : ?>
<nav aria-label="<?= e(t('Main menu')) ?>">
<?= $this->partial('partials/nav', ['items' => $mt_main]) ?>
</nav>
<?php endif; ?>
<?php if ($mt_jobs !== []) : ?>
<nav class="mt-drawer-jobs" aria-label="<?= e(t('Jobs')) ?>">
<p class="mt-drawer-ask"><?= e(t('What are you going to do?')) ?></p>
<ul>
<?php foreach ($mt_jobs as $mt_job) : ?>
<?php if ($mt_job['url'] !== '') : ?>
<li><a class="mt-job" href="<?= e($mt_job['url']) ?>"><?= metrica_svg(metrica_job_icon($mt_job['label']), 22) ?><span><?= e($mt_job['label']) ?></span></a></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
</nav>
<?php endif; ?>
<?php if (base_theme_can('account')) : ?>
<p><a class="af-btn af-btn-block" href="<?= e(base_theme_link('account')) ?>"><?= e(t('My account')) ?></a></p>
<?php endif; ?>
<?php if ($mt_legal !== []) : ?>
<nav class="af-small" aria-label="<?= e(t('Legal links')) ?>">
<?= $this->partial('partials/nav', ['items' => $mt_legal]) ?>
</nav>
<?php endif; ?>
</div>
</aside>
<?php if (base_theme_can('search')) : ?>
<aside class="af-drawer af-drawer-top mt-seek" id="af-search" aria-label="<?= e(t('Search')) ?>" tabindex="-1">
<div class="af-drawer-head">
<p class="af-drawer-title"><?= e(t('Search')) ?></p>
<button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= metrica_svg('close') ?></button>
</div>
<form class="af-drawer-body af-search-form" role="search" action="<?= e(base_theme_link('search')) ?>" method="get"<?= hook('webmcp.form', 'search_products') ?>>
<label class="af-sr" for="af-q-drawer"><?= e(t('Search')) ?></label>
<input id="af-q-drawer" class="af-input" type="search" name="q" placeholder="<?= e(t('Search by product, measure or reference…')) ?>" autocomplete="off"<?= hook('webmcp.param', 'search_products', 'q') ?>>
<button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
</form>
</aside>
<?php endif; ?>
<?php if (base_theme_can('cart')) : ?>
<aside class="af-drawer af-drawer-right" id="af-cart" aria-label="<?= e(t('Cart')) ?>" tabindex="-1">
<div class="af-drawer-head">
<p class="af-drawer-title"><?= e(t('Cart')) ?></p>
<button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= metrica_svg('close') ?></button>
</div>
<div class="af-drawer-body">
<p class="af-muted" data-af-cart-empty><?= e(t('Your cart is empty.')) ?></p>
<ul class="af-mini-cart" data-af-cart-lines></ul>
</div>
<div class="af-drawer-foot" data-af-cart-actions hidden>
<p class="af-totals-row" data-af-cart-total-row hidden><span><?= e(t('Total')) ?></span> <strong data-af-cart-total></strong></p>
<a class="af-btn af-btn-block" href="<?= e(base_theme_link('cart')) ?>"><?= e(t('View cart')) ?></a>
<?php if (base_theme_can('checkout')) : ?>
<a class="af-btn af-btn-primary af-btn-block" href="<?= e(base_theme_link('checkout')) ?>"><?= e(t('Checkout')) ?></a>
<?php endif; ?>
</div>
</aside>
<?php endif; ?>
<div class="af-toasts" id="af-toasts" aria-live="polite"></div>
