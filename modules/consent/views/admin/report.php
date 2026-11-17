<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Informe del consentimiento: los últimos 30 días y la tabla por semanas (aceptan, rechazan, configuran).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $weeks
 * @var array<string,int> $last
 */

declare(strict_types=1);

$pct = static function (int $part, int $total): string {
    return $total > 0 ? (string) intdiv($part * 100 + intdiv($total, 2), $total) . ' %' : '–';
};
?><div class="af-grid">
    <div class="af-col-4"><div class="af-card"><div class="af-card-body af-stat"><div class="af-stat-label"><?= e(t('Accept all')) ?></div><div class="af-stat-value"><?= e($pct((int) $last['all'], (int) $last['total'])) ?></div><div class="af-stat-note"><?= e(t('Last 30 days')) ?></div></div></div></div>
    <div class="af-col-4"><div class="af-card"><div class="af-card-body af-stat"><div class="af-stat-label"><?= e(t('Reject all')) ?></div><div class="af-stat-value"><?= e($pct((int) $last['none'], (int) $last['total'])) ?></div><div class="af-stat-note"><?= e(t('Last 30 days')) ?></div></div></div></div>
    <div class="af-col-4"><div class="af-card"><div class="af-card-body af-stat"><div class="af-stat-label"><?= e(t('Configured')) ?></div><div class="af-stat-value"><?= e($pct((int) $last['custom'], (int) $last['total'])) ?></div><div class="af-stat-note"><?= e(tn('{n} decision', '{n} decisions', (int) $last['total'])) ?></div></div></div></div>
</div>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('By week')) ?></h2></div>
    <div class="af-card-body">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr><th scope="col"><?= e(t('Week')) ?></th><th scope="col"><?= e(t('Starts')) ?></th><th class="af-num" scope="col"><?= e(t('Decisions')) ?></th><th class="af-num" scope="col"><?= e(t('Accept all')) ?></th><th class="af-num" scope="col"><?= e(t('Reject all')) ?></th><th class="af-num" scope="col"><?= e(t('Configured')) ?></th></tr></thead>
                <tbody>
<?php foreach ($weeks as $week) : ?>
                    <tr>
                        <td><?= e($week['week']) ?></td>
                        <td><?= e($week['start']) ?></td>
                        <td class="af-num"><?= e($week['total']) ?></td>
                        <td class="af-num"><?= e($pct((int) $week['all'], (int) $week['total'])) ?></td>
                        <td class="af-num"><?= e($pct((int) $week['none'], (int) $week['total'])) ?></td>
                        <td class="af-num"><?= e($pct((int) $week['custom'], (int) $week['total'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
