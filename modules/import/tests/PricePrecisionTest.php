<?php
/**
 * AIFEC — la precisión de los precios en la migración: PrestaShop guarda el precio sin impuestos y con seis decimales, y al ponerle
 * el impuesto aquí hay que partir de esas millonésimas, no del céntimo ya redondeado (82,644628 € + 21 % son 100,00 €; desde 82,64 €
 * saldrían 99,99 €). Cubre Neutral::micros(), el formato neutro, los dos lectores y los escritores de productos y transportistas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\PrestaShop\Env;
use Aifec\Modules\Import\Readers\PrestaShop\Mapper as PsMapper;
use Aifec\Modules\Import\Readers\WooCommerce\Mapper as WooMapper;
use Aifec\Modules\Import\Writers\CarriersWriter;
use Aifec\Modules\Import\Writers\ProductsWriter;
use Aifec\Modules\Import\Writers\ShippingZonesWriter;
use Aifec\Modules\Import\Writers\TaxClassesWriter;
use Aifec\Modules\Import\Writers\TaxRatesWriter;

require_once __DIR__ . '/Writers/WritersKit.php';

/** Un entorno de PrestaShop armado a mano: castellano, euros, atributos 21 y 22 del grupo 1. */
function ppEnv(): Env
{
    $env = new Env();
    $env->cfg = ['PS_LANG_DEFAULT' => '1', 'PS_CURRENCY_DEFAULT' => '1', 'PS_HOME_CATEGORY' => '2', 'PS_ROOT_CATEGORY' => '1', 'PS_SHIPPING_METHOD' => '1'];
    $env->langs = [1 => 'es'];
    $env->allLangs = [1 => 'es'];
    $env->defaultLang = 1;
    $env->currencies = [1 => 'EUR'];
    $env->countries = [1 => ['iso' => 'ES', 'zone' => 2, 'states' => true, 'active' => true]];
    $env->states = [];
    $env->taxes = [1 => ['rate' => '21.000', 'name' => [1 => 'IVA 21 %']]];
    $env->timezone = 'Europe/Madrid';
    $env->baseUrl = 'https://tienda.test';
    $env->now = '2026-11-01T00:00:00Z';
    $env->version = '8.2.0';
    $env->loaders = ['attribute_groups' => static function (): array {
        return [21 => 1, 22 => 1];
    }];

    return $env;
}

/** Un precio específico general de PrestaShop; se cambia lo que haga falta. */
function ppSpecific(array $over = []): array
{
    return $over + ['id' => 1, 'id_group' => 0, 'id_country' => 0, 'id_currency' => 0, 'id_customer' => 0, 'id_product_attribute' => 0, 'from_quantity' => 1,
        'price' => '-1.000000', 'reduction' => '0', 'reduction_tax' => 0, 'reduction_type' => 'amount', 'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00'];
}

/** Un producto de PrestaShop sencillo a 100,00 € con impuestos (82,644628 sin ellos) y la clase de impuestos 1 (21 %). */
function ppPsProduct(array $over = []): array
{
    return $over + ['id' => 7, 'name' => [1 => 'Mesa de prueba'], 'price' => '82.644628', 'active' => 1, 'id_tax_rules_group' => 1];
}

/** El traductor de WooCommerce con los precios sin impuestos en el origen. */
function ppWoo(bool $includesTax = false): WooMapper
{
    return new WooMapper(['locale' => 'es_ES', 'timezone' => 'Europe/Madrid', 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'prices_include_tax' => $includesTax, 'site_url' => 'https://tienda.test']);
}

/** Un sitio con lo que el producto necesita ya escrito. Por defecto los precios aquí llevan impuestos; con ['tax.prices_include_tax' => false], no. */
function ppSite(array $settings = []): array
{
    $app = writersApp($settings);
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->unmapped = [];
    $ctx->notes = [];

    return [$app, $ctx, new ProductsWriter($app)];
}

