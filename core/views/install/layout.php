<?php
/**
 * AIFEC — envoltorio del instalador: cabecera, indicador de pasos y tarjeta. Sin JavaScript.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var callable $t
 * @var string $title
 * @var int $step
 * @var string $lang
 * @var string $cssUrl
 */

$labels = [1 => $t('Language and checks'), 2 => $t('Database'), 3 => $t('Your shop'), 4 => $t('Done')];
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($t($title)) ?> · AIFEC</title>
<link rel="stylesheet" href="<?= e($cssUrl) ?>">
<style>
.af-install-steps { display: flex; flex-wrap: wrap; gap: .4rem .9rem; list-style: none; margin: 0 0 1rem; padding: 0; justify-content: center; font-size: .88rem; color: var(--muted); }
.af-install-steps li[aria-current="step"] { color: var(--ink); font-weight: 700; }
.af-install-actions { display: flex; flex-direction: row-reverse; justify-content: space-between; gap: .6rem; margin-top: 1rem; }
.af-auth .af-install-actions .af-btn { width: auto; flex: 1 1 0; margin-top: 0; }
.af-install-langs { display: flex; gap: .9rem; margin: 0 0 .9rem; }
.af-install-checks td:first-child { width: 55%; }
.af-install-radio { display: flex; gap: .6rem; align-items: flex-start; padding: .55rem 0; }
.af-install-radio input { margin-top: .3rem; }
.af-install-section { margin: 1rem 0 .2rem; }
</style>
</head>
<body>
<main class="af-auth">
    <div class="af-auth-card" style="width:min(680px,100%)">
        <div class="af-auth-brand">AIFEC</div>
        <ol class="af-install-steps" aria-label="<?= e($t('Installation steps')) ?>">
            <?php foreach ($labels as $n => $label) : ?>
                <li<?= $n === $step ? ' aria-current="step"' : '' ?>><?= e((string) $n) ?> · <?= e($label) ?></li>
            <?php endforeach; ?>
        </ol>
        <div class="af-card"><div class="af-card-body">
            <h1><?= e($t($title)) ?></h1>
            <?php if ($notice !== '') : ?>
                <div class="af-alert af-alert-warn" role="alert"><?= e($notice) ?></div>
            <?php endif; ?>
            <?php if ($error !== '') : ?>
                <div class="af-alert af-alert-bad" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?= $this->section('content') ?>
        </div></div>
    </div>
</main>
</body>
</html>
