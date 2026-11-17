<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La pieza del inicio del panel: qué hacen los visitantes con el aviso de cookies (últimos 30 días).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,int> $last
 */

declare(strict_types=1);

$total = (int) $last['total'];
$all = $total > 0 ? intdiv((int) $last['all'] * 100 + intdiv($total, 2), $total) : 0;
?><div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Cookie consent')) ?></h2></div>
    <div class="af-card-body af-stat">
<?php if ($total > 0) : ?>
        <div class="af-stat-value"><?= e($all) ?> %</div>
        <div class="af-stat-note"><?= e(t('accept all')) ?> · <?= e(tn('{n} decision', '{n} decisions', $total)) ?> · <?= e(t('Last 30 days')) ?></div>
<?php else : ?>
        <div class="af-empty"><?= e(t('No decisions yet')) ?></div>
<?php endif; ?>
        <p><a href="<?= e(app()->url()->route('admin.consent')) ?>"><?= e(t('See the report')) ?></a></p>
    </div>
</div>
