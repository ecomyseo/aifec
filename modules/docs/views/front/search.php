<?php
/**
 * AIFEC — resultados del buscador de la documentación, con el fragmento donde aparece lo buscado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page
 * @var array<int,array<string,mixed>> $tree
 * @var string                         $query
 * @var array<int,array<string,string>> $results title, url, snippet_html (ya escapado), section
 * @var string                         $search_url
 */

$this->layout('layout', ['page' => $page]);
$this->push('styles', '<link rel="stylesheet" href="' . e(app()->url()->asset('m', 'docs', 'docs.css')) . '">');
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $page['breadcrumbs']]) ?>
    <h1 class="af-title"><?= e(t('Search the documentation')) ?></h1>
    <?= $this->partial('docs::front/search-form', ['search_url' => $search_url, 'query' => $query]) ?>
    <?php if ($query === '') : ?>
        <p class="af-muted"><?= e(t('Write one or more words to search for.')) ?></p>
    <?php elseif ($results === []) : ?>
        <div class="af-empty" data-docs-no-results>
            <p class="af-empty-title"><?= e(t('Nothing found.')) ?></p>
            <p class="af-muted"><?= e(t('Try fewer words or a different one.')) ?></p>
        </div>
    <?php else : ?>
        <p class="af-small af-muted"><?= e(tn('{n} result', '{n} results', count($results))) ?></p>
        <ol class="af-docs-results">
            <?php foreach ($results as $af_hit) : ?>
                <li>
                    <a class="af-docs-result-title" href="<?= e($af_hit['url']) ?>"><?= e($af_hit['title']) ?></a>
                    <?php if ($af_hit['section'] !== '') : ?>
                        <span class="af-small af-muted"> · <?= e($af_hit['section']) ?></span>
                    <?php endif; ?>
                    <p class="af-docs-snippet"><?= $af_hit['snippet_html'] ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