/** Escribe un producto sencillo (clase 1, el 21 %) con lo que se diga y devuelve su fila. */
function ppProductRow(array $site, array $over): array
{
    static $n = 0;
    ++$n;
    list($app, $ctx, $writer) = $site;
    $record = wr('products', $over + [
        'id' => 'pp' . $n, 'sku' => 'PP' . $n, 'name' => ['es' => 'Precio ' . $n], 'slug' => ['es' => 'precio-' . $n], 'summary' => [], 'description' => [], 'old_paths' => [],
        'price_includes_tax' => false, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null,
        'price_micros' => null, 'compare_price_micros' => null, 'sale_price_micros' => null,
        'variants' => [], 'images' => [], 'related_ids' => [], 'attributes' => [], 'tags' => [], 'meta_title' => [], 'meta_description' => [], 'extra' => [],
    ]);
    $out = $writer->write($record, $ctx);
    check(in_array($out['result'], ['created', 'updated'], true), 'el producto se escribe: ' . $out['result'] . ' ' . (isset($out['reason']) ? $out['reason'] : ''));

    return $app->db()->find('products', ['id' => (int) $out['id']]);
}

/** Un sitio con la clase de impuestos del origen (21 %), una zona que cubre España y el contexto. */
function ppCarrierSite(array $settings = []): array
{
    $app = writersApp($settings);
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    (new ShippingZonesWriter($app))->write(wr('shipping_zones', ['id' => 'z1', 'name' => 'Zona de prueba', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => '']], 'position' => 0, 'active' => true]), $ctx);

    return [$app, $ctx];
}

/**
 * Escribe un transportista con tarifas planas y devuelve sus precios aquí, en orden.
 *
 * @param array<int,array<string,mixed>> $rates [['price' => céntimos, 'price_micros' => millonésimas (opcional)], …]
 *
 * @return int[]
 */
function ppCarrierPrices(array $site, array $rates, bool $includes = false, int $fee = 0, ?int $feeMicros = null): array
{
    static $n = 0;
    ++$n;
    list($app, $ctx) = $site;
    $rows = [];
    foreach ($rates as $rate) {
        $rows[] = $rate + ['zone_id' => 'z1', 'basis' => 'flat', 'min' => 0, 'max' => null];
    }
    $record = wr('carriers', [
        'id' => 'pc' . $n, 'name' => 'Envío de precisión ' . $n, 'kind' => 'rates', 'tax_class_id' => 'c1', 'price_includes_tax' => $includes, 'free_from' => null, 'max_weight' => 0,
        'handling_fee' => $fee, 'handling_fee_micros' => $feeMicros, 'rates' => $rows, 'zone_ids' => ['z1'], 'position' => 0, 'active' => true, 'logo' => null,
    ]);
    $out = (new CarriersWriter($app))->write($record, $ctx);
    check(in_array($out['result'], ['created', 'updated'], true), 'el transportista se escribe: ' . $out['result']);
    $prices = [];
    foreach ($app->db()->select('SELECT price FROM {carrier_rates} WHERE carrier_id = ? ORDER BY id', [$out['id']]) as $row) {
        $prices[] = (int) $row['price'];
    }

    return $prices;
}

// ---------------------------------------------------------------------------------------------------------------- Neutral::micros

test('micros(): coma o punto, signo, entero, vacío y basura', function (): void {
    same(82644628, Neutral::micros('82.644628'));
    same(19990000, Neutral::micros('19,99'));
    same(12000000, Neutral::micros(12));
    same(1234500000, Neutral::micros('1234.5'));
    same(500000, Neutral::micros('.5'));
    same(5000000, Neutral::micros('5.'));
    same(2000000, Neutral::micros('+2'));
    same(-1500000, Neutral::micros('-1.5'));
    same(-1500000, Neutral::micros('-1,5'));
    same(19990000, Neutral::micros(19.99), 'un decimal de PHP tampoco se desvía');
    same(0, Neutral::micros(''));
    same(0, Neutral::micros('   '));
    same(0, Neutral::micros(null));
    same(0, Neutral::micros('abc'));
    same(0, Neutral::micros('12,5 €'));
    same(0, Neutral::micros('1.2.3'));
    same(0, Neutral::micros('.'));
});

test('micros(): de más de seis decimales se corta, no se redondea hacia arriba', function (): void {
    same(999999, Neutral::micros('0.9999999'), 'un 0.9999999 no pasa a 1.000000: el origen no guardaba esa millonésima');
    same(1234567, Neutral::micros('1.23456789'));
    same(1000000, Neutral::micros('1.0000009'));
    same(-999999, Neutral::micros('-0.9999999'));
});

test('micros() y cents() dicen lo mismo: el céntimo es el redondeo de las millonésimas', function (): void {
    foreach (['82.644628', '12.355372', '0.005', '0.0049999', '1.2349999', '1.2345', '0.9999999', '14.95', '19,99', '123.966942', '0', '100'] as $text) {
        same(Neutral::cents($text), Money::divRound(Neutral::micros($text), 10000), 'cents y micros de «' . $text . '»');
    }
});

