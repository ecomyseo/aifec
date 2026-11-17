<?php
/**
 * AIFEC — página para escribir una opinión: la del enlace del correo y la que enseña los errores del formulario de la ficha.
 * Es privada: no se indexa y no se guarda en ninguna caché.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>      $page
 * @var array<string,mixed>|null $product ProductData (null si el enlace no vale)
 * @var string                   $action
 * @var array<string,string>     $values
 * @var array<string,string>     $errors
 * @var string                   $message error general
 * @var bool                     $locked el correo no se pide
 * @var bool                     $invalid el enlace no vale o ya se usó
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
<?php if ($invalid || $product === null) : ?>
    <h1 class="af-title"><?= e(t('Review link not valid')) ?></h1>
    <p class="af-alert af-alert-bad" role="alert"><?= e(t('This review link is not valid or has already been used.')) ?></p>
    <p><a class="af-btn" href="<?= e(url('/')) ?>"><?= e(t('Back to the shop')) ?></a></p>
<?php else : ?>
    <h1 class="af-title"><?= e(t('Review {product}', ['product' => (string) $product['name']])) ?></h1>
<?php if ($message !== '') : ?>
    <p class="af-alert af-alert-bad" role="alert"><?= e($message) ?></p>
<?php endif; ?>
    <?= \Aifec\Front\ThemeVars::styleTag(app(), 'm', 'reviews', 'reviews.css') ?>

    <div class="af-rv"><?= $this->partial('reviews::front/_form', ['action' => $action, 'values' => $values, 'errors' => $errors, 'locked' => $locked]) ?></div>
    <p><a href="<?= e((string) $product['url']) ?>"><?= e(t('Back to the product')) ?></a></p>
<?php endif; ?>
</div>
