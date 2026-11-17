<?php
/**
 * AIFEC — el céntimo: lo que paga el cliente en el origen es lo que paga aquí, producto a producto y variante a variante. El redondeo es UNO,
 * al final, sobre el importe con impuestos; con precios sin impuestos aquí, donde una base en céntimos no siempre puede formar ese importe,
 * se busca la base que sí lo da, o la más cercana, y se apunta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Writers\ProductsWriter;
use Aifec\Modules\Import\Writers\TaxClassesWriter;

require_once __DIR__ . '/Writers/WritersKit.php';

/** Un sitio con las tres clases de impuestos de España (21, 10 y 4 %). Por defecto los precios llevan impuestos; con ['tax.prices_include_tax' => false], no. */
function pxSite(array $settings = []): array
{
    $app = writersApp($settings);
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $classes = new TaxClassesWriter($app);
    $classes->write(wr('tax_classes', ['id' => '2', 'code' => 'reduced', 'name' => 'Reducido', 'is_default' => false]), $ctx);
    $classes->write(wr('tax_classes', ['id' => '3', 'code' => 'super-reduced', 'name' => 'Superreducido', 'is_default' => false]), $ctx);
    $ctx->unmapped = [];
    $ctx->notes = [];

    return [$app, $ctx, new ProductsWriter($app)];
}

/** Escribe un producto (clase y precios que se digan) y devuelve su fila. */
function pxWrite(array $site, array $over): array
{
    static $n = 0;
    ++$n;
    list($app, $ctx, $writer) = $site;
    $record = wr('products', $over + [
        'id' => 'px' . $n, 'sku' => 'PX' . $n, 'name' => ['es' => 'Cuenta ' . $n], 'slug' => ['es' => 'cuenta-' . $n], 'summary' => [], 'description' => [], 'old_paths' => [],
        'price_includes_tax' => false, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null,
        'price_micros' => null, 'compare_price_micros' => null, 'sale_price_micros' => null,
        'variants' => [], 'images' => [], 'related_ids' => [], 'attributes' => [], 'tags' => [], 'meta_title' => [], 'meta_description' => [], 'extra' => [],
    ]);
    $out = $writer->write($record, $ctx);
    check(in_array($out['result'], ['created', 'updated'], true), 'el producto se escribe: ' . $out['result'] . ' ' . (isset($out['reason']) ? $out['reason'] : ''));

    return $app->db()->find('products', ['id' => (int) $out['id']]);
}

/** Lo que cuesta con impuestos aquí un importe escrito (con el tipo de aquí y según cómo se escriben los precios). */
function pxGross(App $app, ?string $classId, ?int $written): int
{
    $tax = $app->service('tax');
    $rate = (int) $tax->rate($classId !== null ? (int) $classId : null, $tax->baseDestination())['rate'];

    return $tax->pricesIncludeTax() ? (int) $written : Money::add((int) $written, $rate)[1];
}

/** Lo que pagaba el cliente en el origen: el redondeo, una sola vez, del precio sin impuestos con seis decimales más el impuesto. */
function pxSource(int $micros, int $rate): int
{
    return Money::divRound($micros * (Money::SCALE + $rate), 100000000);
}

/**
 * Los 40 precios de origen elegidos para romper el redondeo: 30 importes con impuestos «redondos» (99, 100, 101, 495, 505, 995, 1005…) vistos desde su
 * base sin impuestos con seis decimales, con el 21, el 10 y el 4 %, y 10 bases de medio céntimo (x,995 y x,005) repartidas entre los tres tipos.
 *
 * @return array<int,array{rate:int,class:string,micros:int,gross:int}>
 */
function pxCases(): array
{
    $classes = [2100 => '1', 1000 => '2', 400 => '3'];
    $cases = [];
    foreach ($classes as $rate => $class) {
        foreach ([99, 100, 101, 495, 505, 995, 1005, 1999, 9999, 10000] as $gross) {
            $micros = Money::divRound($gross * 100000000, Money::SCALE + $rate);
            $cases[] = ['rate' => $rate, 'class' => $class, 'micros' => $micros, 'gross' => pxSource($micros, $rate)];
        }
    }
    $rates = array_keys($classes);
    foreach (['0.995000', '1.005000', '2.995000', '3.005000', '9.995000', '10.005000', '49.995000', '50.005000', '99.995000', '100.005000'] as $i => $text) {
        $rate = $rates[$i % 3];
        $micros = Neutral::micros($text);
        $cases[] = ['rate' => $rate, 'class' => $classes[$rate], 'micros' => $micros, 'gross' => pxSource($micros, $rate)];
    }

    return $cases;
}

