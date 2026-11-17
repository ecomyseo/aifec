<?php
/**
 * AIFEC — árbol de categorías del panel «Todas las categorías»: cada grupo con su cabecera y sus subcategorías en lista.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items elementos ya limpios: label, url, current, children
 * @var int                            $level nivel de anidamiento (0 = grupos)
 */

$tl_items = isset($items) && is_array($items) ? $items : [];
$tl_level = isset($level) ? (int) $level : 0;
if ($tl_items === [] || $tl_level > 3) {
    return;
}
?>
<ul class="<?= $tl_level === 0 ? 'tl-cats-list' : 'tl-cats-sub' ?>">
<?php foreach ($tl_items as $tl_item) : ?>
    <li class="<?= $tl_level === 0 ? 'tl-cats-group' : 'tl-cats-leaf' ?>">
    <?php if ($tl_item['url'] !== '') : ?>
        <a class="<?= $tl_level === 0 ? 'tl-cats-head' : 'tl-cats-link' ?>" href="<?= e($tl_item['url']) ?>"<?= $tl_item['current'] ? ' aria-current="page"' : '' ?>><?= e($tl_item['label']) ?></a>
    <?php else : ?>
        <span class="<?= $tl_level === 0 ? 'tl-cats-head' : 'tl-cats-link' ?>"><?= e($tl_item['label']) ?></span>
    <?php endif; ?>
    <?php if ($tl_item['children'] !== []) : ?>
        <?= $this->partial('partials/cats', ['items' => $tl_item['children'], 'level' => $tl_level + 1]) ?>
    <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
