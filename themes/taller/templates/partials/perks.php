<?php
/**
 * AIFEC — fila de ventajas del servicio de taller: tres iconos con su texto (envío en 24 h, recogida en tienda, devolución).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$tl_perks = [
    ['truck', t('Delivery in 24 h'), t('Order in the morning and it leaves the same day.')],
    ['store', t('Pick up in store'), t('Collect your order at the counter when it is ready.')],
    ['returns', t('Easy returns'), t('Something does not fit the job? We will sort it out.')],
];
?>
<section class="tl-perks af-container" aria-label="<?= e(t('Our service')) ?>">
    <ul class="tl-perks-list">
        <?php foreach ($tl_perks as $tl_perk) : ?>
            <li class="tl-perk">
                <span class="tl-perk-ico"><?= taller_svg($tl_perk[0], 30) ?></span>
                <span class="tl-perk-copy"><strong><?= e($tl_perk[1]) ?></strong><span><?= e($tl_perk[2]) ?></span></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
