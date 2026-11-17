<?php
/**
 * AIFEC — una sección de la portada con productos en Lookbook. Cada una tiene su composición:
 *   - las novedades («catalog.new») son el lookbook: dos dobles páginas, cada una con una pieza grande y otra pequeña
 *     al lado (la segunda, vuelta), y el resto en hilera;
 *   - los destacados («catalog.featured») son un índice escrito —nombre y precio de cada pieza— junto a una foto, que
 *     cambia al señalar cada línea;
 *   - las demás (ofertas…) van en una hilera uniforme.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (sirve para enlazar el título)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas de producto
 */

$lb_title_id = 'af-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
$lb_items = isset($items) && is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
$lb_id = (string) $id;
?>
<?php if ($lb_id === 'catalog.new' && count($lb_items) >= 2) : ?>
    <section class="lb-lookbook" aria-labelledby="<?= e($lb_title_id) ?>">
        <header class="lb-section-head">
            <p class="lb-over"><?= e(t('Lookbook')) ?></p>
            <h2 id="<?= e($lb_title_id) ?>"><?= e((string) $title) ?></h2>
        </header>
        <?php foreach (array_chunk(array_slice($lb_items, 0, 4), 2) as $lb_n => $lb_pair) : ?>
            <?php if (count($lb_pair) === 2) : ?>
                <div class="lb-spread<?= ($lb_n % 2 === 1 ? ' lb-spread-turn' : '') ?>">
                    <div class="lb-spread-plate"><?= $this->partial('partials/product-card', ['product' => $lb_pair[0]]) ?></div>
                    <div class="lb-spread-piece"><?= $this->partial('partials/product-card', ['product' => $lb_pair[1]]) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php $lb_rest = array_slice($lb_items, count($lb_items) >= 4 ? 4 : 2, 4); ?>
        <?php if ($lb_rest !== []) : ?>
            <ul class="lb-rail">
                <?php foreach ($lb_rest as $lb_card) : ?>
                    <li><?= $this->partial('partials/product-card', ['product' => $lb_card]) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php elseif ($lb_id === 'catalog.featured' && $lb_items !== []) : ?>
    <?php $lb_list = array_slice($lb_items, 0, 6); ?>
    <section class="lb-roster" aria-labelledby="<?= e($lb_title_id) ?>">
        <figure class="lb-roster-stage">
            <?php foreach ($lb_list as $lb_card) : ?>
                <?= image_tag(isset($lb_card['image']) && is_array($lb_card['image']) ? $lb_card['image'] : null, 'card', ['class' => 'lb-roster-shot', 'alt' => '']) ?>
            <?php endforeach; ?>
        </figure>
        <div class="lb-roster-index">
            <h2 id="<?= e($lb_title_id) ?>"><?= e((string) $title) ?></h2>
            <ol class="lb-roster-list">
                <?php foreach ($lb_list as $lb_card) : ?>
                    <li>
                        <?php if (isset($lb_card['url']) && (string) $lb_card['url'] !== '') : ?>
                            <a class="lb-roster-name" href="<?= e((string) $lb_card['url']) ?>"><?= e(isset($lb_card['name']) ? (string) $lb_card['name'] : '') ?></a>
                        <?php else : ?>
                            <span class="lb-roster-name"><?= e(isset($lb_card['name']) ? (string) $lb_card['name'] : '') ?></span>
                        <?php endif; ?>
                        <?= $this->partial('partials/price', ['price' => isset($lb_card['price']) && is_array($lb_card['price']) ? $lb_card['price'] : [], 'size' => 'sm']) ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
<?php elseif ($lb_items !== []) : ?>
    <section class="lb-suite" aria-labelledby="<?= e($lb_title_id) ?>">
        <header class="lb-section-head">
            <h2 id="<?= e($lb_title_id) ?>"><?= e((string) $title) ?></h2>
        </header>
        <ul class="lb-rail">
            <?php foreach (array_slice($lb_items, 0, 8) as $lb_card) : ?>
                <li><?= $this->partial('partials/product-card', ['product' => $lb_card]) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