// ---------------------------------------------------------------------------------------------------------------- formato neutro

test('el formato neutro acepta los registros con y sin los campos de precisión y rechaza un _micros que no sea entero', function (): void {
    $with = neutralSample('products');
    same([], Neutral::check('products', $with), 'con los campos nuevos');
    $without = $with;
    unset($without['price_micros'], $without['compare_price_micros'], $without['sale_price_micros']);
    unset($without['variants'][0]['price_micros'], $without['variants'][0]['compare_price_micros']);
    same([], Neutral::check('products', $without), 'sin ellos');

    foreach (['price_micros', 'sale_price_micros', 'compare_price_micros'] as $field) {
        foreach (['82.64', 1.5, true, []] as $bad) {
            check(in_array('products.' . $field . ': expected an integer', Neutral::check('products', [$field => $bad] + $with), true), $field . ' no admite ' . json_encode($bad));
        }
    }
    $variant = $with;
    $variant['variants'][0]['price_micros'] = '106.61';
    contains('products.variants[0].price_micros: expected an integer', implode('|', Neutral::check('products', $variant)));

    $carrier = neutralSample('carriers');
    same([], Neutral::check('carriers', $carrier));
    $carrier['rates'][0]['price_micros'] = 5.9;
    contains('carriers.rates[0].price_micros: expected an integer', implode('|', Neutral::check('carriers', $carrier)));
    $fee = neutralSample('carriers');
    $fee['handling_fee_micros'] = 'x';
    contains('carriers.handling_fee_micros: expected an integer', implode('|', Neutral::check('carriers', $fee)));
});

test('el formato neutro: sale_reduction es una parte con importe, millonésimas opcionales y si lleva impuestos', function (): void {
    $product = neutralSample('products');
    same([], Neutral::check('products', ['sale_reduction' => ['amount' => 1000, 'amount_micros' => 10000000, 'includes_tax' => true]] + $product));
    same([], Neutral::check('products', ['sale_reduction' => ['amount' => 1000, 'includes_tax' => false]] + $product), 'las millonésimas son opcionales');
    same([], Neutral::check('products', ['sale_reduction' => null] + $product), 'y la rebaja admite null');
    contains('products.sale_reduction.includes_tax: missing', implode('|', Neutral::check('products', ['sale_reduction' => ['amount' => 1000]] + $product)));
    contains('products.sale_reduction.amount: missing', implode('|', Neutral::check('products', ['sale_reduction' => ['includes_tax' => true]] + $product)));
    contains('products.sale_reduction.amount_micros: expected an integer', implode('|', Neutral::check('products', ['sale_reduction' => ['amount' => 1000, 'amount_micros' => '10', 'includes_tax' => true]] + $product)));
    contains('products.sale_reduction.extra: unknown field', implode('|', Neutral::check('products', ['sale_reduction' => ['amount' => 1000, 'includes_tax' => true, 'extra' => 1]] + $product)));
});

// ---------------------------------------------------------------------------------------------------------------- escritor de productos

test('productos con el 21 %: las millonésimas dan 100,00, 14,95, 19,99, 0,99 y 150,00', function (): void {
    $site = ppSite();
    foreach (['82.644628' => 10000, '12.355372' => 1495, '16.520661' => 1999, '0.818182' => 99, '123.966942' => 15000] as $text => $expected) {
        $row = ppProductRow($site, ['price' => Neutral::cents($text), 'price_micros' => Neutral::micros($text)]);
        same($expected, (int) $row['price'], $text . ' sin impuestos son ' . $expected . ' con ellos');
    }
});

test('productos: sin _micros (un registro antiguo) 8264 sigue dando 9999 y 1236 sigue dando 1496', function (): void {
    $site = ppSite();
    same(9999, (int) ppProductRow($site, ['price' => 8264])['price'], 'es lo que había y no se toca');
    same(1496, (int) ppProductRow($site, ['price' => 1236])['price']);
});

test('productos: unas millonésimas que no cuadran con el céntimo se ignoran y manda el céntimo', function (): void {
    $site = ppSite();
    same(9999, (int) ppProductRow($site, ['price' => 8264, 'price_micros' => 90000000])['price'], 'registro retocado a mano');
    same(9999, (int) ppProductRow($site, ['price' => 8264, 'price_micros' => -82644628])['price'], 'negativas');
});

