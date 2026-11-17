<?php
/**
 * AIFEC — «Pedido nuevo»: el resumen de la derecha (debajo en pantallas estrechas).
 *
 * Todas las cifras las calcula el servidor (ManualOrderQuote, sobre el carrito de la tienda). Con JavaScript, el guion las
 * vuelve a pedir a «quote» tras cada paso y repinta estas mismas filas copiando sus textos: no suma nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $q ManualOrderQuote::of()
 */

declare(strict_types=1);

?>
<section class="af-card mo-summary" aria-labelledby="mo-h-summary">
    <div class="af-card-head"><h2 id="mo-h-summary"><?= e(t('Summary')) ?></h2></div>
    <div class="af-card-body af-stack">
        <dl class="mo-rows" data-mo-rows>
<?php foreach ($q['rows'] as $mo_row) : ?>
            <div class="mo-row mo-row-<?= e((string) $mo_row['kind']) ?>"><dt><?= e((string) $mo_row['label']) ?></dt><dd><?= e((string) $mo_row['value']) ?></dd></div>
<?php endforeach; ?>
        </dl>
        <ul class="mo-notes" data-mo-problems<?= $q['problems'] === [] ? ' hidden' : '' ?>>
<?php foreach ($q['problems'] as $mo_problem) : ?>
            <li><?= e((string) $mo_problem) ?></li>
<?php endforeach; ?>
        </ul>
        <p class="af-small af-muted"><?= e($q['prices_include_tax'] ? t('The prices include taxes.') : t('The prices do not include taxes: they are added in the tax lines.')) ?></p>
    </div>
</section>
