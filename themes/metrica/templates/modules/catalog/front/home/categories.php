<?php
/**
 * AIFEC — la sección «Categorías» de la portada en Métrica: el directorio de secciones. Una fila por categoría de primer
 * nivel con imagen: la foto cuadrada pequeña, el nombre en grande y una flecha; toda la fila es el enlace.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */
?>
<section class="mt-shelf" aria-labelledby="mt-directory-title">
    <h2 id="mt-directory-title"><?= e((string) $title) ?></h2>
    <ul class="mt-directory">
        <?php foreach ($categories as $mt_category) : ?>
            <li>
                <a href="<?= e((string) $mt_category['url']) ?>">
                    <?= image_tag($mt_category['image'], 'thumb', ['alt' => '']) ?>
                    <span><?= e((string) $mt_category['name']) ?></span>
                    <?= metrica_svg('arrow', 22) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
