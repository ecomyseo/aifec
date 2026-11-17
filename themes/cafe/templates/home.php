<?php
/**
 * AIFEC — portada de Café, «la barra». La apertura va a todo el ancho sobre el papel: a la izquierda el antetítulo y el
 * titular en grande; a la derecha la entradilla y los dos botones («Elige tu cafetera», que abre el panel de sistemas, y
 * el de la categoría principal). Debajo, tras un filete, «¿Qué cafetera tienes?»: la hilera de sistemas y cafeteras para
 * los que hay café, cada uno con su pictograma, su nombre y cuántos cafés tiene (la faceta real del atributo de sistema;
 * sin ese dato la hilera no sale). Después, la franja de ventajas y las secciones que aportan los módulos, en su orden.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                                       $page
 * @var array<int,array{id:string,order:int,html:string}>         $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$cf_sections = isset($sections) && is_array($sections) ? $sections : [];
$cf_has_h1 = false;
foreach ($cf_sections as $cf_section) {
    if (stripos((string) $cf_section['html'], '<h1') !== false) {
        $cf_has_h1 = true;
    }
}
$cf_app = app();
$cf_title = trim((string) theme_option('home_title', ''));
$cf_title = $cf_title !== '' ? $cf_title : $cf_app->settings()->str('store.name', 'AIFEC');
$cf_text = trim((string) theme_option('home_text', ''));
$cf_text = $cf_text !== '' ? $cf_text : trim($cf_app->settings()->str('seo.home_description', ''));
$cf_kicker = trim((string) theme_option('home_kicker', ''));
$cf_store = \Aifec\Front\StoreInfo::data($cf_app, true);
$cf_main = $cf_store['main'];
// La pieza de las líneas es del catálogo: sin él, la portada no lleva franja.
$cf_trust = $cf_app->view()->exists('catalog::front/partials/trust') ? $cf_store['trust'] : [];
$cf_systems = (int) theme_option('home_makers', 1) === 1 ? cafe_rail()['items'] : [];
$cf_can_pick = cafe_rail()['items'] !== [];
?>
<section class="cf-open" aria-labelledby="cf-open-title">
    <div class="af-container cf-open-grid">
        <div class="cf-open-head">
            <?php if ($cf_kicker !== '') : ?>
                <p class="cf-kicker"><?= e($cf_kicker) ?></p>
            <?php endif; ?>
            <?php if ($cf_has_h1) : ?>
                <p class="cf-open-title" id="cf-open-title"><?= e($cf_title) ?></p>
            <?php else : ?>
                <h1 class="cf-open-title" id="cf-open-title"><?= e($cf_title) ?></h1>
            <?php endif; ?>
        </div>
        <div class="cf-open-side">
            <?php if ($cf_text !== '') : ?>
                <p class="cf-open-text"><?= e($cf_text) ?></p>
            <?php endif; ?>
            <?php if ($cf_can_pick || $cf_main !== null) : ?>
                <p class="cf-open-go">
                    <?php if ($cf_can_pick) : ?>
                        <button type="button" class="af-btn af-btn-primary af-btn-lg" data-af-drawer-open="cf-systems" aria-controls="cf-systems" aria-expanded="false"><?= e(t('Choose your coffee maker')) ?></button>
                    <?php endif; ?>
                    <?php if ($cf_main !== null) : ?>
                        <a class="af-btn af-btn-lg" href="<?= e($cf_main['url']) ?>"><?= e(t('Shop {category}', ['category' => $cf_main['name']])) ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php if ($cf_systems !== []) : ?>
    <section class="af-container cf-which" aria-labelledby="cf-which-title">
        <h2 id="cf-which-title"><?= e(t('Which coffee maker do you have?')) ?></h2>
        <p class="af-muted"><?= e(t('Pick it once: we keep it in your browser and every card tells you whether a coffee fits it.')) ?></p>
        <ul class="cf-which-row">
            <?php foreach ($cf_systems as $cf_system) : ?>
                <li>
                    <a href="<?= e($cf_system['url']) ?>" data-cf-pick="<?= e($cf_system['code']) ?>" data-cf-label="<?= e($cf_system['label']) ?>">
                        <?= cafe_svg($cf_system['pict'], 30) ?>
                        <b><?= e($cf_system['label']) ?></b>
                        <small><?= e(tn('{n} coffee', '{n} coffees', $cf_system['count'])) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php if ($cf_trust !== []) : ?>
    <div class="af-container cf-perks">
        <?= $this->partial('catalog::front/partials/trust', ['lines' => $cf_trust, 'class' => 'af-trust-row']) ?>
    </div>
<?php endif; ?>
<?= hook('home.top') ?>
<?php foreach ($cf_sections as $cf_section) : ?>
    <div class="af-home-section af-container" data-section="<?= e($cf_section['id']) ?>">
        <?= $cf_section['html'] ?>
    </div>
<?php endforeach; ?>
<?php if ($cf_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?= hook('home.bottom') ?>
