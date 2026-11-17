<?php
/**
 * AIFEC — insignias de un producto (el hueco «af-badges» de la tarjeta y de la ficha): «Oferta −17 %», «Nuevo», «Agotado» y lo que
 * añada un plugin con el filtro product.badges. Todo texto sale escapado; un tipo desconocido se pinta como «info».
 *
 * Los temas que sustituyen la tarjeta llaman a esta pieza una vez, con su etiqueta y su clase: `tag` (span, p, div o ul; «none» pinta
 * solo las insignias, sin envoltorio, para el tema que lo escribe él), `class` (clase propia del tema, además de af-badges), `item`
 * (span o li, para las listas) y, si el tema quiere su propio texto para la oferta, `sale_label`.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard o ProductData (con «badge_list»)
 * @var string              $tag
 * @var string              $class
 * @var string              $item
 * @var string              $sale_label
 */

$af_list = isset($product) && is_array($product) && isset($product['badge_list']) && is_array($product['badge_list']) ? $product['badge_list'] : [];
// Una tarjeta hecha a mano (la muestra de un tema, un dato antiguo) que solo trae los códigos de siempre: se pintan como siempre.
if (isset($product) && is_array($product) && !isset($product['badge_list']) && isset($product['badges']) && is_array($product['badges'])) {
    $af_price = isset($product['price']) && is_array($product['price']) ? $product['price'] : [];
    $af_amount = isset($af_price['amount']) ? (int) $af_price['amount'] : 0;
    $af_compare = isset($af_price['compare']) ? (int) $af_price['compare'] : 0;
    $af_percent = $af_compare > $af_amount && $af_compare > 0
        ? (isset($af_price['discount_percent']) && (int) $af_price['discount_percent'] > 0 ? (int) $af_price['discount_percent'] : intdiv(($af_compare - $af_amount) * 100 + intdiv($af_compare, 2), $af_compare))
        : 0;
    $af_list = [];
    if (in_array('sale', $product['badges'], true)) {
        $af_list[] = ['code' => 'sale', 'label' => $af_percent > 0 ? '−' . $af_percent . "\u{a0}%" : t('Sale'), 'kind' => 'sale'];
    }
    if (in_array('new', $product['badges'], true)) {
        $af_list[] = ['code' => 'new', 'label' => t('New'), 'kind' => 'ok'];
    }
    if (in_array('out', $product['badges'], true)) {
        $af_list[] = ['code' => 'out', 'label' => t('Sold out'), 'kind' => 'warn'];
    }
}
$af_tag = isset($tag) && in_array($tag, ['span', 'p', 'div', 'ul', 'none'], true) ? $tag : 'span';
$af_class = isset($class) && is_string($class) ? trim($class) : '';
$af_item = isset($item) && $item === 'li' ? 'li' : 'span';
$af_sale = isset($sale_label) && is_string($sale_label) ? trim($sale_label) : '';
$af_kinds = ['info' => 'af-badge', 'ok' => 'af-badge af-badge-new', 'warn' => 'af-badge af-badge-out', 'sale' => 'af-badge af-badge-sale'];
$af_rows = [];
foreach ($af_list as $af_one) {
    if (is_array($af_one) && isset($af_one['label']) && is_scalar($af_one['label']) && trim((string) $af_one['label']) !== '') {
        $af_kind = isset($af_one['kind']) && is_string($af_one['kind']) && isset($af_kinds[$af_one['kind']]) ? $af_one['kind'] : 'info';
        $af_label = $af_sale !== '' && isset($af_one['code']) && $af_one['code'] === 'sale' ? $af_sale : (string) $af_one['label'];
        $af_rows[] = ['label' => $af_label, 'class' => $af_kinds[$af_kind]];
    }
}
if ($af_rows === []) {
    return;
}
?>
<?php if ($af_tag !== 'none') : ?>
<<?= e($af_tag) ?> class="<?= e(trim($af_class . ' af-badges')) ?>"<?php if ($af_tag === 'ul') : ?> role="list"<?php endif; ?>>
<?php endif; ?>
<?php foreach ($af_rows as $af_row) : ?>
<?php if ($af_item === 'li') : ?><li><?php endif; ?><span class="<?= e($af_row['class']) ?>"><?= e($af_row['label']) ?></span><?php if ($af_item === 'li') : ?></li><?php endif; ?>

<?php endforeach; ?>
<?php if ($af_tag !== 'none') : ?>
</<?= e($af_tag) ?>>
<?php endif; ?>
