<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Los campos de una dirección de la caja (entrega o facturación), en el orden de la caja de siempre: país, nombre y
 * apellidos, dirección, piso o puerta, código postal, ciudad, provincia y teléfono. Los nombres son «shipping[campo]» o
 * «billing[campo]» y cada error sale junto a su campo. La empresa y el NIF van dentro de un desplegable («Necesito factura
 * de empresa») que se abre solo si ya hay datos, un error o el NIF es obligatorio; funciona sin JavaScript.
 *
 * La provincia es una lista para España y un texto en los demás países: se pintan las dos y solo está activa la que
 * corresponde al país (checkout.js las cambia al cambiar de país y pone la provincia en cuanto el código postal es de
 * España). Cada campo lleva su autocomplete y su teclado para que el navegador y el móvil lo rellenen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $c       lo que prepara CheckoutController::context()
 * @var string              $group   shipping o billing
 * @var bool                $full    dirección completa (si no, solo país, nombre, empresa y NIF: lo digital no se envía)
 * @var string              $idp     prefijo de los identificadores
 * @var string              $section prefijo de autocomplete de la dirección («billing » para la factura a otros datos)
 */

declare(strict_types=1);

$af_values = $c['state'][$group];
$af_errors = $c['errors'];
$af_err = static function (string $field) use ($af_errors, $group): string {
    return isset($af_errors[$group . '.' . $field]) ? (string) $af_errors[$group . '.' . $field] : '';
};
$af_es = $af_values['country'] === 'ES';
$af_ac = isset($section) ? (string) $section : '';
$af_company_open = $af_values['company'] !== '' || $af_values['vat_id'] !== '' || $c['require_vat'] || $af_err('company') !== '' || $af_err('vat_id') !== '';
$af_vat_hint = $c['require_vat'] ? t('Required for the invoice.') : t('Needed if you buy as a company.');
// Lo que el servidor exige y el HTML anuncia: con una dirección guardada elegida los campos de una dirección nueva sobran
// (checkout.js los esconde) y la factura a otros datos está plegada: ahí un «required» bloquearía el envío.
$af_req = $c['addresses'] === [] && ($group === 'shipping' || !$full);
$af_legend = $group === 'shipping' ? t('Delivery address') : t('Invoice details');
?>
<?php
// Con un encabezado ya a la vista («Datos de facturación»), el grupo se nombra con él y no se repite el nombre (14/11/2026: un lector
// de pantalla oía «Datos de facturación» dos veces seguidas).
$af_labelledby = isset($labelledby) && is_string($labelledby) && $labelledby !== '' ? $labelledby : '';
?>
<div class="af-co-address af-stack" data-af-address="<?= e($group) ?>" role="group" aria-labelledby="<?= e($af_labelledby !== '' ? $af_labelledby : $idp . '-legend') ?>">
<?php if ($af_labelledby === '') : ?>
    <span class="af-sr" id="<?= e($idp) ?>-legend"><?= e($af_legend) ?></span>
<?php endif; ?>
    <div class="af-field<?= $af_err('country') !== '' ? ' has-error' : '' ?>">
        <label for="<?= e($idp) ?>-country"><?= e(t('Country')) ?></label>
        <select id="<?= e($idp) ?>-country" class="af-select" name="<?= e($group) ?>[country]" autocomplete="<?= e($af_ac) ?>country" data-af-country data-af-update<?= $af_err('country') !== '' ? ' aria-invalid="true" aria-describedby="' . e($idp) . '-country-error"' : '' ?>>
<?php foreach ($c['countries'] as $af_iso => $af_name) : ?>
            <option value="<?= e((string) $af_iso) ?>"<?= (string) $af_iso === $af_values['country'] ? ' selected' : '' ?>><?= e($af_name) ?></option>
<?php endforeach; ?>
        </select>
<?php if ($af_err('country') !== '') : ?>
        <span class="af-error" id="<?= e($idp) ?>-country-error" role="alert"><?= e($af_err('country')) ?></span>
