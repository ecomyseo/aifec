<?php
/**
 * AIFEC — página de error de la tienda, dentro del envoltorio del tema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int    $status  código HTTP
 * @var string $title   título en inglés (se traduce aquí)
 * @var string $message detalle en inglés, o vacío
 */

$af_title = t(isset($title) ? (string) $title : 'Error');
$af_message = isset($message) ? (string) $message : '';
$this->layout('layout', ['page' => ['type' => 'error', 'title' => $af_title]]);
?>
<div class="af-container">
    <div class="af-empty af-error">
        <p class="af-error-code"><?= e(isset($status) ? (int) $status : 500) ?></p>
        <h1 class="af-title"><?= e($af_title) ?></h1>
        <?php if ($af_message !== '' && $af_message !== (isset($title) ? (string) $title : '')) : ?>
            <p class="af-muted"><?= e(t($af_message)) ?></p>
        <?php endif; ?>
        <?php if (app()->router()->has('search')) : ?>
            <form class="af-error-search" role="search" action="<?= e(route('search')) ?>" method="get"<?= hook('webmcp.form', 'search_products') ?>>
                <label class="af-sr" for="af-q-error"><?= e(t('Search')) ?></label>
                <input id="af-q-error" class="af-input" type="search" name="q" placeholder="<?= e(t('Search…')) ?>" autocomplete="off"<?= hook('webmcp.param', 'search_products', 'q') ?>>
                <button type="submit" class="af-btn"><?= e(t('Search')) ?></button>
            </form>
        <?php endif; ?>
        <p><a class="af-btn af-btn-primary" href="<?= e(url('/')) ?>"><?= e(t('Back to the home page')) ?></a></p>
    </div>
</div>
