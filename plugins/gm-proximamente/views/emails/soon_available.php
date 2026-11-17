<?php
/**
 * AIFEC — GM coming soon: correo «soon_available», el producto que pidió ya se puede comprar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name         correo de quien lo pidió
 * @var string $product_name
 * @var string $product_url
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello')) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('You asked us to tell you when {product} is available. It is now in the shop.', ['product' => $product_name])) ?></p>
<p style="margin:0 0 16px 0;"><a href="<?= e($product_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('See the product')) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('This is the only email we will send you about it.')) ?></p>
