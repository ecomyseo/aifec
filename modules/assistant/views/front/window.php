<?php
/** AIFEC — vista independiente del mismo asistente de la tienda. */
declare(strict_types=1);
?><!doctype html>
<html lang="<?= e(app()->lang()) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($assistant->name() ?: t('Shop assistant')) ?></title>
</head>
<body data-af-chat-window>
<?= app()->view()->render('assistant::front/widget', ['assistant' => $assistant]) ?>
</body>
</html>
