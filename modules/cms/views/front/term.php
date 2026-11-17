<?php
/**
 * AIFEC — las entradas de una categoría o de una etiqueta del blog, con su paginación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page    datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>> $crumbs
 * @var string                         $heading nombre de la categoría o la etiqueta
 * @var string                         $intro   su descripción, si la tiene
 * @var array<string,mixed>            $result  lo que da Cms::posts()
 * @var string                         $base    dirección de la primera página
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1 class="af-title"><?= e($heading) ?></h1>
    <?php if ($intro !== '') : ?>
        <p class="af-subtitle"><?= e($intro) ?></p>
    <?php endif; ?>
    <?= $this->partial('cms::front/post-list', ['result' => $result, 'base' => $base]) ?>
</div>
