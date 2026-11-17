<?php
/**
 * AIFEC — portada de atelier: imagen de campaña a todo el ancho (la elegida o, si no hay, la foto de un producto destacado de
 * la tienda, entera; nunca un dibujo de otro sector) con el texto abajo a la izquierda, las secciones de los
 * módulos (mosaico asimétrico de categorías, novedades en fila de cuatro…) y un bloque editorial partido en imagen y texto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$at_sections = isset($sections) && is_array($sections) ? $sections : [];
$at_has_h1 = false;
foreach ($at_sections as $at_section) {
    if (stripos((string) $at_section['html'], '<h1') !== false) {
        $at_has_h1 = true;
    }
}

// Campaña: la foto elegida o la de un producto destacado de la tienda (nunca un dibujo de otro sector).
$at_hero = atelier_hero_image();
$at_kicker = trim((string) theme_option('hero_kicker', ''));
$at_title = trim((string) theme_option('hero_title', ''));
$at_title = $at_title !== '' ? $at_title : t('The new season');
$at_text = trim((string) theme_option('hero_text', ''));
$at_text = $at_text !== '' ? $at_text : t('Clean lines, honest fabrics and pieces made to last.');
$at_cta = trim((string) theme_option('hero_cta', ''));
$at_cta = $at_cta !== '' ? $at_cta : t('Discover the collection');
$at_link = atelier_link((string) theme_option('hero_link', ''));
if ($at_link === '') {
    foreach (menu('main') as $at_menu_item) {
        if (is_array($at_menu_item) && !empty($at_menu_item['url'])) {
            $at_link = (string) $at_menu_item['url'];
            break;
        }
    }
}
$at_tall = theme_option('hero_height', 'tall') !== 'medium';

// Bloque editorial.
$at_editorial_on = (int) theme_option('editorial_show', 1) === 1;
$at_ed_image = atelier_image('editorial_image');
$at_ed_title = trim((string) theme_option('editorial_title', ''));
$at_ed_title = $at_ed_title !== '' ? $at_ed_title : t('Made slowly, worn for years');
$at_ed_text = trim((string) theme_option('editorial_text', ''));
$at_ed_text = $at_ed_text !== '' ? $at_ed_text : t('Each season we edit the collection down to the pieces we would wear ourselves: natural fibres, careful cuts and colours that go with everything.');
$at_ed_cta = trim((string) theme_option('editorial_cta', ''));
$at_ed_cta = $at_ed_cta !== '' ? $at_ed_cta : t('Read our story');
$at_ed_link = atelier_link((string) theme_option('editorial_link', ''));

// El bloque editorial va detrás de las novedades; si no las hay, al final.
$at_ed_after = count($at_sections);
foreach ($at_sections as $at_i => $at_section) {
    if ((string) $at_section['id'] === 'catalog.new') {
        $at_ed_after = $at_i + 1;
    }
}
?>
<section class="at-hero<?= ($at_tall ? ' at-hero-tall' : '') ?>" aria-labelledby="at-hero-title">
    <?php if ($at_hero !== null) : ?>
        <?= image_tag($at_hero['image'], 'large', ['class' => 'at-hero-img' . ($at_hero['product'] ? ' at-hero-product' : ''), 'alt' => '', 'loading' => 'eager', 'fetchpriority' => 'high']) ?>
    <?php else : ?>
        <span class="at-hero-img" aria-hidden="true"></span>
    <?php endif; ?>
    <div class="at-hero-copy af-container">
        <?php if ($at_kicker !== '') : ?>
            <p class="at-kicker"><?= e($at_kicker) ?></p>
        <?php endif; ?>
        <?php if ($at_has_h1) : ?>
            <p class="at-hero-title" id="at-hero-title"><?= e($at_title) ?></p>
        <?php else : ?>
            <h1 class="at-hero-title" id="at-hero-title"><?= e($at_title) ?></h1>
        <?php endif; ?>
        <p class="at-hero-text"><?= e($at_text) ?></p>
        <?php if ($at_link !== '') : ?>
            <p><a class="at-btn-light af-btn" href="<?= e($at_link) ?>"><?= e($at_cta) ?></a></p>
        <?php endif; ?>
    </div>
</section>
<?= hook('home.top') ?>
<?php foreach ($at_sections as $at_i => $at_section) : ?>
    <div class="at-home-section af-container" data-section="<?= e($at_section['id']) ?>">
        <?= ($at_section['html']) ?>
    </div>
    <?php if ($at_editorial_on && $at_ed_after === $at_i + 1) : ?>
        <?= $this->partial('partials/editorial', ['image' => $at_ed_image, 'title' => $at_ed_title, 'text' => $at_ed_text, 'cta' => $at_ed_cta, 'link' => $at_ed_link]) ?>
    <?php endif; ?>
<?php endforeach; ?>
<?php if ($at_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
    <?php if ($at_editorial_on) : ?>
        <?= $this->partial('partials/editorial', ['image' => $at_ed_image, 'title' => $at_ed_title, 'text' => $at_ed_text, 'cta' => $at_ed_cta, 'link' => $at_ed_link]) ?>
    <?php endif; ?>
<?php endif; ?>
<?= hook('home.bottom') ?>
