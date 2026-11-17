<?php
/**
 * AIFEC — portada de taller: una banda de título, el contenido propio de la portada si lo hay, la rejilla de categorías con
 * icono, «Ofertas de la semana», la franja de marcas, el resto de secciones de los módulos, la fila de ventajas del
 * servicio y los consejos del blog. Un solo h1 aunque una sección traiga el suyo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$tl_sections = isset($sections) && is_array($sections) ? $sections : [];
$tl_has_h1 = false;
foreach ($tl_sections as $tl_section) {
    if (stripos((string) $tl_section['html'], '<h1') !== false) {
        $tl_has_h1 = true;
    }
}
$tl_title = trim((string) theme_option('home_title', ''));
$tl_title = $tl_title !== '' ? $tl_title : t('Everything for your workshop');
$tl_text = trim((string) theme_option('home_text', ''));
$tl_text = $tl_text !== '' ? $tl_text : t('Tools, fixings and materials for the job, with the reference and the key features in sight.');

// El orden de la portada: lo propio primero, después categorías, ofertas, marcas y lo demás tal como lo dan los módulos.
$tl_head = [];
$tl_cats = [];
$tl_deals = [];
$tl_rest = [];
foreach ($tl_sections as $tl_section) {
    $tl_id = (string) $tl_section['id'];
    if ($tl_id === 'cms.home') {
        $tl_head[] = $tl_section;
    } elseif ($tl_id === 'catalog.categories') {
        $tl_cats[] = $tl_section;
    } elseif ($tl_id === 'catalog.sale') {
        $tl_deals[] = $tl_section;
    } else {
        $tl_rest[] = $tl_section;
    }
}
$tl_brands_on = (int) theme_option('brands_show', 1) === 1;
$tl_perks_on = (int) theme_option('perks_show', 1) === 1;
$tl_tips_on = (int) theme_option('tips_show', 1) === 1;
$tl_brands = $tl_brands_on ? taller_home_brands(8) : [];
$tl_tips = $tl_tips_on ? taller_home_tips(3) : [];
?>
<section class="tl-band" aria-labelledby="tl-band-title">
    <div class="tl-band-inner af-container">
        <div class="tl-band-copy">
            <?php if ($tl_has_h1) : ?>
                <p class="tl-band-title" id="tl-band-title"><?= e($tl_title) ?></p>
            <?php else : ?>
                <h1 class="tl-band-title" id="tl-band-title"><?= e($tl_title) ?></h1>
            <?php endif; ?>
            <p class="tl-band-text"><?= e($tl_text) ?></p>
        </div>
        <img class="tl-band-img af-hide-sm" src="<?= e(asset('img/workbench.svg')) ?>" alt="" width="360" height="150" loading="eager">
    </div>
</section>
<?= hook('home.top') ?>
<?php foreach ([$tl_head, $tl_cats, $tl_deals] as $tl_group) : ?>
    <?php foreach ($tl_group as $tl_section) : ?>
        <div class="tl-home-section af-container" data-section="<?= e($tl_section['id']) ?>">
            <?= $tl_section['html'] ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php if ($tl_brands !== []) : ?>
    <?= $this->partial('partials/brands', ['brands' => $tl_brands]) ?>
<?php endif; ?>
<?php foreach ($tl_rest as $tl_section) : ?>
    <div class="tl-home-section af-container" data-section="<?= e($tl_section['id']) ?>">
        <?= $tl_section['html'] ?>
    </div>
<?php endforeach; ?>
<?php if ($tl_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?php if ($tl_perks_on) : ?>
    <?= $this->partial('partials/perks') ?>
<?php endif; ?>
<?php if ($tl_tips !== []) : ?>
    <?= $this->partial('partials/tips', ['tips' => $tl_tips]) ?>
<?php endif; ?>
<?= hook('home.bottom') ?>
