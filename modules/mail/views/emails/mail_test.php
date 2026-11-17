<?php
/**
 * AIFEC — correo «mail_test»: comprueba que el correo de la tienda funciona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $shop nombre de la tienda
 */

declare(strict_types=1);

?><p style="margin:0 0 16px 0;"><?= e(t('The email of {shop} is working. This is only a test message.', ['shop' => $shop])) ?></p>
<p style="margin:0;"><?= e(t('You do not need to do anything.')) ?></p>
