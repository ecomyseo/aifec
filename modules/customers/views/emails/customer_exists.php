<?php
/**
 * AIFEC — correo «customer_exists»: aviso al dueño de una cuenta cuando alguien intenta registrarse con su correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $name      nombre de la persona
 * @var string $login_url dirección para entrar
 * @var string $reset_url dirección para pedir una contraseña nueva
 * @var string $shop      nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Someone tried to create an account at {shop} with this email address, but you already have one.', ['shop' => $shop])) ?></p>
<p style="margin:0 0 20px 0;"><a href="<?= e($login_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('Sign in')) ?></a></p>
<p style="margin:0 0 8px 0;"><?= e(t('If you forgot your password, you can choose a new one here:')) ?></p>
<p style="margin:0 0 20px 0;word-break:break-all;"><a href="<?= e($reset_url) ?>" style="color:#2f5d8a;"><?= e($reset_url) ?></a></p>
<p style="margin:0;"><?= e(t('If it was not you, you can ignore this message: nothing has changed in your account.')) ?></p>
