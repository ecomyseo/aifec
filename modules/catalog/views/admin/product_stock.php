<?php
/**
 * AIFEC — edición rápida de precio y existencias: buscador, filtro de pocas existencias y la tabla de la página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $products productos de la página (id, sku, price, stock, manage_stock, has_variants, name)
 * @var array<int,array<int,array<string,mixed>>> $variants variantes por producto (id, display, price, stock)
 * @var string $q        texto buscado
 * @var bool   $low      solo pocas existencias
 * @var int    $page     página actual
 * @var int    $pages    páginas
 * @var int    $total    productos que cumplen el filtro
 * @var bool   $canEdit  puede guardar
 * @var string $action   dirección del envío
 * @var string $here     dirección de esta página con su búsqueda (a donde se vuelve)
 * @var \Closure $productUrl dirección de la ficha de un producto
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$cents = static function ($value): string {
    return $value === null || $value === '' ? '' : number_format(((int) $value) / 100, 2, ',', '');
};
$base = app()->url()->plain('/admin/catalog/stock');

?><div class="af-card">
    <form class="af-toolbar" method="get" action="<?= e($base) ?>" role="search">
        <input class="af-input af-search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Search…')) ?>" aria-label="<?= e(t('Search')) ?>">
        <label class="af-check"><input type="checkbox" name="low" value="1"<?= $low ? ' checked' : '' ?> data-af-autosubmit><span><?= e(t('Low stock')) ?></span></label>
        <button type="submit" class="af-btn"><?= Admin::icon('search') ?><?= e(t('Filter')) ?></button>
        <?php if ($q !== '' || $low) : ?><a class="af-btn af-btn-ghost" href="<?= e($base) ?>"><?= e(t('Clear')) ?></a><?php endif; ?>
    </form>
<?php if ($products === []) : ?>
    <div class="af-empty"><h3><?= e($q !== '' || $low ? t('No results') : t('Nothing here yet')) ?></h3></div>
<?php else : ?>
    <form method="post" action="<?= e($action) ?>"<?= $canEdit ? ' data-af-dirty' : '' ?>>
        <?= Admin::tokenField(app()) ?>
        <input type="hidden" name="next" value="<?= e($here) ?>">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr>
                    <th scope="col"><?= e(t('Name')) ?></th>
                    <th scope="col" class="af-hide-sm"><?= e(t('Reference (SKU)')) ?></th>
                    <th scope="col"><?= e(t('Price')) ?></th>
                    <th scope="col"><?= e(t('Stock')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($products as $product) : ?>
                    <?php $id = (int) $product['id']; $name = $product['name'] !== null && (string) $product['name'] !== '' ? (string) $product['name'] : '#' . $id; $rows = isset($variants[$id]) ? $variants[$id] : []; ?>
                    <tr>
                        <td><a href="<?= e($productUrl($id)) ?>"><strong><?= e($name) ?></strong></a></td>
                        <td class="af-mono af-hide-sm"><?= e($product['sku']) ?></td>
                        <?php if ($canEdit) : ?>
                        <td>
                            <input type="hidden" name="p[<?= $id ?>][price0]" value="<?= e($cents($product['price'])) ?>">
                            <input class="af-input af-input-sm" type="text" inputmode="decimal" name="p[<?= $id ?>][price]" value="<?= e($cents($product['price'])) ?>" aria-label="<?= e(t('Price')) ?>">
                        </td>
                        <td>
                            <?php if ((int) $product['has_variants'] === 1 && $rows !== []) : ?>
                                <span class="af-muted af-small"><?= e(t('Stock per variant')) ?></span>
                            <?php elseif ((int) $product['manage_stock'] === 0) : ?>
                                <span class="af-muted">—</span>
                            <?php else : ?>
                                <input type="hidden" name="p[<?= $id ?>][stock0]" value="<?= (int) $product['stock'] ?>">
                                <input class="af-input af-input-sm" type="number" min="0" step="1" name="p[<?= $id ?>][stock]" value="<?= (int) $product['stock'] ?>" aria-label="<?= e(t('Stock')) ?>">
                            <?php endif; ?>
                        </td>
                        <?php else : ?>
                        <td class="af-num"><?= e(money((int) $product['price'])) ?></td>
                        <td class="af-num"><?= (int) $product['manage_stock'] === 0 ? '<span class="af-muted">—</span>' : e((string) (int) $product['stock']) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php foreach ((int) $product['has_variants'] === 1 ? $rows : [] as $variant) : ?>
                    <?php $vid = (int) $variant['id']; ?>
                    <tr>
                        <td><span class="af-muted" aria-hidden="true">— </span><?= e($variant['display']) ?><?php if ((int) $variant['active'] === 0) : ?> <?= Admin::badge(t('Off'), 'warn') ?><?php endif; ?></td>
                        <td class="af-mono af-hide-sm"><?= e($variant['sku']) ?></td>
                        <?php if ($canEdit) : ?>
                        <td>
                            <input type="hidden" name="v[<?= $vid ?>][price0]" value="<?= e($cents($variant['price'])) ?>">
                            <input class="af-input af-input-sm" type="text" inputmode="decimal" name="v[<?= $vid ?>][price]" value="<?= e($cents($variant['price'])) ?>" placeholder="<?= e(t('Price of the product')) ?>" aria-label="<?= e(t('Price')) ?>">
                        </td>
                        <td>
                            <?php if ((int) $product['manage_stock'] === 0) : ?>
                                <span class="af-muted">—</span>
                            <?php else : ?>
                                <input type="hidden" name="v[<?= $vid ?>][stock0]" value="<?= (int) $variant['stock'] ?>">
                                <input class="af-input af-input-sm" type="number" min="0" step="1" name="v[<?= $vid ?>][stock]" value="<?= (int) $variant['stock'] ?>" aria-label="<?= e(t('Stock')) ?>">
                            <?php endif; ?>
                        </td>
                        <?php else : ?>
                        <td class="af-num"><?= $variant['price'] !== null ? e(money((int) $variant['price'])) : '<span class="af-muted">—</span>' ?></td>
                        <td class="af-num"><?= e((string) (int) $variant['stock']) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="af-pagination">
            <span class="af-muted af-small"><?= e(tn('{n} product', '{n} products', $total)) ?></span>
            <span class="af-top-space"></span>
            <?php if ($pages > 1) : ?>
                <?php if ($page > 1) : ?><a class="af-btn af-btn-sm" rel="prev" href="<?= e(app()->url()->plain('/admin/catalog/stock', ['q' => $q, 'low' => $low ? '1' : null, 'p' => (string) ($page - 1)])) ?>"><?= e(t('Previous')) ?></a><?php endif; ?>
                <span class="af-small"><?= e(t('Page {page} of {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
                <?php if ($page < $pages) : ?><a class="af-btn af-btn-sm" rel="next" href="<?= e(app()->url()->plain('/admin/catalog/stock', ['q' => $q, 'low' => $low ? '1' : null, 'p' => (string) ($page + 1)])) ?>"><?= e(t('Next')) ?></a><?php endif; ?>
            <?php endif; ?>
            <?php if ($canEdit) : ?><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button><?php endif; ?>
        </div>
    </form>
<?php endif; ?>
</div>
