<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La zona del copiloto que se rehace sin recargar: la propuesta en curso (con su «antes → después» y sus botones) y el historial.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>|null                $proposal lo que devuelve Copilot::present(), o null
 * @var array<int,array<string,string>>         $found    resultados del buscador cuando no se entendió la orden
 * @var array<int,array<string,mixed>>          $history  propuestas anteriores de esta persona
 * @var array<string,string>                    $urls     complete, choose, apply, discard, undo, base
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
$tz = $app->site()->timezone();
$token = Admin::tokenField($app);

/**
 * Un botón que envía su propio formulario (con JavaScript, sin recargar la página).
 *
 * @param array<string,string> $hidden
 */
$button = static function (string $action, string $label, string $class, array $hidden = [], string $confirm = '') use ($token): string {
    $html = '<form class="cp-inline" method="post" action="' . e($action) . '" data-af-ajax data-af-target="#cp-stage"' . ($confirm !== '' ? ' data-af-confirm="' . e($confirm) . '"' : '') . '>' . $token;
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }

    return $html . '<button type="submit" class="af-btn ' . e($class) . '">' . e($label) . '</button></form>';
};

?>
<?php if ($proposal !== null) : ?>
<section class="af-card cp-proposal" id="cp-proposal" data-cp-status="<?= e($proposal['status']) ?>">
    <div class="af-card-head">
        <h2><?= e($proposal['summary'] !== '' ? $proposal['summary'] : $proposal['action_label']) ?></h2>
        <?= Admin::badge($proposal['status_label'], $proposal['status_kind']) ?>
    </div>
    <div class="af-card-body af-stack">
        <p class="af-small af-muted"><?= e(t('You wrote')) ?>: «<?= e($proposal['text']) ?>»<?= $proposal['source'] === 'ai' ? ' · ' . e(t('understood with the AI')) : '' ?></p>

<?php if ($proposal['status'] === 'failed' && $proposal['result'] !== '') : ?>
        <div class="af-alert af-alert-warn"><?= e($proposal['result']) ?></div>
<?php endif; ?>
<?php if (in_array($proposal['status'], ['applied', 'undone'], true) && $proposal['result'] !== '') : ?>
        <div class="af-alert af-alert-ok"><?= e($proposal['result']) ?></div>
<?php endif; ?>
<?php foreach ($proposal['errors'] as $error) : ?>
        <div class="af-alert af-alert-bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($proposal['warnings'] as $warning) : ?>
        <div class="af-alert af-alert-warn"><?= e($warning) ?></div>
<?php endforeach; ?>

<?php if ($proposal['choices'] !== []) : ?>
        <div>
            <strong><?= e(t('Choose one')) ?>:</strong>
            <div class="cp-choices">
<?php foreach ($proposal['choices'] as $choice) : ?>
                <form class="cp-inline" method="post" action="<?= e($urls['complete']) ?>" data-af-ajax data-af-target="#cp-stage">
                    <?= $token ?>
                    <input type="hidden" name="p[<?= e($choice['param']) ?>]" value="<?= e($choice['value']) ?>">
                    <button type="submit" class="af-btn af-btn-sm"><?= e($choice['label']) ?></button>
                </form>
<?php endforeach; ?>
            </div>
        </div>
<?php endif; ?>

<?php if ($proposal['alternatives'] !== []) : ?>
        <div>
            <strong><?= e(t('Did you mean')) ?>:</strong>
            <div class="cp-choices">
<?php foreach ($proposal['alternatives'] as $alternative) : ?>
                <?= $button($urls['choose'], $alternative['label'], 'af-btn-sm', ['alt' => (string) $alternative['index']]) ?>
<?php endforeach; ?>
            </div>
        </div>
<?php endif; ?>

<?php if ($proposal['changes'] !== []) : ?>
        <div class="af-table-wrap">
            <table class="af-table cp-changes">
                <thead>
                    <tr><th><?= e(t('What')) ?></th><th><?= e(t('Before')) ?></th><th><?= e(t('After')) ?></th></tr>
                </thead>
                <tbody>
<?php foreach ($proposal['changes'] as $change) : ?>
                    <tr>
                        <td><?= e($change['label']) ?></td>
                        <td class="af-muted"><?= e($change['from']) ?></td>
                        <td><strong><?= e($change['to']) ?></strong></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="af-small af-muted">
<?php if ($proposal['more'] > 0) : ?>
            <?= e(tn('and {n} more', 'and {n} more', $proposal['more'])) ?> ·
<?php endif; ?>
            <?= e(tn('{n} record in total', '{n} records in total', $proposal['count'])) ?>
        </p>
<?php endif; ?>

<?php foreach ($proposal['links'] as $link) : ?>
        <p><a class="af-btn af-btn-sm" href="<?= e($link['url']) ?>"><?= e($link['label']) ?></a></p>
<?php endforeach; ?>

