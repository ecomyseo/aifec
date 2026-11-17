<?php
/**
 * AIFEC — lo que el buscador ha entendido, encima de los resultados: la corrección de una errata, la respuesta a una pregunta
 * de la tienda, la respuesta directa («12 resultados para silla negra por menos de 200 €»), las fichas de lo entendido (cada una
 * se quita con su ✕) y el botón de preguntarle al asistente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                                  $q         lo que se buscó
 * @var string                                                  $answer    respuesta directa (puede ir vacía)
 * @var array{topic:string,text:string,url:string}|null         $shop      respuesta a una pregunta sobre la tienda
 * @var array{shown:string,asked:string}|null                   $corrected la frase corregida y la que se escribió
 * @var array<int,array{key:string,label:string,url:string}>    $chips     lo entendido; url = la búsqueda sin esa ficha
 * @var bool                                                    $assistant ¿hay asistente al que preguntar?
 * @var array{url:string,link:string,script:string}|null        $more      la IA aún puede ayudar: url = significado.json, link = la
 *                                                                         búsqueda con «significado=1» (sin JavaScript), script = more.js
 */

$af_more = isset($more) && is_array($more) ? $more : null;
if ($af_more !== null) {
    $this->push('scripts', '<script src="' . e($af_more['script']) . '" defer></script>');
}
?>
<div class="af-search-understood" data-af-search-understood>
    <?php if ($af_more !== null) : ?>
        <div class="af-search-more" data-af-search-more="<?= e($af_more['url']) ?>">
            <p class="af-container af-muted" aria-live="polite" data-af-search-more-status hidden><?= e(t('Searching by meaning…')) ?></p>
            <p class="af-muted" data-af-search-more-link><a href="<?= e($af_more['link']) ?>" rel="nofollow"><?= e(t('Search by meaning')) ?></a></p>
        </div>
    <?php endif; ?>
    <?php if ($corrected !== null) : ?>
        <p class="af-alert af-alert-info" role="status"><?= e(t('Showing results for {q}', ['q' => $corrected['shown']])) ?>. <?= e(t('You searched for {q}', ['q' => $corrected['asked']])) ?>.</p>
    <?php endif; ?>
    <?php if ($shop !== null) : ?>
        <div class="af-alert af-alert-info" role="status" data-af-search-shop="<?= e($shop['topic']) ?>">
            <p><?= e($shop['text']) ?></p>
            <?php if ($shop['url'] !== '') : ?>
                <p><a class="af-btn af-btn-sm" href="<?= e($shop['url']) ?>"><?= e(t('Read more')) ?></a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($answer !== '') : ?>
        <p class="af-search-answer" role="status" data-af-search-answer><strong><?= e($answer) ?></strong></p>
    <?php endif; ?>
    <?php if ($chips !== []) : ?>
        <ul class="af-row af-search-chips" aria-label="<?= e(t('What we understood')) ?>">
            <?php foreach ($chips as $af_chip) : ?>
                <li>
                    <a class="af-btn af-btn-ghost af-btn-sm" href="<?= e($af_chip['url']) ?>" data-af-search-chip="<?= e($af_chip['key']) ?>" aria-label="<?= e(t('Remove filter: {label}', ['label' => $af_chip['label']])) ?>"><?= e($af_chip['label']) ?> <span aria-hidden="true">✕</span></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($assistant && $q !== '') : ?>
        <p><button type="button" class="af-btn af-btn-sm" data-af-search-ask="<?= e($q) ?>"><?= e(t('Ask the assistant')) ?></button></p>
    <?php endif; ?>
</div>
