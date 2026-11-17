<?php
/**
 * AIFEC — franja de marcas de la portada de taller: una fila de logotipos (o del nombre si la marca no tiene imagen).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $brands name, url, image
 */

$tl_brands = isset($brands) && is_array($brands) ? $brands : [];
if ($tl_brands === []) {
    return;
}
?>
<section class="tl-brands af-container" aria-labelledby="tl-brands-title">
    <h2 class="tl-brands-title" id="tl-brands-title"><?= e(t('Our brands')) ?></h2>
    <ul class="tl-brands-list">
        <?php foreach ($tl_brands as $tl_brand) : ?>
            <li class="tl-brand-item">
                <a class="tl-brand-link" href="<?= e((string) $tl_brand['url']) ?>">
                    <?php if (is_array($tl_brand['image']) && !empty($tl_brand['image']['url'])) : ?>
                        <?= image_tag($tl_brand['image'], 'thumb', ['class' => 'tl-brand-img', 'alt' => (string) $tl_brand['name']]) ?>
                    <?php else : ?>
                        <span class="tl-brand-name"><?= e((string) $tl_brand['name']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
