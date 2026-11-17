<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El resumen del pedido de la caja: las líneas (con su miniatura y su cantidad) y el desglose con el cupón, el envío, los
 * impuestos y el total. Es un fragmento: la caja lo pinta dos veces (plegable arriba en móvil y fijo a un lado en
 * escritorio) y, con JavaScript, lo sustituye por el que devuelve la ruta de actualización. El importe es siempre el del
 * cálculo del servidor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $c lo que prepara CheckoutController::context()
 */

declare(strict_types=1);

?>
<ul class="af-stack af-co-lines" data-af-summary-lines>
<?php foreach ($c['lines'] as $af_line) : ?>
    <li class="af-co-line">
        <span class="af-co-thumb"><?= image_tag(is_array($af_line['image']) ? $af_line['image'] : null, 'thumb', ['alt' => '']) ?><span class="af-co-qty" title="<?= e(t('Quantity')) ?>"><?= e($af_line['qty']) ?></span></span>
        <span class="af-co-line-name"><?= e($af_line['name']) ?><?php if ($af_line['variant_label'] !== '') : ?> <span class="af-muted af-small">(<?= e($af_line['variant_label']) ?>)</span><?php endif; ?></span>
        <strong><?= e($af_line['total_formatted']) ?></strong>
    </li>
<?php endforeach; ?>
</ul>
<?= $this->partial('orders::front/totals', ['v' => $c['view']]) ?>
<p class="af-muted af-small" data-af-invoice-note><?= e(\Aifec\Modules\Orders\InvoiceNotice::text(app())) ?></p>
