<?php
/**
 * AIFEC — GM licences: correo «license_issued», las claves de licencia de un pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $name
 * @var string                         $number
 * @var string                         $account_url enlace a «Mis licencias» ('' si no hay)
 * @var array<int,array<string,mixed>> $licenses    product, key, kind, pending, domains, units, updates_until
 */

declare(strict_types=1);

$licenses = isset($licenses) && is_array($licenses) ? $licenses : [];

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('Here are the licences of your order {number}.', ['number' => $number])) ?></p>
<?php foreach ($licenses as $af_license) : ?>
<p style="margin:0 0 4px 0;font-weight:bold;"><?= e((string) $af_license['product']) ?></p>
<?php if (!empty($af_license['pending'])) : ?>
<p style="margin:0 0 16px 0;"><?= e(t('We are preparing this key. We will email you when it is ready.')) ?></p>
<?php else : ?>
<p style="margin:0 0 4px 0;font-family:Consolas,monospace;font-size:14px;word-break:break-all;"><?= e((string) $af_license['key']) ?></p>
<p style="margin:0 0 16px 0;font-size:13px;"><?= e((string) $af_license['kind'] === 'domain'
    ? ((int) $af_license['domains'] > 0 ? tn('Valid for {n} domain.', 'Valid for {n} domains.', (int) $af_license['domains']) : t('Valid for any number of domains.'))
    : t('It works without internet: paste it once in the program.')) ?><?php if ((string) $af_license['updates_until'] !== '') : ?> <?= e(t('Updates included until {date}.', ['date' => (string) $af_license['updates_until']])) ?><?php endif; ?></p>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($account_url !== '') : ?>
<p style="margin:0 0 8px 0;"><a href="<?= e($account_url) ?>" style="color:#2f5d8a;"><?= e(t('See my licences')) ?></a></p>
<?php endif; ?>
