<?php
/**
 * AIFEC — pestañas de «Envío»: cada una es una dirección propia y enseña solo su contenido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{key:string,label:string,url:string,active:bool}> $tabs
 */

declare(strict_types=1);

?><nav class="af-tabs" role="tablist" aria-label="<?= e(t('Shipping')) ?>">
    <?php foreach ($tabs as $tab) : ?>
        <a class="af-tab" role="tab" href="<?= e($tab['url']) ?>" aria-selected="<?= $tab['active'] ? 'true' : 'false' ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