/** ¿Hay una base en céntimos que con ese impuesto dé exactamente ese importe? */
function pxReachable(int $gross, int $rate): bool
{
    for ($net = max(0, (int) floor($gross / (1 + $rate / 10000)) - 3); $net <= $gross; ++$net) {
        $total = Money::add($net, $rate)[1];
        if ($total === $gross) {
            return true;
        }
        if ($total > $gross) {
            return false;
        }
    }

    return false;
}

/** Las notas «differs by a cent» de un contexto. */
function pxCentNotes(WritersContext $ctx): int
{
    return count(array_filter($ctx->noteTexts(), static function (string $text): bool {
        return strpos($text, 'differs by a cent') !== false;
    }));
}

test('el céntimo: los 40 precios de origen están bien elegidos (los importes con impuestos salen de redondear UNA vez)', function (): void {
    $cases = pxCases();
    same(40, count($cases));
    foreach ($cases as $case) {
        // El origen redondeado una sola vez desde las seis decimales; redondear la base antes (dos veces) da otra cosa en muchos de ellos.
        $twice = Money::add(Money::divRound($case['micros'], Money::SCALE), $case['rate'])[1];
        same($case['gross'], pxSource($case['micros'], $case['rate']));
        check(abs($twice - $case['gross']) <= 1, 'dos redondeos no se alejan más de un céntimo');
    }
    $different = count(array_filter($cases, static function (array $c): bool {
        return Money::add(Money::divRound($c['micros'], Money::SCALE), $c['rate'])[1] !== $c['gross'];
    }));
    check($different >= 5, 'redondear dos veces falla en bastantes de ellos: ' . $different);
});

test('el céntimo con precios CON impuestos aquí: los 40 cuestan al céntimo lo mismo que en el origen (diferencia 0)', function (): void {
    $site = pxSite();
    foreach (pxCases() as $case) {
        $row = pxWrite($site, ['tax_class_id' => $case['class'], 'price' => Money::divRound($case['micros'], Money::SCALE), 'price_micros' => $case['micros']]);
        same($case['gross'], pxGross($site[0], $case['class'], (int) $row['price']), $case['micros'] . ' micros al ' . $case['rate'] / 100 . ' %');
    }
    same(0, pxCentNotes($site[1]), 'y no hace falta apuntar nada');
});

test('el céntimo con precios SIN impuestos aquí: diferencia 0 siempre que una base en céntimos pueda formar el importe; si no, un céntimo y apuntado', function (): void {
    $site = pxSite(['tax.prices_include_tax' => false]);
    $exact = 0;
    $inexact = 0;
    foreach (pxCases() as $case) {
        $before = pxCentNotes($site[1]);
        $row = pxWrite($site, ['tax_class_id' => $case['class'], 'price' => Money::divRound($case['micros'], Money::SCALE), 'price_micros' => $case['micros']]);
        $diff = pxGross($site[0], $case['class'], (int) $row['price']) - $case['gross'];
        $label = $case['micros'] . ' micros al ' . $case['rate'] / 100 . ' %';
        if (pxReachable($case['gross'], $case['rate'])) {
            same(0, $diff, $label . ': se puede formar y sale exacto');
            same($before, pxCentNotes($site[1]), $label . ': sin nota');
            ++$exact;
        } else {
            same(1, abs($diff), $label . ': no se puede formar, la más cercana');
            same($before + 1, pxCentNotes($site[1]), $label . ': se apunta');
            ++$inexact;
        }
    }
    check($exact >= 20, 'la mayoría salen exactos: ' . $exact);
    check($inexact >= 3, 'y hay importes que una base en céntimos no puede formar (a 21 %, 100,00 €): ' . $inexact);
});

test('el céntimo sin impuestos aquí: una base vecina da el importe exacto donde la redondeada no (1,013324 + 21 % son 1,23, no 1,22)', function (): void {
    $site = pxSite(['tax.prices_include_tax' => false]);
    $row = pxWrite($site, ['price' => 101, 'price_micros' => 1013324]);
    same(102, (int) $row['price'], 'la base 1,02 da 1,23; la 1,01 daría 1,22');
    same(123, pxGross($site[0], '1', 102));
    same(0, pxCentNotes($site[1]));
});

