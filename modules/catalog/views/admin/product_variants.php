<?php
/**
 * AIFEC — variantes de un producto: generar combinaciones y la tabla editable (o de solo lectura).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var int    $productId     producto
 * @var array<int,array<string,mixed>> $attributes atributos para variantes con sus valores (label por valor)
 * @var array<string,mixed>|null $typed lo que salió de «Escríbelas»: text, chosen, unknown, preview (rows, new, existing, capped), ai, aiProblem
 * @var bool   $aiOn          la IA está disponible para esta persona
 * @var array<int,array<string,mixed>> $rows       variantes: id, display, sku, gtin, price, compare_price, stock, weight, media_id, active, in_orders
 * @var array<int,string> $images imágenes del producto: id de medios => texto
 * @var string[] $errors      problemas de la última pasada
 * @var bool   $canEdit       puede cambiar variantes
 * @var string $action        dirección a la que se envía todo
 * @var string $attributesUrl listado de atributos
 * @var int    $max           tope de variantes
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

/** Un importe en céntimos como se escribe: 12,50. */
$cents = static function ($value): string {
    return $value === null || $value === '' ? '' : number_format(((int) $value) / 100, 2, ',', '');
};

?><?php if ($errors !== []) : ?>
<div class="af-alert af-alert-bad" role="alert">
    <?= e(t('Nothing was saved. Review these variants:')) ?>
    <ul>
    <?php foreach ($errors as $error) : ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if ($canEdit && $attributes !== []) : ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Write them')) ?></h2></div>
    <div class="af-card-body af-stack">
        <form method="post" action="<?= e($action) ?>" class="af-stack">
            <?= Admin::tokenField(app()) ?>
            <input type="hidden" name="op" value="parse">
            <div class="af-field">
                <label class="af-label" for="vr-typed"><?= e(t('Describe the combinations')) ?></label>
                <div class="af-control">
                    <textarea id="vr-typed" name="typed" class="af-textarea" rows="2" maxlength="500" placeholder="<?= e(t('black and grey; sizes S to L')) ?>"><?= e($typed !== null ? (string) $typed['text'] : '') ?></textarea>
                    <div class="af-hint"><?= e(t('For example: red/blue; 38, 39, 40. You see the combinations before anything is created.')) ?><?= $aiOn ? ' ' . e(t('If it is not clear, the AI helps.')) : '' ?></div>
                </div>
            </div>
            <div><button type="submit" class="af-btn"><?= Admin::icon('layers') ?><?= e(t('Show the combinations')) ?></button></div>
        </form>
        <?php if ($typed !== null) : ?>
            <?php if ((string) $typed['aiProblem'] !== '') : ?>
                <div class="af-alert af-alert-warn" role="status"><?= e((string) $typed['aiProblem']) ?></div>
            <?php endif; ?>
            <?php if ($typed['preview'] === null) : ?>
                <div class="af-alert af-alert-warn" role="status"><?= e(t('I could not understand that. Write the values of your attributes, for example black and grey; sizes S to L.')) ?></div>
            <?php else : ?>
                <?php $preview = $typed['preview']; ?>
                <?php if ($typed['unknown'] !== []) : ?>
                    <div class="af-alert af-alert-warn" role="status"><?= e(t('Not understood, and left out: {list}', ['list' => implode(', ', array_map('strval', $typed['unknown']))])) ?></div>
                <?php endif; ?>
                <?php if ($typed['ai']) : ?>
                    <div class="af-alert af-alert-info" role="status"><?= e(t('The AI understood this. Check it before you create anything.')) ?></div>
                <?php endif; ?>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('Combination')) ?></th>
                            <th scope="col"><?= e(t('Status')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($preview['rows'] as $line) : ?>
                            <tr>
                                <td><strong><?= e($line['label']) ?></strong></td>
                                <td><?= $line['exists'] ? Admin::badge(t('Already exists'), 'info') : ($line['capped'] ? Admin::badge(t('Over the limit'), 'bad') : Admin::badge(t('New'), 'ok')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($preview['new'] > 0) : ?>
                    <form method="post" action="<?= e($action) ?>" class="af-stack">
                        <?= Admin::tokenField(app()) ?>
                        <input type="hidden" name="op" value="generate">
                        <?php foreach ($typed['chosen'] as $attributeId => $valueIds) : ?>
                            <?php foreach ($valueIds as $valueId) : ?>
                                <input type="hidden" name="gen[<?= (int) $attributeId ?>][]" value="<?= (int) $valueId ?>">
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <div>
                            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(tn('Create {n} combination', 'Create {n} combinations', (int) $preview['new'])) ?></button>
                            <?php if ($preview['existing'] > 0) : ?><span class="af-muted af-small"><?= e(tn('{n} already exists and is not repeated.', '{n} already exist and are not repeated.', (int) $preview['existing'])) ?></span><?php endif; ?>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="af-alert af-alert-info" role="status"><?= e(t('Every combination already exists.')) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($canEdit) : ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Generate combinations')) ?></h2></div>
    <div class="af-card-body">
    <?php if ($attributes === []) : ?>
        <p class="af-muted"><?= e(t('No attribute can make variants yet.')) ?> <a href="<?= e($attributesUrl) ?>"><?= e(t('Mark the colour or size attributes for variants')) ?></a></p>
    <?php else : ?>
        <form method="post" action="<?= e($action) ?>" class="af-stack">
            <?= Admin::tokenField(app()) ?>
            <input type="hidden" name="op" value="generate">
            <?php foreach ($attributes as $attributeId => $attr) : ?>
            <fieldset class="af-checks-group">
                <legend><strong><?= e($attr['name']) ?></strong></legend>
                <div class="af-checks">
                <?php foreach ($attr['values'] as $valueId => $value) : ?>
                    <label class="af-check"><input type="checkbox" name="gen[<?= (int) $attributeId ?>][]" value="<?= (int) $valueId ?>"><span><?= e($value['label']) ?></span></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <?php endforeach; ?>
            <div>
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('layers') ?><?= e(t('Generate combinations')) ?></button>
                <span class="af-muted af-small"><?= e(t('The combinations that already exist are not repeated. Up to {n} variants.', ['n' => $max])) ?></span>
            </div>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Variants')) ?></h2></div>
<?php if ($rows === []) : ?>
    <div class="af-empty"><h3><?= e(t('This product has no variants yet')) ?></h3></div>
<?php elseif (!$canEdit) : ?>
    <div class="af-table-wrap">
        <table class="af-table">
            <thead><tr>
                <th scope="col"><?= e(t('Variant')) ?></th>
                <th scope="col"><?= e(t('Reference (SKU)')) ?></th>
                <th scope="col" class="af-num"><?= e(t('Price')) ?></th>
                <th scope="col" class="af-num"><?= e(t('Stock')) ?></th>
                <th scope="col"><?= e(t('Active')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?= e($row['display']) ?></strong></td>
                    <td class="af-mono"><?= e($row['sku']) ?></td>
                    <td class="af-num"><?= $row['price'] !== null ? e(money((int) $row['price'])) : '<span class="af-muted">—</span>' ?></td>
                    <td class="af-num"><?= e((string) $row['stock']) ?></td>
                    <td><?= (int) $row['active'] === 1 ? Admin::badge(t('Yes'), 'ok') : Admin::badge(t('No'), 'warn') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else : ?>
    <form method="post" action="<?= e($action) ?>" data-af-dirty>
        <?= Admin::tokenField(app()) ?>
        <input type="hidden" name="op" value="save">
        <div class="af-table-wrap">
            <table class="af-table">
                <thead><tr>
                    <th scope="col"><?= e(t('Variant')) ?></th>
                    <th scope="col"><?= e(t('Reference (SKU)')) ?></th>
                    <th scope="col"><?= e(t('Barcode (GTIN)')) ?></th>
                    <th scope="col"><?= e(t('Price')) ?></th>
                    <th scope="col"><?= e(t('Previous price')) ?></th>
                    <th scope="col"><?= e(t('Stock')) ?></th>
                    <th scope="col"><?= e(t('Weight')) ?> (g)</th>
                    <th scope="col"><?= e(t('Image')) ?></th>
                    <th scope="col"><?= e(t('Active')) ?></th>
                    <th scope="col"><?= e(t('Delete')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $n = 'v[' . (int) $row['id'] . ']'; $posted = !empty($row['posted']); ?>
                    <tr>
                        <td class="af-nowrap"><strong><?= e($row['display']) ?></strong><?php if ($row['in_orders']) : ?> <?= Admin::badge(t('In orders'), 'info') ?><?php endif; ?></td>
                        <td><input class="af-input af-input-sm" type="text" name="<?= e($n) ?>[sku]" value="<?= e($row['sku']) ?>" maxlength="64" aria-label="<?= e(t('Reference (SKU)')) ?>"></td>
                        <td><input class="af-input af-input-sm" type="text" name="<?= e($n) ?>[gtin]" value="<?= e($row['gtin']) ?>" maxlength="32" aria-label="<?= e(t('Barcode (GTIN)')) ?>"></td>
                        <td><input class="af-input af-input-sm" type="text" inputmode="decimal" name="<?= e($n) ?>[price]" value="<?= e($posted ? $row['price'] : $cents($row['price'])) ?>" placeholder="<?= e(t('Price of the product')) ?>" aria-label="<?= e(t('Price')) ?>"></td>
                        <td><input class="af-input af-input-sm" type="text" inputmode="decimal" name="<?= e($n) ?>[compare_price]" value="<?= e($posted ? $row['compare_price'] : $cents($row['compare_price'])) ?>" aria-label="<?= e(t('Previous price')) ?>"></td>
                        <td>
                            <input type="hidden" name="<?= e($n) ?>[stock0]" value="<?= e((string) (int) (isset($row['stock0']) ? $row['stock0'] : $row['stock'])) ?>">
                            <input class="af-input af-input-sm" type="number" min="0" step="1" name="<?= e($n) ?>[stock]" value="<?= e($row['stock']) ?>" aria-label="<?= e(t('Stock')) ?>">
                        </td>
                        <td><input class="af-input af-input-sm" type="number" min="0" step="1" name="<?= e($n) ?>[weight]" value="<?= e($row['weight']) ?>" aria-label="<?= e(t('Weight')) ?>"></td>
                        <td>
                            <select class="af-select" name="<?= e($n) ?>[media_id]" aria-label="<?= e(t('Image')) ?>">
                                <option value=""><?= e(t('The one of the product')) ?></option>
                                <?php foreach ($images as $mediaId => $text) : ?>
                                <option value="<?= (int) $mediaId ?>"<?= (int) $row['media_id'] === (int) $mediaId ? ' selected' : '' ?>><?= e($text) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="hidden" name="<?= e($n) ?>[active]" value="0">
                            <input type="checkbox" name="<?= e($n) ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?> aria-label="<?= e(t('Active')) ?>">
                        </td>
                        <td><input type="checkbox" name="<?= e($n) ?>[delete]" value="1" aria-label="<?= e(t('Delete')) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="af-form-actions">
            <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save all')) ?></button>
            <span class="af-muted af-small"><?= e(t('The ones you tick under Delete are removed when you save.')) ?></span>
        </div>
    </form>
<?php endif; ?>
</div>
