<?php
/**
 * AIFEC — lista de un menú (con submenús). La usan la cabecera, el panel del móvil y el pie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items elementos: label, url, current, children
 * @var int                            $level nivel de anidamiento (0 = el principal)
 */

$af_items = isset($items) && is_array($items) ? $items : [];
$af_level = isset($level) ? (int) $level : 0;
if ($af_items === [] || $af_level > 3) {
    return;
}
?>
<ul class="af-nav-list<?= $af_level > 0 ? ' af-nav-sub' : '' ?>">
<?php foreach ($af_items as $af_item) : ?>
    <?php
    $af_label = isset($af_item['label']) ? (string) $af_item['label'] : '';
    $af_href = isset($af_item['url']) ? (string) $af_item['url'] : '';
    $af_kids = isset($af_item['children']) && is_array($af_item['children']) ? array_values(array_filter($af_item['children'], 'is_array')) : [];
    // Un elemento sin texto, o sin dirección y sin hijos, no se pinta: nunca un enlace roto.
    if ($af_label === '' || ($af_href === '' && $af_kids === [])) {
        continue;
    }
    ?>
    <li class="af-nav-item<?= $af_kids !== [] ? ' has-children' : '' ?>">
    <?php if ($af_href !== '') : ?>
        <a class="af-nav-link" href="<?= e($af_href) ?>"<?= !empty($af_item['current']) ? ' aria-current="page"' : '' ?>><?= e($af_label) ?></a>
    <?php else : ?>
        <span class="af-nav-link af-nav-label"><?= e($af_label) ?></span>
    <?php endif; ?>
    <?php if ($af_kids !== []) : ?>
        <?= $this->partial('partials/nav', ['items' => $af_kids, 'level' => $af_level + 1]) ?>
    <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
