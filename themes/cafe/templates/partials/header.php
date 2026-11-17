<?php
/**
 * AIFEC — cabecera de Café, «barra y regleta». Antes de la cabecera, la franja de avisos en tostado (se va al bajar).
 * La cabecera tiene dos pisos: arriba, sobre papel, la marca con su emblema, las secciones del menú en texto a su lado y,
 * a la derecha, las herramientas en lista («Tu cafetera», buscar, cuenta, idiomas y cesta); abajo, sobre tinta café, la
 * regleta con los sistemas y cafeteras para los que hay café, cada uno con su pictograma. La regleta y «Tu cafetera» salen
 * de la faceta real del atributo de sistema: sin ese dato no se pintan. En el móvil queda la barra con el menú, la marca,
 * «Tu cafetera» reducido a su pictograma y la cesta; el panel de sistemas va después de la cabecera.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$cf_main = menu('main');
$cf_langs = app()->languages();
$cf_path = app()->request()->path;
$cf_notes = (int) theme_option('strip_show', 1) === 1 ? cafe_pieces((string) theme_option('strip_text', '')) : [];
$cf_systems = cafe_rail()['items'];
$cf_band = $cf_systems !== [] && (int) theme_option('rail_show', 1) === 1;
?>
<?php if ($cf_notes !== []) : ?>
    <aside class="cf-strip" aria-label="<?= e(t('Shop notices')) ?>">
        <ul class="af-container">
            <?php foreach ($cf_notes as $cf_note) : ?>
                <li><?= e($cf_note) ?></li>
            <?php endforeach; ?>
        </ul>
    </aside>
<?php endif; ?>
<header class="af-header cf-header">
    <div class="af-container cf-bar">
        <?php if ($cf_main !== []) : ?>
            <button type="button" class="cf-tool af-show-sm" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>"><?= cafe_svg('menu', 24) ?></button>
        <?php endif; ?>
        <a class="af-logo cf-brand" href="<?= e(url('/')) ?>"><?= cafe_logo_is_text() ? cafe_emblem(52) : '' ?><strong class="cf-brand-name"><?= shop_logo() ?></strong></a>
        <?php if ($cf_main !== []) : ?>
            <nav class="af-nav cf-sections af-hide-sm" aria-label="<?= e(t('Main menu')) ?>">
                <?php foreach ($cf_main as $cf_item) : ?>
                    <?php
                    $cf_label = isset($cf_item['label']) ? (string) $cf_item['label'] : '';
                    $cf_href = isset($cf_item['url']) ? (string) $cf_item['url'] : '';
                    $cf_kids = isset($cf_item['children']) && is_array($cf_item['children']) ? array_values(array_filter($cf_item['children'], 'is_array')) : [];
                    if ($cf_label === '' || ($cf_href === '' && $cf_kids === [])) {
                        continue;
                    }
                    ?>
                    <?php if ($cf_kids === []) : ?>
                        <a class="cf-section-link" href="<?= e($cf_href) ?>"<?= !empty($cf_item['current']) ? ' aria-current="page"' : '' ?>><?= e($cf_label) ?></a>
                    <?php else : ?>
                        <details class="cf-section-more">
                            <summary class="cf-section-link"><?= e($cf_label) ?></summary>
                            <?= $this->partial('partials/nav', ['items' => $cf_href !== '' ? array_merge([['label' => t('See all'), 'url' => $cf_href]], $cf_kids) : $cf_kids]) ?>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <ul class="cf-tools">
            <?php if ($cf_systems !== []) : ?>
                <li class="cf-tools-chip">
                    <button type="button" class="cf-chip" data-af-drawer-open="cf-systems" aria-controls="cf-systems" aria-expanded="false">
                        <?= cafe_svg('capsule', 22) ?>
                        <span class="cf-chip-text"><small><?= e(t('Your coffee maker')) ?></small> <b data-cf-chip data-cf-none="<?= e(t('Choose your coffee maker')) ?>"><?= e(t('Choose your coffee maker')) ?></b></span>
                    </button>
                </li>
            <?php endif; ?>
            <?php if (base_theme_can('search')) : ?>
                <li class="af-hide-sm"><a class="cf-tool" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search" aria-label="<?= e(t('Search')) ?>"><?= cafe_svg('search') ?></a></li>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <li class="af-hide-sm"><a class="cf-tool" href="<?= e(base_theme_link('account')) ?>" data-af-account aria-label="<?= e(t('My account')) ?>"><?= cafe_svg('user') ?></a></li>
            <?php endif; ?>
            <?php foreach (count($cf_langs) > 1 ? $cf_langs : [] as $cf_code) : ?>
                <li class="af-hide-sm"><a class="cf-tool cf-lang" href="<?= e(app()->url()->to($cf_path, [], $cf_code)) ?>" hreflang="<?= e($cf_code) ?>" lang="<?= e($cf_code) ?>"<?php if ($cf_code === app()->lang()) : ?> aria-current="true"<?php endif; ?>><?= e(strtoupper($cf_code)) ?></a></li>
            <?php endforeach; ?>
            <?php if (base_theme_can('cart')) : ?>
                <li>
                    <a class="cf-tool cf-basket" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                        <?= cafe_svg('bag') ?>
                        <span class="af-cart-count" data-af-cart-count hidden></span>
                    </a>
                </li>
            <?php endif; ?>
            <li class="cf-tools-hook"><?= hook('header.actions') ?></li>
        </ul>
    </div>
    <?php if ($cf_band) : ?>
        <nav class="cf-rail af-hide-sm" aria-label="<?= e(t('Coffee by coffee maker')) ?>">
            <ol class="af-container">
                <?php foreach (array_slice($cf_systems, 0, 7) as $cf_system) : ?>
                    <li><a href="<?= e($cf_system['url']) ?>" data-cf-pick="<?= e($cf_system['code']) ?>" data-cf-label="<?= e($cf_system['label']) ?>"><?= cafe_svg($cf_system['pict'], 18) ?><?= e($cf_system['label']) ?></a></li>
                <?php endforeach; ?>
                <?php if (count($cf_systems) > 7) : ?>
                    <li class="cf-rail-more"><button type="button" data-af-drawer-open="cf-systems" aria-controls="cf-systems" aria-expanded="false"><?= cafe_svg('machine', 18) ?><?= e(t('All coffee makers')) ?> <b><?= e(count($cf_systems)) ?></b></button></li>
                <?php endif; ?>
            </ol>
        </nav>
    <?php endif; ?>
</header>
<?php if ($cf_systems !== []) : ?>
    <aside class="af-drawer af-drawer-right cf-panel" id="cf-systems" aria-label="<?= e(t('Your coffee maker')) ?>" tabindex="-1">
        <div class="af-drawer-head">
            <p class="af-drawer-title"><?= e(t('Your coffee maker')) ?></p>
            <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= cafe_svg('close') ?></button>
        </div>
        <div class="af-drawer-body">
            <p class="af-muted"><?= e(t('Pick it once: we keep it in your browser and every card tells you whether a coffee fits it.')) ?></p>
            <ul class="cf-panel-list">
                <?php foreach ($cf_systems as $cf_system) : ?>
                    <li>
                        <a href="<?= e($cf_system['url']) ?>" data-cf-pick="<?= e($cf_system['code']) ?>" data-cf-label="<?= e($cf_system['label']) ?>">
                            <?= cafe_svg($cf_system['pict'], 26) ?>
                            <b><?= e($cf_system['label']) ?></b>
                            <small><?= e(tn('{n} coffee', '{n} coffees', $cf_system['count'])) ?></small>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="af-drawer-foot" data-cf-mine hidden>
            <button type="button" class="af-btn af-btn-block" data-cf-clear><?= e(t('Forget my coffee maker')) ?></button>
        </div>
    </aside>
<?php endif; ?>
