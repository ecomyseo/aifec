<?php
/**
 * AIFEC — opciones de una ficha con variantes, pintadas como tarjetas elegibles («formato»: tarro, lata, lote…). El estado
 * lo lleva el radio nativo (checked) y theme.js lo lee igual que en el tema base; la clase is-on solo acompaña al estilo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $options  opciones de ProductData: code, name, values [code, label]
 * @var array<string,string>           $selected código de la opción => código del valor elegido de serie
 */

$ds_options = isset($options) && is_array($options) ? $options : [];
$ds_selected = isset($selected) && is_array($selected) ? $selected : [];
?>
<?php foreach ($ds_options as $ds_option) : ?>
    <?php $ds_code = (string) $ds_option['code']; ?>
    <fieldset class="ds-formats af-variants" data-af-option="<?= e($ds_code) ?>">
        <legend class="ds-formats-title"><?= e((string) $ds_option['name']) ?></legend>
        <div class="ds-formats-list af-variants-list">
            <?php foreach ($ds_option['values'] as $ds_value) : ?>
                <?php $ds_on = isset($ds_selected[$ds_code]) && (string) $ds_selected[$ds_code] === (string) $ds_value['code']; ?>
                <label class="ds-format af-variant<?= $ds_on ? ' is-on' : '' ?>" data-value="<?= e((string) $ds_value['code']) ?>">
                    <input class="af-sr" type="radio" name="opt[<?= e($ds_code) ?>]" value="<?= e((string) $ds_value['code']) ?>"<?= $ds_on ? ' checked' : '' ?>>
                    <span class="ds-format-dot" aria-hidden="true"></span>
                    <span class="ds-format-name"><?= e((string) $ds_value['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
<?php endforeach; ?>
