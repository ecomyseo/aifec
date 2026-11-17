<?php
/**
 * AIFEC — instalador, pantalla 1: idioma y comprobaciones del servidor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var callable $t
 * @var string $lang
 * @var array<string,string> $langs
 * @var string $action
 * @var array<int,array{id:string,label:string,ok:bool,required:bool,detail:string,impact:string}> $checks
 * @var bool $blocked
 */

$this->layout('install::layout');
?>
<p class="af-muted"><?= e($t('Pick your language and check that this server is ready.')) ?></p>

<p class="af-install-langs af-small">
    <?php foreach ($langs as $code => $name) : ?>
        <?php if ($code === $lang) : ?>
            <strong><?= e($name) ?></strong>
        <?php else : ?>
            <a href="<?= e($action . '?lang=' . $code) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"><?= e($name) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</p>

<h2 class="af-install-section"><?= e($t('Requirements')) ?></h2>
<div class="af-table-wrap">
    <table class="af-table af-install-checks">
        <thead><tr><th><?= e($t('Check')) ?></th><th><?= e($t('Result')) ?></th><th><?= e($t('Detail')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($checks as $check) : ?>
            <tr data-check="<?= e($check['id']) ?>">
                <td><?= e($t($check['label'])) ?></td>
                <td>
                    <?php if ($check['ok']) : ?>
                        <span class="af-badge af-badge-ok">✓ <?= e($t('OK')) ?></span>
                    <?php elseif ($check['required']) : ?>
                        <span class="af-badge af-badge-bad">✗ <?= e($t('Required')) ?></span>
                    <?php else : ?>
                        <span class="af-badge af-badge-warn">! <?= e($t('Recommended')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="af-muted af-small"><?= e($t($check['detail'])) ?><?php if (!$check['ok']) : ?> — <?= e($t($check['impact'])) ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($blocked) : ?>
    <div class="af-alert af-alert-bad" role="alert" style="margin-top:1rem"><?= e($t('Fix the items marked ✗ and reload this page.')) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" novalidate>
    <input type="hidden" name="step" value="1">
    <h2 class="af-install-section"><?= e($t('Language')) ?></h2>
    <?php foreach ($langs as $code => $name) : ?>
        <label class="af-install-radio"><input type="radio" name="lang" value="<?= e($code) ?>"<?= $code === $lang ? ' checked' : '' ?>><span><?= e($name) ?></span></label>
    <?php endforeach; ?>
    <div class="af-install-actions">
        <button type="submit" class="af-btn af-btn-primary"<?= $blocked ? ' disabled aria-disabled="true"' : '' ?>><?= e($t('Continue')) ?></button>
    </div>
</form>
