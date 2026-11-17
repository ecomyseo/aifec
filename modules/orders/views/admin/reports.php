<?php
/**
 * AIFEC — «Ventas › Informes»: periodo, cifras del periodo, ventas por día (gráfica del inicio con su tabla), lo más vendido,
 * formas de pago y países. Cada tabla con su «Exportar CSV». Sin JavaScript: el periodo es un formulario GET.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $currency moneda de la tienda
 * @var array{currency:string,n:int,gross:int,net:int,units:int,average:int} $main
 * @var array<int,array{currency:string,n:int,gross:int,net:int,units:int,average:int}> $summary
 * @var array{by:string,points:array<int,array{key:string,n:int,amount:int}>} $series
 * @var array<int,array{product_id:int,name:string,currency:string,units:int,amount:int}> $products
 * @var array<int,array{method:string,title:string,currency:string,n:int,amount:int}> $methods
 * @var array<int,array{country:string,currency:string,n:int,amount:int}> $countries
 * @var array{buyers:int,new:int,returning:int} $customers
 * @var array<int,array{currency:string,n:int,amount:int}> $refunds
 * @var int $tests pedidos de prueba del periodo (no cuentan en ninguna cifra)
 * @var array{key:string,from:string,to:string,days:int,clipped:bool,invalid:bool} $period
 * @var array<string,string> $query
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\Clock;
use Aifec\Modules\Orders\Admin\OrdersDashboard;

$app = app();
$day = static function (string $ymd) use ($app): string {
    return $app->i18n()->date(Clock::parse($ymd . ' 12:00:00'), 'short', 'UTC');
};
$pointLabel = static function (string $key) use ($day): string {
    return strlen($key) === 7 ? substr($key, 5, 2) . '/' . substr($key, 0, 4) : $day($key);
};
$export = static function (string $table) use ($app, $query): string {
    return '<a class="af-btn af-btn-sm af-btn-ghost" href="' . e($app->url()->plain('/admin/reports/export.csv', ['table' => $table] + $query)) . '">'
        . Admin::icon('download') . e(t('Export CSV')) . '</a>';
};
$refundMain = ['n' => 0, 'amount' => 0];
foreach ($refunds as $row) {
    if ($row['currency'] === $currency) {
        $refundMain = $row;
    }
}
$periods = [
    'today' => t('Today'), '7d' => t('Last 7 days'), '30d' => t('Last 30 days'),
    'month' => t('This month'), 'last_month' => t('Last month'), 'custom' => t('Between two dates'),
];
$points = [];
foreach ($series['points'] as $point) {
    $points[] = ['label' => $pointLabel($point['key']), 'n' => $point['n'], 'amount' => $point['amount']];
}
$byMonth = $series['by'] === 'month';
?>
<form method="get" action="<?= e($app->url()->plain('/admin/reports')) ?>" class="af-card af-toolbar" style="padding: var(--s3) var(--s4);flex-wrap:wrap;align-items:flex-end;gap: var(--s2)">
    <label class="af-field-inline"><span class="af-small af-muted"><?= e(t('Period')) ?></span><br>
        <select class="af-input" name="period">
            <?php foreach ($periods as $key => $label) : ?>
                <option value="<?= e($key) ?>"<?= $period['key'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><span class="af-small af-muted"><?= e(t('From date')) ?></span><br><input class="af-input" type="date" name="from" value="<?= e($period['key'] === 'custom' ? $period['from'] : '') ?>"></label>
    <label><span class="af-small af-muted"><?= e(t('To date')) ?></span><br><input class="af-input" type="date" name="to" value="<?= e($period['key'] === 'custom' ? $period['to'] : '') ?>"></label>
    <button type="submit" class="af-btn af-btn-primary"><?= e(t('See the report')) ?></button>
</form>
<?php if ($period['clipped']) : ?>
    <div class="af-alert af-alert-warn" role="status"><?= e(t('The period was longer than two years: it has been cut to the last two years.')) ?></div>
<?php endif; ?>
<?php if ($period['invalid']) : ?>
    <div class="af-alert af-alert-warn" role="status"><?= e(t('Those dates are not valid: the last 30 days are shown.')) ?></div>
<?php endif; ?>
<p class="af-muted af-small" data-af-report-period><?= e(t('From {from} to {to}: {days} days. Paid orders only.', ['from' => $day($period['from']), 'to' => $day($period['to']), 'days' => $period['days']])) ?></p>

<?php if ($tests > 0) : ?>
    <p class="af-muted af-small" data-af-report-tests><?= e(tn('{n} test order in this period does not count in any figure.', '{n} test orders in this period do not count in any figure.', $tests)) ?></p>
<?php endif; ?>
<div class="af-grid">
    <?php
    $tiles = [
        [t('Sales with tax'), money($main['gross'], $currency)],
        [t('Sales without tax'), money($main['net'], $currency)],
        [t('Orders'), (string) $main['n']],
        [t('Average order'), money($main['average'], $currency)],
        [t('Units sold'), (string) $main['units']],
        [t('New customers'), (string) $customers['new']],
        [t('Returning customers'), (string) $customers['returning']],
        [t('Refunds in the period'), money((int) $refundMain['amount'], $currency) . ' · ' . tn('{n} refund', '{n} refunds', (int) $refundMain['n'])],
    ];
    ?>
    <?php foreach ($tiles as $tile) : ?>
        <div class="af-card af-col-3"><div class="af-stat"><div class="af-stat-label"><?= e($tile[0]) ?></div><div class="af-stat-value" data-af-report-stat><?= e($tile[1]) ?></div></div></div>
    <?php endforeach; ?>
</div>
<p class="af-toolbar"><?= $export('summary') ?><?= $export('customers') ?><?= $export('refunds') ?></p>

<?php if (count($summary) > 1 || ($summary !== [] && $summary[0]['currency'] !== $currency)) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('By currency')) ?></h2></div>
        <div class="af-table-wrap"><table class="af-table" data-af-report="summary">
            <thead><tr><th scope="col"><?= e(t('Currency')) ?></th><th scope="col" class="af-num"><?= e(t('Sales with tax')) ?></th><th scope="col" class="af-num"><?= e(t('Sales without tax')) ?></th><th scope="col" class="af-num"><?= e(t('Orders')) ?></th><th scope="col" class="af-num"><?= e(t('Average order')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($summary as $row) : ?>
                    <tr><td><?= e($row['currency']) ?></td><td class="af-num"><?= e(money($row['gross'], $row['currency'])) ?></td><td class="af-num"><?= e(money($row['net'], $row['currency'])) ?></td><td class="af-num"><?= e((string) $row['n']) ?></td><td class="af-num"><?= e(money($row['average'], $row['currency'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<div class="af-card">
    <div class="af-card-head"><h2><?= e($byMonth ? t('Sales per month') : t('Sales per day')) ?></h2><?= $export('series') ?></div>
    <div class="af-card-body"><?= OrdersDashboard::bars($points, $byMonth ? t('Sales per month') : t('Sales per day'), $currency) ?></div>
    <div class="af-table-wrap"><table class="af-table" data-af-report="series">
        <caption class="af-sr"><?= e($byMonth ? t('Sales per month') : t('Sales per day')) ?></caption>
        <thead><tr><th scope="col"><?= e($byMonth ? t('Month') : t('Day')) ?></th><th scope="col" class="af-num"><?= e(t('Orders')) ?></th><th scope="col" class="af-num"><?= e(t('Sales with tax')) ?></th></tr></thead>
        <tbody>
            <?php foreach ($points as $point) : ?>
                <tr><td><?= e($point['label']) ?></td><td class="af-num"><?= e((string) $point['n']) ?></td><td class="af-num"><?= e(money($point['amount'], $currency)) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('The 10 best-selling products')) ?></h2><?= $export('products') ?></div>
    <?php if ($products === []) : ?>
        <div class="af-empty"><p><?= e(t('Nothing was sold in this period.')) ?></p></div>
    <?php else : ?>
        <div class="af-table-wrap"><table class="af-table" data-af-report="products">
            <thead><tr><th scope="col"><?= e(t('Product')) ?></th><th scope="col" class="af-num"><?= e(t('Units')) ?></th><th scope="col" class="af-num"><?= e(t('Amount')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($products as $row) : ?>
                    <tr><td><?= e($row['name']) ?></td><td class="af-num"><?= e((string) $row['units']) ?></td><td class="af-num"><?= e(money($row['amount'], $row['currency'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="af-grid">
    <div class="af-card af-col-6">
        <div class="af-card-head"><h2><?= e(t('Sales by payment method')) ?></h2><?= $export('methods') ?></div>
        <div class="af-table-wrap"><table class="af-table" data-af-report="methods">
            <thead><tr><th scope="col"><?= e(t('Payment method')) ?></th><th scope="col" class="af-num"><?= e(t('Orders')) ?></th><th scope="col" class="af-num"><?= e(t('Amount')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($methods as $row) : ?>
                    <tr><td><?= e($row['title'] !== '' ? $row['title'] : '—') ?></td><td class="af-num"><?= e((string) $row['n']) ?></td><td class="af-num"><?= e(money($row['amount'], $row['currency'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="af-card af-col-6">
        <div class="af-card-head"><h2><?= e(t('Sales by country')) ?></h2><?= $export('countries') ?></div>
        <div class="af-table-wrap"><table class="af-table" data-af-report="countries">
            <thead><tr><th scope="col"><?= e(t('Country')) ?></th><th scope="col" class="af-num"><?= e(t('Orders')) ?></th><th scope="col" class="af-num"><?= e(t('Amount')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($countries as $row) : ?>
                    <tr><td><?= e($row['country'] !== '' ? $row['country'] : '—') ?></td><td class="af-num"><?= e((string) $row['n']) ?></td><td class="af-num"><?= e(money($row['amount'], $row['currency'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
