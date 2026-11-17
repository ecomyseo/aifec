<?php
/**
 * AIFEC — propuesta de traducción de los textos de un correo: el original y la propuesta de cada idioma, lado a lado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                          $key     código de la plantilla
 * @var string                          $from    idioma de los textos de partida
 * @var array<string,string>            $source  los textos guardados del idioma de partida
 * @var array<int,array<string,mixed>>  $rows    un elemento por idioma: code, texts (la propuesta), saved, own, warnings, error
 * @var string                          $action  dirección del guardado
 * @var string                          $backUrl vuelta a la pantalla de edición
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$af_fields = ['subject' => t('Subject'), 'greeting' => t('Greeting'), 'farewell' => t('Sign-off')];
?><form method="post" action="<?= e($action) ?>" class="af-form" novalidate data-mail-translate>
    <?= Admin::tokenField(app()) ?>
    <input type="hidden" name="lang" value="<?= e($from) ?>">
    <div class="af-alert af-alert-info" role="status"><?= e(t('AI proposal: check it and save.')) ?> <?= e(t('Only the languages you tick are saved. A language that already has its own texts starts unticked.')) ?></div>
    <?php foreach ($rows as $row) : ?>
        <?php
        $code = (string) $row['code'];
        $has = $row['texts']['subject'] !== '' || $row['texts']['greeting'] !== '' || $row['texts']['farewell'] !== '';
        ?>
        <div class="af-card" data-mail-translate-lang="<?= e($code) ?>">
            <div class="af-card-head">
                <h2><?= e(strtoupper($code)) ?></h2>
                <?php if ($has) : ?>
                    <label class="af-small"><input type="checkbox" name="apply[<?= e($code) ?>]" value="1"<?= $row['own'] ? '' : ' checked' ?>> <?= e(t('Save this translation')) ?></label>
                <?php endif; ?>
            </div>
            <div class="af-card-body">
                <?php if ($row['error'] !== '') : ?>
                    <div class="af-alert af-alert-bad" role="status"><?= e((string) $row['error']) ?></div>
                <?php endif; ?>
                <?php foreach ($row['warnings'] as $warning) : ?>
                    <div class="af-alert af-alert-warn" role="status"><?= e((string) $warning) ?></div>
                <?php endforeach; ?>
                <?php if ($row['own'] && $has) : ?>
                    <p class="af-muted af-small"><?= e(t('This language already has its own texts. They are replaced only if you tick it.')) ?></p>
                <?php endif; ?>
                <?php if ($has) : ?>
                    <div class="af-table-wrap">
                        <table class="af-table">
                            <thead><tr>
                                <th></th>
                                <th><?= e(t('Original')) ?> (<?= e(strtoupper($from)) ?>)</th>
                                <th><?= e(t('Proposal')) ?> (<?= e(strtoupper($code)) ?>)</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($af_fields as $name => $label) : ?>
                                <tr>
                                    <td><?= e($label) ?></td>
                                    <td><?= nl2br(e((string) $source[$name]), false) ?></td>
                                    <td>
                                        <?php if ($name === 'subject') : ?>
                                            <input class="af-input" type="text" name="t[<?= e($code) ?>][<?= e($name) ?>]" value="<?= e((string) $row['texts'][$name]) ?>" maxlength="191" autocomplete="off">
                                        <?php else : ?>
                                            <textarea class="af-textarea" name="t[<?= e($code) ?>][<?= e($name) ?>]" rows="3" maxlength="2000"><?= e((string) $row['texts'][$name]) ?></textarea>
                                        <?php endif; ?>
                                        <?php if ((string) $row['saved'][$name] !== '') : ?>
                                            <div class="af-hint"><?= e(t('Now:')) ?> <span class="af-mono"><?= e((string) $row['saved'][$name]) ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="af-form-actions">
        <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save the ticked languages')) ?></button>
        <a class="af-btn" href="<?= e($backUrl) ?>"><?= e(t('Back')) ?></a>
    </div>
</form>
