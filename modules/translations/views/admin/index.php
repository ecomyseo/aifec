<?php
/**
 * AIFEC — «Traducciones»: una pestaña por idioma de la tienda que no es el principal y, en cada una, la tabla de cobertura (con
 * «Ver y editar», «Traducir lo que falta» y «Aprobar todo»), los trabajos de la IA, el glosario y el CSV de ida y vuelta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $lang         idioma de la pestaña ('' = la tienda solo tiene uno)
 * @var string                         $default      idioma principal
 * @var array<int,array<string,mixed>> $tabs         code, url, active
 * @var array<int,array<string,mixed>> $rows         Translations::coverage()
 * @var array<int,array<string,mixed>> $jobs         translation_jobs del idioma
 * @var string                         $glossary     glosario del idioma
 * @var bool                           $canEdit      permiso translations.edit
 * @var bool                           $aiInstalled  está el módulo de IA
 * @var bool                           $aiAvailable  IA lista y permisos para lanzarla
 * @var bool                           $canApproveAi puede aprobar propuestas de la bandeja de la IA
 * @var bool                           $publish      «publicar sin revisar» encendido
 * @var string                         $base         /admin/translations
 * @var string                         $settingsUrl  ajustes (idiomas)
 * @var string                         $ownUrl       textos del idioma principal
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;
use Aifec\Modules\Translations\Fill;

$app = app();
$tz = $app->site()->timezone();
$statusNames = [
    'running' => [t('Running'), 'info'],
    'paused' => [t('Paused'), 'warn'],
    'done' => [t('Done'), 'ok'],
    'cancelled' => [t('Cancelled'), ''],
];
$rowNames = [];
foreach ($rows as $row) {
    $rowNames[(string) $row['code']] = (string) $row['label'];
}

if ($lang === '') : ?>
<div class="af-card"><div class="af-card-body">
    <p><?= e(t('The shop has only one language. Add another one in the settings to translate it here.')) ?></p>
    <p><a class="af-btn" href="<?= e($settingsUrl) ?>"><?= e(t('Settings')) ?></a>
        <a class="af-btn" href="<?= e($ownUrl) ?>"><?= e(t('Change a text of the main language')) ?></a></p>
</div></div>
<?php return; endif; ?>
<nav class="af-tabs" aria-label="<?= e(t('Languages')) ?>">
<?php foreach ($tabs as $tab) : ?>
    <a class="af-tab" href="<?= e((string) $tab['url']) ?>"<?= !empty($tab['active']) ? ' aria-selected="true" aria-current="page"' : '' ?> data-tr-tab="<?= e((string) $tab['code']) ?>"><?= e(strtoupper((string) $tab['code'])) ?></a>
<?php endforeach; ?>
</nav>
<?php if ($publish && $aiInstalled) : ?>
<div class="af-alert af-alert-warn" role="status"><?= e(t('The AI translations are published without review.')) ?></div>
<?php endif; ?>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('What is translated')) ?> · <?= e(strtoupper($lang)) ?></h2></div>
    <div class="af-table-wrap"><table class="af-table" data-tr-coverage>
        <thead><tr>
            <th scope="col"><?= e(t('Texts')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Total')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Translated')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Proposed by AI')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Missing')) ?></th>
            <th scope="col"><span class="af-sr"><?= e(t('Actions')) ?></span></th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $row) : ?>
<?php
    $code = (string) $row['code'];
    $canFill = $aiAvailable && (string) $row['blocked'] === '' && (int) $row['missing'] > 0;
    $canApprove = $canEdit && (int) $row['proposed'] > 0 && (!$row['approval_needs_ai'] || $canApproveAi);
?>
            <tr data-tr-row="<?= e($code) ?>">
                <td><strong><?= e((string) $row['label']) ?></strong>
                    <div class="af-small af-muted"><?= e((string) $row['describe']) ?></div>
<?php if ((string) $row['blocked'] !== '' && (int) $row['total'] > (int) $row['translated'] + (int) $row['proposed']) : ?>
                    <div class="af-small af-muted"><?= e((string) $row['blocked']) ?></div>
<?php endif; ?>
                </td>
                <td class="af-num"><?= (int) $row['total'] ?></td>
                <td class="af-num"><?= (int) $row['translated'] ?> <span class="af-muted af-small">(<?= (int) $row['percent'] ?> %)</span></td>
                <td class="af-num" data-tr-proposed><?= (int) $row['proposed'] ?></td>
                <td class="af-num" data-tr-missing><?= (int) $row['missing'] ?>
<?php if ($canFill) : ?>
                    <div class="af-small af-muted"><?= e(t('about {tokens} tokens, {cost} cents', ['tokens' => $app->i18n()->number((float) ($row['estimate']['tokens_in'] + $row['estimate']['tokens_out'])), 'cost' => (int) $row['estimate']['cost_cents']])) ?></div>
<?php endif; ?>
                </td>
                <td class="af-cell-actions">
                    <a class="af-btn af-btn-sm" href="<?= e((string) $row['view_url']) ?>"><?= e(t('View and edit')) ?></a>
<?php if ($canFill) : ?>
                    <form method="post" action="<?= e($base . '/' . $lang . '/' . $code . '/fill') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm af-btn-primary" data-tr-fill data-af-confirm="<?= e(t('Translate with AI what is missing? It runs in the background and counts against the daily AI cap.')) ?>"><?= e(t('Translate what is missing')) ?></button></form>
<?php endif; ?>
<?php if ($canApprove) : ?>
                    <form method="post" action="<?= e($base . '/' . $lang . '/' . $code . '/approve') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm" data-tr-approve data-af-confirm="<?= e(t('Approve every AI proposal of this row? They are published in the shop.')) ?>"><?= e(t('Approve all')) ?></button></form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php if ($jobs !== []) : ?>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('AI translations')) ?></h2></div>
    <div class="af-table-wrap"><table class="af-table" data-tr-jobs>
        <thead><tr>
            <th scope="col"><?= e(t('Texts')) ?></th>
            <th scope="col"><?= e(t('Status')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Done')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Failed')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Total')) ?></th>
            <th scope="col" class="af-hide-sm"><?= e(t('Started')) ?></th>
            <th scope="col"><span class="af-sr"><?= e(t('Actions')) ?></span></th>
        </tr></thead>
        <tbody>
<?php foreach ($jobs as $job) : ?>
<?php
    $st = isset($statusNames[(string) $job['status']]) ? $statusNames[(string) $job['status']] : [(string) $job['status'], ''];
    $started = Clock::parse((string) $job['created_at']);
    $jobUrl = $base . '/jobs/' . (int) $job['id'];
?>
            <tr data-tr-job="<?= (int) $job['id'] ?>">
                <td><?= e(isset($rowNames[(string) $job['scope']]) ? $rowNames[(string) $job['scope']] : (string) $job['scope']) ?></td>
                <td><?= Admin::badge($st[0], $st[1]) ?>
<?php if ((string) $job['reason'] !== '') : ?>
                    <div class="af-small af-muted"><?= e(Fill::reason((string) $job['reason'])) ?></div>
<?php endif; ?>
                </td>
                <td class="af-num"><?= (int) $job['done'] ?></td>
                <td class="af-num"><?= (int) $job['failed'] ?></td>
                <td class="af-num"><?= (int) $job['total'] ?></td>
                <td class="af-hide-sm af-nowrap"><?= e($started > 0 ? $app->i18n()->date($started, 'datetime', $tz) : '') ?></td>
                <td class="af-cell-actions">
<?php if ($aiAvailable && (string) $job['status'] === 'running') : ?>
                    <form method="post" action="<?= e($jobUrl . '/pause') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm"><?= e(t('Pause')) ?></button></form>
<?php endif; ?>
<?php if ($aiAvailable && (string) $job['status'] === 'paused') : ?>
                    <form method="post" action="<?= e($jobUrl . '/resume') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Resume')) ?></button></form>
<?php endif; ?>
<?php if ($aiAvailable && in_array((string) $job['status'], ['running', 'paused'], true)) : ?>
                    <form method="post" action="<?= e($jobUrl . '/cancel') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm af-btn-danger" data-af-confirm="<?= e(t('Stop this translation? What the AI already proposed stays.')) ?>"><?= e(t('Stop')) ?></button></form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php if ($aiInstalled) : ?>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('Glossary')) ?> · <?= e(strtoupper($lang)) ?></h2></div>
    <div class="af-card-body">
        <p class="af-small af-muted"><?= e(t('One term per line. "cart = panier" says how a term is translated; a name alone on its line is never translated.')) ?></p>
<?php if ($canEdit) : ?>
        <form method="post" action="<?= e($base . '/glossary') ?>" class="af-stack">
            <?= Admin::tokenField($app) ?>
            <input type="hidden" name="lang" value="<?= e($lang) ?>">
            <textarea class="af-textarea" name="glossary" rows="5" maxlength="4000" aria-label="<?= e(t('Glossary')) ?>"><?= e($glossary) ?></textarea>
            <p><button type="submit" class="af-btn"><?= e(t('Save the glossary')) ?></button></p>
        </form>
<?php else : ?>
        <pre class="af-mono"><?= e($glossary) ?></pre>
<?php endif; ?>
    </div>
</section>
<?php endif; ?>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('Translate outside the shop')) ?></h2></div>
    <div class="af-card-body af-stack">
        <p class="af-small af-muted"><?= e(t('The texts of the interface in a CSV file, for a translator: columns source, translation, area and origin. Only the translation column is read back.')) ?></p>
        <p><a class="af-btn" href="<?= e($base . '/' . $lang . '/export.csv') ?>" data-tr-export><?= e(t('Download the CSV')) ?></a>
            <a class="af-btn" href="<?= e($ownUrl) ?>"><?= e(t('Change a text of the main language')) ?></a></p>
<?php if ($canEdit) : ?>
        <form method="post" action="<?= e($base . '/' . $lang . '/import') ?>" enctype="multipart/form-data" class="af-stack">
            <?= Admin::tokenField($app) ?>
            <label class="af-small af-muted"><?= e(t('CSV file')) ?> <input type="file" name="file" accept=".csv,text/csv"></label>
            <textarea class="af-textarea" name="csv" rows="3" aria-label="<?= e(t('Or paste the CSV here')) ?>"></textarea>
            <p class="af-small af-muted"><?= e(t('Or paste the CSV here')) ?></p>
            <p><button type="submit" class="af-btn"><?= e(t('Import the CSV')) ?></button></p>
        </form>
<?php endif; ?>
    </div>
</section>
