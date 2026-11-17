<?php
/**
 * AIFEC — «Pedido nuevo» (Ventas › Pedidos › Pedido nuevo): los pasos a la izquierda y el resumen a la derecha.
 *
 * Sin un solo guion en la página: la hoja y el guion propios se enlazan (admin.head y admin.foot) y los textos que necesita el
 * guion van en atributos data-mo-t-*. El aviso de cada búsqueda y del total se lee en voz alta desde data-mo-live.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $v lo que prepara ManualOrderController::screen()
 */

declare(strict_types=1);

$mo_app = app();
$this->push('admin.head', '<link rel="stylesheet" href="' . e($mo_app->url()->asset('m', 'orders', 'admin-manual-order.css')) . '">');
$this->push('admin.foot', '<script src="' . e($mo_app->url()->asset('m', 'orders', 'admin-manual-order.js')) . '" defer></script>');
$mo_texts = [
    'searching' => t('Searching…'),
    'short' => t('Write at least two letters.'),
    'error' => t('It could not be done. Try again.'),
    'unavailable' => t('Not available'),
];
?>
<div class="af-grid mo" data-mo data-mo-quote="<?= e($v['urls']['quote']) ?>"<?php foreach ($mo_texts as $mo_name => $mo_text) : ?> data-mo-t-<?= e($mo_name) ?>="<?= e($mo_text) ?>"<?php endforeach; ?>>
    <div class="af-col-8 af-stack mo-steps" id="mo-steps" data-mo-steps>
<?= $this->partial('orders::admin/manual-steps', ['v' => $v]) ?>
    </div>
    <aside class="af-col-4 mo-side" aria-label="<?= e(t('Summary of the order')) ?>">
<?= $this->partial('orders::admin/manual-summary', ['q' => $v['quote']]) ?>
    </aside>
    <p class="af-sr" role="status" aria-live="polite" data-mo-live></p>
</div>
