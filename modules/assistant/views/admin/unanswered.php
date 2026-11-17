<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Preguntas que el asistente no supo contestar, agrupadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tabs   la navegación del módulo
 * @var array<int,array<string,mixed>> $groups phrase, n, last_at, conversation_id
 */

declare(strict_types=1);

use Aifec\Kernel\Clock;

$app = app();
$tz = $app->site()->timezone();

// Las pestañas las pinta el panel (el menú las declara): esta pantalla ya no lleva su propia barra.
?>
<?php if ($groups === []) : ?>
<div class="af-empty">
    <h3><?= e(t('Nothing unanswered')) ?></h3>
    <p><?= e(t('Every question so far had an answer.')) ?></p>
</div>
<?php else : ?>
<div class="af-table-wrap">
    <table class="af-table">
        <thead>
            <tr>
                <th><?= e(t('Question')) ?></th>
                <th class="af-num"><?= e(t('Times')) ?></th>
                <th class="af-hide-sm"><?= e(t('Last time')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($groups as $group) : ?>
<?php $time = Clock::parse((string) $group['last_at']); ?>
            <tr>
                <td><?= e((string) $group['phrase']) ?></td>
                <td class="af-num"><?= (int) $group['n'] ?></td>
                <td class="af-hide-sm af-nowrap"><?= e($time > 0 ? $app->i18n()->date($time, 'datetime', $tz) : '') ?></td>
                <td class="af-cell-actions"><a class="af-btn af-btn-sm" href="<?= e($app->url()->plain('/admin/assistant/' . (int) $group['conversation_id'])) ?>"><?= e(t('Open the conversation')) ?></a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
