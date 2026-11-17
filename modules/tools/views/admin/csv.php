<?php
/**
 * AIFEC — pantalla del CSV de productos: exportar, subir un fichero y las importaciones anteriores.
 *
 * @var array<int,array<string,mixed>> $runs
 * @var string $exportUrl
 * @var string $uploadUrl
 * @var string[] $languages
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$columns = [
    ['sku', t('The reference. A row is matched with its product by it. Required.')],
    ['parent_sku', t('Only in the rows of variants: the sku of the product they belong to.')],
    ['name', t('Name (in a variant row, its label).')],
    ['summary, description', t('Texts, in HTML.')],
    ['price, compare_price, sale_price', t('Amounts with a decimal comma: 19,99. Written as the shop has them (with or without tax).')],
    ['stock', t('Units in stock.')],
    ['status', t('draft, published or archived.')],
    ['categories', t('Paths separated by |, levels by >: Furniture > Chairs|Home. The first one is the main category. Missing ones are created.')],
    ['brand', t('Name of the brand. It is created if it does not exist.')],
    ['tax_class', t('Code of the tax class.')],
    ['weight', t('Weight in grams.')],
    ['gtin, mpn', t('Product codes.')],
    ['images', t('Addresses of the images separated by |. They are downloaded; the first one is the main one.')],
    ['attribute:<code>', t('Value of an attribute. In a variant row, the option of that variant. Missing attributes and values are created as lists.')],
    ['meta_title, meta_description', t('Texts for search engines.')],
    ['name:<language>', t('Name in another language of the shop, for example name:en.')],
];

?>
<div class="af-grid">
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Export')) ?></h2></div>
            <div class="af-card-body af-stack">
                <p class="af-muted"><?= e(t('All the products and their variants in one file that a spreadsheet opens.')) ?></p>
                <p><a class="af-btn af-btn-primary" href="<?= e($exportUrl) ?>"><?= Admin::icon('download') ?><?= e(t('Download the products')) ?></a></p>
            </div>
        </div>
    </div>
    <div class="af-col-6">
        <div class="af-card">
            <div class="af-card-head"><h2><?= e(t('Import')) ?></h2></div>
            <div class="af-card-body">
                <form method="post" action="<?= e($uploadUrl) ?>" enctype="multipart/form-data" class="af-stack">
                    <?= Admin::tokenField(app()) ?>
                    <input type="file" name="csv" accept=".csv,text/csv,text/plain" required aria-label="<?= e(t('CSV file')) ?>">
                    <p class="af-muted af-small"><?= e(t('You will see a preview before anything is written.')) ?></p>
                    <div><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('upload') ?><?= e(t('Upload and preview')) ?></button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<details class="af-help">
    <summary><?= e(t('Columns of the file')) ?></summary>
    <div class="af-table-wrap">
        <table class="af-table">
            <tbody>
                <?php foreach ($columns as $column) : ?>
                    <tr><td class="af-mono af-small af-nowrap"><?= e($column[0]) ?></td><td><?= e($column[1]) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="af-small af-muted"><?= e(t('Languages of this shop: {list}.', ['list' => implode(', ', $languages)])) ?></p>
</details>
<?php if ($runs !== []) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Previous imports')) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <thead>
                    <tr>
                        <th><?= e(t('File')) ?></th>
                        <th><?= e(t('Uploaded')) ?> (UTC)</th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="af-right"><?= e(t('Created')) ?></th>
                        <th class="af-right"><?= e(t('Changed')) ?></th>
                        <th class="af-right"><?= e(t('Errors')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run) : ?>
                        <tr>
                            <td><?= e($run['name']) ?></td>
                            <td class="af-nowrap"><?= e($run['created_at']) ?></td>
                            <td><?= Admin::badge(\Aifec\Modules\Tools\CsvController::statusLabel((string) $run['status']), \Aifec\Modules\Tools\CsvController::statusKind((string) $run['status'])) ?></td>
                            <td class="af-right"><?= e((int) $run['created']) ?></td>
                            <td class="af-right"><?= e((int) $run['updated']) ?></td>
                            <td class="af-right"><?= e((int) $run['errors']) ?></td>
                            <td class="af-right"><a class="af-btn af-btn-sm" href="<?= e(app()->url()->plain('/admin/tools/csv/' . $run['token'])) ?>"><?= e(t('Open')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
