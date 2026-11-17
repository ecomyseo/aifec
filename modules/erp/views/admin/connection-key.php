<?php
/**
 * AIFEC — ERP: la clave (o el secreto de la firma) que se enseña una sola vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $key clave en claro ('' si lo que se enseña es el secreto)
 * @var string $secret secreto de la firma en claro ('' si lo que se enseña es la clave)
 * @var array<string,mixed> $connection
 * @var string $url enlace a la conexión
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$shown = $key !== '' ? $key : $secret;
?>
<div class="af-alert af-alert-warn"><?= e($key !== '' ? t('Copy the key now. It will not be shown again.') : t('Copy the secret now. It will not be shown again.')) ?></div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e((string) $connection['name']) ?></h2></div>
    <div class="af-card-body af-stack">
        <div>
            <strong><?= e($key !== '' ? t('Key') : t('Secret of the signature')) ?></strong>
            <code class="af-mono" data-erp-secret><?= e($shown) ?></code>
            <button type="button" class="af-btn af-btn-sm af-btn-ghost" data-af-copy="<?= e($shown) ?>"><?= Admin::icon('copy') ?><?= e(t('Copy')) ?></button>
        </div>
        <?php if ($key !== '') : ?>
            <p class="af-muted af-small"><?= e(t('Send it as Authorization: Bearer <key>.')) ?></p>
        <?php else : ?>
            <p class="af-muted af-small"><?= e(t('Each message carries X-Aifec-Timestamp and X-Aifec-Signature: sha256= plus the HMAC-SHA256 of "<timestamp>.<body>" with this secret.')) ?></p>
        <?php endif; ?>
        <div><a class="af-btn af-btn-primary" href="<?= e($url) ?>"><?= e(t('Continue')) ?></a></div>
    </div>
</div>
