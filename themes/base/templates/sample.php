<?php
/**
 * AIFEC — página de muestra del tema (/tema/muestra, solo con la depuración encendida): enseña todas las piezas y
 * clases del contrato con datos de mentira, sin necesidad de ningún módulo. Con ?filtros=1 pinta también la barra
 * inferior con el botón de filtros y un panel de filtros de ejemplo. Los textos de demostración van sin traducir.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters con filtros de ejemplo
 */

$af_filters = !empty($filters);
$af_self = url('/tema/muestra');
$this->layout('layout', [
    'page' => [
        'type' => 'page',
        'title' => t('Theme sample'),
        'robots' => 'noindex, nofollow',
        'breadcrumbs' => [['name' => t('Home page'), 'url' => url('/')], ['name' => t('Theme sample'), 'url' => '']],
    ],
    'filters' => $af_filters,
]);

/** Bloque de precio de mentira con la forma del contrato (los importes, en céntimos). */
$af_block = static function (int $amount, ?int $compare = null, bool $from = false, bool $tax = true): array {
    $sale = $compare !== null && $compare > $amount;

    return [
        'amount' => $amount, 'with_tax' => $tax, 'net' => $amount, 'tax' => 0, 'gross' => $amount, 'tax_rate' => 0, 'tax_name' => 'Tax',
        'currency' => 'EUR', 'compare' => $sale ? $compare : null, 'on_sale' => $sale,
        'discount_percent' => $sale ? intdiv((($compare - $amount) * 100) + intdiv($compare, 2), $compare) : null, 'sale_until' => null, 'from' => $from,
    ];
};

/** Imagen de mentira: un dibujo sencillo en SVG, como «data:», para no depender de ningún fichero. */
$af_image = static function (int $id, int $hue, int $shape, int $view = 0, string $alt = ''): array {
    $ink = 'hsl(' . $hue . ',38%,36%)';
    $mid = 'hsl(' . $hue . ',34%,56%)';
    $lite = 'hsl(' . $hue . ',42%,76%)';
    $shapes = [
        '<path d="M200 120h80l-10 40c60 30 70 120 30 200H170c-40-80-30-170 30-200z" fill="' . $mid . '"/><rect x="206" y="106" width="68" height="16" rx="7" fill="' . $ink . '"/>',
        '<path d="M170 250h140l-30-130h-80z" fill="' . $lite . '"/><rect x="236" y="250" width="8" height="112" fill="' . $ink . '"/><ellipse cx="240" cy="372" rx="64" ry="12" fill="' . $mid . '"/>',
        '<rect x="150" y="236" width="180" height="26" rx="8" fill="' . $mid . '"/><rect x="150" y="108" width="26" height="154" rx="8" fill="' . $ink . '"/><rect x="160" y="262" width="14" height="104" fill="' . $ink . '"/><rect x="306" y="262" width="14" height="104" fill="' . $ink . '"/>',
        '<ellipse cx="240" cy="210" rx="130" ry="30" fill="' . $lite . '"/><rect x="224" y="222" width="32" height="130" fill="' . $ink . '"/><ellipse cx="240" cy="360" rx="70" ry="14" fill="' . $mid . '"/>',
        '<rect x="120" y="150" width="240" height="180" rx="10" fill="' . $lite . '"/><rect x="120" y="190" width="240" height="22" fill="' . $mid . '"/><rect x="120" y="250" width="240" height="22" fill="' . $ink . '"/>',
        '<rect x="110" y="170" width="260" height="22" rx="11" fill="' . $ink . '"/><circle cx="170" cy="236" r="22" fill="' . $mid . '"/><circle cx="240" cy="236" r="22" fill="' . $mid . '"/><circle cx="310" cy="236" r="22" fill="' . $mid . '"/>',
    ];
    $views = ['', 'translate(40 20) scale(.9)', 'translate(-30 -20) scale(1.1)', 'translate(480 0) scale(-1 1)'];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 480" width="480" height="480">'
        . '<rect width="480" height="480" fill="hsl(' . $hue . ',24%,93%)"/>'
        . '<g transform="' . $views[$view % 4] . '">' . $shapes[$shape % 6] . '</g></svg>';
    $uri = 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);

    return ['id' => $id, 'url' => $uri, 'thumb' => $uri, 'card' => $uri, 'large' => $uri, 'width' => 480, 'height' => 480, 'alt' => $alt];
};

