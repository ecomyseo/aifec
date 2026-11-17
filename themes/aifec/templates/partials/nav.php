<?php
/**
 * AIFEC — lista de un menú (con submenús) del tema aifec. Como la del tema base, y además sabe pintar el elemento «Tienda»
 * (el que trae 'shop' => true): un enlace con un botón al lado que despliega las categorías. El botón nace oculto: lo enseña
 * theme.js. Sin JavaScript queda el enlace y, al pasar el ratón o llegar con el teclado, la lista de categorías.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $items elementos: label, url, current, children (y shop en el de la tienda)
 * @var int                            $level nivel de anidamiento (0 = el principal)
 * @var string                         $idp   parte del identificador del desplegable (h = cabecera, d = cajón), para que no se repita
 */

$af_items = isset($items) && is_array($items) ? $items : [];
$af_level = isset($level) ? (int) $level : 0;
$af_idp = isset($idp) && is_string($idp) && preg_match('/^[a-z]{1,3}$/', $idp) === 1 ? $idp : 'h';
if ($af_items === [] || $af_level > 3) {
    return;
}
?>
<ul class="af-nav-list<?= e($af_level > 0 ? ' af-nav-sub' : '') ?>">
<?php foreach ($af_items as $af_item) : ?>
    <?php
    $af_label = isset($af_item['label']) ? (string) $af_item['label'] : '';
    $af_href = isset($af_item['url']) ? (string) $af_item['url'] : '';
    $af_kids = isset($af_item['children']) && is_array($af_item['children']) ? array_values(array_filter($af_item['children'], 'is_array')) : [];
    // Un elemento sin texto, o sin dirección y sin hijos, no se pinta: nunca un enlace roto.
    if ($af_label === '' || ($af_href === '' && $af_kids === [])) {
        continue;
    }
    $af_shop = !empty($af_item['shop']) && $af_href !== '';
    $af_sub = 'af-shop-' . $af_idp . '-' . $af_level;
    ?>
    <li class="af-nav-item<?= $af_kids !== [] ? ' has-children' : '' ?><?= $af_shop ? ' af-nav-shop' : '' ?>">
    <?php if ($af_href !== '') : ?>
        <a class="af-nav-link" href="<?= e($af_href) ?>"<?= !empty($af_item['current']) ? ' aria-current="page"' : '' ?>><?= e($af_label) ?></a>
    <?php else : ?>
        <span class="af-nav-link af-nav-label"><?= e($af_label) ?></span>
    <?php endif; ?>
    <?php if ($af_shop && $af_kids !== []) : ?>
        <button type="button" class="af-nav-toggle" aria-expanded="false" aria-controls="<?= e($af_sub) ?>" aria-label="<?= e(t('Show the categories')) ?>" hidden>
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <ul class="af-nav-list af-nav-sub" id="<?= e($af_sub) ?>">
            <?php foreach ($af_kids as $af_kid) : ?>
                <?php if (!empty($af_kid['label']) && !empty($af_kid['url'])) : ?>
                    <li class="af-nav-item"><a class="af-nav-link" href="<?= e((string) $af_kid['url']) ?>"><?= e((string) $af_kid['label']) ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($af_kids !== []) : ?>
        <?= $this->partial('partials/nav', ['items' => $af_kids, 'level' => $af_level + 1, 'idp' => $af_idp]) ?>
    <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
