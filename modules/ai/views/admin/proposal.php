<?php
/**
 * AIFEC — una propuesta de la IA: el texto actual y el propuesto lado a lado con las diferencias marcadas, y el formulario para
 * corregir el texto, aprobar o rechazar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $proposal   fila de ai_proposals
 * @var array<string,mixed>  $diff       Diff::render(): old, new, same (HTML ya escapado)
 * @var string               $target     nombre de lo que se cambia
 * @var string               $targetUrl  dirección de su ficha en el panel (vacía si no hay)
 * @var string               $fieldLabel el campo, en inglés
 * @var bool                 $canUse     puede aprobar, rechazar y corregir
 * @var array<string,string> $urls       dirección de cada acción
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;

$app = app();
$tz = $app->site()->timezone();
$when = static function (?string $sql) use ($app, $tz): string {
    $time = Clock::parse($sql);

    return $time > 0 ? $app->i18n()->date($time, 'datetime', $tz) : '';
};
$status = (string) $proposal['status'];
$kind = $status === 'approved' ? 'ok' : ($status === 'rejected' ? '' : 'warn');
$names = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$pending = $status === 'pending';

?><div class="af-grid">
    <div class="af-col-9">
        <section class="af-card">
            <div class="af-card-head">
                <h2><?= e(t($fieldLabel)) ?> · <?= $targetUrl !== '' ? '<a href="' . e($targetUrl) . '">' . e($target) . '</a>' : e($target) ?></h2>
                <?= Admin::badge(t($names[$status]), $kind) ?>
            </div>
            <div class="af-card-body">
                <div class="af-grid">
                    <div class="af-col-6">
                        <h3 class="af-small af-muted"><?= e(t('Current text')) ?></h3>
                        <div class="af-readonly" data-ai-current><?= (string) $proposal['current_text'] !== '' ? $diff['old'] : '<span class="af-muted">' . e(t('Empty')) . '</span>' ?></div>
                    </div>
                    <div class="af-col-6">
                        <h3 class="af-small af-muted"><?= e(t('Proposed text')) ?></h3>
                        <div class="af-readonly" data-ai-proposed><?= $diff['new'] ?></div>
                    </div>
                </div>
                <p class="af-small af-muted"><?= e(t('Words that change are marked: removed on the left, added on the right.')) ?></p>
            </div>
        </section>
<?php if ($canUse && $pending) : ?>
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Correct the text before approving')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($urls['save']) ?>" class="af-stack">
                    <?= Admin::tokenField($app) ?>
                    <textarea class="af-textarea" name="text" rows="12" aria-label="<?= e(t('Proposed text')) ?>"><?= e((string) $proposal['proposed_text']) ?></textarea>
                    <p class="af-small af-muted"><?= e(t('Summary and description are HTML. Key facts and questions are a JSON list.')) ?></p>
                    <div class="af-toolbar">
                        <button type="submit" class="af-btn"><?= e(t('Save the text')) ?></button>
                        <button type="submit" class="af-btn af-btn-primary" formaction="<?= e($urls['approve']) ?>"><?= e(t('Approve')) ?></button>
                        <button type="submit" class="af-btn af-btn-danger" formaction="<?= e($urls['reject']) ?>" data-af-confirm="<?= e(t('Reject this proposal? Nothing is changed in the shop.')) ?>"><?= e(t('Reject')) ?></button>
                    </div>
                </form>
            </div>
        </section>
<?php endif; ?>
    </div>
    <div class="af-col-3">
        <section class="af-card">
            <div class="af-card-head"><h2><?= e(t('Details')) ?></h2></div>
            <div class="af-card-body">
                <dl class="af-kv">
                    <dt><?= e(t('Language')) ?></dt><dd><?= e((string) $proposal['lang'] !== '' ? strtoupper((string) $proposal['lang']) : '—') ?></dd>
                    <dt><?= e(t('Model')) ?></dt><dd><?= e((string) $proposal['model'] !== '' ? (string) $proposal['model'] : '—') ?></dd>
                    <dt><?= e(t('Created')) ?></dt><dd><?= e($when((string) $proposal['created_at'])) ?></dd>
<?php if ($proposal['resolved_at'] !== null) : ?>
                    <dt><?= e(t('Resolved')) ?></dt><dd><?= e($when((string) $proposal['resolved_at'])) ?></dd>
<?php endif; ?>
                </dl>
            </div>
        </section>
    </div>
</div>
