<?php
/**
 * AIFEC — opciones de una ficha con variantes: un grupo de botones de radio por opción (talla, color…). Con JavaScript,
 * theme.js lee el radio marcado de cada grupo y cambia precio, existencias e imagen; sin él, el navegador manda
 * «opt[código]» y el servidor resuelve la variante.
 *
 * El estado lo lleva el radio nativo (checked): la etiqueta NO lleva aria-pressed (no es un atributo válido en un
 * <label>, y rompe el árbol de accesibilidad con el que navegan los lectores de pantalla y los agentes de IA). La
 * clase is-on es solo para el estilo en navegadores sin :has().
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $options  opciones de ProductData: code, name, values [code, label]
 * @var array<string,string>           $selected código de la opción => código del valor elegido de serie
 * @var string                         $tool     herramienta WebMCP del formulario que lo contiene (vacío: sin marca)
 */

$af_options = isset($options) && is_array($options) ? $options : [];
$af_tool = isset($tool) && is_string($tool) ? $tool : '';
$af_selected = isset($selected) && is_array($selected) ? $selected : [];
// Vuelta de «Añadir al carrito» sin elegir la talla (sin JavaScript): el aviso, aquí mismo y no en un carrito vacío (14/11/2026).
$af_query = app()->request()->query;
$af_missing = $af_options !== [] && isset($af_query['falta']) && $af_query['falta'] === 'opcion';
?>
<?php if ($af_missing) : ?>
    <p class="af-alert af-alert-error" role="alert" id="af-opciones" data-af-option-missing><?= e(t('Choose an option first')) ?>: <?= e(implode(', ', array_map(static function (array $o): string {
        return (string) $o['name'];
    }, $af_options))) ?></p>
<?php endif; ?>
<?php foreach ($af_options as $af_option) : ?>
    <?php
    $af_code = (string) $af_option['code'];
    // La talla no se marca de serie: quien compra la elige (14/11/2026: sin mirarla se compraba la 46). El color sí.
    if (count($af_option['values']) > 1 && preg_match('/\b(?:talla|tallas|size|sizes|taille|n[uú]mero|numeraci[oó]n|medida)\b/iu', $af_code . ' ' . (string) $af_option['name']) === 1) {
        unset($af_selected[$af_code]);
    }
    ?>
    <fieldset class="af-variants" data-af-option="<?= e($af_code) ?>">
        <legend><?= e((string) $af_option['name']) ?></legend>
        <div class="af-variants-list">
            <?php foreach ($af_option['values'] as $af_value) : ?>
                <?php
                $af_on = isset($af_selected[$af_code]) && (string) $af_selected[$af_code] === (string) $af_value['code'];
                // Un valor sin ninguna variante que se pueda comprar (la talla agotada) se ve, se dice y no se puede elegir.
                $af_out = array_key_exists('available', $af_value) && $af_value['available'] === false;
                ?>
                <label class="af-variant<?= $af_on ? ' is-on' : '' ?><?= $af_out ? ' is-out' : '' ?>" data-value="<?= e((string) $af_value['code']) ?>">
                    <input class="af-sr" type="radio" name="opt[<?= e($af_code) ?>]" value="<?= e((string) $af_value['code']) ?>"<?= $af_on ? ' checked' : '' ?><?= $af_out ? ' disabled' : '' ?><?= $af_tool !== '' ? hook('webmcp.param', $af_tool, 'opt') : '' ?>>
                    <span><?= e((string) $af_value['label']) ?><?php if ($af_out) : ?> <span class="af-small af-muted">· <?= e(t('sold out')) ?></span><?php endif; ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
<?php endforeach; ?>
