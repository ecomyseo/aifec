<?php
/**
 * AIFEC — página de mantenimiento de serie (un tema puede traer la suya con el nombre «maintenance»).
 *
 * @var string $title
 * @var string $message
 * @var string $lang
 */

declare(strict_types=1);

?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> — <?= e(setting('store.name', 'AIFEC')) ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f2eb; color: #17231e; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { max-width: 34rem; padding: 2rem; text-align: center; }
        h1 { margin: 0 0 1rem; font-size: 1.75rem; }
        p { margin: 0; white-space: pre-line; }
    </style>
</head>
<body>
    <main>
        <h1><?= e($title) ?></h1>
        <p><?= e($message) ?></p>
    </main>
</body>
</html>
