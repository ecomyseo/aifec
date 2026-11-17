<?php
/**
 * AIFEC — cabecera de Lookbook, como la mancheta de una revista: una línea fina de temporada y una sola fila con la
 * palabra «Menú» a la izquierda, la marca en el centro y, a la derecha, las acciones escritas («Buscar», «Cuenta»,
 * «Carrito (n)»), sin iconos. El buscador no es una caja: se abre bajo la fila, a todo el ancho. El menú es el índice
 * lateral (panel «af-menu»). Solo pinta los accesos cuya ruta existe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$lb_main = menu('main');
$lb_langs = app()->languages();
$lb_path = app()->request()->path;
$lb_season = '';
if ((int) theme_option('season_show', 1) === 1) {
    $lb_season = trim((string) theme_option('season_text', ''));
    $lb_season = $lb_season !== '' ? $lb_season : t('New season: the collection is here');
}
$lb_under = theme_option('season_place', 'above') === 'below';
$lb_left = theme_option('header_style', 'centered') === 'classic';
?>
<header class="af-header lb-mast<?= ($lb_left ? ' lb-mast-left' : '') ?>">
    <?php if ($lb_season !== '' && !$lb_under) : ?>
        <p class="lb-season"><?= e($lb_season) ?></p>
    <?php endif; ?>
    <div class="lb-mast-row">
        <?php if ($lb_main !== []) : ?>
            <button type="button" class="lb-word lb-mast-menu" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false"><i class="lb-rule" aria-hidden="true"></i><?= e(t('Menu')) ?></button>
        <?php endif; ?>
        <a class="af-logo lb-brand" href="<?= e(url('/')) ?>"><?= shop_logo() ?></a>
        <div class="lb-mast-end">
        <ul class="lb-tools">
            <?php if (base_theme_can('search')) : ?>
                <li class="af-hide-sm">
                    <a class="lb-word" href="<?= e(base_theme_link('search')) ?>" role="button" data-af-toggle="lb-find" aria-controls="lb-find" aria-expanded="false"><?= e(t('Search')) ?></a>
                    <form class="lb-find" id="lb-find" role="search" action="<?= e(base_theme_link('search')) ?>" method="get" hidden<?= hook('webmcp.form', 'search_products') ?>>
                        <label class="af-sr" for="lb-q"><?= e(t('Search')) ?></label>
                        <input id="lb-q" class="af-input" type="search" name="q" placeholder="<?= e(t('What are you looking for?')) ?>" autocomplete="off"<?= hook('webmcp.param', 'search_products', 'q') ?>>
                        <button type="submit" class="lb-word"><?= e(t('Search')) ?></button>
                    </form>
                </li>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <li class="af-hide-sm"><a class="lb-word" href="<?= e(base_theme_link('account')) ?>" data-af-account><?= e(t('Account')) ?></a></li>
            <?php endif; ?>
            <?php foreach (count($lb_langs) > 1 ? $lb_langs : [] as $lb_code) : ?>
                <li class="af-hide-sm"><a class="lb-word" href="<?= e(app()->url()->to($lb_path, [], $lb_code)) ?>" hreflang="<?= e($lb_code) ?>" lang="<?= e($lb_code) ?>"<?= ($lb_code === app()->lang() ? ' aria-current="true"' : '') ?>><?= e(strtoupper($lb_code)) ?></a></li>
            <?php endforeach; ?>
            <?php if (base_theme_can('cart')) : ?>
                <li><a class="lb-word lb-bag" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart"><?= e(t('Cart')) ?> <span class="lb-bag-n" data-af-cart-count hidden></span></a></li>
            <?php endif; ?>
        </ul>
        <?= hook('header.actions') ?>
        </div>
    </div>
    <?php if ($lb_season !== '' && $lb_under) : ?>
        <p class="lb-season"><?= e($lb_season) ?></p>
    <?php endif; ?>
</header>
