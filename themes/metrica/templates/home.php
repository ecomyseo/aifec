<?php
/**
 * AIFEC — portada de Métrica, «dos mostradores». Apertura sin foto: el titular grande a la izquierda y, a la derecha, el
 * texto y los dos accesos. Debajo, los dos mostradores a la par, los dos con datos de verdad: «Por medida» (oscuro: las
 * facetas de medida del catálogo, como conmutadores que llevan al listado ya filtrado) y «Por trabajo» (claro: el menú
 * «jobs», cada trabajo con su pictograma y, si el comerciante se la ha puesto, su lista de material). Sin facetas o sin menú
 * de trabajos, ese mostrador no se pinta. Después, la franja de ventajas y las secciones que aportan los módulos, en su orden.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                               $page
 * @var array<int,array{id:string,order:int,html:string}> $sections cada una ya es HTML de un módulo
 */

$this->layout('layout', ['page' => isset($page) && is_array($page) ? $page : []]);
$mt_sections = isset($sections) && is_array($sections) ? $sections : [];
$mt_has_h1 = false;
foreach ($mt_sections as $mt_section) {
    if (stripos((string) $mt_section['html'], '<h1') !== false) {
        $mt_has_h1 = true;
    }
}
$mt_app = app();
$mt_title = trim((string) theme_option('home_title', ''));
$mt_title = $mt_title !== '' ? $mt_title : $mt_app->settings()->str('store.name', 'AIFEC');
$mt_text = trim((string) theme_option('home_text', ''));
$mt_text = $mt_text !== '' ? $mt_text : trim($mt_app->settings()->str('seo.home_description', ''));
$mt_info = \Aifec\Front\StoreInfo::data($mt_app, true);
$mt_main = $mt_info['main'];
// La pieza de las líneas es del catálogo: sin él, la portada no lleva franja.
$mt_trust = $mt_app->view()->exists('catalog::front/partials/trust') ? $mt_info['trust'] : [];
$mt_counters = (int) theme_option('counters_show', 1) === 1;
$mt_measure = $mt_counters ? metrica_home_measures((string) theme_option('measure_facets', 'metrica,largo')) : null;
$mt_jobs = $mt_counters ? metrica_clean_menu(menu('jobs')) : [];
?>
<section class="mt-opening" aria-labelledby="mt-opening-title">
    <div class="af-container mt-opening-inner">
        <?php if ($mt_has_h1) : ?>
            <p class="mt-opening-title" id="mt-opening-title"><?= e($mt_title) ?></p>
        <?php else : ?>
            <h1 class="mt-opening-title" id="mt-opening-title"><?= e($mt_title) ?></h1>
        <?php endif; ?>
        <div class="mt-opening-side">
            <?php if ($mt_text !== '') : ?>
                <p><?= e($mt_text) ?></p>
            <?php endif; ?>
            <?php if ($mt_measure !== null || $mt_jobs !== []) : ?>
                <p class="mt-opening-links">
                    <?php if ($mt_measure !== null) : ?>
                        <a href="#mt-by-measure"><?= e(t('Search by measure')) ?></a>
                    <?php endif; ?>
                    <?php if ($mt_jobs !== []) : ?>
                        <a href="#mt-by-job"><?= e(t('See the jobs')) ?></a>
                    <?php endif; ?>
                </p>
            <?php elseif ($mt_main !== null) : ?>
                <p class="mt-opening-links"><a href="<?= e($mt_main['url']) ?>"><?= e(t('Shop {category}', ['category' => $mt_main['name']])) ?></a></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php if ($mt_measure !== null || $mt_jobs !== []) : ?>
    <div class="af-container mt-counters">
        <?php if ($mt_measure !== null) : ?>
            <section class="mt-counter mt-counter-measure" id="mt-by-measure" aria-labelledby="mt-by-measure-title">
                <h2 id="mt-by-measure-title"><?= e(t('By measure')) ?></h2>
                <p class="mt-counter-text"><?= e(t('Choose the measure: you get the pieces we have in it.')) ?></p>
                <form method="get" action="<?= e($mt_measure['url']) ?>">
                    <?php foreach ($mt_measure['facets'] as $mt_facet) : ?>
                        <div class="mt-facet" role="group" aria-labelledby="mt-measure-<?= e($mt_facet['code']) ?>">
                            <p class="mt-counter-label" id="mt-measure-<?= e($mt_facet['code']) ?>"><?= e($mt_facet['name']) ?></p>
                            <ul class="mt-facet-values">
                                <?php foreach ($mt_facet['values'] as $mt_value) : ?>
                                    <li><label class="mt-chip"><input class="af-sr" type="checkbox" name="f[<?= e($mt_facet['code']) ?>][]" value="<?= e($mt_value['code']) ?>"><span><?= e($mt_value['label']) ?></span></label></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <p class="mt-counter-go"><button type="submit" class="af-btn af-btn-primary"><?= e(t('See the pieces in {category}', ['category' => $mt_measure['name']])) ?></button></p>
                </form>
                <figure class="mt-screw">
                    <svg viewBox="0 0 260 104" width="260" height="104" role="img" aria-label="<?= e(t('Drawing of a screw with its two measures: diameter and length')) ?>" focusable="false">
                        <path d="M18 24h22v48H18zM40 34h150l12 7v14l-12 7H40z"/>
                        <path d="M78 34l-9 28M92 34l-9 28M106 34l-9 28M120 34l-9 28M134 34l-9 28M148 34l-9 28M162 34l-9 28M176 34l-9 28M190 34l-9 28"/>
                        <path d="M40 88h162M40 82v12M202 82v12M226 34v28M220 34h12M220 62h12"/>
                        <text x="121" y="102" text-anchor="middle">L</text>
                        <text x="244" y="53" text-anchor="middle">Ø</text>
                    </svg>
                    <figcaption><?= e(t('Ø is the thread (M6 is 6 mm). L is the length, measured under the head.')) ?></figcaption>
                </figure>
            </section>
        <?php endif; ?>
        <?php if ($mt_jobs !== []) : ?>
            <section class="mt-counter mt-counter-job" id="mt-by-job" aria-labelledby="mt-by-job-title">
                <h2 id="mt-by-job-title"><?= e(t('By job')) ?></h2>
                <p class="mt-counter-text"><?= e(t('Choose the job: you get its list of material.')) ?></p>
                <div class="mt-joblist">
                    <?php foreach ($mt_jobs as $mt_n => $mt_job) : ?>
                        <?php if ($mt_job['children'] !== []) : ?>
                            <details name="mt-jobs"<?= ($mt_n === 0 ? ' open' : '') ?>>
                                <summary><?= metrica_svg(metrica_job_icon($mt_job['label']), 24) ?><span><?= e($mt_job['label']) ?></span></summary>
                                <ul class="mt-material">
                                    <?php foreach ($mt_job['children'] as $mt_piece) : ?>
                                        <?php if ($mt_piece['url'] !== '') : ?>
                                            <li><a href="<?= e($mt_piece['url']) ?>"><?= e($mt_piece['label']) ?></a></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ($mt_job['url'] !== '') : ?>
                                    <p><a class="mt-job-all" href="<?= e($mt_job['url']) ?>"><?= e(t('See everything for this job')) ?></a></p>
                                <?php endif; ?>
                            </details>
                        <?php elseif ($mt_job['url'] !== '') : ?>
                            <p class="mt-job-row"><a href="<?= e($mt_job['url']) ?>"><?= metrica_svg(metrica_job_icon($mt_job['label']), 24) ?><span><?= e($mt_job['label']) ?></span></a></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($mt_trust !== []) : ?>
    <div class="af-container af-home-perks mt-perks">
        <?= $this->partial('catalog::front/partials/trust', ['lines' => $mt_trust, 'class' => 'af-trust-row']) ?>
    </div>
<?php endif; ?>
<?= hook('home.top') ?>
<?php foreach ($mt_sections as $mt_section) : ?>
    <div class="af-home-section af-container" data-section="<?= e($mt_section['id']) ?>">
        <?= $mt_section['html'] ?>
    </div>
<?php endforeach; ?>
<?php if ($mt_sections === []) : ?>
    <div class="af-container">
        <div class="af-empty">
            <p class="af-empty-title"><?= e(t('The shop is ready.')) ?></p>
            <p class="af-muted"><?= e(t('Add products and pages from the panel and they will appear here.')) ?></p>
        </div>
    </div>
<?php endif; ?>
<?= hook('home.bottom') ?>
