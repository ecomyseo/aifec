<?php
/**
 * AIFEC — portada de despensa: la historia en grande (texto sobre fondo de color y una imagen de forma orgánica), el
 * contenido propio de la portada, «Lotes y regalos» en tres tarjetas grandes, los productos de temporada en una fila que
 * se desliza con el dedo, el resto de secciones de los módulos, el bloque del productor y las recetas del blog. Un solo
 * h1 aunque una sección traiga el suyo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$ds_sections = isset($sections) && is_array($sections) ? $sections : [];
$ds_has_h1 = false;
foreach ($ds_sections as $ds_section) {
    if (stripos((string) $ds_section['html'], '<h1') !== false) {
        $ds_has_h1 = true;
    }
}
$ds_kicker = trim((string) theme_option('story_kicker', ''));
$ds_kicker = $ds_kicker !== '' ? $ds_kicker : t('Our story');
$ds_title = trim((string) theme_option('story_title', ''));
$ds_title = $ds_title !== '' ? $ds_title : t('Good food with a name and a place');
$ds_text = trim((string) theme_option('story_text', ''));
$ds_text = $ds_text !== '' ? $ds_text : t('We bring oils, preserves, cheeses and sweets from the small producers who make them, and send them to your door packed by hand.');
$ds_cta = trim((string) theme_option('story_cta', ''));
$ds_cta = $ds_cta !== '' ? $ds_cta : t('Discover the pantry');
$ds_link = despensa_link((string) theme_option('story_link', ''));
$ds_link = $ds_link !== '' ? $ds_link : '#ds-shop';
$ds_side = theme_option('story_side', 'right') === 'left' ? 'left' : 'right';
$ds_picture = despensa_image('story_image');

// El orden de la portada: lo propio primero, después los lotes, la estantería de temporada y lo demás.
$ds_head = [];
$ds_gifts = [];
$ds_shelf = [];
$ds_rest = [];
foreach ($ds_sections as $ds_section) {
    $ds_id = (string) $ds_section['id'];
    if ($ds_id === 'cms.home') {
        $ds_head[] = $ds_section;
    } elseif ($ds_id === 'catalog.categories') {
        $ds_gifts[] = $ds_section;
    } elseif (strpos($ds_id, 'catalog.') === 0) {
        $ds_shelf[] = $ds_section;
    } else {
        $ds_rest[] = $ds_section;
    }
}
$ds_posts = (int) theme_option('recipes_show', 1) === 1 ? despensa_home_posts(3) : [];
?>
<section class="ds-story ds-story-<?= e($ds_side) ?>" aria-labelledby="ds-story-title">
    <div class="ds-story-inner af-container">
        <div class="ds-story-copy">
            <p class="ds-kicker"><?= despensa_svg('leaf', 16) ?><span><?= e($ds_kicker) ?></span></p>
            <?php if ($ds_has_h1) : ?>
                <p class="ds-story-title" id="ds-story-title"><?= e($ds_title) ?></p>
            <?php else : ?>
                <h1 class="ds-story-title" id="ds-story-title"><?= e($ds_title) ?></h1>
            <?php endif; ?>
            <p class="ds-story-text"><?= e($ds_text) ?></p>
            <p class="ds-story-actions"><a class="ds-story-cta af-btn af-btn-primary af-btn-lg" href="<?= e($ds_link) ?>"><span><?= e($ds_cta) ?></span><?= despensa_svg('arrow', 20) ?></a></p>
        </div>
        <div class="ds-story-art">
            <?php if ($ds_picture !== null) : ?>
                <?= image_tag($ds_picture, 'large', ['class' => 'ds-story-img', 'alt' => '', 'loading' => 'eager', 'fetchpriority' => 'high']) ?>
            <?php else : ?>
                <img class="ds-story-img" src="<?= e(asset('img/story.svg')) ?>" alt="" width="640" height="560" loading="eager" fetchpriority="high">
            <?php endif; ?>
        </div>
    </div>
</section>
<?= hook('home.top') ?>
<div class="ds-home" id="ds-shop">
<?php foreach ([$ds_head, $ds_gifts, $ds_shelf, $ds_rest] as $ds_group) : ?>
    <?php foreach ($ds_group as $ds_section) : ?>
        <div class="ds-home-section af-container" data-section="<?= e($ds_section['id']) ?>">
            <?= $ds_section['html'] ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php if ($ds_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?php if ((int) theme_option('producer_show', 1) === 1) : ?>
    <div class="ds-home-section af-container" data-section="ds.producer">
        <?= $this->partial('partials/producer') ?>
    </div>
<?php endif; ?>
</div>
<?php if ($ds_posts !== []) : ?>
    <?= $this->partial('partials/recipes', ['posts' => $ds_posts]) ?>
<?php endif; ?>
<?= hook('home.bottom') ?>
