<?php
/**
 * AIFEC — «Pedido nuevo»: la columna de los siete pasos (cliente, productos, entrega, envío, cupón, pago y confirmar).
 *
 * Cada paso es un formulario que funciona sin JavaScript (POST y vuelta a la pantalla). Con JavaScript, admin-manual-order.js
 * los envía por fetch y sustituye esta columna por la que devuelve el servidor; el resumen lo pide aparte a «quote».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $v lo que prepara ManualOrderController::screen()
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Geo\Address;

$mo_app = app();
$u = $v['urls'];
$q = $v['quote'];
$state = $v['state'];
$customer = $v['customer'];
$form = is_array($v['form']) ? $v['form'] : [];
$mo_token = Admin::tokenField($mo_app);
$mo_failed = static function (string $step) use ($form): bool {
    return isset($form['step']) && $form['step'] === $step;
};
$mo_old = static function (string $step, string $group, string $field) use ($form): string {
    if (!isset($form['step']) || $form['step'] !== $step) {
        return '';
    }
    $values = isset($form['values'][$group]) && is_array($form['values'][$group]) ? $form['values'][$group] : [];

    return isset($values[$field]) ? (string) $values[$field] : '';
};
$mo_error = static function (string $step, string $field) use ($form): string {
    return isset($form['step'], $form['errors'][$field]) && $form['step'] === $step ? (string) $form['errors'][$field] : '';
};
$mo_address = static function (array $address) use ($mo_app): string {
    return implode(', ', Address::lines($address, $mo_app->lang(), ['tax_id' => false, 'phone' => false]));
};
// Un campo de texto con su etiqueta, su error y el valor que se envió si el paso falló.
$mo_field = static function (string $step, string $group, string $name, string $label, array $attrs = []) use ($mo_old, $mo_error): string {
    $id = 'mo-' . $group . '-' . $name;
    $error = $mo_error($step, $name);
    $html = '<div class="af-field' . ($error !== '' ? ' af-field-invalid' : '') . '"><label class="af-label" for="' . e($id) . '">' . e($label) . '</label><div class="af-control">';
    $html .= '<input class="af-input" id="' . e($id) . '" name="' . e($group . '[' . $name . ']') . '" value="' . e($mo_old($step, $group, $name)) . '"';
    foreach ($attrs as $attr => $value) {
        $html .= ' ' . e($attr) . '="' . e($value) . '"';
    }
    $html .= '>';
    if ($error !== '') {
        $html .= '<div class="af-error" role="alert">' . e($error) . '</div>';
    }

    return $html . '</div></div>';
};
?>
<?php if ($v['changes'] !== []) : ?>
<div class="af-alert af-alert-warn" role="alert" id="mo-changes">
    <p><strong><?= e(t('Nothing was created: the order changed since the total you saw.')) ?></strong></p>
    <ul>
<?php foreach ($v['changes'] as $mo_change) : ?>
        <li><?= e($mo_change['text']) ?></li>
<?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<section class="af-card" id="mo-customer" aria-labelledby="mo-h-customer">
    <div class="af-card-head"><h2 id="mo-h-customer" tabindex="-1"><?= e(t('1. Customer')) ?></h2></div>
    <div class="af-card-body af-stack">
<?php if ($customer !== null) : ?>
        <div class="mo-picked">
            <p><strong><?= e(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name'])) ?></strong><?= !empty($customer['guest']) ? ' ' . Admin::badge(t('No account'), 'info') : '' ?><br>
                <span class="af-small af-muted"><?= e((string) $customer['email']) ?><?= isset($customer['phone']) && (string) $customer['phone'] !== '' ? ' · ' . e((string) $customer['phone']) : '' ?></span></p>
            <form method="post" action="<?= e($u['customer']) ?>" data-mo-form><?= $mo_token ?><input type="hidden" name="customer_id" value="0"><button type="submit" class="af-btn af-btn-sm"><?= e(t('Change customer')) ?></button></form>
        </div>
<?php else : ?>
        <form method="get" action="<?= e($u['show']) ?>#mo-customer" role="search" class="mo-find" data-mo-find>
            <label class="af-label" for="mo-cq"><?= e(t('Find a customer')) ?></label>
            <div class="mo-find-row">
                <input id="mo-cq" class="af-input" type="search" name="cq" value="<?= e($v['cq']) ?>" autocomplete="off" placeholder="<?= e(t('Name, email or phone')) ?>"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mo-cq-list" aria-describedby="mo-cq-hint"
                    data-mo-combo="<?= e($u['customers']) ?>" data-mo-pick="<?= e($u['customer']) ?>">
                <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Search')) ?></button>
            </div>
            <p class="af-hint" id="mo-cq-hint"><?= e(t('At least two letters. Up to 10 customers.')) ?></p>
            <ul class="mo-options" id="mo-cq-list" role="listbox" aria-label="<?= e(t('Customers found')) ?>" hidden></ul>
        </form>
<?php if ($v['searched']['customers']) : ?>
<?php if ($v['customers_found'] === []) : ?>
        <p class="af-muted"><?= e(t('No customer found. Create it below.')) ?></p>
<?php else : ?>
        <ul class="mo-results" aria-label="<?= e(t('Customers found')) ?>">
<?php foreach ($v['customers_found'] as $mo_found) : ?>
            <li><span><?= e($mo_found['name']) ?> <span class="af-small af-muted"><?= e($mo_found['email']) ?><?= $mo_found['phone'] !== '' ? ' · ' . e($mo_found['phone']) : '' ?></span></span>
                <form method="post" action="<?= e($u['customer']) ?>" data-mo-form><?= $mo_token ?><input type="hidden" name="customer_id" value="<?= (int) $mo_found['id'] ?>"><button type="submit" class="af-btn af-btn-sm"><?= e(t('Choose')) ?></button></form></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php endif; ?>
<?php if (isset($form['existing']) && is_array($form['existing'])) : ?>
        <div class="af-alert af-alert-info" role="status">
            <p><?= e(t('{name} ({email}) is already a customer.', ['name' => (string) $form['existing']['name'], 'email' => (string) $form['existing']['email']])) ?></p>
            <form method="post" action="<?= e($u['customer']) ?>" data-mo-form><?= $mo_token ?><input type="hidden" name="customer_id" value="<?= (int) $form['existing']['id'] ?>"><button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Use this customer')) ?></button></form>
        </div>
<?php endif; ?>
        <details class="mo-new"<?= $mo_failed('customer-new') ? ' open' : '' ?>>
            <summary><?= e(t('New customer')) ?></summary>
            <form method="post" action="<?= e($u['customer-new']) ?>" data-mo-form class="mo-new-form">
                <?= $mo_token ?>
                <?= $mo_field('customer-new', 'c', 'first_name', t('First name'), ['autocomplete' => 'off', 'maxlength' => '80', 'required' => 'required']) ?>
                <?= $mo_field('customer-new', 'c', 'last_name', t('Last name'), ['autocomplete' => 'off', 'maxlength' => '120']) ?>
                <?= $mo_field('customer-new', 'c', 'email', t('Email'), ['type' => 'email', 'autocomplete' => 'off', 'maxlength' => '191', 'required' => 'required']) ?>
                <?= $mo_field('customer-new', 'c', 'phone', t('Phone'), ['type' => 'tel', 'autocomplete' => 'off', 'maxlength' => '40']) ?>
                <p class="af-hint"><?= e(t('The confirmation of the order goes to this email. If it is already a customer, you can use it instead.')) ?></p>
                <div class="mo-actions"><button type="submit" class="af-btn af-btn-primary"><?= e(t('Create customer')) ?></button></div>
            </form>
        </details>
<?php endif; ?>
    </div>
</section>

<section class="af-card" id="mo-products" aria-labelledby="mo-h-products">
    <div class="af-card-head"><h2 id="mo-h-products" tabindex="-1"><?= e(t('2. Products')) ?></h2></div>
    <div class="af-card-body af-stack">
        <form method="get" action="<?= e($u['show']) ?>#mo-products" role="search" class="mo-find" data-mo-find>
            <label class="af-label" for="mo-pq"><?= e(t('Find a product')) ?></label>
            <div class="mo-find-row">
                <input id="mo-pq" class="af-input" type="search" name="pq" value="<?= e($v['pq']) ?>" autocomplete="off" placeholder="<?= e(t('Name, reference or barcode')) ?>"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mo-pq-list" aria-describedby="mo-pq-hint"
                    data-mo-combo="<?= e($u['products']) ?>" data-mo-pick="<?= e($u['line']) ?>">
                <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Search')) ?></button>
            </div>
            <p class="af-hint" id="mo-pq-hint"><?= e(t('Choose one to add one unit; change the units below. What is out of stock cannot be chosen.')) ?></p>
            <ul class="mo-options" id="mo-pq-list" role="listbox" aria-label="<?= e(t('Products found')) ?>" hidden></ul>
        </form>
<?php if ($v['searched']['products']) : ?>
<?php if ($v['products_found'] === []) : ?>
        <p class="af-muted"><?= e(t('No product found.')) ?></p>
<?php else : ?>
        <ul class="mo-results" aria-label="<?= e(t('Products found')) ?>">
<?php foreach ($v['products_found'] as $mo_found) : ?>
            <li><span><?= e($mo_found['label']) ?></span>
                <form method="post" action="<?= e($u['line']) ?>" data-mo-form><?= $mo_token ?><input type="hidden" name="product_id" value="<?= (int) $mo_found['product_id'] ?>"><input type="hidden" name="variant_id" value="<?= (int) $mo_found['variant_id'] ?>"><input type="hidden" name="qty" value="1"><button type="submit" class="af-btn af-btn-sm"<?= $mo_found['available'] ? '' : ' disabled' ?>><?= e($mo_found['available'] ? t('Add') : t('Out of stock')) ?></button></form></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php endif; ?>
<?php if ($q['lines'] === []) : ?>
        <p class="af-muted"><?= e(t('No products yet: find them above.')) ?></p>
<?php else : ?>
        <div class="af-table-wrap">
            <table class="af-table mo-lines" aria-label="<?= e(t('Products of the order')) ?>">
                <thead><tr><th scope="col"><?= e(t('Product')) ?></th><th scope="col" class="af-num"><?= e(t('Unit price')) ?></th><th scope="col"><?= e(t('Units')) ?></th><th scope="col" class="af-num"><?= e(t('Subtotal')) ?></th></tr></thead>
                <tbody>
<?php foreach ($q['lines'] as $mo_line) : ?>
<?php $mo_key = (string) $mo_line['key']; $mo_name = $mo_line['name'] . ($mo_line['variant'] !== '' ? ' (' . $mo_line['variant'] . ')' : ''); $mo_changed = isset($v['changed'][$mo_key]); ?>
                    <tr id="mo-line-<?= e($mo_key) ?>"<?= $mo_changed ? ' class="mo-changed"' : '' ?>>
                        <td><strong><?= e($mo_line['name']) ?></strong><?= $mo_line['variant'] !== '' ? '<br><span class="af-small">' . e($mo_line['variant']) . '</span>' : '' ?><?= $mo_line['sku'] !== '' ? '<br><span class="af-small af-muted af-mono">' . e($mo_line['sku']) . '</span>' : '' ?>
<?php foreach ($mo_line['warnings'] as $mo_warning) : ?>
                            <br><?= Admin::badge($mo_warning, 'warn') ?>
<?php endforeach; ?>
<?php if ($mo_changed) : ?>
                            <br><?= Admin::badge(t('Changed'), 'warn') ?>
<?php endif; ?>
<?php if ($v['can_price']) : ?>
                            <details class="mo-price"<?= $mo_failed('price') && isset($form['values']['key']) && $form['values']['key'] === $mo_key ? ' open' : '' ?>>
                                <summary><?= e(t('Price of this sale')) ?></summary>
                                <form method="post" action="<?= e($u['price']) ?>" data-mo-form class="mo-price-form">
                                    <?= $mo_token ?><input type="hidden" name="key" value="<?= e($mo_key) ?>">
                                    <label class="af-label" for="mo-price-<?= e($mo_key) ?>"><?= e(t('Unit price, tax included')) ?></label>
                                    <input class="af-input" id="mo-price-<?= e($mo_key) ?>" name="price" inputmode="decimal" autocomplete="off" value="<?= e($mo_line['price'] !== null ? number_format((int) $mo_line['price'] / 100, 2, ',', '') : '') ?>">
                                    <label class="af-label" for="mo-reason-<?= e($mo_key) ?>"><?= e(t('Reason')) ?></label>
                                    <input class="af-input" id="mo-reason-<?= e($mo_key) ?>" name="reason" maxlength="120" autocomplete="off" value="<?= e((string) $mo_line['reason']) ?>">
                                    <div class="mo-actions">
                                        <button type="submit" class="af-btn af-btn-sm"><?= e(t('Save price')) ?></button>
<?php if ($mo_line['price'] !== null) : ?>
                                        <button type="submit" class="af-btn af-btn-sm af-btn-ghost" formaction="<?= e($u['price-clear']) ?>"><?= e(t('Back to the catalogue price')) ?></button>
<?php endif; ?>
                                    </div>
                                </form>
                            </details>
<?php endif; ?>
                        </td>
                        <td class="af-num"><?= e($mo_line['unit_formatted']) ?><?php if ($mo_line['price'] !== null) : ?><br><span class="af-small"><?= e(t('This sale: {price}', ['price' => (string) $mo_line['price_formatted']])) ?></span><br><span class="af-small af-muted"><?= e((string) $mo_line['reason']) ?></span><?php endif; ?></td>
                        <td>
                            <form method="post" action="<?= e($u['qty']) ?>" data-mo-form class="mo-qty-form">
                                <?= $mo_token ?><input type="hidden" name="key" value="<?= e($mo_key) ?>">
                                <label class="af-sr" for="mo-qty-<?= e($mo_key) ?>"><?= e(t('Units of {product}', ['product' => $mo_name])) ?></label>
                                <input class="af-input mo-qty" id="mo-qty-<?= e($mo_key) ?>" type="number" name="qty" min="1"<?= (int) $mo_line['max'] > 0 ? ' max="' . (int) $mo_line['max'] . '"' : '' ?> step="1" inputmode="numeric" value="<?= (int) $mo_line['qty'] ?>">
                                <button type="submit" class="af-btn af-btn-sm"><?= e(t('Update')) ?></button>
                                <button type="submit" class="af-btn af-btn-sm af-btn-ghost" formaction="<?= e($u['remove']) ?>" aria-label="<?= e(t('Remove {product}', ['product' => $mo_name])) ?>" title="<?= e(t('Remove {product}', ['product' => $mo_name])) ?>"><?= Admin::icon('trash') ?></button>
                            </form>
                        </td>
                        <td class="af-num"><?= e($mo_line['subtotal_formatted']) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</section>

<section class="af-card" id="mo-delivery" aria-labelledby="mo-h-delivery">
    <div class="af-card-head"><h2 id="mo-h-delivery" tabindex="-1"><?= e(t('3. Delivery')) ?></h2></div>
    <div class="af-card-body af-stack">
<?php if ($customer === null) : ?>
        <p class="af-muted"><?= e(t('Choose the customer first.')) ?></p>
<?php else : ?>
<?php if ($v['addresses'] !== []) : ?>
        <form method="post" action="<?= e($u['address']) ?>" data-mo-form>
            <?= $mo_token ?>
            <fieldset class="mo-choices">
                <legend class="af-label"><?= e(t('Addresses of the customer')) ?></legend>
<?php foreach ($v['addresses'] as $mo_address_row) : ?>
                <label class="af-check mo-choice"><input type="radio" name="address_id" value="<?= (int) $mo_address_row['id'] ?>"<?= (int) $mo_address_row['id'] === (int) $v['address_id'] ? ' checked' : '' ?>> <span><?= e($mo_address($mo_address_row)) ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <div class="mo-actions"><button type="submit" class="af-btn af-btn-sm"><?= e(t('Use this address')) ?></button></div>
        </form>
<?php else : ?>
        <p class="af-muted"><?= e(t('The customer has no saved address: add one.')) ?></p>
<?php endif; ?>
<?php if ($v['pickup'] !== null) : ?>
        <form method="post" action="<?= e($u['shipping']) ?>" data-mo-form class="mo-pickup">
            <?= $mo_token ?><input type="hidden" name="carrier_id" value="<?= (int) $v['pickup']['id'] ?>">
<?php if ($v['pickup']['selected']) : ?>
            <p><?= Admin::badge(t('Collects in store'), 'ok') ?> <?= e($v['pickup']['name']) ?></p>
<?php else : ?>
            <button type="submit" class="af-btn af-btn-sm"><?= e(t('The customer collects it in store: {name}', ['name' => $v['pickup']['name']])) ?></button>
<?php endif; ?>
            <p class="af-hint"><?= e(t('The address is still needed for the invoice.')) ?></p>
        </form>
<?php endif; ?>
        <details class="mo-new"<?= $mo_failed('address-new') || $v['addresses'] === [] ? ' open' : '' ?>>
            <summary><?= e(t('New address')) ?></summary>
            <form method="post" action="<?= e($u['address-new']) ?>" data-mo-form class="mo-new-form">
                <?= $mo_token ?>
<?php $mo_country = $mo_old('address-new', 'a', 'country') !== '' ? $mo_old('address-new', 'a', 'country') : (string) key($v['countries']); ?>
                <div class="af-field<?= $mo_error('address-new', 'country') !== '' ? ' af-field-invalid' : '' ?>"><label class="af-label" for="mo-a-country"><?= e(t('Country')) ?></label><div class="af-control">
                    <select class="af-select" id="mo-a-country" name="a[country]">
<?php foreach ($v['countries'] as $mo_iso => $mo_cname) : ?>
                        <option value="<?= e((string) $mo_iso) ?>"<?= (string) $mo_iso === $mo_country ? ' selected' : '' ?>><?= e($mo_cname) ?></option>
<?php endforeach; ?>
                    </select><?= $mo_error('address-new', 'country') !== '' ? '<div class="af-error" role="alert">' . e($mo_error('address-new', 'country')) . '</div>' : '' ?></div></div>
                <?= $mo_field('address-new', 'a', 'first_name', t('First name'), ['autocomplete' => 'off', 'maxlength' => '80']) ?>
                <?= $mo_field('address-new', 'a', 'last_name', t('Last name'), ['autocomplete' => 'off', 'maxlength' => '120']) ?>
                <?= $mo_field('address-new', 'a', 'company', t('Company'), ['autocomplete' => 'off', 'maxlength' => '160']) ?>
                <?= $mo_field('address-new', 'a', 'line1', t('Address'), ['autocomplete' => 'off', 'maxlength' => '191']) ?>
                <?= $mo_field('address-new', 'a', 'line2', t('Floor, door…'), ['autocomplete' => 'off', 'maxlength' => '191']) ?>
                <?= $mo_field('address-new', 'a', 'postcode', t('Postcode'), ['autocomplete' => 'off', 'maxlength' => '16']) ?>
                <?= $mo_field('address-new', 'a', 'city', t('City'), ['autocomplete' => 'off', 'maxlength' => '100']) ?>
                <?= $mo_field('address-new', 'a', 'phone', t('Phone'), ['type' => 'tel', 'autocomplete' => 'off', 'maxlength' => '40']) ?>
                <p class="af-hint"><?= e(t('It is saved in the account of the customer.')) ?></p>
                <div class="mo-actions"><button type="submit" class="af-btn af-btn-primary"><?= e(t('Save address')) ?></button></div>
            </form>
        </details>
<?php endif; ?>
    </div>
</section>

<section class="af-card" id="mo-shipping" aria-labelledby="mo-h-shipping">
    <div class="af-card-head"><h2 id="mo-h-shipping" tabindex="-1"><?= e(t('4. Shipping')) ?></h2></div>
    <div class="af-card-body af-stack">
<?php if ($q['lines'] !== [] && !$q['needs_shipping']) : ?>
        <p class="af-muted"><?= e(t('Nothing to send: the order only has digital products or services.')) ?></p>
<?php elseif ($customer === null || (int) $v['address_id'] === 0 || $q['lines'] === []) : ?>
        <p class="af-muted"><?= e(t('Choose the products and the delivery address first: the prices depend on them.')) ?></p>
<?php elseif ($v['shipping'] === []) : ?>
        <div class="af-alert af-alert-warn" role="status"><?= e(t('We cannot deliver to this destination')) ?></div>
<?php else : ?>
        <form method="post" action="<?= e($u['shipping']) ?>" data-mo-form>
            <?= $mo_token ?>
            <fieldset class="mo-choices">
                <legend class="af-label"><?= e(t('What the shop offers for these products and this address')) ?></legend>
<?php foreach ($v['shipping'] as $mo_option) : ?>
                <label class="af-check mo-choice"><input type="radio" name="carrier_id" value="<?= (int) $mo_option['id'] ?>"<?= $mo_option['selected'] ? ' checked' : '' ?>> <span><?= e($mo_option['name']) ?><?= $mo_option['delay'] !== '' ? ' <span class="af-small af-muted">' . e($mo_option['delay']) . '</span>' : '' ?> · <strong><?= e($mo_option['price']) ?></strong><?= $mo_option['pickup'] ? ' ' . Admin::badge(t('Collects in store'), 'info') : '' ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <div class="mo-actions"><button type="submit" class="af-btn af-btn-sm"><?= e(t('Use this shipping')) ?></button></div>
        </form>
<?php endif; ?>
    </div>
</section>

<section class="af-card" id="mo-coupon" aria-labelledby="mo-h-coupon">
    <div class="af-card-head"><h2 id="mo-h-coupon" tabindex="-1"><?= e(t('5. Coupon')) ?></h2></div>
    <div class="af-card-body af-stack">
<?php if (!$v['coupons_on']) : ?>
        <p class="af-muted"><?= e(t('Coupons are not available')) ?></p>
<?php else : ?>
<?php foreach ($v['coupons'] as $mo_coupon) : ?>
        <form method="post" action="<?= e($u['coupon-remove']) ?>" data-mo-form class="mo-coupon">
            <?= $mo_token ?><input type="hidden" name="code" value="<?= e((string) $mo_coupon['code']) ?>">
            <span><?= Admin::badge((string) $mo_coupon['code'], 'ok') ?> <?= e((string) $mo_coupon['label']) ?></span>
            <button type="submit" class="af-btn af-btn-sm af-btn-ghost" aria-label="<?= e(t('Remove the coupon {code}', ['code' => (string) $mo_coupon['code']])) ?>" title="<?= e(t('Remove the coupon {code}', ['code' => (string) $mo_coupon['code']])) ?>"><?= Admin::icon('x') ?></button>
        </form>
<?php endforeach; ?>
        <form method="post" action="<?= e($u['coupon']) ?>" data-mo-form>
            <?= $mo_token ?>
            <div class="af-field af-field-wide<?= $mo_error('coupon', 'code') !== '' ? ' af-field-invalid' : '' ?>"><label class="af-label" for="mo-code"><?= e(t('Coupon code (optional)')) ?></label>
                <div class="af-control mo-find-row"><input class="af-input" id="mo-code" name="code" autocomplete="off" maxlength="40" value="<?= e($mo_failed('coupon') && isset($form['values']['code']) ? (string) $form['values']['code'] : '') ?>"><button type="submit" class="af-btn"><?= e(t('Apply')) ?></button></div>
<?= $mo_error('coupon', 'code') !== '' ? '<div class="af-error" role="alert">' . e($mo_error('coupon', 'code')) . '</div>' : '' ?>
            </div>
        </form>
<?php endif; ?>
    </div>
</section>

<section class="af-card" id="mo-payment" aria-labelledby="mo-h-payment">
    <div class="af-card-head"><h2 id="mo-h-payment" tabindex="-1"><?= e(t('6. Payment')) ?></h2></div>
    <div class="af-card-body af-stack">
        <form method="post" action="<?= e($u['payment']) ?>" data-mo-form class="af-stack">
            <?= $mo_token ?>
            <fieldset class="mo-choices">
                <legend class="af-label"><?= e(t('How the order is paid')) ?></legend>
<?php foreach ($v['methods'] as $mo_method) : ?>
                <label class="af-check mo-choice"><input type="radio" name="mode" value="method:<?= e($mo_method['code']) ?>"<?= $mo_method['selected'] ? ' checked' : '' ?>> <span><?= e($mo_method['title']) ?><?= $mo_method['fee_formatted'] !== '' ? ' <span class="af-small af-muted">(' . e(t('surcharge {amount}', ['amount' => $mo_method['fee_formatted']])) . ')</span>' : '' ?></span></label>
<?php endforeach; ?>
<?php if ($v['methods'] !== []) : ?>
                <p class="af-hint"><?= e(t('The order waits for the money as in the shop, and the customer receives the instructions.')) ?></p>
<?php endif; ?>
                <label class="af-check mo-choice"><input type="radio" name="mode" value="paid"<?= (string) $state['pay'] === 'paid' ? ' checked' : '' ?>> <span><?= e(t('Already paid')) ?></span></label>
                <div class="af-field af-field-wide<?= $mo_error('payment', 'paid_with') !== '' ? ' af-field-invalid' : '' ?>"><label class="af-label" for="mo-paid-with"><?= e(t('How was it paid')) ?></label>
                    <div class="af-control"><input class="af-input" id="mo-paid-with" name="paid_with" maxlength="60" autocomplete="off" placeholder="<?= e(t('Cash, card at the counter, Bizum…')) ?>" value="<?= e($mo_failed('payment') && isset($form['values']['paid_with']) ? (string) $form['values']['paid_with'] : (string) $state['paid_with']) ?>">
<?= $mo_error('payment', 'paid_with') !== '' ? '<div class="af-error" role="alert">' . e($mo_error('payment', 'paid_with')) . '</div>' : '' ?>
                    <div class="af-hint"><?= e(t('The order is created paid, with this note.')) ?></div></div>
                </div>
                <label class="af-check mo-choice mo-off"><input type="radio" name="mode" value="link" disabled aria-describedby="mo-link-why"> <span><?= e(t('Send a payment link')) ?></span></label>
                <p class="af-hint" id="mo-link-why"><?= e($v['link_reason']) ?></p>
            </fieldset>
            <div class="mo-actions"><button type="submit" class="af-btn af-btn-sm"><?= e(t('Use this payment')) ?></button></div>
        </form>
    </div>
</section>

<section class="af-card" id="mo-confirm" aria-labelledby="mo-h-confirm">
    <div class="af-card-head"><h2 id="mo-h-confirm" tabindex="-1"><?= e(t('7. Confirm')) ?></h2></div>
    <div class="af-card-body af-stack">
<?php if ($q['missing'] !== [] || $q['problems'] !== []) : ?>
        <ul class="mo-missing">
<?php foreach ($q['missing'] as $mo_step => $mo_text) : ?>
            <li><a href="#mo-<?= e((string) $mo_step) ?>"><?= e($mo_text) ?></a></li>
<?php endforeach; ?>
<?php foreach ($q['problems'] as $mo_problem) : ?>
            <li><?= Admin::badge(t('Problem'), 'bad') ?> <?= e($mo_problem) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <form method="post" action="<?= e($u['place']) ?>" data-mo-form data-mo-place class="af-stack">
            <?= $mo_token ?><input type="hidden" name="confirm" value="<?= e((string) $q['token']) ?>">
            <div class="af-field af-field-wide"><label class="af-label" for="mo-note"><?= e(t('Internal note')) ?></label>
                <div class="af-control"><textarea class="af-textarea" id="mo-note" name="note" rows="3" maxlength="2000" aria-describedby="mo-note-hint"><?= e((string) $state['note']) ?></textarea>
                <div class="af-hint" id="mo-note-hint"><?= e(t('Only the team sees it, in the history of the order.')) ?></div></div>
            </div>
            <label class="af-check"><input type="checkbox" name="notify" value="1"<?= !empty($state['notify']) ? ' checked' : '' ?>> <span><?= e(t('Send the confirmation email to the customer')) ?></span></label>
            <div class="mo-actions">
                <button type="submit" class="af-btn af-btn-primary"<?= $q['ready'] ? '' : ' disabled' ?>><?= Admin::icon('check') ?><?= e(t('Create the order · {total}', ['total' => (string) $q['total_formatted']])) ?></button>
            </div>
        </form>
        <form method="post" action="<?= e($u['reset']) ?>" data-mo-form data-mo-confirm="<?= e(t('The order you are preparing will be emptied. Continue?')) ?>">
            <?= $mo_token ?><button type="submit" class="af-btn af-btn-sm af-btn-ghost"><?= Admin::icon('refresh') ?><?= e(t('Start again')) ?></button>
        </form>
    </div>
</section>
