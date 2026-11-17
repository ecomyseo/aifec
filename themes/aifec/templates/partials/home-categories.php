<?php
/**
 * AIFEC — rejilla de las categorías de primer nivel de la tienda, en la portada del tema aifec: el nombre y el enlace de cada una
 * y, si la categoría tiene imagen, la imagen (si no, su inicial). Es el destino del botón «Comprar en esta tienda» (id «tienda»).
 * La pinta aifec_theme_home_sections() solo si el sitio tiene categorías: sin ellas, esta pieza no se llama.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{id:int,name:string,url:string,image:array<string,mixed>|null}> $categories
 */

$af_categories = isset($categories) && is_array($categories) ? $categories : [];
if ($af_categories === []) {
    return;
}
?>
<section class="af-cats" id="tienda" aria-labelledby="af-cats-title">
    <h2 class="af-cats-title" id="af-cats-title"><?= e(t('Shop by category')) ?></h2>
    <ul class="af-cats-grid">
        <?php foreach ($af_categories as $af_category) : ?>
            <li>
                <a class="af-cat" href="<?= e((string) $af_category['url']) ?>">
                    <span class="af-cat-media">
                        <?php if (!empty($af_category['image'])) : ?>
                            <?= image_tag($af_category['image'], 'card', ['class' => 'af-cat-img', 'alt' => '']) ?>
                        <?php else : ?>
                            <span class="af-cat-letter" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $af_category['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="af-cat-name"><?= e((string) $af_category['name']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
