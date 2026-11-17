<?php
/**
 * AIFEC — categorías de la portada de taller: una rejilla densa de seis a ocho por fila, cada una con su icono dibujado
 * (elegido por el nombre de la categoría) y su nombre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title      título de la sección
 * @var array<int,array<string,mixed>> $categories name, url, image
 */
?>
<section class="tl-cats" aria-labelledby="tl-cats-title">
    <h2 class="tl-shelf-title" id="tl-cats-title"><?= e((string) $title) ?></h2>
    <ul class="tl-cat-grid">
        <?php foreach ($categories as $tl_i => $tl_category) : ?>
            <li class="tl-cat">
                <a class="tl-cat-link" href="<?= e((string) $tl_category['url']) ?>">
                    <span class="tl-cat-ico"><?= taller_svg(taller_icon_key((string) $tl_category['name'], (int) $tl_i), 34) ?></span>
                    <span class="tl-cat-name"><?= e((string) $tl_category['name']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
