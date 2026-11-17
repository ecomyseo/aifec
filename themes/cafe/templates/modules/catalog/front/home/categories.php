<?php
/**
 * AIFEC — sección «categorías» de la portada de Café (sustituye `catalog::front/home/categories`): las secciones en
 * estante, cada una con su foto sobre el tono leche y su nombre debajo, en una lista.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */
?>
<section class="cf-estante" aria-labelledby="af-catalog-categories-title">
    <h2 id="af-catalog-categories-title"><?= e((string) $title) ?></h2>
    <ul class="cf-estante-row">
        <?php foreach ($categories as $cf_category) : ?>
            <li>
                <a href="<?= e((string) $cf_category['url']) ?>">
                    <?= image_tag($cf_category['image'], 'card', ['alt' => '']) ?>
                    <b><?= e((string) $cf_category['name']) ?></b>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
