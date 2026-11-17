<?php
/**
 * AIFEC — columna derecha de la ficha de una página o de una entrada: vista previa por idioma y las 20 últimas versiones.
 * Va dentro del formulario del contenido: «Restaurar» es un botón con su propia dirección, no un formulario aparte.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,string>             $previews idioma => dirección de la vista previa
 * @var array<int,array<string,mixed>>   $revisions id, when, who, url
 * @var bool                             $canEdit
 * @var bool                             $multi    hay más de un idioma
 */

use Aifec\Admin\Admin;

?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Preview')) ?></h2></div>
    <div class="af-card-body af-stack">
        <?php foreach ($previews as $lang => $url) : ?>
            <a class="af-btn" target="_blank" rel="noopener" href="<?= e($url) ?>"><?= Admin::icon('eye') ?><?= e($multi ? t('Preview in {lang}', ['lang' => strtoupper((string) $lang)]) : t('Preview')) ?></a>
        <?php endforeach; ?>
        <p class="af-small af-muted"><?= e(t('Shows the saved content with the theme, published or not. Save first to see your latest changes.')) ?></p>
    </div>
</div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Versions')) ?></h2></div>
    <?php if ($revisions === []) : ?>
        <div class="af-card-body af-muted"><?= e(t('Every time you save, a version is kept here.')) ?></div>
    <?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <tbody>
                <?php foreach ($revisions as $revision) : ?>
                    <tr>
                        <td>
                            <span class="af-nowrap"><?= e($revision['when']) ?></span>
                            <?php if ($revision['who'] !== '') : ?><br><span class="af-small af-muted"><?= e($revision['who']) ?></span><?php endif; ?>
                        </td>
                        <td class="af-right">
                            <?php if ($canEdit) : ?>
                                <button type="submit" class="af-btn af-btn-sm af-btn-ghost" formaction="<?= e($revision['url']) ?>" formmethod="post" formnovalidate data-af-confirm="<?= e(t('Load this version? The content goes back to a draft.')) ?>"><?= e(t('Restore')) ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="af-card-body af-small af-muted"><?= e(t('The last 20 versions are kept. Restoring one loads it as a draft.')) ?></div>
    <?php endif; ?>
</div>