<?php if ($proposal['fields'] !== [] && in_array($proposal['status'], ['proposed', 'incomplete'], true)) : ?>
        <details class="af-help"<?= $proposal['status'] === 'incomplete' ? ' open' : '' ?>>
            <summary><?= e($proposal['status'] === 'incomplete' ? t('Fill in what is missing') : t('Change the details')) ?></summary>
            <form class="cp-details" method="post" action="<?= e($urls['complete']) ?>" data-af-ajax data-af-target="#cp-stage">
                <?= $token ?>
<?php foreach ($proposal['fields'] as $field) : ?>
                <div class="cp-field<?= $field['missing'] ? ' cp-field-missing' : '' ?>">
                    <label for="cp-f-<?= e($field['name']) ?>"><?= e($field['label']) ?><?= $field['required'] ? ' *' : '' ?></label>
<?php if ($field['type'] === 'enum') : ?>
                    <select class="af-input" id="cp-f-<?= e($field['name']) ?>" name="p[<?= e($field['name']) ?>]">
<?php foreach ($field['options'] as $code => $text) : ?>
                        <option value="<?= e((string) $code) ?>"<?= (string) $field['value'] === (string) $code ? ' selected' : '' ?>><?= e($text) ?></option>
<?php endforeach; ?>
                    </select>
<?php elseif ($field['type'] === 'bool') : ?>
                    <select class="af-input" id="cp-f-<?= e($field['name']) ?>" name="p[<?= e($field['name']) ?>]">
                        <option value=""<?= $field['value'] === '' ? ' selected' : '' ?>><?= e(t('Default')) ?></option>
                        <option value="1"<?= $field['value'] === '1' ? ' selected' : '' ?>><?= e(t('Yes')) ?></option>
                        <option value="0"<?= $field['value'] === '0' ? ' selected' : '' ?>><?= e(t('No')) ?></option>
                    </select>
<?php else : ?>
                    <input class="af-input" id="cp-f-<?= e($field['name']) ?>" name="p[<?= e($field['name']) ?>]" type="text" value="<?= e($field['value']) ?>" maxlength="500">
<?php endif; ?>
                </div>
<?php endforeach; ?>
                <button type="submit" class="af-btn"><?= e(t('Update the preview')) ?></button>
            </form>
        </details>
<?php endif; ?>

<?php if ($proposal['status'] === 'failed' && $proposal['action'] === '' && $found !== []) : ?>
        <div>
            <strong><?= e(t('This is what I found')) ?>:</strong>
            <ul class="cp-found">
<?php foreach ($found as $item) : ?>
                <li><a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a> <small class="af-muted"><?= e($item['group']) ?></small></li>
<?php endforeach; ?>
            </ul>
        </div>
<?php endif; ?>

        <div class="cp-actions">
<?php if ($proposal['can_apply']) : ?>
            <?= $button($urls['apply'], t('Apply'), 'af-btn-primary') ?>
<?php endif; ?>
<?php if ($proposal['status'] === 'proposed' && !$proposal['allowed']) : ?>
            <span class="af-small af-muted"><?= e(t('You do not have permission to do this.')) ?></span>
<?php endif; ?>
<?php if (in_array($proposal['status'], ['proposed', 'incomplete'], true)) : ?>
            <?= $button($urls['discard'], t('Discard'), 'af-btn-ghost') ?>
<?php endif; ?>
<?php if ($proposal['can_undo']) : ?>
            <?= $button($urls['undo'], t('Undo'), '', [], t('Undo this change?')) ?>
<?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="af-card cp-history">
    <div class="af-card-head"><h2><?= e(t('History')) ?></h2></div>
<?php if ($history === []) : ?>
    <div class="af-empty">
        <h3><?= e(t('Nothing yet')) ?></h3>
        <p><?= e(t('The orders you write will appear here, with their result.')) ?></p>
    </div>
<?php else : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead>
                <tr><th><?= e(t('Order')) ?></th><th class="af-hide-sm"><?= e(t('Action')) ?></th><th><?= e(t('Status')) ?></th><th class="af-hide-sm"><?= e(t('When')) ?></th><th></th></tr>
            </thead>
            <tbody>
<?php foreach ($history as $row) : ?>
                <tr>
                    <td><a href="<?= e($urls['base'] . $row['id']) ?>"><?= e(mb_strimwidth($row['text'], 0, 80, '…', 'UTF-8')) ?></a></td>
                    <td class="af-hide-sm"><?= e($row['action_label'] !== '' ? $row['action_label'] : '—') ?></td>
                    <td><?= Admin::badge($row['status_label'], $row['status_kind']) ?></td>
                    <td class="af-hide-sm af-nowrap"><?= e($row['created_ts'] > 0 ? $app->i18n()->date($row['created_ts'], 'datetime', $tz) : '') ?></td>
                    <td class="af-cell-actions">
<?php if ($row['can_undo']) : ?>
                        <?= $button($urls['base'] . $row['id'] . '/undo', t('Undo'), 'af-btn-sm', [], t('Undo this change?')) ?>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>
