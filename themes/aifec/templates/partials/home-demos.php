<?php
/**
 * AIFEC — tema del sitio: las tiendas de demostración en la portada, a la vista debajo del titular.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{name:string,sector:string,url:string,preview:string}> $demos
 */

$af_demos = isset($demos) && is_array($demos) ? $demos : [];
if ($af_demos === []) {
    return;
}
?>
<section class="af-demos-home" id="demos" aria-labelledby="af-demos-title">
    <h2 id="af-demos-title"><?= e(t('Demo shops you can open right now')) ?></h2>
    <p><?= e(t('Each one is a complete shop of a sector, with its catalogue, its checkout and its assistant. Nothing is charged.')) ?></p>
    <ul class="af-demos-list">
        <?php foreach ($af_demos as $af_demo) : ?>
            <li>
                <a class="af-demo" href="<?= e((string) $af_demo['url']) ?>">
                    <?php if ($af_demo['preview'] !== '') : ?>
                        <img width="600" height="450" loading="lazy" alt="" src="<?= e((string) $af_demo['preview']) ?>">
                    <?php else : ?>
                        <span class="af-demo-blank" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $af_demo['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                    <?php endif; ?>
                    <strong><?= e((string) $af_demo['name']) ?></strong>
                    <?php if ($af_demo['sector'] !== '') : ?>
                        <span><?= e((string) $af_demo['sector']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
