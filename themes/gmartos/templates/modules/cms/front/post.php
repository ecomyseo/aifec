<?php
/**
 * AIFEC — entrada del blog de gmartos: la etiqueta «Guías», el título grande, la fecha y el autor, la imagen destacada con
 * esquinas redondeadas y el contenido en una columna de lectura ancha; debajo, categorías, anterior y siguiente y relacionadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page     datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed>            $post     lo que da Cms::post()
 * @var string                         $html     el contenido ya pintado
 * @var array<string,mixed>|null       $featured la imagen destacada elegida, si la hay (se pinta sin diferir)
 * @var array<int,array<string,mixed>> $crumbs
 * @var array{iso:string,label:string} $date     fecha de publicación
 * @var array<string,mixed>|null       $previous la entrada más antigua que esta
 * @var array<string,mixed>|null       $next     la entrada más reciente que esta
 * @var array<int,array<string,mixed>> $related  entradas de la misma categoría
 * @var string                         $script   el guion de los formularios, si la entrada lleva alguno
 */

$this->layout('layout', ['page' => $page]);
$gm_categories = isset($post['categories']) && is_array($post['categories']) ? $post['categories'] : [];
$gm_tags = isset($post['tags']) && is_array($post['tags']) ? $post['tags'] : [];
?>
<div class="af-container gm-editorial">
    <article class="af-post gm-reading">
        <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
        <header class="gm-reading-head">
            <p class="gm-kicker"><?= e(t('Guides')) ?></p>
            <h1 class="af-title"><?= e($post['title']) ?></h1>
            <p class="gm-reading-meta">
                <?php if ((string) $post['author'] !== '') : ?>
                    <span><?= e(t('By {author}', ['author' => (string) $post['author']])) ?></span>
                <?php endif; ?>
                <?php if ($date['iso'] !== '') : ?>
                    <time datetime="<?= e($date['iso']) ?>"><?= e($date['label']) ?></time>
                <?php endif; ?>
                <span><?= e(tn('{n} minute read', '{n} minutes read', (int) $post['reading_minutes'])) ?></span>
            </p>
        </header>
        <?php if ($featured !== null) : ?>
            <figure class="af-post-image gm-reading-image">
                <?= image_tag($featured, 'large', ['loading' => 'eager', 'fetchpriority' => 'high']) ?>
            </figure>
        <?php endif; ?>
        <div class="af-prose gm-reading-content">
            <?= $html ?>
        </div>
        <?php if ($gm_categories !== [] || $gm_tags !== []) : ?>
            <p class="af-small gm-chips">
                <?php foreach ($gm_categories as $gm_term) : ?>
                    <a href="<?= e($gm_term['url']) ?>"><?= e($gm_term['name']) ?></a>
                <?php endforeach; ?>
                <?php foreach ($gm_tags as $gm_term) : ?>
                    <a href="<?= e($gm_term['url']) ?>" rel="tag">#<?= e($gm_term['name']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($previous !== null || $next !== null) : ?>
            <nav class="af-post-nav af-row" aria-label="<?= e(t('Other posts')) ?>">
                <?php if ($previous !== null) : ?>
                    <a class="af-btn af-btn-ghost" href="<?= e($previous['url']) ?>" rel="prev"><span aria-hidden="true">&lsaquo;</span> <span class="af-sr"><?= e(t('Previous post')) ?>: </span><?= e($previous['title']) ?></a>
                <?php endif; ?>
                <?php if ($next !== null) : ?>
                    <a class="af-btn af-btn-ghost" href="<?= e($next['url']) ?>" rel="next"><span class="af-sr"><?= e(t('Next post')) ?>: </span><?= e($next['title']) ?> <span aria-hidden="true">&rsaquo;</span></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </article>
    <?php if ($related !== []) : ?>
        <section class="gm-related" aria-labelledby="af-related-title">
            <h2 id="af-related-title"><?= e(t('Related posts')) ?></h2>
            <div class="af-grid af-cols-2 gm-articles">
                <?php foreach ($related as $gm_item) : ?>
                    <?= $this->partial('cms::front/post-card', ['post' => $gm_item]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?= $script ?>
