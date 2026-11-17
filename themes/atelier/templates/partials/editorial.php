<?php
/**
 * AIFEC — bloque editorial partido de la portada de atelier: imagen a un lado y texto al otro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>|null $image imagen de la biblioteca; sin ella, el dibujo del tema
 * @var string                   $title
 * @var string                   $text
 * @var string                   $cta   texto del botón
 * @var string                   $link  dirección ya validada (puede ir vacía: entonces no hay botón)
 */

$at_image = isset($image) && is_array($image) ? $image : null;
$at_title = isset($title) ? (string) $title : '';
$at_text = isset($text) ? (string) $text : '';
$at_cta = isset($cta) ? (string) $cta : '';
$at_link = isset($link) ? (string) $link : '';
?>
<section class="at-editorial" aria-labelledby="at-editorial-title">
    <div class="at-editorial-media">
        <?php if ($at_image !== null) : ?>
            <?= image_tag($at_image, 'large', ['alt' => '']) ?>
        <?php else : ?>
            <img src="<?= e(asset('img/editorial.svg')) ?>" alt="" width="900" height="1100" loading="lazy">
        <?php endif; ?>
    </div>
    <div class="at-editorial-copy">
        <p class="at-kicker"><?= e(t('The edit')) ?></p>
        <h2 id="at-editorial-title"><?= e($at_title) ?></h2>
        <p><?= e($at_text) ?></p>
        <?php if ($at_link !== '') : ?>
            <p><a class="af-btn" href="<?= e($at_link) ?>"><?= e($at_cta) ?></a></p>
        <?php endif; ?>
    </div>
</section>
