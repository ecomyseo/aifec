<?php
/**
 * AIFEC — lotes de la IA: el formulario para lanzar uno (con la estimación del coste delante) y la lista de los últimos, con pausar,
 * reanudar y cancelar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $form          lo elegido: task, type, lang, category_id, field, to
 * @var array<string,mixed>|null       $estimate      Batches::estimate() o null
 * @var string                         $estimateCost  coste estimado en céntimos
 * @var string                         $estimateSpent gasto de hoy en céntimos
 * @var string                         $estimateLimit tope diario en céntimos
 * @var array<string,string>           $tasks         tarea => texto
 * @var string[]                       $types         conjuntos posibles
 * @var string[]                       $languages     idiomas de la tienda
 * @var string                         $defaultLang   idioma principal
 * @var array<int,array<string,mixed>> $categories    id y name
 * @var array<int,array<string,mixed>> $rows          lotes (ai_batches)
 * @var array<string,string>           $urls          estimate, start y base
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;
use Aifec\Modules\Ai\AiAdmin;

$app = app();
$tz = $app->site()->timezone();
$when = static function (?string $sql) use ($app, $tz): string {
    $time = Clock::parse($sql);

    return $time > 0 ? $app->i18n()->date($time, 'datetime', $tz) : '';
};
$typeNames = ['no_description' => 'Products without a description', 'category' => 'Products of a category', 'missing_lang' => 'Products without a translation'];
$statusNames = ['running' => ['Running', 'info'], 'paused' => ['Paused', 'warn'], 'done' => ['Done', 'ok'], 'cancelled' => ['Cancelled', '']];
$selected = static function (string $a, string $b): string {
    return $a === $b ? ' selected' : '';
};
$fields = ['name', 'summary', 'description', 'facts', 'faqs', 'meta_title', 'meta_description'];

?><section class="af-card">
    <div class="af-card-head"><h2><?= e(t('Launch a batch')) ?></h2></div>
    <div class="af-card-body">
        <form method="post" action="<?= e($urls['estimate']) ?>" class="af-stack" data-ai-batch-form>
            <?= Admin::tokenField($app) ?>
            <div class="af-toolbar">
                <label class="af-small af-muted"><?= e(t('Task')) ?>
                    <select class="af-select" name="task">
<?php foreach ($tasks as $key => $label) : ?>
                        <option value="<?= e($key) ?>"<?= $selected((string) $form['task'], $key) ?>><?= e(t($label)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="af-small af-muted"><?= e(t('Products')) ?>
                    <select class="af-select" name="type">
<?php foreach ($types as $type) : ?>
                        <option value="<?= e($type) ?>"<?= $selected((string) $form['type'], $type) ?>><?= e(t($typeNames[$type])) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="af-small af-muted"><?= e(t('Language')) ?>
                    <select class="af-select" name="lang">
<?php foreach ($languages as $lang) : ?>
                        <option value="<?= e($lang) ?>"<?= $selected((string) ($form['lang'] !== '' ? $form['lang'] : $defaultLang), $lang) ?>><?= e(strtoupper($lang)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
<?php if ($categories !== []) : ?>
                <label class="af-small af-muted"><?= e(t('Category')) ?>
                    <select class="af-select" name="category_id">
                        <option value="0">—</option>
<?php foreach ($categories as $category) : ?>
                        <option value="<?= (int) $category['id'] ?>"<?= (int) $form['category_id'] === (int) $category['id'] ? ' selected' : '' ?>><?= e((string) $category['name'] !== '' ? (string) $category['name'] : '#' . (int) $category['id']) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
<?php endif; ?>
            </div>
<?php if (count($languages) > 1) : ?>
            <div class="af-toolbar">
                <label class="af-small af-muted"><?= e(t('Field to translate')) ?>
                    <select class="af-select" name="field">
<?php foreach ($fields as $field) : ?>
                        <option value="<?= e($field) ?>"<?= $selected((string) $form['field'], $field) ?>><?= e(t(AiAdmin::fieldLabel($field))) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="af-small af-muted"><?= e(t('Translate into')) ?>
                    <select class="af-select" name="to">
<?php foreach ($languages as $lang) : ?>
<?php if ($lang !== $defaultLang) : ?>
                        <option value="<?= e($lang) ?>"<?= $selected((string) $form['to'], $lang) ?>><?= e(strtoupper($lang)) ?></option>
<?php endif; ?>
<?php endforeach; ?>
                    </select>
                </label>
            </div>
<?php endif; ?>
            <p><button type="submit" class="af-btn"><?= e(t('Estimate the cost')) ?></button></p>
        </form>
<?php if ($estimate !== null) : ?>
        <div class="af-alert af-alert-info" data-ai-estimate>
            <strong><?= e(tn('{n} product', '{n} products', (int) $estimate['count'])) ?></strong>
            · <?= e(t('about {cost} cents', ['cost' => $estimateCost])) ?>
            · <?= e(t('today {spent} of {limit} cents', ['spent' => $estimateSpent, 'limit' => $estimateLimit])) ?>
        </div>
<?php if ((int) $estimate['count'] > 0) : ?>
        <form method="post" action="<?= e($urls['start']) ?>" class="af-stack">
            <?= Admin::tokenField($app) ?>
            <input type="hidden" name="task" value="<?= e((string) $form['task']) ?>">
            <input type="hidden" name="type" value="<?= e((string) $form['type']) ?>">
            <input type="hidden" name="lang" value="<?= e((string) $form['lang']) ?>">
            <input type="hidden" name="category_id" value="<?= (int) $form['category_id'] ?>">
            <input type="hidden" name="field" value="<?= e((string) $form['field']) ?>">
            <input type="hidden" name="to" value="<?= e((string) $form['to']) ?>">
            <p><button type="submit" class="af-btn af-btn-primary" data-af-confirm="<?= e(t('Launch the batch? The AI will be called for every product in the selection.')) ?>"><?= e(t('Launch the batch')) ?></button></p>
        </form>
<?php endif; ?>
<?php endif; ?>
    </div>
</section>
<section class="af-card">
    <div class="af-card-head"><h2><?= e(t('Batches')) ?></h2></div>
<?php if ($rows === []) : ?>
    <div class="af-empty"><h3><?= e(t('No batches yet')) ?></h3></div>
<?php else : ?>
    <div class="af-table-wrap"><table class="af-table">
        <thead><tr>
            <th scope="col"><?= e(t('Task')) ?></th>
            <th scope="col"><?= e(t('Status')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Done')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Failed')) ?></th>
            <th scope="col" class="af-num"><?= e(t('Total')) ?></th>
            <th scope="col" class="af-hide-sm"><?= e(t('Started')) ?></th>
            <th scope="col"><span class="af-sr"><?= e(t('Actions')) ?></span></th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $row) : ?>
<?php $st = isset($statusNames[(string) $row['status']]) ? $statusNames[(string) $row['status']] : [(string) $row['status'], '']; ?>
            <tr data-ai-batch="<?= (int) $row['id'] ?>">
                <td><strong><?= e(t(AiAdmin::taskLabel((string) $row['task']))) ?></strong></td>
                <td><?= Admin::badge(t($st[0]), $st[1]) ?>
<?php if ((string) $row['reason'] !== '') : ?>
                    <div class="af-small af-muted"><?= e(t((string) $row['reason'])) ?></div>
<?php endif; ?>
                </td>
                <td class="af-num"><?= (int) $row['done'] ?></td>
                <td class="af-num"><?= (int) $row['failed'] ?></td>
                <td class="af-num"><?= (int) $row['total'] ?></td>
                <td class="af-hide-sm af-nowrap"><?= e($when((string) $row['created_at'])) ?></td>
                <td class="af-cell-actions">
<?php if ((string) $row['status'] === 'running') : ?>
                    <form method="post" action="<?= e($urls['base'] . '/' . (int) $row['id'] . '/pause') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm"><?= e(t('Pause')) ?></button></form>
<?php endif; ?>
<?php if ((string) $row['status'] === 'paused') : ?>
                    <form method="post" action="<?= e($urls['base'] . '/' . (int) $row['id'] . '/resume') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm af-btn-primary"><?= e(t('Resume')) ?></button></form>
<?php endif; ?>
<?php if (in_array((string) $row['status'], ['running', 'paused'], true)) : ?>
                    <form method="post" action="<?= e($urls['base'] . '/' . (int) $row['id'] . '/cancel') ?>" class="af-inline"><?= Admin::tokenField($app) ?><button type="submit" class="af-btn af-btn-sm af-btn-danger" data-af-confirm="<?= e(t('Cancel this batch? The proposals already made stay in the inbox.')) ?>"><?= e(t('Cancel')) ?></button></form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>
