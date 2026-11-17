<?php
/**
 * AIFEC — trozo común de los correos de pedido: direcciones de facturación y de envío.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $order el pedido tal como lo prepara OrderMails::payload()
 */

declare(strict_types=1);

use Aifec\Geo\Address;

$blocks = [t('Billing address') => $order['billing'], t('Shipping address') => $order['shipping']];

?><?php foreach ($blocks as $title => $address) : ?>
<?php if (is_array($address) && $address !== []) : ?>
<?php $lines = Address::lines($address, app()->lang(), ['tax_id' => false, 'phone' => false]); ?>
<p style="margin:0 0 16px 0;"><strong><?= e($title) ?></strong><br>
<?= implode("<br>\n", array_map('e', $lines)) ?></p>
<?php endif; ?>
<?php endforeach; ?>
