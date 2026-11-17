<?php
/**
 * AIFEC — pantalla «Probar un envío»: formulario de destino y paquete, y las opciones que salen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs pestañas de «Envío»
 * @var string                $action    dirección de la pantalla
 * @var array<string,string>  $countries código => nombre
 * @var string                $country   país elegido
 * @var string                $postcode
 * @var string                $weight    gramos, tal como se escribió
 * @var string                $amount    importe de los artículos, tal como se escribió
 * @var array<string,string>  $errors    campo => mensaje
 * @var array<string,mixed>|null $result zone_id, zone_name, province, options (null si aún no se ha probado)
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><?= $this->partial('shipping::admin/tabs', ['tabs' => $tabs]) ?>
<div class="af-card">
    <div class="af-card-body">
        <form method="get" action="<?= e($action) ?>" class="af-form">
            <input type="hidden" name="run" value="1">
            <div class="af-field">
                <label class="af-label" for="shtest-country"><?= e(t('Country')) ?></label>
                <div class="af-control">
                    <select id="shtest-country" name="country" class="af-select af-input-md">
                        <?php foreach ($countries as $iso => $name) : ?>
                            <option value="<?= e($iso) ?>"<?= $iso === $country ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="af-field">
                <label class="af-label" for="shtest-postcode"><?= e(t('Postcode')) ?></label>
                <div class="af-control"><input id="shtest-postcode" name="postcode" type="text" class="af-input af-input-sm" value="<?= e($postcode) ?>" autocomplete="off"></div>
            </div>
            <div class="af-field<?= isset($errors['weight']) ? ' af-field-invalid' : '' ?>">
                <label class="af-label" for="shtest-weight"><?= e(t('Weight')) ?></label>
                <div class="af-control">
                    <div class="af-input-group"><input id="shtest-weight" name="weight" type="text" inputmode="numeric" class="af-input" value="<?= e($weight) ?>" autocomplete="off"><span class="af-input-addon">g</span></div>
                    <?php if (isset($errors['weight'])) : ?><div class="af-error" role="alert"><?= e($errors['weight']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="af-field<?= isset($errors['amount']) ? ' af-field-invalid' : '' ?>">
                <label class="af-label" for="shtest-amount"><?= e(t('Amount of the products')) ?></label>
                <div class="af-control">
                    <div class="af-input-group"><input id="shtest-amount" name="amount" type="text" inputmode="decimal" class="af-input" value="<?= e($amount) ?>" autocomplete="off"><span class="af-input-addon"><?= e((string) setting('shop.currency_symbol', '€')) ?></span></div>
                    <?php if (isset($errors['amount'])) : ?><div class="af-error" role="alert"><?= e($errors['amount']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="af-form-actions"><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('truck') ?><?= e(t('Test')) ?></button></div>
        </form>
    </div>
</div>

<?php if ($result !== null) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Result')) ?></h2></div>
        <div class="af-card-body">
            <?php if ($result['zone_id'] === null) : ?>
                <div class="af-alert af-alert-warn"><?= e(t('No zone covers this destination.')) ?></div>
            <?php else : ?>
                <dl class="af-kv">
                    <dt><?= e(t('Zone')) ?></dt>
                    <dd><?= e(t((string) $result['zone_name'])) ?></dd>
                    <?php if ((string) $result['province'] !== '') : ?>
                        <dt><?= e(t('Province')) ?></dt>
                        <dd><?= e((string) $result['province']) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($result['options'] === []) : ?>
                    <div class="af-alert af-alert-warn"><?= e(t('No carrier can send this package to this destination.')) ?></div>
                <?php else : ?>
                    <div class="af-table-wrap">
                        <table class="af-table">
                            <thead><tr>
                                <th><?= e(t('Carrier')) ?></th>
                                <th><?= e(t('Delivery time')) ?></th>
                                <th class="af-num"><?= e(t('Price')) ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($result['options'] as $option) : ?>
                                <tr>
                                    <td><strong><?= e((string) $option['name']) ?></strong><br><span class="af-mono af-small af-muted"><?= e((string) $option['code']) ?></span></td>
                                    <td><?= (string) $option['delay'] !== '' ? e((string) $option['delay']) : '<span class="af-muted">—</span>' ?></td>
                                    <td class="af-num"><?= !empty($option['free']) ? e(t('Free')) : e(money((int) $option['price'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
