<?php
/**
 * AIFEC — correo «order_confirmed»: pedido recibido y pagado, con el desglose y, si hay, las descargas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                           $name      nombre del cliente
 * @var string                           $number    número de pedido
 * @var string                           $order_url enlace para ver el pedido ('' si no hay)
 * @var array<string,mixed>              $order     el pedido (OrderMails::payload)
 * @var array<int,array<string,string>>  $downloads descargas concedidas: name y url
 */

declare(strict_types=1);

$downloads = isset($downloads) && is_array($downloads) ? $downloads : [];

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Thank you for your order {number}. We have received your payment and we are getting it ready.', ['number' => $number])) ?></p>
<?= $this->partial('orders::emails/_order', ['order' => $order]) ?>
<?php if ($downloads !== []) : ?>
<p style="margin:0 0 8px 0;"><strong><?= e(t('Your downloads')) ?></strong></p>
<?php foreach ($downloads as $download) : ?>
<p style="margin:0 0 6px 0;"><a href="<?= e($download['url']) ?>" style="color:#2f5d8a;"><?= e($download['name']) ?></a></p>
<?php endforeach; ?>
<?php endif; ?>
<p style="margin:0 0 16px 0;" data-af-invoice-note><?= e(\Aifec\Modules\Orders\InvoiceNotice::mailText(app())) ?></p>
<?= $this->partial('orders::emails/_addresses', ['order' => $order]) ?>
<?php if ($order_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($order_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('View your order')) ?></a></p>
<?php endif; ?>
