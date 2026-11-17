<?php
/**
 * AIFEC — portada del tema aifec: las secciones que aportan los módulos, en su orden. Las que trae la página de portada del
 * gestor de contenidos (cms.home arriba y cms.home.more debajo del catálogo; ver aifec_theme_home_sections()) van SIN contenedor:
 * sus bandas (los bloques «grupo» de primer nivel) ocupan todo el ancho y se centran ellas mismas. Las secciones de otros
 * módulos (la rejilla de categorías, los destacados, las novedades) van en el contenedor de siempre.
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
?>
<?php if (!$af_has_h1) : ?>
    <h1 class="af-sr"><?= e(app()->settings()->str('store.name', 'AIFEC')) ?></h1>
<?php endif; ?>
<?= hook('home.top') ?>
<?php foreach ($af_sections as $af_section) : ?>
    <?php if (strpos((string) $af_section['id'], 'cms.home') === 0) : ?>
        <div class="af-home-section af-home-bands" data-section="<?= e($af_section['id']) ?>">
            <?= $af_section['html'] ?>
        </div>
    <?php else : ?>
        <div class="af-home-section af-container" data-section="<?= e($af_section['id']) ?>">
            <?= $af_section['html'] ?>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
<?php if ($af_sections === []) : ?>
    <div class="af-container af-section">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The site is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Mark a page as the home page from the panel and it will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?= hook('home.bottom') ?>
