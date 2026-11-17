<?php
/**
 * AIFEC — página de error de reserva, para cuando el tema no trae la suya. Sin dependencias: siempre se puede pintar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int $status
 * @var string $title
 * @var string $message
 */

?>
<!doctype html>
<html lang="<?= e(app()->i18n()->lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= (int) $status ?> · <?= e(t($title)) ?></title>
<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f2eb;color:#17231e}main{max-width:32rem;padding:2rem;text-align:center}h1{font-size:1.6rem;margin:.2rem 0 .6rem}p{color:#44524b}a{color:#b94a17}</style>
</head>
<body>
<main>
    <p><?= (int) $status ?></p>
    <h1><?= e(t($title)) ?></h1>
    <?php if ($message !== '' && $message !== $title) : ?>
        <p><?= e(t($message)) ?></p>
    <?php endif; ?>
    <p><a href="<?= e(url('/')) ?>"><?= e(t('Back to the home page')) ?></a></p>
</main>
</body>
</html>
