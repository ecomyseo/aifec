<?php
/**
 * AIFEC — correo «review_request»: se pide al cliente su opinión sobre lo que compró, con un enlace por producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $name     nombre del cliente
 * @var string                         $number   número de pedido
 * @var array<int,array<string,string>> $products cada uno: name, url (el enlace de un solo uso para valorarlo)
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Thank you for your order {number}. Your opinion helps other customers decide.', ['number' => $number])) ?></p>
<ul style="margin:0 0 16px 0;padding-left:20px;">
<?php foreach ($products as $product) : ?>
<li style="margin:0 0 8px 0;"><a href="<?= e($product['url']) ?>" style="color:#2f5d8a;font-weight:bold;"><?= e(t('Review {product}', ['product' => $product['name']])) ?></a></li>
<?php endforeach; ?>
</ul>
<p style="margin:0 0 8px 0;color:#6b7280;font-size:13px;"><?= e(t('Each link can be used once.')) ?></p>
