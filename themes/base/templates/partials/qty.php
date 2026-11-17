<?php
/**
 * AIFEC — selector de cantidad con botones − y + (theme.js los maneja con data-af-qty).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string   $name  nombre del campo (por defecto «qty»)
 * @var int      $value cantidad inicial
 * @var int      $min   mínimo (por defecto 1)
 * @var int|null $max   máximo (sin tope si falta)
 * @var string   $tool  herramienta WebMCP del formulario que lo contiene (vacío: sin marca)
 * @var string   $of    producto al que se refiere (opcional): en un listado, cada selector dice de qué producto es
 */

$af_tool = isset($tool) && is_string($tool) ? $tool : '';
$af_of = isset($of) && is_string($of) ? trim($of) : '';
$af_labels = $af_of !== ''
    ? [t('Decrease quantity of {name}', ['name' => $af_of]), t('Quantity of {name}', ['name' => $af_of]), t('Increase quantity of {name}', ['name' => $af_of])]
    : [t('Decrease quantity'), t('Quantity'), t('Increase quantity')];
$af_name = isset($name) && is_string($name) && $name !== '' ? $name : 'qty';
$af_min = isset($min) ? max(0, (int) $min) : 1;
$af_max = isset($max) && $max !== null && (int) $max >= $af_min ? (int) $max : null;
$af_value = isset($value) ? (int) $value : $af_min;
$af_value = max($af_min, $af_max !== null ? min($af_max, $af_value) : $af_value);
?>
<div class="af-qty" data-af-qty>
    <button type="button" class="af-qty-btn" data-af-qty-step="-1" aria-label="<?= e($af_labels[0]) ?>"><span aria-hidden="true">&minus;</span></button>
    <input class="af-qty-input" type="number" name="<?= e($af_name) ?>" value="<?= e($af_value) ?>" min="<?= e($af_min) ?>"<?= $af_max !== null ? ' max="' . e($af_max) . '"' : '' ?> step="1" inputmode="numeric" aria-label="<?= e($af_labels[1]) ?>"<?= $af_tool !== '' ? hook('webmcp.param', $af_tool, $af_name) : '' ?>>
    <button type="button" class="af-qty-btn" data-af-qty-step="1" aria-label="<?= e($af_labels[2]) ?>"><span aria-hidden="true">+</span></button>
</div>
