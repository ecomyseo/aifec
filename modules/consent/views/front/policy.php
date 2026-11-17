<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * /cookies cuando la tienda no ha publicado su política de cookies: qué cookies pone de verdad (la misma tabla del bloque
 * «consent-cookies»), para qué y cuánto duran, y el botón para cambiar la elección.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var string              $table  la tabla de cookies, ya pintada (HTML de confianza)
 * @var string              $button el botón para cambiar la elección (HTML de confianza)
 * @var string              $shop
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <article class="af-page">
        <h1 class="af-title"><?= e(t('Cookie policy')) ?></h1>
        <div class="af-prose">
            <p><?= e(t('Cookies are small files that a website stores in your browser to remember information about your visit.')) ?></p>
            <p><?= e(t('{name} uses the technical cookies that are needed to keep your cart and your session. Analytics and advertising cookies are only used if you accept them.', ['name' => $shop])) ?></p>
            <h2><?= e(t('Which cookies we use')) ?></h2>
            <?= $table ?>
            <h2><?= e(t('How to manage them')) ?></h2>
            <p><?= e(t('Rejecting is as easy as accepting, and you can change your choice whenever you want.')) ?></p>
            <p><?= $button ?></p>
        </div>
    </article>
</div>