test('productos: la rebaja se calcula desde sus millonésimas (66,115702 con el 21 % son 80,00 y no 80,01)', function (): void {
    $site = ppSite();
    $exact = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_price' => 6612, 'sale_price_micros' => 66115702]);
    same([10000, 8000], [(int) $exact['price'], (int) $exact['sale_price']]);
    $legacy = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_price' => 6612]);
    same(8001, (int) $legacy['sale_price'], 'desde el céntimo salen 80,01');
});

test('productos: el precio anterior también sale de sus millonésimas', function (): void {
    $site = ppSite();
    $row = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'compare_price' => 1236, 'compare_price_micros' => 12355372]);
    same([10000, 1495], [(int) $row['price'], (int) $row['compare_price']]);
});

test('productos con el 10 % y con el 4 %', function (): void {
    $site = ppSite();
    list($app, $ctx) = $site;
    $classes = new TaxClassesWriter($app);
    $classes->write(wr('tax_classes', ['id' => '2', 'code' => 'reduced', 'name' => 'Reducido', 'is_default' => false]), $ctx);
    $classes->write(wr('tax_classes', ['id' => '3', 'code' => 'super-reduced', 'name' => 'Superreducido', 'is_default' => false]), $ctx);
    $reduced = ppProductRow($site, ['tax_class_id' => '2', 'price' => 955, 'price_micros' => 9545454]);
    same(1050, (int) $reduced['price'], '9,545454 + 10 % son 10,50 (desde 9,55 saldrían 10,51)');
    $super = ppProductRow($site, ['tax_class_id' => '3', 'price' => 9615, 'price_micros' => 96153846]);
    same(10000, (int) $super['price'], '96,153846 + 4 % son 100,00');
});

test('productos: con tipo 0 o con importe 0 no hay conversión y se usa el céntimo; sin clase de impuestos manda la clase por defecto', function (): void {
    $site = ppSite();
    list($app, $ctx) = $site;
    (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => '4', 'code' => 'zero', 'name' => 'Tipo cero', 'is_default' => false]), $ctx);
    same(8264, (int) ppProductRow($site, ['tax_class_id' => '4', 'price' => 8264, 'price_micros' => 82644628])['price'], 'tipo 0');
    same(10000, (int) ppProductRow($site, ['tax_class_id' => null, 'price' => 8264, 'price_micros' => 82644628])['price'], 'sin clase: la de por defecto, el 21 %, también desde las millonésimas');
    $zero = ppProductRow($site, ['price' => 0, 'price_micros' => 0, 'sale_price' => 0, 'sale_price_micros' => 0]);
    same([0, 0], [(int) $zero['price'], (int) $zero['sale_price']], 'importe 0');
});

test('productos al revés: origen con impuestos y varios decimales, sitio sin impuestos (100,000000 son 82,64; 14,950000 son 12,36)', function (): void {
    $site = ppSite(['tax.prices_include_tax' => false]);
    same(8264, (int) ppProductRow($site, ['price_includes_tax' => true, 'price' => 10000, 'price_micros' => 100000000])['price']);
    same(1236, (int) ppProductRow($site, ['price_includes_tax' => true, 'price' => 1495, 'price_micros' => 14950000])['price']);
    same(8264, (int) ppProductRow($site, ['price_includes_tax' => true, 'price' => 10000])['price'], 'y sin millonésimas, como antes');
});

test('productos: si el origen y el sitio coinciden en llevar impuestos, el céntimo pasa tal cual aunque vengan millonésimas', function (): void {
    $withTax = ppSite();
    same(10000, (int) ppProductRow($withTax, ['price_includes_tax' => true, 'price' => 10000, 'price_micros' => 100000000])['price']);
    $withoutTax = ppSite(['tax.prices_include_tax' => false]);
    same(8264, (int) ppProductRow($withoutTax, ['price_includes_tax' => false, 'price' => 8264, 'price_micros' => 82644628])['price']);
});

