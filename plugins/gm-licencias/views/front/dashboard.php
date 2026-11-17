<?php
/**
 * AIFEC — GM licences: el recuadro del resumen de la cuenta con el enlace a «Mis licencias».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int $count
 */

declare(strict_types=1);

?>
<section class="af-card af-stack" aria-label="<?= e(t('My licences')) ?>">
    <h2><?= e(t('My licences')) ?></h2>
    <p><?= e(tn('You have {n} licence.', 'You have {n} licences.', $count)) ?></p>
    <p><a class="af-btn af-btn-sm" href="<?= e(route('gm.licenses.account')) ?>"><?= e(t('See my licences')) ?></a></p>
</section>
