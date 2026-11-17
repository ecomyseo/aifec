<?php
/**
 * AIFEC — vista previa de una plantilla de correo con sus datos de ejemplo, con selector de idioma.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $key
 * @var array{subject:string,html:string,text:string} $mail
 * @var array<int,array<string,mixed>> $links
 * @var string $description
 */

declare(strict_types=1);

?><div class="af-card"><div class="af-card-body">
    <?php if ($description !== '') : ?>
        <p class="af-muted"><?= e($description) ?></p>
    <?php endif; ?>
    <div class="af-toolbar">
        <?php foreach ($links as $link) : ?>
            <a class="af-btn af-btn-sm<?= $link['current'] ? ' af-btn-primary' : '' ?>" href="<?= e($link['url']) ?>"><?= e(strtoupper((string) $link['code'])) ?></a>
        <?php endforeach; ?>
        <?php if ($editUrl !== '') : ?>
            <a class="af-btn af-btn-sm af-btn-primary" href="<?= e($editUrl) ?>"><?= e(t('Edit texts')) ?></a>
        <?php endif; ?>
    </div>
    <dl class="af-kv"><dt><?= e(t('Subject')) ?></dt><dd><?= e($mail['subject']) ?></dd></dl>
</div></div>
<div class="af-card">
    <div class="af-card-body">
        <iframe sandbox srcdoc="<?= e($mail['html']) ?>" title="<?= e($key) ?>" class="af-frame" style="height:560px"></iframe>
    </div>
    <details class="af-help"><summary><?= e(t('Plain text version')) ?></summary><pre class="af-mono af-small"><?= e($mail['text']) ?></pre></details>
</div>