test('variantes: base 8,223140 más impacto 4,132232 son 12,355372 y cuestan 14,95', function (): void {
    $site = ppSite();
    $variant = ['id' => '900', 'sku' => 'PP-V1', 'price' => 1236, 'price_micros' => 8223140 + 4132232, 'compare_price' => null, 'cost' => null, 'stock' => 1, 'weight' => 0, 'image_index' => null,
        'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'a1', 'value_id' => '21']]];
    $row = ppProductRow($site, ['price' => 822, 'price_micros' => 8223140, 'variants' => [$variant]]);
    $stored = $site[0]->db()->row('SELECT * FROM {variants} WHERE product_id = ?', [$row['id']]);
    same([995, 1495], [(int) $row['price'], (int) $stored['price']], 'el producto, 8,22 + 21 % = 9,95, y la variante a 14,95');
    $old = $variant;
    unset($old['price_micros']);
    $oldRow = ppProductRow($site, ['price' => 822, 'variants' => [['id' => '901', 'sku' => 'PP-V2'] + $old]]);
    same(1496, (int) $site[0]->db()->row('SELECT * FROM {variants} WHERE product_id = ?', [$oldRow['id']])['price'], 'una variante sin millonésimas, como antes');
});

test('rebaja por importe con impuestos: 10,000000 sobre 100,00 deja 90,00', function (): void {
    $site = ppSite();
    $row = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 1000, 'amount_micros' => 10000000, 'includes_tax' => true],
        'sale_from' => '2026-11-01T00:00:00Z', 'sale_to' => '2026-12-01T00:00:00Z']);
    same([10000, 9000], [(int) $row['price'], (int) $row['sale_price']]);
    same(['2026-11-01', '2026-12-01'], [substr((string) $row['sale_from'], 0, 10), substr((string) $row['sale_to'], 0, 10)], 'con sus fechas');
});

test('rebaja por importe con impuestos y el sitio con precios sin impuestos: 82,64 menos 8,26 son 74,38', function (): void {
    $site = ppSite(['tax.prices_include_tax' => false]);
    $row = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 1000, 'amount_micros' => 10000000, 'includes_tax' => true]]);
    same([8264, 7438], [(int) $row['price'], (int) $row['sale_price']], '10,00 con el 21 % son 8,26 sin él');
    $net = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 826, 'amount_micros' => 8264463, 'includes_tax' => false]]);
    same(7438, (int) $net['sale_price'], 'una rebaja que ya viene sin impuestos se resta tal cual');
});

test('rebaja por importe: si no deja un precio por debajo del del producto, no hay rebaja y se anota', function (): void {
    $site = ppSite();
    $bigger = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 20000, 'amount_micros' => 200000000, 'includes_tax' => true]]);
    same([10000, null], [(int) $bigger['price'], $bigger['sale_price']], 'una rebaja mayor que el precio');
    $notes = implode(' | ', $site[1]->noteTexts('products'));
    contains('The reduction does not leave a price below the product price', $notes);
    $equal = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 10000, 'amount_micros' => 100000000, 'includes_tax' => true]]);
    same(null, $equal['sale_price'], 'una rebaja igual al precio tampoco es un precio rebajado');
    $none = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_reduction' => ['amount' => 0, 'includes_tax' => true]]);
    same(null, $none['sale_price'], 'una rebaja de 0');
});

test('rebaja por importe: si el registro ya trae un precio rebajado, ese manda', function (): void {
    $site = ppSite();
    $row = ppProductRow($site, ['price' => 8264, 'price_micros' => 82644628, 'sale_price' => 6612, 'sale_price_micros' => 66115702,
        'sale_reduction' => ['amount' => 1000, 'amount_micros' => 10000000, 'includes_tax' => true]]);
    same(8000, (int) $row['sale_price']);
});

// ---------------------------------------------------------------------------------------------------------------- escritor de transportistas

test('transportista: una tarifa de 12,355372 sin impuestos cuesta 14,95 con ellos', function (): void {
    $site = ppCarrierSite();
    same([1495, 1496], ppCarrierPrices($site, [['price' => 1236, 'price_micros' => 12355372], ['price' => 1236]]), 'con millonésimas 14,95; sin ellas, como antes, 14,96');
});

test('transportista: tarifa 4,132231 y recargo 0,826446 se suman en millonésimas antes de convertir (4,958677 son 6,00)', function (): void {
    $site = ppCarrierSite();
    same([600], ppCarrierPrices($site, [['price' => 413, 'price_micros' => 4132231]], false, 83, 826446));
    // Aquí la suma de los céntimos sí se desvía: 12,36 + 0,83 = 13,19 con el 21 % son 15,96; las millonésimas suman 13,181818 y dan 15,95.
    same([1595], ppCarrierPrices($site, [['price' => 1236, 'price_micros' => 12355372]], false, 83, 826446));
    same([1596], ppCarrierPrices($site, [['price' => 1236]], false, 83, null), 'y sin millonésimas, como antes');
});

