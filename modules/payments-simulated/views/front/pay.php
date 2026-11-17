<?php
/**
 * AIFEC — la página del pago simulado: el número y el total del pedido y dos botones, «correcto» y «rechazado».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page     datos de cabecera (ver Aifec\Front\Page)
 * @var string              $number   número del pedido
 * @var int                 $total    total del pedido, en céntimos
 * @var string              $currency moneda del pedido
 * @var string              $action   dirección a la que se envía la decisión
 * @var string              $token    ficha de formulario de la tienda
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Simulated payment')) ?></h1>
    <p class="af-muted"><?= e(t('This is a test: no money will be charged.')) ?></p>
    <p><?= e(t('Order {number}', ['number' => $number])) ?> &middot; <strong><?= e(money($total, $currency)) ?></strong></p>
    <form method="post" action="<?= e($action) ?>" class="af-row">
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <button type="submit" name="outcome" value="paid" class="af-btn af-btn-primary"><?= e(t('Simulate a successful payment')) ?></button>
        <button type="submit" name="outcome" value="failed" class="af-btn"><?= e(t('Simulate a declined payment')) ?></button>
    </form>
</div>
