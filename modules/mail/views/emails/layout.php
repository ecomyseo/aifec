<?php
/**
 * AIFEC — marco de todos los correos: tabla de 600 px con estilos en línea (los clientes de correo ignoran las hojas de estilo).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $content   cuerpo ya pintado (HTML de confianza: lo han escapado las plantillas)
 * @var string $shop      nombre de la tienda
 * @var string $shopUrl   dirección de la tienda
 * @var string $subject   asunto
 * @var string $locale    idioma del destinatario
 * @var string $legalName razón social
 * @var string $address   dirección postal
 */

declare(strict_types=1);

?><!DOCTYPE html>
<html lang="<?= e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:6px;">
<tr><td style="padding:20px 32px;border-bottom:1px solid #e5e7eb;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;color:#1f2933;">
<a href="<?= e($shopUrl) ?>" style="color:#1f2933;text-decoration:none;"><?= e($shop) ?></a>
</td></tr>
<tr><td style="padding:28px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
<?= $content ?>
</td></tr>
<tr><td style="padding:18px 32px;border-top:1px solid #e5e7eb;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">
<?php if ($legalName !== '') : ?>
<strong><?= e($legalName) ?></strong><br>
<?php endif; ?>
<?php if ($address !== '') : ?>
<?= nl2br(e($address)) ?>
<?php endif; ?>
<?php if ($legalName === '' && $address === '') : ?>
<?= e($shop) ?>
<?php endif; ?>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
