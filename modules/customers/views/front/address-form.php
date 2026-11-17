<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Alta o cambio de una dirección.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<int,array<int,string>> $messages
 * @var array<string,mixed> $customer
 * @var string $token
 * @var string $active
 * @var array<string,mixed> $values    valores de los campos (y default_shipping, default_billing)
 * @var string $action                 dirección donde se envía el formulario
 * @var array<string,string> $countries código ISO => nombre
 * @var bool   $editing                se está cambiando una dirección que ya existe
 * @var \Aifec\Modules\Customers\Front\Account $account
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e($page['title']) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $messages]) ?>
    <div class="af-split">
        <form class="af-card" method="post" action="<?= e($action) ?>">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <?= $this->partial('customers::front/field', ['id' => 'cu-ad-label', 'name' => 'label', 'label' => t('Label'), 'value' => $values['label'], 'max' => 60, 'hint' => t('For example Home or Office.')]) ?>
            <div class="af-grid af-cols-2">
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-first', 'name' => 'first_name', 'label' => t('First name'), 'value' => $values['first_name'], 'autocomplete' => 'given-name', 'required' => true, 'max' => 120]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-last', 'name' => 'last_name', 'label' => t('Last name'), 'value' => $values['last_name'], 'autocomplete' => 'family-name', 'max' => 120]) ?>
            </div>
            <div class="af-grid af-cols-2">
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-company', 'name' => 'company', 'label' => t('Company'), 'value' => $values['company'], 'autocomplete' => 'organization', 'max' => 160]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-vat', 'name' => 'vat_id', 'label' => t('Tax ID'), 'value' => $values['vat_id'], 'max' => 40]) ?>
            </div>
            <?= $this->partial('customers::front/field', ['id' => 'cu-ad-line1', 'name' => 'line1', 'label' => t('Address'), 'value' => $values['line1'], 'autocomplete' => 'address-line1', 'required' => true, 'max' => 191]) ?>
            <?= $this->partial('customers::front/field', ['id' => 'cu-ad-line2', 'name' => 'line2', 'label' => t('Address line 2'), 'value' => $values['line2'], 'autocomplete' => 'address-line2', 'max' => 191]) ?>
            <div class="af-grid af-cols-2">
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-postcode', 'name' => 'postcode', 'label' => t('Postcode'), 'value' => $values['postcode'], 'autocomplete' => 'postal-code', 'required' => true, 'max' => 16]) ?>
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-city', 'name' => 'city', 'label' => t('City'), 'value' => $values['city'], 'autocomplete' => 'address-level2', 'required' => true, 'max' => 120]) ?>
            </div>
            <div class="af-grid af-cols-2">
                <?= $this->partial('customers::front/field', ['id' => 'cu-ad-region', 'name' => 'region', 'label' => t('Province or state'), 'value' => $values['region'], 'max' => 16, 'hint' => t('In Spain it is filled in from the postcode.')]) ?>
                <div class="af-field">
                    <label for="cu-ad-country"><?= e(t('Country')) ?></label>
                    <select id="cu-ad-country" class="af-select" name="country" autocomplete="country">
<?php foreach ($countries as $af_iso => $af_name) : ?>
                        <option value="<?= e($af_iso) ?>"<?= (string) $af_iso === strtoupper((string) $values['country']) ? ' selected' : '' ?>><?= e($af_name) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?= $this->partial('customers::front/field', ['id' => 'cu-ad-phone', 'name' => 'phone', 'label' => t('Phone'), 'type' => 'tel', 'value' => $values['phone'], 'autocomplete' => 'tel', 'max' => 40]) ?>
            <div class="af-field">
                <label class="af-check" for="cu-ad-ship"><input id="cu-ad-ship" type="checkbox" name="default_shipping" value="1"<?= !empty($values['default_shipping']) ? ' checked' : '' ?>><span><?= e(t('Default shipping address')) ?></span></label>
                <label class="af-check" for="cu-ad-bill"><input id="cu-ad-bill" type="checkbox" name="default_billing" value="1"<?= !empty($values['default_billing']) ? ' checked' : '' ?>><span><?= e(t('Default billing address')) ?></span></label>
            </div>
            <div class="af-row">
                <button type="submit" class="af-btn af-btn-primary"><?= e(t('Save address')) ?></button>
                <a href="<?= e(route('account.addresses')) ?>"><?= e(t('Cancel')) ?></a>
            </div>
        </form>
        <aside><?= $this->partial('customers::front/account-nav', ['active' => $active, 'token' => $token, 'account' => $account]) ?></aside>
    </div>
</div>