test('transportista: si solo una de las dos partes trae millonésimas, la otra entra como céntimos exactos', function (): void {
    $site = ppCarrierSite();
    same([1495], ppCarrierPrices($site, [['price' => 1236, 'price_micros' => 12355372]], false, 0, null), 'tarifa con millonésimas y sin recargo');
    same([1210 + 121], ppCarrierPrices($site, [['price' => 1000]], false, 100, null), 'ninguna: como antes (10,00 + 1,00 = 11,00 con el 21 % son 13,31)');
    same([1210 + 121], ppCarrierPrices($site, [['price' => 1000]], false, 100, 1000000), 'solo el recargo: 10,00 + 1,000000 son 13,31');
});

test('transportista al revés: precios con impuestos y varios decimales, sitio sin impuestos', function (): void {
    $site = ppCarrierSite(['tax.prices_include_tax' => false]);
    same([8264, 1236], ppCarrierPrices($site, [['price' => 10000, 'price_micros' => 100000000], ['price' => 1495, 'price_micros' => 14950000]], true));
    same([8264], ppCarrierPrices($site, [['price' => 10000]], true), 'sin millonésimas, como antes');
});

test('transportista: unas millonésimas que no cuadran con el céntimo se ignoran', function (): void {
    $site = ppCarrierSite();
    same([1210], ppCarrierPrices($site, [['price' => 1000, 'price_micros' => 99000000]]));
    same([1210], ppCarrierPrices($site, [['price' => 1000]], false, 0, 5000000), 'un recargo en millonésimas con el céntimo en 0 tampoco');
});

// ---------------------------------------------------------------------------------------------------------------- lector de PrestaShop

test('lector de PrestaShop: entrega las millonésimas del producto y de sus combinaciones sin tocar los céntimos', function (): void {
    $product = PsMapper::product(ppPsProduct(['combinations' => [['id' => 31, 'price' => '4.132232', 'reference' => 'PP-A', 'values' => [21]], ['id' => 32, 'price' => '-1.000000', 'values' => [22]]]]), ppEnv());
    same([], Neutral::check('products', $product));
    same([8264, 82644628], [$product['price'], $product['price_micros']]);
    same([8678, 86776860], [$product['variants'][0]['price'], $product['variants'][0]['price_micros']], '82,644628 + 4,132232');
    same([8164, 81644628], [$product['variants'][1]['price'], $product['variants'][1]['price_micros']], 'con un impacto negativo');
    check(!array_key_exists('sale_price_micros', $product) && !array_key_exists('sale_reduction', $product), 'sin rebaja no hay campos de rebaja');
});

test('lector de PrestaShop: el precio rebajado lleva sus millonésimas (el 20 % de 82,644628 son 66,115702)', function (): void {
    $product = PsMapper::product(ppPsProduct(['specific_prices' => [ppSpecific(['reduction' => '0.200000', 'reduction_type' => 'percentage'])]]), ppEnv());
    same([6612, 66115702], [$product['sale_price'], $product['sale_price_micros']]);
    check(!array_key_exists('sale_reduction', $product));
});

test('lector de PrestaShop: una rebaja por importe con impuestos va tal cual en sale_reduction', function (): void {
    $sp = ppSpecific(['id' => 4, 'reduction' => '10.000000', 'reduction_tax' => 1, 'from' => '2026-11-10 00:00:00', 'to' => '2026-12-10 00:00:00']);
    $product = PsMapper::product(ppPsProduct(['specific_prices' => [$sp]]), ppEnv());
    same([], Neutral::check('products', $product));
    same(['amount' => 1000, 'amount_micros' => 10000000, 'includes_tax' => true], $product['sale_reduction']);
    same([null, '2026-11-09T23:00:00Z', '2026-12-09T23:00:00Z'], [$product['sale_price'], $product['sale_from'], $product['sale_to']]);
    check(!isset($product['extra']['specific_prices']), 'la rebaja elegida ya no se repite en extra');
});

