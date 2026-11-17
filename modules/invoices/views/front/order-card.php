<?php
/**
 * AIFEC — tarjeta con los enlaces de descarga de las facturas de un pedido (página del pedido) o de un cliente (zona de cliente).
 *
 * Huecos: order.thanks y account.dashboard.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,string>> $links cada uno: number, credit ('1' si es rectificativa), url
 */

declare(strict_types=1);

?>
<section class="af-card af-stack" aria-labelledby="af-invoices-h">
    <h2 id="af-invoices-h"><?= e(t('Invoices')) ?></h2>
    <ul class="af-stack">
<?php foreach ($links as $link) : ?>
        <li><a href="<?= e($link['url']) ?>" download><?= e($link['credit'] !== '' ? t('Credit note {number}', ['number' => $link['number']]) : t('Invoice {number}', ['number' => $link['number']])) ?></a> <span class="af-muted af-small">PDF</span></li>
<?php endforeach; ?>
    </ul>
</section>
