<?php
/**
 * AIFEC — comprar desde la tarjeta de un listado: elegir la combinación (talla, color, formato…), las unidades y añadir al
 * carrito sin salir de la página. Lo incluye la tarjeta de cada tema donde antes ponía su botón.
 *
 * Usa las mismas piezas que la ficha: el parcial de opciones (radios «opt[código]»), el campo oculto de la variante
 * (data-af-variant-id) y los datos de las variantes (data-af-variants-data), así que el guion del tema ya cambia el precio y
 * la disponibilidad al elegir; el guion del catálogo (card-options.js) cambia la foto, el tope de unidades y el enlace a la
 * ficha. La tarjeta que lo incluye tiene que llevar en su <article> los atributos data-af-card y data-af-card-url="<dirección
 * de la ficha>" (y data-af-variants si el producto tiene variantes), y pintar su precio con 'live' => true.
 *
 * Sin JavaScript también compra bien: el botón envía a «elegir variante», que resuelve en el servidor las opciones marcadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product      ProductCard con «buy» (si no lo trae, no pinta nada)
 * @var string              $class        clases extra del formulario (las del tema)
 * @var string              $button_class clases del botón (por defecto las comunes)
 * @var string              $button_html  contenido del botón ya escapado (un icono del tema); por defecto el texto «Añadir»
 * @var string              $action       dirección de «añadir al carrito» (por defecto la ruta cart.add)
 */

declare(strict_types=1);

use Aifec\Modules\Catalog\Front\Variants;

$af_card = isset($product) && is_array($product) ? $product : [];
$af_buy = isset($af_card['buy']) && is_array($af_card['buy']) ? $af_card['buy'] : null;
if ($af_buy === null) {
    return;
}
$af_app = app();
$af_action = isset($action) && is_string($action) && $action !== '' ? $action : ($af_app->router()->has('cart.add') ? route('cart.add') : '');
if ($af_action === '') {
    return;
}
$af_name = isset($af_card['name']) ? (string) $af_card['name'] : '';
$af_url = isset($af_card['url']) ? (string) $af_card['url'] : '';
$af_form_class = trim('af-co ' . (isset($class) && is_string($class) ? $class : ''));
$af_button_class = isset($button_class) && is_string($button_class) && $button_class !== '' ? $button_class : 'af-btn af-btn-primary af-btn-sm';
$af_button = isset($button_html) && is_string($button_html) && $button_html !== '' ? $button_html : '<span>' . e(t('Add')) . '</span>';

// Más variantes de las que caben en una tarjeta: se eligen en la ficha.
if (!empty($af_buy['too_many'])) {
    if ($af_url !== '') {
        echo '<a class="af-co-more ' . e($af_button_class) . '" href="' . e($af_url) . '">' . e(t('Choose options')) . '</a>';
    }

    return;
}

$af_variants = isset($af_buy['variants']) && is_array($af_buy['variants']) ? $af_buy['variants'] : [];
$af_has = $af_variants !== [];
$af_initial = null;
foreach ($af_variants as $af_variant) {
    if ((int) $af_variant['id'] === (int) $af_buy['initial']) {
        $af_initial = $af_variant;
        break;
    }
}
if ($af_has && $af_initial === null) {
    return;
}
$af_max = $af_has ? $af_initial['max_qty'] : $af_buy['max_qty'];
// Qué valores de cada opción se pueden comprar en alguna variante (los demás salen apagados en el desplegable).
$af_can = [];
foreach ($af_variants as $af_variant) {
    if (!empty($af_variant['purchasable'])) {
        foreach ($af_variant['values'] as $af_option_code => $af_value_code) {
            $af_can[(string) $af_option_code][(string) $af_value_code] = true;
        }
    }
}
$af_choose = $af_has && $af_app->router()->has('catalog.choose') ? route('catalog.choose') : '';
?>
<form class="<?= e($af_form_class) ?>" method="post" action="<?= e($af_action) ?>" data-af-cart-add data-af-co<?= hook('webmcp.form', 'add_to_cart', ['n' => (int) $af_buy['product_id'], 'name' => $af_name]) ?>>
    <input type="hidden" name="product_id" value="<?= (int) $af_buy['product_id'] ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
    <input type="hidden" name="variant_id" value="<?= $af_has ? (int) $af_initial['id'] : 0 ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
<?php if ($af_has) : ?>
    <div class="af-co-options">
<?php foreach ($af_buy['options'] as $af_option) : ?>
<?php   if (count($af_option['values']) > 6) : ?>
<?php     /* Muchas opciones (las tallas de un zapato): un desplegable, que en una tarjeta ocupa una línea. */ ?>
        <label class="af-co-pick">
            <span><?= e((string) $af_option['name']) ?></span>
            <select class="af-select" name="opt[<?= e((string) $af_option['code']) ?>]" data-af-option="<?= e((string) $af_option['code']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'opt') ?>>
<?php     foreach ($af_option['values'] as $af_value) : ?>
<?php       $af_code = (string) $af_value['code']; ?>
                <option value="<?= e($af_code) ?>"<?= isset($af_initial['values'][$af_option['code']]) && (string) $af_initial['values'][$af_option['code']] === $af_code ? ' selected' : '' ?><?= empty($af_can[$af_option['code']][$af_code]) ? ' disabled' : '' ?>><?= e((string) $af_value['label']) ?></option>
<?php     endforeach; ?>
            </select>
        </label>
<?php   else : ?>
        <?= $this->partial('catalog::front/partials/options', ['options' => [$af_option], 'selected' => $af_initial['values'], 'tool' => 'add_to_cart']) ?>
<?php   endif; ?>
<?php endforeach; ?>
    </div>
    <script type="application/json" data-af-variants-data><?= json_html(Variants::cardData($af_buy)) ?></script>
    <p class="af-co-stock af-small" data-af-availability><?= e((string) $af_initial['stock_label']) ?></p>
<?php endif; ?>
    <div class="af-co-row">
        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => 1, 'min' => 1, 'max' => $af_max !== null && (int) $af_max > 0 ? (int) $af_max : null, 'of' => $af_name, 'tool' => 'add_to_cart']) ?>
        <button type="submit" class="af-co-add <?= e($af_button_class) ?>" data-af-add<?= $af_choose !== '' ? ' formaction="' . e($af_choose) . '"' : '' ?> aria-label="<?= e(t('Add {name} to the cart', ['name' => $af_name])) ?>"><?= $af_button ?></button>
    </div>
</form>
