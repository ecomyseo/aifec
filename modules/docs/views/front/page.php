<?php
/**
 * AIFEC — una página de la documentación: el árbol a la izquierda, el contenido en el centro y el índice de la página a la
 * derecha; debajo, anterior y siguiente y el enlace a la versión en texto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                  $page      datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>>       $tree      secciones con sus páginas
 * @var string                               $current   dirección de la página (`guia/instalar`)
 * @var array<string,string>                 $entry     la página: title, path, section, slug
 * @var string                               $html      su contenido, ya convertido y saneado
 * @var array<int,array{id:string,text:string}> $headings los h2 de la página
 * @var array<string,string>|null            $prev      la página anterior
 * @var array<string,string>|null            $next      la siguiente
 * @var string                               $text_url  dirección del texto tal cual (.md)
 * @var string                               $search_url
 * @var string                               $query
 */

$this->layout('layout', ['page' => $page]);
$this->push('styles', '<link rel="stylesheet" href="' . e(app()->url()->asset('m', 'docs', 'docs.css')) . '">');
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $page['breadcrumbs']]) ?>
    <div class="af-docs">
        <aside class="af-docs-side" aria-label="<?= e(t('Documentation')) ?>">
            <details class="af-docs-menu" open>
                <summary><?= e(t('Documentation')) ?></summary>
                <?= $this->partial('docs::front/search-form', ['search_url' => $search_url, 'query' => $query]) ?>
                <nav class="af-docs-tree" aria-label="<?= e(t('Documentation pages')) ?>">
                    <?= $this->partial('docs::front/tree', ['tree' => $tree, 'current' => $current]) ?>
                </nav>
            </details>
        </aside>
        <article class="af-docs-body">
            <h1 class="af-title"><?= e((string) $entry['title']) ?></h1>
            <div class="af-prose af-docs-prose">
                <?= $html ?>
            </div>
            <nav class="af-docs-pager" aria-label="<?= e(t('Previous and next page')) ?>">
                <?php if ($prev !== null) : ?>
                    <a class="af-docs-prev" rel="prev" href="<?= e(url('/docs/' . $prev['path'])) ?>"><span class="af-small af-muted"><?= e(t('Previous')) ?></span><br><?= e((string) $prev['title']) ?></a>
                <?php endif; ?>
                <?php if ($next !== null) : ?>
                    <a class="af-docs-next" rel="next" href="<?= e(url('/docs/' . $next['path'])) ?>"><span class="af-small af-muted"><?= e(t('Next')) ?></span><br><?= e((string) $next['title']) ?></a>
                <?php endif; ?>
            </nav>
            <p class="af-small af-muted"><a href="<?= e($text_url) ?>" type="text/markdown"><?= e(t('View this page as plain text')) ?></a></p>
        </article>
        <?php if ($headings !== []) : ?>
            <nav class="af-docs-toc" aria-label="<?= e(t('On this page')) ?>">
                <p class="af-docs-group-title"><?= e(t('On this page')) ?></p>
                <ul class="af-docs-list">
                    <?php foreach ($headings as $af_h) : ?>
                        <li><a href="#<?= e($af_h['id']) ?>"><?= e($af_h['text']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
