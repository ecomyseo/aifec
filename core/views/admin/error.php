<?php
/**
 * AIFEC — página de error del panel. No depende del marco: tiene que poder pintarse aunque algo vaya mal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int $status
 * @var string $title
 * @var string $message
 */

$app = app();
?>
<!doctype html>
<html lang="<?= e($app->i18n()->lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= (int) $status ?> · <?= e(t($title)) ?></title>
<link rel="stylesheet" href="<?= e($app->url()->asset('c', 'core', 'admin/admin.css')) ?>">
</head>
<body>
<main class="af-auth">
    <div class="af-auth-card">
        <div class="af-auth-brand">AIFEC</div>
        <div class="af-card"><div class="af-card-body">
            <p class="af-muted af-small" style="margin:0"><?= (int) $status ?></p>
            <h1><?= e(t($title)) ?></h1>
            <?php if ($message !== '' && $message !== $title) : ?>
                <p style="margin-top: var(--s2)"><?= e(t($message)) ?></p>
            <?php endif; ?>
            <p style="margin: var(--s4) 0 0"><a class="af-btn" href="<?= e($app->url()->plain('/admin')) ?>"><?= e(t('Back to the panel')) ?></a></p>
        </div></div>
    </div>
</main>
</body>
</html>
