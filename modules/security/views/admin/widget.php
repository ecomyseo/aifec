<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La pieza del inicio: la nota de la última auditoría de seguridad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,int> $last
 */

declare(strict_types=1);

?><div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Security')) ?></h2></div>
    <div class="af-card-body af-stat">
        <div class="af-stat-value"><?= e((int) $last['score']) ?> / 100</div>
        <div class="af-stat-note"><?= e(tn('{n} to fix now', '{n} to fix now', (int) $last['bad'])) ?> · <?= e(tn('{n} to review', '{n} to review', (int) $last['warn'])) ?></div>
        <p><a href="<?= e(app()->url()->plain('/admin/security')) ?>"><?= e(t('Open the audit')) ?></a></p>
    </div>
</div>
