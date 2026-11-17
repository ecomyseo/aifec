<?php
/**
 * AIFEC — correo «customer_welcome»: bienvenida a una cuenta nueva con el enlace para confirmar el correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name        nombre de la persona
 * @var string $verify_url  enlace para confirmar el correo
 * @var string $account_url dirección de la zona de cliente
 * @var int    $days        días que vale el enlace
 * @var string $shop        nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Your account at {shop} is ready. Confirm your email address to finish setting it up.', ['shop' => $shop])) ?></p>
<p style="margin:0 0 20px 0;"><a href="<?= e($verify_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('Confirm my email')) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('If the button does not work, copy this address into your browser:')) ?></p>
<p style="margin:0 0 20px 0;word-break:break-all;"><a href="<?= e($verify_url) ?>" style="color:#2f5d8a;"><?= e($verify_url) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('The link works for {days} days.', ['days' => $days])) ?></p>
<p style="margin:0;"><?= e(t('You can sign in at any time from')) ?> <a href="<?= e($account_url) ?>" style="color:#2f5d8a;"><?= e($account_url) ?></a></p>
