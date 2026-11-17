<?php
/**
 * AIFEC — paginación: anterior, números con huecos, siguiente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int      $page  página actual (desde 1)
 * @var int      $pages total de páginas
 * @var callable $url   recibe un número de página y devuelve su dirección
 */

$af_pages = isset($pages) ? (int) $pages : 1;
$af_page = isset($page) ? max(1, min($af_pages, (int) $page)) : 1;
if ($af_pages < 2 || !isset($url) || !is_callable($url)) {
    return;
}
// Primera, última y dos a cada lado de la actual; el resto, un hueco.
$af_show = [];
for ($af_n = 1; $af_n <= $af_pages; ++$af_n) {
    if ($af_n === 1 || $af_n === $af_pages || abs($af_n - $af_page) <= 2) {
        $af_show[] = $af_n;
    }
}
$af_last = 0;
?>
<nav class="af-pagination" aria-label="<?= e(t('Pagination')) ?>">
    <ul>
        <?php if ($af_page > 1) : ?>
            <li><a class="af-pagination-step" href="<?= e($url($af_page - 1)) ?>" rel="prev"><span aria-hidden="true">&lsaquo;</span><span class="af-sr"><?= e(t('Previous page')) ?></span></a></li>
        <?php endif; ?>
        <?php foreach ($af_show as $af_n) : ?>
            <?php if ($af_n - $af_last > 1) : ?>
                <li><span class="af-pagination-gap" aria-hidden="true">&hellip;</span></li>
            <?php endif; ?>
            <?php $af_last = $af_n; ?>
            <li>
                <?php if ($af_n === $af_page) : ?>
                    <span class="is-current" aria-current="page"><span class="af-sr"><?= e(t('Page')) ?> </span><?= e($af_n) ?></span>
                <?php else : ?>
                    <a href="<?= e($url($af_n)) ?>"><span class="af-sr"><?= e(t('Page')) ?> </span><?= e($af_n) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($af_page < $af_pages) : ?>
            <li><a class="af-pagination-step" href="<?= e($url($af_page + 1)) ?>" rel="next"><span aria-hidden="true">&rsaquo;</span><span class="af-sr"><?= e(t('Next page')) ?></span></a></li>
        <?php endif; ?>
    </ul>
</nav>
