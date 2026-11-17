<?php
/**
 * AIFEC — portada del tema base: una cabecera de portada (P100: el título y el texto de «Temas → Portada» o, si no están,
 * el nombre de la tienda y su descripción de portada, con un botón a la categoría principal), la franja de ventajas (las
 * mismas líneas de confianza de la ficha: envío, devolución y pago, solo con datos) y las secciones que aportan los módulos,
 * en su orden (el mosaico de categorías con imagen es la sección «catalog.categories»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$af_sections = isset($sections) && is_array($sections) ? $sections : [];
$af_has_h1 = false;
foreach ($af_sections as $af_section) {
    if (stripos((string) $af_section['html'], '<h1') !== false) {
        $af_has_h1 = true;
    }
}
$af_app = app();
$af_title = trim((string) theme_option('home_title', ''));
$af_title = $af_title !== '' ? $af_title : $af_app->settings()->str('store.name', 'AIFEC');
$af_text = trim((string) theme_option('home_text', ''));
$af_text = $af_text !== '' ? $af_text : trim($af_app->settings()->str('seo.home_description', ''));
$af_info = \Aifec\Front\StoreInfo::data($af_app, true);
$af_main = $af_info['main'];
// La pieza de las líneas es del catálogo: sin él, la portada no lleva franja.
$af_trust = $af_app->view()->exists('catalog::front/partials/trust') ? $af_info['trust'] : [];
?>
<section class="af-hero" aria-labelledby="af-hero-title">
    <div class="af-container af-hero-inner">
        <?php if ($af_has_h1) : ?>
            <p class="af-hero-title" id="af-hero-title"><?= e($af_title) ?></p>
        <?php else : ?>
            <h1 class="af-hero-title" id="af-hero-title"><?= e($af_title) ?></h1>
        <?php endif; ?>
        <?php if ($af_text !== '') : ?>
            <p class="af-hero-text"><?= e($af_text) ?></p>
        <?php endif; ?>
        <?php if ($af_main !== null) : ?>
            <p><a class="af-btn af-btn-primary af-btn-lg" href="<?= e($af_main['url']) ?>"><?= e(t('Shop {category}', ['category' => $af_main['name']])) ?></a></p>
        <?php endif; ?>
    </div>
</section>
<?php if ($af_trust !== []) : ?>
    <div class="af-container af-home-perks">
        <?= $this->partial('catalog::front/partials/trust', ['lines' => $af_trust, 'class' => 'af-trust-row']) ?>
    </div>
<?php endif; ?>
<?= hook('home.top') ?>
<?php foreach ($af_sections as $af_section) : ?>
    <div class="af-home-section af-container" data-section="<?= e($af_section['id']) ?>">
        <?= $af_section['html'] ?>
    </div>
<?php endforeach; ?>
<?php if ($af_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?= hook('home.bottom') ?>
