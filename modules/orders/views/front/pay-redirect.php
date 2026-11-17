<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página intermedia de una forma de pago que pide enviar un formulario a su pasarela: lleva los campos ocultos y se
 * envía sola (pay-redirect.js). Si el navegador no ejecuta JavaScript, el botón de abajo hace lo mismo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $page
 * @var string               $action dirección de la pasarela
 * @var array<string,string> $fields campos ocultos del formulario
 * @var string               $number número del pedido
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
$this->push('scripts', '<script src="' . e(app()->url()->asset('m', 'orders', 'pay-redirect.js')) . '" defer></script>');
?>
<div class="af-container af-section">
    <div class="af-card af-stack">
        <h1 class="af-title"><?= e(t('Redirecting to the payment')) ?></h1>
        <p class="af-muted"><?= e(t('We are taking you to the payment page for order {number}. If nothing happens, press the button.', ['number' => $number])) ?></p>
        <form id="af-pay-form" method="post" action="<?= e($action) ?>" data-af-autopost>
<?php foreach ($fields as $af_name => $af_value) : ?>
            <input type="hidden" name="<?= e((string) $af_name) ?>" value="<?= e((string) $af_value) ?>">
<?php endforeach; ?>
            <button type="submit" class="af-btn af-btn-primary af-btn-lg"><?= e(t('Continue to the payment')) ?></button>
        </form>
    </div>
</div>
