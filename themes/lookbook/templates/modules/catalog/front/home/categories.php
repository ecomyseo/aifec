<?php
/**
 * AIFEC — la sección «Categorías» de la portada en Lookbook: la apertura partida. Las dos primeras colecciones ocupan
 * media página cada una, con su foto, una línea pequeña, el nombre en serifa cursiva, un texto opcional y el acceso; las
 * demás van debajo como palabras. Con una sola colección, ocupa todo el ancho.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */

$lb_all = isset($categories) && is_array($categories) ? array_values(array_filter($categories, 'is_array')) : [];
$lb_doors = array_slice($lb_all, 0, 2);
$lb_more = array_slice($lb_all, 2, 10);
$lb_kicker = trim((string) theme_option('opening_kicker', ''));
$lb_kicker = $lb_kicker !== '' ? $lb_kicker : t('New collection');
$lb_texts = [trim((string) theme_option('opening_text_1', '')), trim((string) theme_option('opening_text_2', ''))];
$lb_first = (int) theme_option('opening_first', 1) === 1;
?>
<section class="lb-opening" aria-labelledby="af-catalog-categories-title">
    <h2 class="af-sr" id="af-catalog-categories-title"><?= e((string) $title) ?></h2>
    <?php if ($lb_doors !== []) : ?>
        <div class="lb-doors<?= count($lb_doors) === 1 ? ' lb-doors-one' : '' ?>">
            <?php foreach ($lb_doors as $lb_n => $lb_door) : ?>
                <?php
                $lb_photo = isset($lb_door['image']) && is_array($lb_door['image']) ? $lb_door['image'] : null;
                $lb_attrs = ['class' => 'lb-door-img', 'alt' => ''];
                if ($lb_photo !== null && !empty($lb_photo['srcset'])) {
                    $lb_attrs['sizes'] = count($lb_doors) === 1 ? '100vw' : '(max-width: 759px) 100vw, 50vw';
                }
                if ($lb_first && $lb_n === 0) {
                    // Es la primera imagen de la página: se carga ya y con prioridad.
                    $lb_attrs['loading'] = 'eager';
                    $lb_attrs['fetchpriority'] = 'high';
                }
                ?>
                <a class="lb-door" href="<?= e((string) $lb_door['url']) ?>">
                    <?= image_tag($lb_photo, 'large', $lb_attrs) ?>
                    <span class="lb-door-copy">
                        <small class="lb-over"><?= e($lb_kicker) ?></small>
                        <strong class="lb-door-name"><?= e((string) $lb_door['name']) ?></strong>
                        <?php if ($lb_texts[$lb_n] !== '') : ?>
                            <span class="lb-door-text"><?= e($lb_texts[$lb_n]) ?></span>
                        <?php endif; ?>
                        <span class="lb-door-cta"><?= e(t('Shop the collection')) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($lb_more !== []) : ?>
        <p class="lb-opening-more">
            <?php foreach ($lb_more as $lb_other) : ?>
                <a class="lb-word" href="<?= e((string) $lb_other['url']) ?>"><?= e((string) $lb_other['name']) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>