test('lector de PrestaShop: de varias rebajas con impuestos, la mayor; las demás, la caducada, la de un grupo y la de precio fijo, a extra', function (): void {
    $product = PsMapper::product(ppPsProduct(['specific_prices' => [
        ppSpecific(['id' => 1, 'reduction' => '5.000000', 'reduction_tax' => 1]),
        ppSpecific(['id' => 2, 'reduction' => '12.500000', 'reduction_tax' => 1]),
        ppSpecific(['id' => 3, 'reduction' => '40.000000', 'reduction_tax' => 1, 'to' => '2026-01-01 00:00:00']),
        ppSpecific(['id' => 4, 'reduction' => '30.000000', 'reduction_tax' => 1, 'id_group' => 3]),
        ppSpecific(['id' => 5, 'reduction' => '20.000000', 'reduction_tax' => 1, 'price' => '70.000000']),
    ]]), ppEnv());
    same(1250, $product['sale_reduction']['amount']);
    same(['1', '3', '4', '5'], array_column($product['extra']['specific_prices'], 'id'), 'queda lo que no se pudo entregar');
});

test('lector de PrestaShop: si ya hay un precio rebajado, la rebaja con impuestos se queda en extra', function (): void {
    $product = PsMapper::product(ppPsProduct(['specific_prices' => [
        ppSpecific(['id' => 1, 'reduction' => '0.200000', 'reduction_type' => 'percentage']),
        ppSpecific(['id' => 2, 'reduction' => '10.000000', 'reduction_tax' => 1]),
    ]]), ppEnv());
    same([6612, 66115702], [$product['sale_price'], $product['sale_price_micros']]);
    check(!array_key_exists('sale_reduction', $product));
    same(['2'], array_column($product['extra']['specific_prices'], 'id'));
});

test('lector de PrestaShop: tarifas y recargo de un transportista con sus millonésimas', function (): void {
    $carrier = ['id_carrier' => 5, 'id_reference' => 9, 'name' => 'X', 'url' => '', 'is_free' => 0, 'shipping_method' => 1, 'shipping_handling' => 1, 'position' => 0, 'max_weight' => '0', 'emitted_zones' => [1],
        'zones' => [1], 'weight_ranges' => [1 => ['0.000000', '2.500000']], 'price_ranges' => [],
        'deliveries' => [['id_range_price' => 0, 'id_range_weight' => 1, 'id_zone' => 1, 'price' => '12.355372']]];
    $env = ppEnv();
    $env->cfg['PS_SHIPPING_HANDLING'] = '0.826446';
    $record = PsMapper::carrier($carrier, $env);
    same([], Neutral::check('carriers', $record));
    same([1236, 12355372], [$record['rates'][0]['price'], $record['rates'][0]['price_micros']]);
    same([83, 826446], [$record['handling_fee'], $record['handling_fee_micros']]);
    $without = PsMapper::carrier(['shipping_handling' => 0] + $carrier, $env);
    check(!array_key_exists('handling_fee_micros', $without), 'sin recargo no hay campo');
});

test('de punta a punta con PrestaShop: un producto a 82,644628 con una combinación y una rebaja con impuestos cobra lo mismo que en el origen', function (): void {
    $source = ppPsProduct(['combinations' => [['id' => 31, 'price' => '4.132232', 'reference' => 'PP-A', 'values' => [21]]],
        'specific_prices' => [ppSpecific(['reduction' => '10.000000', 'reduction_tax' => 1])]]);
    $record = PsMapper::product($source, ppEnv());
    same([], Neutral::check('products', $record));
    $record['old_paths'] = [];
    $site = ppSite();
    $out = $site[2]->write($record, $site[1]);
    same('created', $out['result']);
    $row = $site[0]->db()->find('products', ['id' => (int) $out['id']]);
    $variant = $site[0]->db()->row('SELECT * FROM {variants} WHERE product_id = ?', [$row['id']]);
    same([10000, 10500, 9000], [(int) $row['price'], (int) $variant['price'], (int) $row['sale_price']], 'producto 100,00; combinación 105,00; rebajado 90,00');
});

// ---------------------------------------------------------------------------------------------------------------- lector de WooCommerce

