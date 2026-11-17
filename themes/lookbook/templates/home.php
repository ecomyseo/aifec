<?php
/**
 * AIFEC — portada de Lookbook, compuesta como el número de una revista: la apertura partida (las dos colecciones
 * principales, cada una con su foto a media página, su nombre en serifa cursiva y su acceso), una frase de la casa, las
 * secciones que aportan los módulos en su orden (las novedades como lookbook en parejas, los destacados como un índice
 * escrito junto a una foto, las ofertas en hilera) y, de cierre, dos accesos escritos en grande al resto de colecciones.
 *
 * La apertura es la sección «catalog.categories» del catálogo: con la opción «opening_first» sube al principio de la
 * página; sin ella, se queda en el sitio que le toque. Si ninguna sección trae un <h1>, lo pone esta plantilla (uno solo).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$lb_sections = isset($sections) && is_array($sections) ? array_values($sections) : [];
$lb_has_h1 = false;
foreach ($lb_sections as $lb_section) {
    if (stripos((string) $lb_section['html'], '<h1') !== false) {
        $lb_has_h1 = true;
    }
}
$lb_app = app();
$lb_title = trim((string) theme_option('home_title', ''));
$lb_title = $lb_title !== '' ? $lb_title : $lb_app->settings()->str('store.name', 'AIFEC');
$lb_text = trim((string) theme_option('home_text', ''));
$lb_text = $lb_text !== '' ? $lb_text : trim($lb_app->settings()->str('seo.home_description', ''));

// La apertura: la sección de categorías, al principio.
$lb_opening = '';
if ((int) theme_option('opening_first', 1) === 1) {
    foreach ($lb_sections as $lb_i => $lb_section) {
        if ((string) $lb_section['id'] === 'catalog.categories') {
            $lb_opening = (string) $lb_section['html'];
            unset($lb_sections[$lb_i]);
            break;
        }
    }
    $lb_sections = array_values($lb_sections);
}

// El cierre: las colecciones que no caben en la apertura (la tercera y la cuarta), escritas en grande.
$lb_info = \Aifec\Front\StoreInfo::data($lb_app);
$lb_doors = array_slice(is_array($lb_info['categories']) ? array_values($lb_info['categories']) : [], 2, 2);
?>
<?php if (!$lb_has_h1) : ?>
    <h1 class="af-sr"><?= e($lb_title) ?></h1>
<?php endif; ?>
<?php if ($lb_opening !== '') : ?>
    <div class="lb-home-opening" data-section="catalog.categories">
        <?= ($lb_opening) ?>
    </div>
<?php endif; ?>
<?php if ($lb_text !== '') : ?>
    <p class="lb-statement"><?= e($lb_text) ?></p>
<?php endif; ?>
<?= hook('home.top') ?>
<?php foreach ($lb_sections as $lb_section) : ?>
    <div class="lb-home-section af-container" data-section="<?= e($lb_section['id']) ?>">
        <?= ($lb_section['html']) ?>
    </div>
<?php endforeach; ?>
<?php if ($lb_sections === [] && $lb_opening === '') : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?php if ($lb_doors !== []) : ?>
    <nav class="lb-issue" aria-label="<?= e(t('More collections')) ?>">
        <?php foreach ($lb_doors as $lb_door) : ?>
            <a class="lb-issue-door" href="<?= e((string) $lb_door['url']) ?>">
                <small class="lb-over"><?= e(t('Collection')) ?></small>
                <strong><?= e((string) $lb_door['name']) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?= hook('home.bottom') ?>
