<?php
/**
 * AIFEC — pestaña «Zonas y tarifas»: las zonas y todas las tarifas en una tabla, con el precio editable en su fila y, al lado,
 * lo que pagaría un pedido de 1 kg a Madrid.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs
 * @var array<int,array<string,mixed>> $zones filas de shipping_zones, con rates_count y where_html (ya escapado)
 * @var array<int,array<string,mixed>> $rates id, carrier, zone, basis, range, price, field (lo que se ve en el campo)
 * @var bool   $canEdit puede cambiar tarifas
 * @var string $preview tarjeta de muestra, ya pintada
 * @var string $currency símbolo de la moneda
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><?= $this->partial('shipping::admin/tabs', ['tabs' => $tabs]) ?>
<div class="af-grid">
    <div class="af-col-8 af-stack">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Shipping zones')) ?></h2></div>
            <?php if ($zones === []) : ?>
                <div class="af-empty"><h3><?= e(t('No zones yet')) ?></h3></div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('Name')) ?></th>
                            <th scope="col"><?= e(t('Where')) ?></th>
                            <th scope="col" class="af-num af-hide-sm"><?= e(t('Rates')) ?></th>
                            <th scope="col"><?= e(t('Active')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($zones as $zone) : ?>
                            <tr>
                                <td><a href="<?= e(Admin::resourceUrl(app(), 'shipping_zones', (int) $zone['id'])) ?>"><strong><?= e(t((string) $zone['name'])) ?></strong></a></td>
                                <td><?= $zone['where_html'] ?></td>
                                <td class="af-num af-hide-sm"><?= (int) $zone['rates_count'] ?></td>
                                <td><?= (int) $zone['active'] === 1 ? Admin::badge(t('Yes'), 'ok') : Admin::badge(t('No'), 'warn') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Rates')) ?></h2></div>
            <?php if ($rates === []) : ?>
                <div class="af-empty">
                    <h3><?= e(t('No rates yet')) ?></h3>
                    <p><?= e(t('Open a carrier with a rate table and add its rates.')) ?></p>
                </div>
            <?php else : ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('Carrier')) ?></th>
                            <th scope="col"><?= e(t('Zone')) ?></th>
                            <th scope="col"><?= e(t('Based on')) ?></th>
                            <th scope="col"><?= e(t('Price')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rates as $rate) : ?>
                            <tr id="rate-<?= (int) $rate['id'] ?>">
                                <td><strong><?= e((string) $rate['carrier']) ?></strong></td>
                                <td><?= e(t((string) $rate['zone'])) ?></td>
                                <td>
                                    <?= e($rate['basis'] === 'price' ? t('Order amount') : t('Weight (g)')) ?>
                                    <br><span class="af-small af-muted"><?= e((string) $rate['range']) ?></span>
                                </td>
                                <td class="af-nowrap">
                                <?php if ($canEdit) : ?>
                                    <form method="post" action="<?= e(app()->url()->plain('/admin/shipping/rates/' . (int) $rate['id'])) ?>" class="af-inline" data-af-ajax data-af-target="#shpreview">
                                        <?= Admin::tokenField(app()) ?>
                                        <input class="af-input af-input-sm" type="text" inputmode="decimal" name="price" value="<?= e((string) $rate['field']) ?>" autocomplete="off" aria-label="<?= e(t('Price of this rate')) ?>">
                                        <span class="af-small af-muted"><?= e($currency) ?></span>
                                        <button type="submit" name="op" value="check" class="af-btn af-btn-sm"><?= e(t('See the effect')) ?></button>
                                        <button type="submit" name="op" value="save" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Save')) ?></button>
                                    </form>
                                <?php else : ?>
                                    <?= e(money((int) $rate['price'])) ?>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="af-col-4">
        <div id="shpreview"><?= $preview ?></div>
    </div>
</div>