test('lector de WooCommerce: 82,6446 sin impuestos trae price_micros y aquí cuesta 100,00; 19,99 no manda el campo', function (): void {
    $woo = ppWoo();
    $precise = $woo->product(['id' => 5, 'name' => 'Mesa', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '82.6446', 'sale_price' => '', 'price' => '82.6446']);
    same([], Neutral::check('products', $precise));
    same([8264, 82644600], [$precise['price'], $precise['price_micros']]);
    check(!array_key_exists('sale_price_micros', $precise), 'sin rebaja no hay millonésimas de rebaja');
    $plain = $woo->product(['id' => 6, 'name' => 'Silla', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '19.99', 'sale_price' => '', 'price' => '19.99']);
    same(1999, $plain['price']);
    check(!array_key_exists('price_micros', $plain), 'con dos decimales no hace falta');
    $site = ppSite();
    $precise['old_paths'] = [];
    $precise['tax_class_id'] = '1';
    $out = $site[2]->write($precise, $site[1]);
    same(10000, (int) $site[0]->db()->find('products', ['id' => (int) $out['id']])['price'], 'de punta a punta');
});

test('lector de WooCommerce: el precio rebajado, la coma decimal y los ceros finales', function (): void {
    $woo = ppWoo();
    $product = $woo->product(['id' => 5, 'name' => 'Mesa', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '82,6446', 'sale_price' => '66.1157', 'price' => '66.1157']);
    same([8264, 6612, 82644600, 66115700], [$product['price'], $product['sale_price'], $product['price_micros'], $product['sale_price_micros']]);
    $zeros = $woo->product(['id' => 6, 'name' => 'Silla', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '19.990', 'sale_price' => '', 'price' => '19.990']);
    same([1999, 19990000], [$zeros['price'], $zeros['price_micros']], 'más de dos decimales en el texto: lo dice el origen, aunque el último sea un cero');
    $one = $woo->product(['id' => 7, 'name' => 'Banco', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '20', 'sale_price' => '', 'price' => '20']);
    check(!array_key_exists('price_micros', $one), 'un entero tampoco');
});

test('lector de WooCommerce: las variaciones con precisión llevan sus millonésimas y las que no, no', function (): void {
    $woo = ppWoo();
    $product = $woo->product(['id' => 5, 'name' => 'Camisa', 'type' => 'variable', 'status' => 'publish', 'regular_price' => '', 'sale_price' => '', 'price' => '', 'attributes' => [['id' => 0, 'name' => 'Talla', 'variation' => true, 'options' => ['M', 'L']]]], [
        ['id' => 51, 'regular_price' => '82.6446', 'sale_price' => '', 'attributes' => [['id' => 0, 'name' => 'Talla', 'option' => 'M']]],
        ['id' => 52, 'regular_price' => '90.12', 'sale_price' => '75.5555', 'attributes' => [['id' => 0, 'name' => 'Talla', 'option' => 'L']]],
    ]);
    same([], Neutral::check('products', $product));
    same(2, count($product['variants']));
    same([8264, 82644600], [$product['variants'][0]['price'], $product['variants'][0]['price_micros']]);
    same([7556, 75555500, 9012], [$product['variants'][1]['price'], $product['variants'][1]['price_micros'], $product['variants'][1]['compare_price']], 'rebajada con precisión y precio anterior de dos decimales');
    check(!array_key_exists('compare_price_micros', $product['variants'][1]), 'el precio anterior con dos decimales no manda el campo');
    same([7556, 75555500], [$product['price'], $product['price_micros']], 'el producto toma el más barato y sus millonésimas');
});

test('lector de WooCommerce: el coste de una tarifa con más de dos decimales, y una fórmula sigue como esté', function (): void {
    $woo = ppWoo();
    $precise = $woo->carrier(['id' => 1], ['instance_id' => 5, 'method_id' => 'flat_rate', 'settings' => ['cost' => '4.1322'], 'enabled' => true]);
    same([], Neutral::check('carriers', $precise));
    same([413, 4132200], [$precise['rates'][0]['price'], $precise['rates'][0]['price_micros']]);
    $plain = $woo->carrier(['id' => 1], ['instance_id' => 6, 'method_id' => 'flat_rate', 'settings' => ['cost' => '5.90'], 'enabled' => true]);
    same([['zone_id' => '1', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 590]], $plain['rates']);
    $formula = $woo->carrier(['id' => 1], ['instance_id' => 7, 'method_id' => 'flat_rate', 'settings' => ['cost' => '10 + 2 * [qty]'], 'enabled' => true]);
    same([['zone_id' => '1', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 0]], $formula['rates']);
    same('10 + 2 * [qty]', $formula['extra']['cost_formula']);
    $site = ppCarrierSite();
    same([1495], ppCarrierPrices($site, [['price' => 1236, 'price_micros' => 12355372]]), 'y escrita aquí con el 21 %');
});
