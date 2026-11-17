<?php
/**
 * AIFEC — gasto de la IA: lo de hoy frente al tope, la gráfica de 30 días y las tablas por día y por tarea.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $days       un día por fila, del más reciente al más antiguo
 * @var array<int,array<string,mixed>> $tasks      por tarea, de más a menos gasto
 * @var string                         $chart      SVG ya montado (HTML de confianza)
 * @var string                         $spentToday gasto de hoy, en la moneda de la tienda («0,00 €»)
 * @var string                         $limit      tope diario, en la moneda de la tienda
 * @var string                         $total      gasto de los 30 días, en la moneda de la tienda
 * @var int                            $calls      llamadas de los 30 días
 * @var bool                           $pricesMissing algún nivel en uso sin precio por millón de fichas: el coste no se puede estimar
 * @var string                         $settingsUrl   dirección de los ajustes de la IA ('' = quien mira no puede cambiarlos)
 * @var callable                       $cents      millonésimas de céntimo → importe en la moneda de la tienda
 */

declare(strict_types=1);

?><?php if ($pricesMissing) : ?>
<div class="af-alert af-alert-warn" role="status" data-af-ai-prices><?= e(t('The cost cannot be estimated: the price per million tokens is not set, so every amount here shows zero. The calls are real.')) ?><?php if ($settingsUrl !== '') : ?> <a href="<?= e($settingsUrl) ?>"><?= e(t('Set the prices')) ?></a><?php endif; ?></div>
<?php endif; ?>
<?php if ($limitReason !== '') : ?>
<div class="af-alert af-alert-warn" role="status"><?= e(t($limitReason)) ?></div>
<?php endif; ?>
<div class="af-grid">
    <div class="af-col-4"><div class="af-card"><div class="af-stat">
        <div class="af-stat-label"><?= e(t('Today')) ?></div>
        <div class="af-stat-value"><?= e($spentToday) ?><span class="af-muted" style="font-size: var(--f1)"> <?= e(t('of {limit}', ['limit' => $limit])) ?></span></div>
        <div class="af-stat-note"><?= e(t('Estimated cost against the daily cap')) ?></div>
    </div></div></div>
    <div class="af-col-4"><div class="af-card"><div class="af-stat">
        <div class="af-stat-label"><?= e(t('Calendar month (UTC)')) ?></div>
        <div class="af-stat-value"><?= e($spentMonth) ?></div>
        <div class="af-stat-note"><?= e($monthLimit) ?></div>
    </div></div></div>
    <div class="af-col-4"><div class="af-card"><div class="af-stat">
        <div class="af-stat-label"><?= e(t('Tokens today')) ?></div>
        <div class="af-stat-value"><?= e((string) $tokensToday) ?></div>
        <div class="af-stat-note"><?= e($tokenLimit > 0 ? t('of {limit}', ['limit' => (string) $tokenLimit]) : t('No token cap')) ?></div>
    </div></div></div>
    <div class="af-col-4"><div class="af-card"><div class="af-stat">
        <div class="af-stat-label"><?= e(t('Last 30 days')) ?></div>
        <div class="af-stat-value"><?= e($total) ?></div>
        <div class="af-stat-note"><?= e(tn('{n} call', '{n} calls', $calls)) ?></div>
    </div></div></div>
</div>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('Spending per day')) ?></h2></div>
    <div class="af-card-body"><?= $chart ?></div>
</section>
<div class="af-grid">
    <div class="af-col-6">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('By task')) ?></h2></div>
<?php if ($tasks === []) : ?>
            <div class="af-empty"><h3><?= e(t('No AI calls yet')) ?></h3></div>
<?php else : ?>
            <div class="af-table-wrap"><table class="af-table">
                <thead><tr><th scope="col"><?= e(t('Task')) ?></th><th scope="col" class="af-num"><?= e(t('Calls')) ?></th><th scope="col" class="af-num"><?= e(t('Failed')) ?></th><th scope="col" class="af-num"><?= e(t('Cost')) ?></th></tr></thead>
                <tbody>
<?php foreach ($tasks as $row) : ?>
                    <tr><td><?= e(isset($row['label']) ? (string) $row['label'] : (string) $row['task']) ?></td><td class="af-num"><?= e((string) (int) $row['calls']) ?></td><td class="af-num"><?= e((string) (int) $row['failed']) ?></td><td class="af-num"><?= e($cents((int) $row['cost_micro'])) ?></td></tr>
<?php endforeach; ?>
                </tbody>
            </table></div>
<?php endif; ?>
        </section>
    </div>
    <div class="af-col-6">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('By day')) ?></h2></div>
            <div class="af-table-wrap"><table class="af-table">
                <thead><tr><th scope="col"><?= e(t('Day')) ?></th><th scope="col" class="af-num"><?= e(t('Calls')) ?></th><th scope="col" class="af-num"><?= e(t('Failed')) ?></th><th scope="col" class="af-num"><?= e(t('Tokens')) ?></th><th scope="col" class="af-num"><?= e(t('Cost')) ?></th></tr></thead>
                <tbody>
<?php foreach ($days as $row) : ?>
                    <tr><td class="af-nowrap"><?= e((string) $row['day']) ?></td><td class="af-num"><?= e((string) (int) $row['calls']) ?></td><td class="af-num"><?= e((string) (int) $row['failed']) ?></td><td class="af-num"><?= e((string) ((int) $row['tokens_in'] + (int) $row['tokens_out'])) ?></td><td class="af-num"><?= e($cents((int) $row['cost_micro'])) ?></td></tr>
<?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    </div>
</div>
