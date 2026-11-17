<?php
/**
 * AIFEC — entrada del blog: título, fecha, autor y tiempo de lectura, imagen destacada, contenido, categorías y etiquetas,
 * la anterior y la siguiente, y entradas relacionadas de la misma categoría.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page     datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed>            $post     lo que da Cms::post()
 * @var string                         $html     el contenido ya pintado (con su primera imagen sin diferir si no hay destacada)
 * @var array<string,mixed>|null       $featured la imagen destacada elegida, si la hay (se pinta sin diferir)
 * @var array<int,array<string,mixed>> $crumbs
 * @var array{iso:string,label:string} $date     fecha de publicación
 * @var array<string,mixed>|null       $previous la entrada más antigua que esta
 * @var array<string,mixed>|null       $next     la entrada más reciente que esta
 * @var array<int,array<string,mixed>> $related  entradas de la misma categoría
 * @var string                         $script   el guion de los formularios, si la entrada lleva alguno
 */

$this->layout('layout', ['page' => $page]);
$af_categories = isset($post['categories']) && is_array($post['categories']) ? $post['categories'] : [];
$af_tags = isset($post['tags']) && is_array($post['tags']) ? $post['tags'] : [];
?>
<div class="af-container af-section">
    <article class="af-post">
        <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
        <header>
            <h1 class="af-title"><?= e($post['title']) ?></h1>
            <p class="af-muted af-small">
                <?php if ($date['iso'] !== '') : ?>
                    <time datetime="<?= e($date['iso']) ?>"><?= e($date['label']) ?></time>
                <?php endif; ?>
                <?php if ((string) $post['author'] !== '') : ?>
                    &middot; <?= e($post['author']) ?>
                <?php endif; ?>
                &middot; <?= e(tn('{n} minute read', '{n} minutes read', (int) $post['reading_minutes'])) ?>
            </p>
        </header>
        <?php if ($featured !== null) : ?>
            <figure class="af-post-image">
                <?= image_tag($featured, 'large', ['loading' => 'eager', 'fetchpriority' => 'high']) ?>
            </figure>
        <?php endif; ?>
        <div class="af-prose">
            <?= $html ?>
        </div>
        <?php if ($af_categories !== [] || $af_tags !== []) : ?>
            <p class="af-small af-row">
                <?php foreach ($af_categories as $af_term) : ?>
                    <a class="af-badge" href="<?= e($af_term['url']) ?>"><?= e($af_term['name']) ?></a>
                <?php endforeach; ?>
                <?php foreach ($af_tags as $af_term) : ?>
                    <a class="af-badge" href="<?= e($af_term['url']) ?>" rel="tag">#<?= e($af_term['name']) ?></a>
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
        <section class="af-section" aria-labelledby="af-related-title">
            <h2 id="af-related-title"><?= e(t('Related posts')) ?></h2>
            <div class="af-grid af-cols-3">
                <?php foreach ($related as $af_item) : ?>
                    <?= $this->partial('cms::front/post-card', ['post' => $af_item]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?= $script ?>
