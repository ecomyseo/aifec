<?php
/**
 * AIFEC — portada de gmartos: el titular grande con su raya azul y el diagrama de nodos conectados, el buscador con sus
 * atajos, las tarjetas de productos que aportan los módulos, las dos tarjetas de servicios y la presentación, todo con los
 * textos de fábrica de la tienda y una opción para cambiar cada uno.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                               $page
 * @var array<int,array{id:string,order:int,html:string}> $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$gm_sections = isset($sections) && is_array($sections) ? $sections : [];
$gm_has_h1 = false;
$gm_head = [];
$gm_rest = [];
foreach ($gm_sections as $gm_section) {
    if (stripos((string) $gm_section['html'], '<h1') !== false) {
        $gm_has_h1 = true;
    }
    if ((string) $gm_section['id'] === 'cms.home') {
        $gm_head[] = $gm_section;
    } else {
        $gm_rest[] = $gm_section;
    }
}
$gm_title = gmartos_lines(gmartos_text('home_title', t('Your shop has|potential. Give it|the tools.')));
$gm_shop_url = gmartos_shop_url();
$gm_btn2_url = gmartos_link((string) theme_option('home_button2_link', ''));
$gm_chips = gmartos_chips();
$gm_dev_url = gmartos_link((string) theme_option('dev_link', ''));
$gm_sup_url = gmartos_link((string) theme_option('support_link', ''));
$gm_dev_link_text = gmartos_text('dev_link_text', t('Tell me about your project'));
$gm_sup_link_text = gmartos_text('support_link_text', t('See support packs'));
$gm_note = gmartos_text('about_note', t('You will see the new brand in emails and invoices. The domain and the contact email stay the same.'));
$gm_name = gmartos_text('about_name', t('Gustavo Martos'));
?>
<section class="af-container gm-hero" aria-labelledby="gm-hero-title">
    <div class="gm-hero-copy">
        <p class="gm-kicker gm-kicker-dot"><?= e(gmartos_text('home_kicker', t('Tools for your e-commerce'))) ?></p>
        <?php if ($gm_has_h1) : ?>
            <p class="gm-hero-title" id="gm-hero-title"><?= $gm_title ?></p>
        <?php else : ?>
            <h1 class="gm-hero-title" id="gm-hero-title"><?= $gm_title ?></h1>
        <?php endif; ?>
        <p class="gm-lead"><?= e(gmartos_text('home_text', t('Own modules, integrations and specialised support so your PrestaShop works the way your business needs.'))) ?></p>
        <div class="gm-actions">
            <a class="af-btn af-btn-primary" href="<?= e($gm_shop_url !== '' ? $gm_shop_url : '#gm-catalog') ?>"><?= e(gmartos_text('home_button', t('Explore products'))) ?></a>
            <?php if ($gm_btn2_url !== '') : ?>
                <a class="af-btn af-btn-ghost gm-btn-outline" href="<?= e($gm_btn2_url) ?>"><?= e(gmartos_text('home_button2', t('Tell me about your project'))) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ((int) theme_option('diagram_show', 1) === 1) : ?>
        <div class="gm-diagram" role="img" aria-label="<?= e(t('Your PrestaShop connected with modules, management, B2B sales and automation')) ?>">
            <svg class="gm-links af-hide-sm" viewBox="0 0 535 485" fill="none" aria-hidden="true" focusable="false"><path d="M105 105H220V210M445 98V208H340M100 340H260V260M400 365V260" stroke="currentColor" stroke-width="2"/></svg>
            <div class="gm-node gm-node-store"><?= gmartos_svg('store', 54) ?><strong><?= e(t('Your PrestaShop')) ?></strong><span><?= e(t('The centre of your business')) ?></span></div>
            <div class="gm-node gm-node-erp"><i></i><strong><?= e(t('Your ERP')) ?></strong></div>
            <div class="gm-node gm-node-modules"><?= gmartos_svg('modules', 49) ?><strong><?= e(t('Modules')) ?></strong></div>
            <div class="gm-node gm-node-b2b"><span class="gm-people" aria-hidden="true">● ●</span><strong><?= e(t('B2B sales')) ?></strong></div>
            <div class="gm-node gm-node-auto"><strong><?= e(t('Automation')) ?></strong><span><?= e(t('Fewer repetitive tasks.')) ?></span></div>
        </div>
    <?php endif; ?>
</section>
<?= hook('home.top') ?>
<?php foreach ($gm_head as $gm_section) : ?>
    <div class="gm-home-section af-container" data-section="<?= e($gm_section['id']) ?>">
        <?= $gm_section['html'] ?>
    </div>
<?php endforeach; ?>
<?php if (base_theme_can('search')) : ?>
<section class="af-container gm-discovery" aria-labelledby="gm-discovery-title">
    <div>
        <p class="gm-kicker"><?= e(t('Find your solution')) ?></p>
        <h2 id="gm-discovery-title"><?= e(t('What does your shop need?')) ?></h2>
    </div>
    <div class="gm-discovery-box">
        <form class="gm-search" role="search" action="<?= e(base_theme_link('search')) ?>" method="get"<?= hook('webmcp.form', 'search_products') ?>>
            <?= gmartos_svg('search', 21) ?>
            <label class="af-sr" for="gm-q-home"><?= e(t('Search')) ?></label>
            <input id="gm-q-home" type="search" name="q" placeholder="<?= e(t('Search a module or a need…')) ?>" autocomplete="off" required<?= hook('webmcp.param', 'search_products', 'q') ?>>
            <button type="submit" class="af-btn"><span><?= e(t('Search')) ?></span></button>
        </form>
        <?php if ($gm_chips !== []) : ?>
            <p class="af-sr" id="gm-chips-label"><?= e(t('Search by need')) ?></p>
            <div class="gm-chips" role="group" aria-labelledby="gm-chips-label">
                <?php foreach ($gm_chips as $gm_chip) : ?>
                    <a href="<?= e($gm_chip[1]) ?>"><?= e($gm_chip[0]) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php if ($gm_rest !== []) : ?>
<section class="af-container gm-catalog" id="gm-catalog" aria-labelledby="gm-catalog-title">
    <div class="gm-heading">
        <div>
            <p class="gm-kicker"><?= e(gmartos_text('catalog_kicker', t('Own modules'))) ?></p>
            <h2 id="gm-catalog-title"><?= gmartos_lines(gmartos_text('catalog_title', t('Small tools.|Big possibilities.'))) ?></h2>
        </div>
        <?php if ($gm_shop_url !== '') : ?>
            <a class="gm-text-link" href="<?= e($gm_shop_url) ?>"><?= e(t('View all')) ?> <?= gmartos_svg('arrow', 24) ?></a>
        <?php endif; ?>
    </div>
    <?php foreach ($gm_rest as $gm_section) : ?>
        <div class="gm-home-section" data-section="<?= e($gm_section['id']) ?>">
            <?= $gm_section['html'] ?>
        </div>
    <?php endforeach; ?>
    <p class="gm-note"><?= e(t('Check each product for its options and buying conditions.')) ?></p>
</section>
<?php endif; ?>
<?php if ($gm_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?php if ((int) theme_option('services_show', 1) === 1) : ?>
<section class="af-container gm-services" id="servicios" aria-labelledby="gm-services-title">
    <p class="gm-kicker"><?= e(t('Development and support')) ?></p>
    <h2 id="gm-services-title"><?= e(t('When you need something more.')) ?></h2>
    <div class="gm-service-grid">
        <article class="gm-service gm-service-dark">
            <p class="gm-kicker"><?= e(t('01 / Custom development')) ?></p>
            <h3><?= gmartos_lines(gmartos_text('dev_title', t('Your business does not fit|in a standard module.'))) ?></h3>
            <p class="gm-service-text"><?= e(gmartos_text('dev_text', t('Connectors, B2B processes and features that adapt to the way you work.'))) ?></p>
            <?php if ($gm_dev_url !== '') : ?>
                <a class="gm-text-link" href="<?= e($gm_dev_url) ?>"><?= e($gm_dev_link_text) ?> <?= gmartos_svg('arrow', 24) ?></a>
            <?php endif; ?>
        </article>
        <article class="gm-service gm-service-pale">
            <p class="gm-kicker"><?= e(t('02 / Shop support')) ?></p>
            <h3><?= gmartos_lines(gmartos_text('support_title', t('Your shop,|in good hands.'))) ?></h3>
            <p class="gm-service-text"><?= e(gmartos_text('support_text', t('Incidents, maintenance and improvements. Technical help with direct contact.'))) ?></p>
            <?php if ($gm_sup_url !== '') : ?>
                <a class="gm-text-link" href="<?= e($gm_sup_url) ?>"><?= e($gm_sup_link_text) ?> <?= gmartos_svg('arrow', 24) ?></a>
            <?php endif; ?>
        </article>
    </div>
</section>
<?php endif; ?>
<?php if ((int) theme_option('about_show', 1) === 1) : ?>
<section class="af-container gm-about" aria-labelledby="gm-about-title">
    <div>
        <p class="gm-kicker"><?= e(t('Same contact. New identity.')) ?></p>
        <h2 id="gm-about-title"><?= gmartos_lines(gmartos_text('about_title', t('A new brand.|The same person behind it.'))) ?></h2>
    </div>
    <div class="gm-about-copy">
        <p><?= e(gmartos_text('about_text', t('I am Gustavo Martos. I develop modules, connect systems and solve problems of online shops. This work has a name: Ecom Experts.'))) ?></p>
        <strong><?= e($gm_name) ?></strong>
        <span><?= e(gmartos_text('about_role', t('Development · Integrations · Support'))) ?></span>
    </div>
    <p class="gm-continuity"><?= e($gm_note) ?></p>
</section>
<?php endif; ?>
<?= hook('home.bottom') ?>
