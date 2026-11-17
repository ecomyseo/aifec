<?php
/**
 * AIFEC — cabecera de atelier: franja fina de aviso, logotipo centrado, menú a la izquierda con desplegable ancho por
 * columnas, y buscador (capa a pantalla completa), cuenta y carrito como iconos a la derecha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$at_main = menu('main');
$at_mega = (int) theme_option('mega_menu', 1) === 1;
$at_classic = theme_option('header_style', 'centered') === 'classic';
$at_strip = (int) theme_option('strip_show', 1) === 1 ? trim((string) theme_option('strip_text', '')) : '';
if ((int) theme_option('strip_show', 1) === 1 && $at_strip === '') {
    $at_strip = t('New season. Discover the latest arrivals.');
}
$at_langs = app()->languages();
$at_path = app()->request()->path;

/**
 * Los elementos de un menú que se pueden pintar: con texto y, o con dirección, o con hijos que la tengan.
 *
 * @param array<int,mixed> $items
 *
 * @return array<int,array<string,mixed>>
 */
$at_clean = static function (array $items): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = isset($item['label']) ? (string) $item['label'] : '';
        $href = isset($item['url']) ? (string) $item['url'] : '';
        $kids = isset($item['children']) && is_array($item['children']) ? array_values(array_filter($item['children'], 'is_array')) : [];
        if ($label === '' || ($href === '' && $kids === [])) {
            continue;
        }
        $out[] = ['label' => $label, 'url' => $href, 'current' => !empty($item['current']), 'children' => $kids];
    }

    return $out;
};
?>
<?php if ($at_strip !== '') : ?>
<div class="at-strip"><p class="at-strip-text"><?= e($at_strip) ?></p></div>
<?php endif; ?>
<header class="at-header af-header<?= ($at_classic ? ' at-header-classic' : '') ?>">
    <div class="at-header-inner af-container">
        <div class="at-header-start">
            <?php if ($at_main !== []) : ?>
                <button type="button" class="af-icon-btn af-show-sm" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M3 8h18M3 16h12"/></svg>
                </button>
                <nav class="at-nav af-hide-sm<?= ($at_mega ? '' : ' at-nav-simple') ?>" aria-label="<?= e(t('Main menu')) ?>">
                    <ul class="at-menu">
                        <?php foreach ($at_clean($at_main) as $at_item) : ?>
                            <?php $at_kids = $at_clean($at_item['children']); ?>
                            <li class="at-menu-item<?= ($at_kids !== [] ? ' has-mega' : '') ?>">
                                <?php if ($at_item['url'] !== '') : ?>
                                    <a class="at-menu-link" href="<?= e($at_item['url']) ?>"<?= ($at_item['current'] ? ' aria-current="page"' : '') ?>><?= e($at_item['label']) ?></a>
                                <?php else : ?>
                                    <span class="at-menu-link at-menu-label" tabindex="0"><?= e($at_item['label']) ?></span>
                                <?php endif; ?>
                                <?php if ($at_kids !== []) : ?>
                                    <div class="at-mega">
                                        <div class="at-mega-inner">
                                            <?php foreach ($at_kids as $at_kid) : ?>
                                                <?php $at_leaves = $at_clean($at_kid['children']); ?>
                                                <div class="at-mega-col">
                                                    <?php if ($at_kid['url'] !== '') : ?>
                                                        <a class="at-mega-head" href="<?= e($at_kid['url']) ?>"><?= e($at_kid['label']) ?></a>
                                                    <?php else : ?>
                                                        <p class="at-mega-head"><?= e($at_kid['label']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($at_leaves !== []) : ?>
                                                        <ul class="at-mega-list">
                                                            <?php foreach ($at_leaves as $at_leaf) : ?>
                                                                <?php if ($at_leaf['url'] !== '') : ?>
                                                                    <li><a href="<?= e($at_leaf['url']) ?>"><?= e($at_leaf['label']) ?></a></li>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
        <a class="at-logo af-logo" href="<?= e(url('/')) ?>"><?= shop_logo() ?></a>
        <div class="at-header-end af-header-actions">
            <?= hook('header.actions') ?>
            <?php if (count($at_langs) > 1) : ?>
                <ul class="af-langs af-hide-sm" aria-label="<?= e(t('Language')) ?>">
                    <?php foreach ($at_langs as $at_code) : ?>
                        <li><a href="<?= e(app()->url()->to($at_path, [], $at_code)) ?>" hreflang="<?= e($at_code) ?>" lang="<?= e($at_code) ?>"<?= ($at_code === app()->lang() ? ' aria-current="true"' : '') ?>><?= e(strtoupper($at_code)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (base_theme_can('search')) : ?>
                <button type="button" class="af-icon-btn af-hide-sm" data-af-drawer-open="af-search" aria-controls="af-search" aria-expanded="false" aria-label="<?= e(t('Search')) ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                </button>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <a class="af-icon-btn af-hide-sm" href="<?= e(base_theme_link('account')) ?>" aria-label="<?= e(t('My account')) ?>" data-af-account>
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="8.5" r="3.8"/><path d="M5 20.5c.6-3.6 3.5-5.4 7-5.4s6.4 1.8 7 5.4"/></svg>
                </a>
            <?php endif; ?>
            <?php if (base_theme_can('cart')) : ?>
                <a class="af-icon-btn af-hide-sm" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></svg>
                    <span class="af-cart-count" data-af-cart-count hidden></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
