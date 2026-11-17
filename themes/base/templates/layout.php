<?php
/**
 * AIFEC — envoltorio de todas las páginas del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page    datos de cabecera de la página (ver Aifec\Front\Page)
 * @var bool                $filters true si la página tiene filtros funcionales (activa el botón de la barra inferior)
 */

$af_page = isset($page) && is_array($page) ? page_meta($page) : page_meta([]);
$af_filters = !empty($filters);
$af_bar = base_theme_can('search') || base_theme_can('cart') || base_theme_can('account') || $af_filters;
$af_locale = str_replace('_', '-', app()->i18n()->locale());
$af_cart_url = app()->router()->has('cart.summary') ? route('cart.summary') : '';
$af_account_url = app()->router()->has('account.status') ? route('account.status') : '';
$af_classes = trim('af-type-' . $af_page['type'] . ($af_bar ? ' af-has-bar' : '') . ($af_page['body_class'] !== '' ? ' ' . $af_page['body_class'] : ''));
?>
<!doctype html>
<html lang="<?= e(app()->lang()) ?>" data-currency="<?= e(setting('shop.currency', 'EUR')) ?>" data-locale="<?= e($af_locale) ?>" data-cart-cookie="<?= e(app()->cookieName('ck')) ?>"<?= $af_cart_url !== '' ? ' data-cart-url="' . e($af_cart_url) . '"' : '' ?><?= $af_account_url !== '' ? ' data-account-cookie="' . e(app()->cookieName('u')) . '" data-account-url="' . e($af_account_url) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= page_head($af_page) ?>
<?= theme_styles() ?>
<?= $this->stack('styles') ?>
<?= theme_scripts() ?>
</head>
<body class="<?= e($af_classes) ?>">
<?= hook('body.start') ?>
<a class="af-skip" href="#main"><?= e(t('Skip to content')) ?></a>
<?= hook('header.before') ?>
<?= $this->partial('partials/header') ?>
<?= hook('header.after') ?>
<main id="main" class="af-main" tabindex="-1">
<?= $this->section('content') ?>
</main>
<?= hook('footer.before') ?>
<?= $this->partial('partials/footer') ?>
<?= hook('footer.after') ?>
<?php if ($af_bar) : ?>
<?= $this->partial('partials/mobile-bar', ['filters' => $af_filters]) ?>
<?php endif; ?>
<?= $this->partial('partials/drawers') ?>
<?= $this->stack('scripts') ?>
<?= hook('body.end') ?>
</body>
</html>
