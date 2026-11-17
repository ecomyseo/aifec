<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Conversaciones del asistente: la navegación del módulo y la tabla (sin datos personales).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs  cada una: label, url, count, active
 * @var string                         $table tabla ya pintada (HTML de confianza)
 * @var array{n:int,median:int,p90:int} $timing lo que tardan las respuestas con IA (últimos 7 días), en milisegundos
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

?>
<div class="af-actions" data-assistant-settings-links>
<?php if (app()->auth()->can('assistant.edit')) : ?>
    <a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/settings/assistant')) ?>"><?= e(t('Configure the assistant')) ?></a>
<?php endif; ?>
<?php if (app()->auth()->can('ai.edit') && app()->has('ai')) : ?>
    <a class="af-btn af-btn-sm af-btn-ghost" href="<?= e(app()->url()->plain('/admin/settings/ai')) ?>#tab=aimodels"><?= e(t('AI provider, models and limits')) ?></a>
    <a class="af-btn af-btn-sm af-btn-ghost" href="<?= e(app()->url()->plain('/admin/ai/connection')) ?>"><?= e(t('Test the AI connection')) ?></a>
<?php endif; ?>
</div>
<?php

// Las pestañas las pinta el panel (el menú las declara): aquí solo el aviso de lo que espera en la otra pestaña.
foreach ($tabs as $tab) :
    if (empty($tab['active']) && (int) $tab['count'] > 0) : ?>
<p data-assistant-pending><a href="<?= e($tab['url']) ?>"><?= e($tab['label']) ?></a> <?= Admin::badge((string) (int) $tab['count'], 'warn') ?></p>
<?php endif;
endforeach;
if ((int) $timing['n'] > 0) : ?>
<p class="af-muted" data-assistant-timing><?= e(t('Response time, last 7 days: median {median}, 90th percentile {p90} ({n} answers with AI).', [
    'median' => app()->i18n()->number($timing['median'] / 1000, 1) . ' s',
    'p90' => app()->i18n()->number($timing['p90'] / 1000, 1) . ' s',
    'n' => (int) $timing['n'],
])) ?></p>
<?php endif; ?>
<?= $table ?>
