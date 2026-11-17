<?php
/**
 * AIFEC — vista previa de una página o de una entrada con el tema de la tienda. No se indexa: solo la ve quien tiene sesión en el panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $content el contenido (lo que da Cms::page() o Cms::post())
 * @var array<string,mixed> $meta    datos de cabecera de la página (ver Aifec\Front\Page)
 * @var string              $type    page o post
 * @var bool                $visible lo ve ahora el público
 */

$this->layout('layout', ['page' => isset($meta) && is_array($meta) ? $meta : []]);
?>
<div class="af-container">
    <div class="af-alert <?= $visible ? 'af-alert-info' : 'af-alert-warn' ?>" role="status">
        <?= e($visible ? t('Preview of the saved content. Visitors can already see it.') : t('Preview of the saved content. It is not published: visitors cannot see it.')) ?>
    </div>
    <article class="af-prose">
        <h1><?= e($content['title']) ?></h1>
        <?php if ($type === 'post' && $content['published_at'] !== null) : ?>
            <p class="af-muted af-small"><time datetime="<?= e(substr((string) $content['published_at'], 0, 10)) ?>"><?= e(substr((string) $content['published_at'], 0, 10)) ?></time><?= $content['author'] !== '' ? ' · ' . e($content['author']) : '' ?></p>
        <?php endif; ?>
        <?= $content['html'] ?>
    </article>
</div>