test('el céntimo con el origen CON impuestos y el sitio sin ellos: 100,01 se forma exacto; 100,00 no existe a 21 % y se queda a un céntimo, apuntado', function (): void {
    $site = pxSite(['tax.prices_include_tax' => false]);
    $a = pxWrite($site, ['price_includes_tax' => true, 'price' => 10001, 'price_micros' => 100010000]);
    same(10001, pxGross($site[0], '1', (int) $a['price']), '100,01');
    same(0, pxCentNotes($site[1]));
    $b = pxWrite($site, ['price_includes_tax' => true, 'price' => 10000, 'price_micros' => 100000000]);
    same(1, abs(pxGross($site[0], '1', (int) $b['price']) - 10000), '100,00 no existe');
    same(1, pxCentNotes($site[1]), 'y se apunta una vez');
    $c = pxWrite($site, ['price_includes_tax' => true, 'price' => 10000]);
    same((int) $b['price'], (int) $c['price'], 'sin millonésimas, el mismo resultado');
});

test('el céntimo con descuento: precio normal, de oferta y anterior salen del origen con un solo redondeo', function (): void {
    $site = pxSite();
    $cases = array_slice(pxCases(), 0, 10);
    foreach ($cases as $case) {
        $sale = (int) round($case['micros'] * 0.8);
        $row = pxWrite($site, [
            'tax_class_id' => $case['class'], 'price' => Money::divRound($case['micros'], Money::SCALE), 'price_micros' => $case['micros'],
            'sale_price' => Money::divRound($sale, Money::SCALE), 'sale_price_micros' => $sale,
            'compare_price' => Money::divRound($case['micros'], Money::SCALE), 'compare_price_micros' => $case['micros'],
        ]);
        same($case['gross'], pxGross($site[0], $case['class'], (int) $row['price']), 'el precio');
        same(pxSource($sale, $case['rate']), pxGross($site[0], $case['class'], (int) $row['sale_price']), 'la oferta');
        same($case['gross'], pxGross($site[0], $case['class'], (int) $row['compare_price']), 'y el precio anterior');
    }
});

test('el céntimo con variantes: cada una cuesta lo que cobraba el origen, con precios con y sin impuestos aquí', function (): void {
    foreach ([[], ['tax.prices_include_tax' => false]] as $settings) {
        $site = pxSite($settings);
        $net = $settings !== [];
        $cases = array_slice(pxCases(), 5, 20);
        foreach (array_chunk($cases, 2) as $pair) {
            // Las dos variantes comparten la clase de impuestos del producto: la del primer caso del par.
            $class = $pair[0]['class'];
            $rate = $pair[0]['rate'];
            $variants = [];
            foreach ($pair as $i => $case) {
                $variants[] = ['id' => 'v' . $case['micros'] . 'x' . $i, 'sku' => 'PXV' . $case['micros'] . $i, 'price' => Money::divRound($case['micros'], Money::SCALE), 'price_micros' => $case['micros'],
                    'compare_price' => null, 'cost' => null, 'stock' => 1, 'weight' => 0, 'image_index' => null, 'active' => true, 'position' => $i,
                    'values' => [['attribute_id' => 'a1', 'value_id' => $i === 0 ? '21' : '22']]];
            }
            $row = pxWrite($site, ['tax_class_id' => $class, 'price' => 100, 'price_micros' => null, 'variants' => $variants]);
            $stored = $site[0]->db()->select('SELECT sku, price FROM {variants} WHERE product_id = ? ORDER BY position', [$row['id']]);
            same(2, count($stored), 'las dos variantes se escriben');
            foreach ($stored as $i => $variant) {
                $expected = pxSource($pair[$i]['micros'], $rate);
                $diff = pxGross($site[0], $class, (int) $variant['price']) - $expected;
                if ($net && !pxReachable($expected, $rate)) {
                    same(1, abs($diff), 'variante ' . $variant['sku'] . ' sin impuestos: un céntimo');
                } else {
                    same(0, $diff, 'variante ' . $variant['sku'] . ($net ? ' sin' : ' con') . ' impuestos');
                }
            }
        }
    }
});

test('el céntimo: un importe de 0, un tipo de 0 % o sin tipo no se convierten (se usa el céntimo tal cual)', function (): void {
    $site = pxSite(['tax.prices_include_tax' => false]);
    same(0, (int) pxWrite($site, ['price' => 0, 'price_micros' => 0])['price']);
    $site2 = pxSite();
    same(0, (int) pxWrite($site2, ['price' => 0, 'price_micros' => 0])['price']);
    same(1236, (int) pxWrite($site2, ['price' => 1236, 'price_includes_tax' => true])['price'], 'con impuestos en el origen y aquí, el mismo');
});

test('el céntimo: unas millonésimas que no cuadran con el céntimo se ignoran también sin impuestos aquí (manda el céntimo)', function (): void {
    $site = pxSite(['tax.prices_include_tax' => false]);
    same(8264, (int) pxWrite($site, ['price' => 8264, 'price_micros' => 99999999])['price'], 'un lector estropeado no cambia el precio');
});
