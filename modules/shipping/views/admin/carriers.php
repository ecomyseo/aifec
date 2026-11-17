<?php
/**
 * AIFEC — pestaña «Transportistas»: los transportistas de la tienda y lo que cobra o tarda cada uno.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs
 * @var array<int,array<string,mixed>> $carriers filas de carriers, con handler_label
 * @var bool $canEdit
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?><?= $this->partial('shipping::admin/tabs', ['tabs' => $tabs]) ?>
<div class="af-card">
<?php if ($carriers === []) : ?>
    <div class="af-empty">
        <h3><?= e(t('No carriers yet')) ?></h3>
        <p><?= e(t('Add the first carrier to offer shipping in the shop.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th scope="col"><?= e(t('Name')) ?></th>
                <th scope="col"><?= e(t('Calculation')) ?></th>
                <th scope="col" class="af-hide-sm"><?= e(t('Delivery time')) ?></th>
                <th scope="col" class="af-hide-sm"><?= e(t('Free from')) ?></th>
                <th scope="col"><?= e(t('Active')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($carriers as $carrier) : ?>
                <tr>
                    <td>
                        <a href="<?= e(Admin::resourceUrl(app(), 'carriers', (int) $carrier['id'])) ?>"><strong><?= e((string) $carrier['name']) ?></strong></a>
                        <br><span class="af-mono af-small af-muted"><?= e((string) $carrier['code']) ?></span>
                    </td>
                    <td><?= e((string) $carrier['handler_label']) ?></td>
                    <td class="af-hide-sm"><?= (string) $carrier['delay'] !== '' ? e((string) $carrier['delay']) : \Aifec\Modules\Shipping\ShippingAdmin::notStated(app(), (int) $carrier['id']) ?></td>
                    <td class="af-hide-sm"><?= $carrier['free_from'] !== null && $carrier['free_from'] !== '' ? e(money((int) $carrier['free_from'])) : \Aifec\Modules\Shipping\ShippingAdmin::notStated(app(), (int) $carrier['id']) ?></td>
                    <td><?= (int) $carrier['active'] === 1 ? Admin::badge(t('Yes'), 'ok') : Admin::badge(t('No'), 'warn') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
