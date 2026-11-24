<?php
/**
 * AIFEC — página de contenido: título, migas si tiene página madre y el contenido ya pintado (bloques). Las plantillas que ofrece
 * el tema base son «default», «wide» (sin límite de ancho de lectura) y «landing» (sin título visible ni migas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page     datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed> $content  lo que da Cms::page()
 * @var array<string,mixed> $crumbs   migas: portada, páginas madre y esta página
 * @var string              $template default, wide o landing
 * @var string              $script   el guion de los formularios, si la página lleva alguno
 */

$this->layout('layout', ['page' => $page]);
$af_landing = $template === 'landing';
$af_wide = $template === 'wide' || $af_landing;
$af_html = (string) $content['html'];
?>
<div class="af-container af-section">
    <article class="af-page af-page-<?= e($template) ?>">
        <?php if (!$af_landing && !empty($content['parent_id']) && count($crumbs) > 2) : ?>
            <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
        <?php endif; ?>
        <?php if (stripos($af_html, '<h1') === false) : ?>
            <h1 class="<?= $af_landing ? 'af-sr' : 'af-title' ?>"><?= e($content['title']) ?></h1>
        <?php endif; ?>
        <div class="af-prose<?= $af_wide ? ' af-prose-wide' : '' ?>"<?= $af_wide ? ' style="max-width:none"' : '' ?>>
            <?= $af_html ?>
        </div>
    </article>
</div>
<?= $script ?>
