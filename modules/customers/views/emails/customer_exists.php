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
<p style="margin:0 0 16px 0;"><?= e(t('Someone tried to register at {shop} with this email address, but you already have a record with us: an account or an earlier purchase.', ['shop' => $shop])) ?></p>
<p style="margin:0 0 20px 0;"><a href="<?= e($reset_url) ?>" style="display:inline-block;padding:12px 22px;background-color:#2f5d8a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?= e(t('Claim my record')) ?></a></p>
<p style="margin:0 0 16px 0;"><?= e(t('If you press it, you choose a password and the record becomes your account, with the orders you already made. If you already have a password, sign in here:')) ?> <a href="<?= e($login_url) ?>" style="color:#2f5d8a;"><?= e(t('Sign in')) ?></a></p>
<p style="margin:0;"><?= e(t('If it was not you, you can ignore this message: nothing has changed in your account.')) ?></p>