<?php endif; ?>
    </div>
    <div class="af-grid af-cols-2">
        <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-first', 'name' => $group . '[first_name]', 'label' => t('First name'), 'value' => $af_values['first_name'], 'error' => $af_err('first_name'), 'autocomplete' => $af_ac . 'given-name', 'required' => $af_req, 'max' => 80]) ?>
        <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-last', 'name' => $group . '[last_name]', 'label' => t('Last name'), 'value' => $af_values['last_name'], 'error' => $af_err('last_name'), 'autocomplete' => $af_ac . 'family-name', 'max' => 120]) ?>
    </div>
<?php if ($full) : ?>
    <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-line1', 'name' => $group . '[line1]', 'label' => t('Address'), 'value' => $af_values['line1'], 'error' => $af_err('line1'), 'autocomplete' => $af_ac . 'address-line1', 'required' => $af_req, 'max' => 191]) ?>
    <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-line2', 'name' => $group . '[line2]', 'label' => t('Apartment, floor or door (optional)'), 'value' => $af_values['line2'], 'error' => $af_err('line2'), 'autocomplete' => $af_ac . 'address-line2', 'max' => 191]) ?>
    <div class="af-grid af-cols-2">
        <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-postcode', 'name' => $group . '[postcode]', 'label' => t('Postcode'), 'value' => $af_values['postcode'], 'error' => $af_err('postcode'), 'autocomplete' => $af_ac . 'postal-code', 'inputmode' => $af_es ? 'numeric' : 'text', 'required' => $af_req, 'max' => 16, 'update' => true]) ?>
        <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-city', 'name' => $group . '[city]', 'label' => t('City'), 'value' => $af_values['city'], 'error' => $af_err('city'), 'autocomplete' => $af_ac . 'address-level2', 'required' => $af_req, 'max' => 100]) ?>
    </div>
    <div class="af-grid af-cols-2">
        <div data-af-region-box>
            <div class="af-field" data-af-region-es<?= $af_es ? '' : ' hidden' ?>>
                <label for="<?= e($idp) ?>-region-es"><?= e(t('Province')) ?></label>
                <select id="<?= e($idp) ?>-region-es" class="af-select" name="<?= e($group) ?>[region]"<?= $af_es ? '' : ' disabled' ?>>
                    <option value="">&mdash;</option>
<?php foreach ($c['provinces'] as $af_prefix => $af_province) : ?>
                    <option value="<?= e((string) $af_prefix) ?>"<?= (string) $af_prefix === $af_values['region'] ? ' selected' : '' ?>><?= e($af_province) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="af-field" data-af-region-other<?= $af_es ? ' hidden' : '' ?>>
                <label for="<?= e($idp) ?>-region"><?= e(t('Province or state')) ?></label>
                <input id="<?= e($idp) ?>-region" class="af-input" type="text" name="<?= e($group) ?>[region]" value="<?= e($af_es ? '' : $af_values['region']) ?>" maxlength="16" autocomplete="<?= e($af_ac) ?>address-level1"<?= $af_es ? ' disabled' : '' ?>>
            </div>
        </div>
<?php if ($group === 'shipping') : ?>
        <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-phone', 'name' => $group . '[phone]', 'label' => t('Phone'), 'type' => 'tel', 'value' => $af_values['phone'], 'error' => $af_err('phone'), 'autocomplete' => $af_ac . 'tel', 'inputmode' => 'tel', 'max' => 40, 'required' => true, 'hint' => t('For the courier, in case there is a problem with the delivery.')]) ?>
<?php endif; ?>
    </div>
<?php endif; ?>
    <p class="af-hint" id="<?= e($idp) ?>-company-help"><?= e(t('Only if you buy as a company: open the section below and write the company name and the tax ID.')) ?></p>
    <details class="af-co-company"<?= $af_company_open ? ' open' : '' ?>>
        <summary><?= e(t('I need an invoice for a company')) ?></summary>
        <div class="af-grid af-cols-2">
            <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-company', 'name' => $group . '[company]', 'label' => t('Company'), 'value' => $af_values['company'], 'error' => $af_err('company'), 'autocomplete' => $af_ac . 'organization', 'max' => 160]) ?>
            <?= $this->partial('orders::front/checkout-field', ['id' => $idp . '-vat', 'name' => $group . '[vat_id]', 'label' => t('Tax ID'), 'value' => $af_values['vat_id'], 'error' => $af_err('vat_id'), 'max' => 40, 'hint' => $af_vat_hint]) ?>
        </div>
    </details>
</div>