$af_names = [
    1 => 'Oak side table', 2 => 'Linen table lamp', 3 => 'Ceramic vase', 4 => 'Walnut shelf',
    5 => 'Wool throw', 6 => 'Reading chair', 7 => 'A very long product name that has to wrap onto two lines and then be trimmed with an ellipsis', 8 => 'Brass hook set',
];
$af_cards = [
    ['id' => 1, 'name' => $af_names[1], 'url' => $af_self . '#p1', 'image' => $af_image(1, 28, 3, 0), 'price' => $af_block(12900, 15900), 'brand' => 'Nordika', 'rating' => ['average' => 4.6, 'count' => 12], 'badges' => ['sale']],
    ['id' => 2, 'name' => $af_names[2], 'url' => $af_self . '#p2', 'image' => $af_image(2, 48, 1, 0), 'price' => $af_block(4990), 'brand' => 'Lumo', 'rating' => ['average' => 4.2, 'count' => 5], 'badges' => ['new']],
    ['id' => 3, 'name' => $af_names[3], 'url' => $af_self . '#p3', 'image' => $af_image(3, 200, 0, 0), 'price' => $af_block(2450), 'brand' => 'Terra', 'rating' => null, 'badges' => ['out']],
    ['id' => 4, 'name' => $af_names[4], 'url' => $af_self . '#p4', 'image' => $af_image(4, 18, 4, 0), 'price' => $af_block(8900, null, true), 'brand' => 'Nordika', 'rating' => ['average' => 5.0, 'count' => 3], 'badges' => []],
    ['id' => 5, 'name' => $af_names[5], 'url' => $af_self . '#p5', 'image' => $af_image(5, 340, 4, 1), 'price' => $af_block(6900, 9900), 'brand' => 'Lumo', 'rating' => ['average' => 4.0, 'count' => 40], 'badges' => ['sale', 'new']],
    ['id' => 6, 'name' => $af_names[6], 'url' => $af_self . '#p6', 'image' => $af_image(6, 150, 2, 0), 'price' => $af_block(21900), 'brand' => 'Nordika', 'rating' => ['average' => 3.8, 'count' => 21], 'badges' => []],
    ['id' => 7, 'name' => $af_names[7], 'url' => $af_self . '#p7', 'image' => null, 'price' => $af_block(1500), 'brand' => '', 'rating' => null, 'badges' => []],
    ['id' => 8, 'name' => $af_names[8], 'url' => $af_self . '#p8', 'image' => $af_image(8, 38, 5, 2), 'price' => $af_block(1890, null, false, false), 'brand' => 'Terra', 'rating' => ['average' => 4.4, 'count' => 8], 'badges' => []],
];

// La ficha de ejemplo: dos opciones (tamaño y acabado) y seis variantes.
$af_sizes = ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'];
$af_finishes = ['oak' => 'Oak', 'walnut' => 'Walnut'];
$af_variants = [];
$af_vid = 100;
$af_base = ['s' => 12900, 'm' => 14900, 'l' => 17900];
foreach ($af_finishes as $af_f => $af_flabel) {
    foreach ($af_sizes as $af_s => $af_slabel) {
        ++$af_vid;
        $af_amount = $af_base[$af_s] + ($af_f === 'walnut' ? 1000 : 0);
        $af_status = $af_vid === 103 ? 'out_of_stock' : ($af_vid === 105 ? 'low_stock' : 'in_stock');
        $af_labels = ['in_stock' => t('In stock'), 'low_stock' => t('Only a few left'), 'out_of_stock' => t('Out of stock')];
        $af_variants[] = [
            'id' => $af_vid, 'sku' => 'TBL-' . strtoupper($af_s) . '-' . strtoupper(substr($af_f, 0, 2)), 'label' => $af_slabel . ' / ' . $af_flabel,
            'values' => ['size' => $af_s, 'finish' => $af_f], 'price' => $af_block($af_amount, $af_s === 's' && $af_f === 'oak' ? 15900 : null),
            'availability' => ['status' => $af_status, 'qty' => null, 'label' => $af_labels[$af_status], 'delivery_days' => null], 'image_id' => $af_f === 'oak' ? 1 : 2,
        ];
    }
}
$af_first = $af_variants[0];
$af_gallery = [$af_image(1, 28, 3, 0, 'Front'), $af_image(2, 28, 3, 1, 'Side'), $af_image(3, 28, 3, 2, 'Close-up'), $af_image(4, 28, 3, 3, 'Mirrored')];

