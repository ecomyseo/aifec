<?php
/**
 * AIFEC — GM legal texts per product: la tarjeta de la ficha del pedido con la respuesta a la renuncia al desistimiento.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool   $waiver
 * @var string $version
 * @var string $at
 * @var string $mailed
 */

declare(strict_types=1);

?>
<div class="af-card">
    <div class="af-card-head"><strong><?= e(t('Withdrawal waiver')) ?></strong></div>
    <div class="af-card-body">
        <p><?= e($waiver ? t('The customer asked for the download to start now and waived the right of withdrawal.') : t('The customer did not waive the right of withdrawal.')) ?></p>
        <dl class="af-kv">
            <dt><?= e(t('Answered on')) ?></dt><dd><?= e($at) ?></dd>
<?php if ($version !== '') : ?>
            <dt><?= e(t('Version of the texts')) ?></dt><dd><?= e($version) ?></dd>
<?php endif; ?>
<?php if ($mailed !== '') : ?>
            <dt><?= e(t('Legal email sent on')) ?></dt><dd><?= e($mailed) ?></dd>
<?php endif; ?>
        </dl>
    </div>
</div>
