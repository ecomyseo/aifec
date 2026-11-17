<?php
/**
 * AIFEC — correo «admin_reset»: enlace para elegir una contraseña nueva del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name    nombre de la persona
 * @var string $url     enlace para elegir la contraseña
 * @var int    $minutes minutos que vale el enlace
 * @var string $shop    nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Someone asked to choose a new password for the panel of {shop}. If it was you, use this button.', ['shop' => $shop])) ?></p>
<p style="margin:0 0 20px 0;"><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('Choose a new password')) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('If the button does not work, copy this address into your browser:')) ?></p>
<p style="margin:0 0 20px 0;word-break:break-all;"><a href="<?= e($url) ?>" style="color:#2f5d8a;"><?= e($url) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('The link works for {minutes} minutes.', ['minutes' => $minutes])) ?></p>
<p style="margin:0;"><?= e(t('If you did not ask for it, you can ignore this message: your password stays the same.')) ?></p>