$af_pages_url = static function (int $n) use ($af_self): string {
    return $af_self . '?pagina=' . $n;
};
?>
<div class="af-container af-sample">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => [['name' => t('Home page'), 'url' => url('/')], ['name' => t('Theme sample'), 'url' => '']]]) ?>
    <h1 class="af-title"><?= e(t('Theme sample')) ?></h1>
    <p class="af-subtitle"><?= e(t('Every piece of the base theme with made-up data. It only exists while debugging.')) ?></p>
    <p class="af-row af-small">
        <a href="<?= e($af_self) ?>"><?= e(t('Without filters')) ?></a>
        <a href="<?= e($af_self . '?filtros=1') ?>"><?= e(t('With filters')) ?></a>
    </p>

    <section class="af-section" id="m-type">
        <h2><?= e(t('Text')) ?></h2>
        <div class="af-stack">
            <h3>Heading three</h3>
            <p>Body text with a <a href="<?= e($af_self) ?>">link</a>, <strong>bold</strong> and <em>emphasis</em>. <span class="af-muted">Muted text.</span> <span class="af-small">Small text.</span></p>
            <p class="af-muted">Secondary text keeps a contrast of at least 4.5 to 1 on the page background.</p>
        </div>
    </section>

    <section class="af-section" id="m-buttons">
        <h2><?= e(t('Buttons')) ?></h2>
        <div class="af-row">
            <a class="af-btn" href="<?= e($af_self) ?>">Default</a>
            <button type="button" class="af-btn af-btn-primary">Primary</button>
            <button type="button" class="af-btn af-btn-ghost">Ghost</button>
            <button type="button" class="af-btn af-btn-primary af-btn-sm">Small</button>
            <button type="button" class="af-btn af-btn-primary af-btn-lg">Large</button>
            <button type="button" class="af-btn af-btn-primary" disabled>Disabled</button>
        </div>
        <p><button type="button" class="af-btn af-btn-primary af-btn-block">Block</button></p>
        <div class="af-row" aria-label="<?= e(t('Panels')) ?>">
            <button type="button" class="af-btn af-btn-sm" data-af-drawer-open="af-menu"><?= e(t('Menu')) ?></button>
            <button type="button" class="af-btn af-btn-sm" data-af-drawer-open="af-search"><?= e(t('Search')) ?></button>
            <button type="button" class="af-btn af-btn-sm" data-af-drawer-open="af-cart"><?= e(t('Cart')) ?></button>
            <?php if ($af_filters) : ?>
                <button type="button" class="af-btn af-btn-sm" data-af-drawer-open="af-filters"><?= e(t('Filters')) ?></button>
            <?php endif; ?>
            <button type="button" class="af-btn af-btn-sm" data-af-toggle="m-more" aria-expanded="false"><?= e(t('Show more')) ?></button>
        </div>
        <p id="m-more" hidden class="af-muted">Hidden until the toggle button is pressed.</p>
    </section>

    <section class="af-section" id="m-listing">
        <h2><?= e(t('Product list')) ?></h2>
        <div class="af-listing">
            <?php if ($af_filters) : ?>
                <aside class="af-listing-filters af-drawer" id="af-filters" aria-label="<?= e(t('Filters')) ?>" tabindex="-1">
                    <div class="af-drawer-head af-show-sm">
                        <p class="af-drawer-title"><?= e(t('Filters')) ?></p>
                        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
                            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                    </div>
                    <form class="af-drawer-body" method="get" action="<?= e($af_self) ?>" data-af-autosubmit>
                        <input type="hidden" name="filtros" value="1">
                        <div class="af-filter">
                            <p class="af-filter-title"><?= e(t('Sort by')) ?></p>
                            <label class="af-sr" for="m-order"><?= e(t('Sort by')) ?></label>
                            <select id="m-order" class="af-select" name="orden" data-af-autosubmit>
                                <option value="new">Newest</option>
                                <option value="price-asc">Price: low to high</option>
                                <option value="price-desc">Price: high to low</option>
                            </select>
                        </div>
                        <div class="af-filter">
                            <p class="af-filter-title">Brand</p>
                            <label class="af-check"><input type="checkbox" name="marca[]" value="nordika"><span>Nordika</span></label>
                            <label class="af-check"><input type="checkbox" name="marca[]" value="lumo"><span>Lumo</span></label>
                            <label class="af-check"><input type="checkbox" name="marca[]" value="terra"><span>Terra</span></label>
                        </div>
                        <div class="af-filter">
                            <p class="af-filter-title">Price</p>
                            <div class="af-row">
                                <label class="af-sr" for="m-min"><?= e(t('Minimum price')) ?></label>
                                <input id="m-min" class="af-input" type="number" name="min" placeholder="0" inputmode="numeric">
                                <label class="af-sr" for="m-max"><?= e(t('Maximum price')) ?></label>
                                <input id="m-max" class="af-input" type="number" name="max" placeholder="500" inputmode="numeric">
                            </div>
                        </div>
                        <button type="submit" class="af-btn af-btn-primary af-btn-block"><?= e(t('Apply')) ?></button>
                    </form>
                </aside>
            <?php endif; ?>
            <div class="af-listing-results">
                <div class="af-grid af-cols-4">
                    <?php foreach ($af_cards as $af_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
                    <?php endforeach; ?>
                </div>
                <?= $this->partial('partials/pagination', ['page' => 3, 'pages' => 9, 'url' => $af_pages_url]) ?>
            </div>
        </div>
    </section>

    <section class="af-section" id="m-price">
        <h2><?= e(t('Price')) ?></h2>
        <div class="af-grid af-cols-3">
            <?php foreach (['sm', 'md', 'lg'] as $af_size) : ?>
                <div class="af-card af-stack">
                    <p class="af-small af-muted"><?= e($af_size) ?></p>
                    <?= $this->partial('partials/price', ['price' => $af_block(4990), 'size' => $af_size]) ?>
                    <?= $this->partial('partials/price', ['price' => $af_block(4990, 5990), 'size' => $af_size]) ?>
                    <?= $this->partial('partials/price', ['price' => $af_block(4990, null, true), 'size' => $af_size]) ?>
                    <?= $this->partial('partials/price', ['price' => $af_block(4990, 5990, false, false), 'size' => $af_size]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="af-section" id="m-marks">
        <h2><?= e(t('Alerts and marks')) ?></h2>
        <?= $this->partial('partials/messages', ['messages' => [['ok', 'Saved. Everything went well.'], ['bad', 'Something went wrong and nothing was saved.'], ['info', 'A neutral note for the customer.'], ['warn', 'Careful: only two units left.']]]) ?>
        <div class="af-row">
            <span class="af-badge">Badge</span>
            <span class="af-badge af-badge-sale">&minus;20&nbsp;%</span>
            <span class="af-badge af-badge-new"><?= e(t('New')) ?></span>
            <span class="af-badge af-badge-out"><?= e(t('Sold out')) ?></span>
            <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => '3.5'])) ?>" style="--rating:3.5"></span>
            <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => '5.0'])) ?>" style="--rating:5"></span>
        </div>
    </section>

    <section class="af-section" id="m-forms">
        <h2><?= e(t('Form')) ?></h2>
        <form class="af-grid af-cols-2" method="post" action="<?= e($af_self) ?>">
            <div class="af-field">
                <label for="m-name">Full name</label>
                <input id="m-name" class="af-input" type="text" name="name" autocomplete="name" value="Ana Ruiz">
                <span class="af-hint">As it appears on your ID.</span>
            </div>
            <div class="af-field has-error">
                <label for="m-email">Email</label>
                <input id="m-email" class="af-input" type="email" name="email" value="not-an-email" aria-invalid="true" aria-describedby="m-email-err">
                <span class="af-error" id="m-email-err">Enter a valid email address.</span>
            </div>
            <div class="af-field">
                <label for="m-country">Country</label>
                <select id="m-country" class="af-select" name="country"><option>Spain</option><option>Portugal</option><option>France</option></select>
            </div>
            <div class="af-field">
                <label for="m-note">Note</label>
                <textarea id="m-note" class="af-textarea" name="note" rows="3"></textarea>
            </div>
            <div class="af-field">
                <label class="af-check"><input type="checkbox" name="terms" checked><span>I accept the terms</span></label>
                <label class="af-check"><input type="checkbox" name="news"><span>Send me the newsletter</span></label>
            </div>
            <div class="af-field">
                <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => 2, 'min' => 1, 'max' => 10]) ?>
            </div>
            <div><button type="submit" class="af-btn af-btn-primary"><?= e(t('Save')) ?></button></div>
        </form>
    </section>

    <section class="af-section" id="m-tabs">
        <h2><?= e(t('Tabs')) ?></h2>
        <div class="af-tabs" data-af-tabs>
            <div role="tablist" aria-label="<?= e(t('Tabs')) ?>">
                <button type="button" role="tab" id="tab-a" aria-controls="panel-a" aria-selected="true">Description</button>
                <button type="button" role="tab" id="tab-b" aria-controls="panel-b" aria-selected="false" tabindex="-1">Details</button>
                <button type="button" role="tab" id="tab-c" aria-controls="panel-c" aria-selected="false" tabindex="-1">Reviews</button>
            </div>
            <div role="tabpanel" id="panel-a" aria-labelledby="tab-a"><p>Solid oak, finished with a water-based oil that keeps the grain visible.</p></div>
            <div role="tabpanel" id="panel-b" aria-labelledby="tab-b" hidden><p>Assembled in a single piece. Felt feet protect the floor.</p></div>
            <div role="tabpanel" id="panel-c" aria-labelledby="tab-c" hidden><p>Twelve reviews, average 4.6 out of 5.</p></div>
        </div>
    </section>

    <section class="af-section" id="m-product">
        <h2><?= e(t('Product page')) ?></h2>
        <div class="af-product" data-af-variants>
            <div class="af-product-gallery af-gallery" data-af-gallery>
                <div class="af-gallery-main">
                    <img data-af-gallery-main src="<?= e($af_gallery[0]['large']) ?>" alt="<?= e($af_gallery[0]['alt']) ?>" width="480" height="480">
                </div>
                <ul class="af-gallery-thumbs">
                    <?php foreach ($af_gallery as $af_i => $af_g) : ?>
                        <li><a href="<?= e($af_g['large']) ?>" data-src="<?= e($af_g['large']) ?>" data-alt="<?= e($af_g['alt']) ?>" data-image-id="<?= e($af_g['id']) ?>"<?= $af_i === 0 ? ' aria-current="true"' : '' ?>>
                            <?= image_tag($af_g, 'thumb', ['alt' => $af_g['alt']]) ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="af-product-buy">
                <h2 class="af-title" id="p1"><?= e($af_names[1]) ?></h2>
                <p class="af-row af-small af-muted">
                    <span>Nordika</span>
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => '4.6'])) ?>" style="--rating:4.6"></span>
                    <span><?= e(t('SKU')) ?>: <span data-af-sku><?= e($af_first['sku']) ?></span></span>
                </p>
                <?= $this->partial('partials/price', ['price' => $af_first['price'], 'size' => 'lg', 'live' => true]) ?>
                <p class="af-muted">A small side table in solid oak with a round top and a single leg.</p>
                <form method="post" action="<?= e($af_self) ?>" data-af-cart-add>
                    <input type="hidden" name="product_id" value="1">
                    <input type="hidden" name="variant_id" value="<?= e($af_first['id']) ?>" data-af-variant-id>
                    <fieldset class="af-variants" data-af-option="size">
                        <legend><?= e(t('Size')) ?></legend>
                        <div class="af-variants-list">
                            <?php foreach ($af_sizes as $af_s => $af_slabel) : ?>
                                <button type="button" class="af-variant" data-value="<?= e($af_s) ?>" aria-pressed="<?= $af_s === 's' ? 'true' : 'false' ?>"><?= e($af_slabel) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="af-variants" data-af-option="finish">
                        <legend><?= e(t('Finish')) ?></legend>
                        <div class="af-variants-list">
                            <?php foreach ($af_finishes as $af_f => $af_flabel) : ?>
                                <button type="button" class="af-variant" data-value="<?= e($af_f) ?>" aria-pressed="<?= $af_f === 'oak' ? 'true' : 'false' ?>"><?= e($af_flabel) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <p class="af-availability is-ok" data-af-availability><?= e($af_first['availability']['label']) ?></p>
                    <script type="application/json" data-af-variants-data><?= json_html($af_variants) ?></script>
                    <div class="af-row af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => 1, 'min' => 1, 'max' => 10]) ?>
                        <button type="submit" class="af-btn af-btn-primary af-btn-lg" data-af-add><?= e(t('Add to cart')) ?></button>
                    </div>
                </form>
                <dl class="af-facts">
                    <div><dt>Material</dt><dd>Solid oak</dd></div>
                    <div><dt>Height</dt><dd>48 cm</dd></div>
                    <div><dt>Diameter</dt><dd>40 cm</dd></div>
                    <div><dt>Weight</dt><dd>6.5 kg</dd></div>
                </dl>
            </div>
        </div>
    </section>

    <section class="af-section" id="m-cart">
        <h2><?= e(t('Cart')) ?></h2>
        <div class="af-split">
            <div class="af-cart">
                <form method="post" action="<?= e($af_self) ?>" data-af-autosubmit>
                    <ul class="af-cart-lines">
                        <?php foreach ([[1, 'Oak side table', 'Small / Oak', 12900, 1], [5, 'Wool throw', 'Grey', 6900, 2], [8, 'Brass hook set', '', 1890, 3]] as $af_line) : ?>
                            <?php $af_card = $af_cards[$af_line[0] - 1]; ?>
                            <li class="af-cart-line">
                                <a class="af-cart-line-image" href="<?= e($af_card['url']) ?>"><?= image_tag($af_card['image'], 'thumb', ['alt' => '']) ?></a>
                                <div class="af-cart-line-info">
                                    <a class="af-cart-line-name" href="<?= e($af_card['url']) ?>"><?= e($af_line[1]) ?></a>
                                    <?php if ($af_line[2] !== '') : ?>
                                        <p class="af-small af-muted"><?= e($af_line[2]) ?></p>
                                    <?php endif; ?>
                                    <div class="af-row">
                                        <?= $this->partial('partials/qty', ['name' => 'qty[' . $af_line[0] . ']', 'value' => $af_line[4], 'min' => 1, 'max' => 10]) ?>
                                        <button type="button" class="af-btn af-btn-ghost af-btn-sm"><?= e(t('Remove')) ?></button>
                                    </div>
                                </div>
                                <p class="af-cart-line-total"><?= e(money($af_line[3] * $af_line[4])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </form>
            </div>
            <aside class="af-summary af-card">
                <h3><?= e(t('Order summary')) ?></h3>
                <dl class="af-totals">
                    <div class="af-totals-row"><dt><?= e(t('Subtotal')) ?></dt><dd><?= e(money(29470)) ?></dd></div>
                    <div class="af-totals-row"><dt><?= e(t('Discount')) ?></dt><dd>&minus;<?= e(money(2000)) ?></dd></div>
                    <div class="af-totals-row"><dt><?= e(t('Shipping')) ?></dt><dd><?= e(t('Free')) ?></dd></div>
                    <div class="af-totals-row af-totals-total"><dt><?= e(t('Total')) ?></dt><dd><?= e(money(27470)) ?></dd></div>
                </dl>
                <p class="af-small af-muted"><?= e(t('Tax included')) ?></p>
                <a class="af-btn af-btn-primary af-btn-block af-btn-lg" href="<?= e($af_self) ?>"><?= e(t('Checkout')) ?></a>
            </aside>
        </div>
    </section>

    <section class="af-section" id="m-checkout">
        <h2><?= e(t('Checkout')) ?></h2>
        <ol class="af-steps">
            <li class="is-done"><?= e(t('Cart')) ?></li>
            <li class="is-current" aria-current="step">Details</li>
            <li>Shipping</li>
            <li>Payment</li>
        </ol>
        <div class="af-grid af-cols-2">
            <fieldset class="af-stack af-fieldset">
                <legend><?= e(t('Shipping')) ?></legend>
                <label class="af-radio-card"><input type="radio" name="ship" value="a" checked><span class="af-radio-card-body"><strong>Standard</strong><span class="af-muted">2 to 4 days</span><span class="af-radio-card-price"><?= e(t('Free')) ?></span></span></label>
                <label class="af-radio-card"><input type="radio" name="ship" value="b"><span class="af-radio-card-body"><strong>Express</strong><span class="af-muted">Next day</span><span class="af-radio-card-price"><?= e(money(690)) ?></span></span></label>
            </fieldset>
            <fieldset class="af-stack af-fieldset">
                <legend>Payment</legend>
                <label class="af-radio-card"><input type="radio" name="pay" value="card" checked><span class="af-radio-card-body"><strong>Card</strong><span class="af-muted">Visa, Mastercard</span></span></label>
                <label class="af-radio-card"><input type="radio" name="pay" value="transfer"><span class="af-radio-card-body"><strong>Bank transfer</strong><span class="af-muted">Shipped when the money arrives</span></span></label>
            </fieldset>
        </div>
    </section>

    <section class="af-section" id="m-table">
        <h2><?= e(t('Table')) ?></h2>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Total</th></tr></thead>
                <tbody>
                    <tr><td>#1042</td><td>12/11/2026</td><td><span class="af-badge af-badge-new">Paid</span></td><td><?= e(money(27470)) ?></td></tr>
                    <tr><td>#1041</td><td>10/11/2026</td><td><span class="af-badge">Packing</span></td><td><?= e(money(4990)) ?></td></tr>
                    <tr><td>#1040</td><td>02/11/2026</td><td><span class="af-badge af-badge-out">Cancelled</span></td><td><?= e(money(12900)) ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="af-section" id="m-prose">
        <h2><?= e(t('Long text')) ?></h2>
        <div class="af-prose">
            <h3>Care and maintenance</h3>
            <p>Wipe the surface with a soft, slightly damp cloth and dry it straight away. Avoid leaving hot cups or wet glasses without a coaster: solid wood breathes and marks easily.</p>
            <ul><li>Once a year, apply a thin coat of the same oil.</li><li>Keep it away from radiators and direct sunlight.</li><li>Move it by lifting it, never by dragging it.</li></ul>
            <blockquote>A good table is the one you stop noticing because it is always in the right place.</blockquote>
            <p>If something goes wrong, <a href="<?= e($af_self) ?>">contact us</a> and we will sort it out. Every piece has a two-year guarantee.</p>
            <table><thead><tr><th scope="col">Size</th><th scope="col">Height</th><th scope="col">Diameter</th><th scope="col">Weight</th></tr></thead><tbody><tr><td>Small</td><td>48 cm</td><td>40 cm</td><td>6.5 kg</td></tr><tr><td>Medium</td><td>52 cm</td><td>46 cm</td><td>8 kg</td></tr></tbody></table>
        </div>
    </section>

    <section class="af-section" id="m-empty">
        <h2><?= e(t('Empty state')) ?></h2>
        <div class="af-card af-empty">
            <p class="af-empty-title"><?= e(t('Your cart is empty.')) ?></p>
            <p class="af-muted">Browse the shop and add something you like.</p>
            <a class="af-btn af-btn-primary" href="<?= e(url('/')) ?>"><?= e(t('Back to the home page')) ?></a>
        </div>
    </section>
</div>
