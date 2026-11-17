<?php
/**
 * AIFEC — pruebas del servicio de impuestos: el tipo según clase y destino, el filtro sellado, el impuesto del envío,
 * las exenciones y la lista de códigos postales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Tax\Postcodes;

require_once __DIR__ . '/TaxKit.php';

test('península: la clase general lleva el 21 % y el nombre del tipo', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, $standard, 'ES', '28001'));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, $standard, 'ES', '08001'));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, $standard, 'es', ''), 'el país en minúsculas y sin código postal');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, $standard, 'ES', '07001'), 'Baleares, como la península');
});

test('Canarias se reconoce por el prefijo del código postal: IGIC 7 %', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], taxRateAt($app, $standard, 'ES', '35001'), 'Las Palmas');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], taxRateAt($app, $standard, 'ES', '38670'), 'Santa Cruz de Tenerife');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], taxRateAt($app, $standard, 'ES', '35 001'), 'con un espacio dentro');
    same(2100, taxRateAt($app, $standard, 'ES', '36001')['rate'], 'Pontevedra no es Canarias');
    same(2100, taxRateAt($app, $standard, 'ES', '3')['rate'], 'un prefijo a medias no casa con «35»');
});

test('Ceuta y Melilla no llevan IVA', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    foreach (['51001', '52001'] as $postcode) {
        same(['rate' => 0, 'name' => 'Sin IVA (IPSI en destino)'], taxRateAt($app, $standard, 'ES', $postcode), $postcode);
    }
    same(['rate' => 0, 'name' => 'Sin IVA (IPSI en destino)'], taxRateAt($app, taxClassId($app, 'reduced'), 'ES', '51001'), 'también en la clase reducida');
});

test('las clases reducida, superreducida y cero llevan su tipo, en península y en Canarias', function (): void {
    $app = taxTestApp();
    $reduced = taxClassId($app, 'reduced');
    $super = taxClassId($app, 'super-reduced');
    same(['rate' => 1000, 'name' => 'IVA 10 %'], taxRateAt($app, $reduced, 'ES', '28001'));
    same(['rate' => 300, 'name' => 'IGIC 3 %'], taxRateAt($app, $reduced, 'ES', '35001'));
    same(['rate' => 400, 'name' => 'IVA 4 %'], taxRateAt($app, $super, 'ES', '28001'));
    same(['rate' => 0, 'name' => 'IGIC 0 %'], taxRateAt($app, $super, 'ES', '38001'));
    same(['rate' => 0, 'name' => 'IVA 0 %'], taxRateAt($app, taxClassId($app, 'zero'), 'ES', '28001'));
});

test('una clase nula, cero o inexistente es la de por defecto, y cambiarla se nota', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    same(taxClassId($app, 'standard'), $tax->defaultClassId());
    foreach ([null, 0, 9999, -3] as $classId) {
        same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, $classId, 'ES', '28001'), 'clase ' . var_export($classId, true));
    }

    $reduced = taxClassId($app, 'reduced');
    $app->db()->run('UPDATE {tax_classes} SET is_default = 0');
    $app->db()->update('tax_classes', ['is_default' => 1], ['id' => $reduced]);
    $tax->flush();
    same($reduced, $tax->defaultClassId());
    same(['rate' => 1000, 'name' => 'IVA 10 %'], taxRateAt($app, null, 'ES', '28001'), 'la nula ahora es la reducida');

    // Sin ninguna marcada, la primera por orden.
    $app->db()->run('UPDATE {tax_classes} SET is_default = 0');
    $tax->flush();
    same(taxClassId($app, 'standard'), $tax->defaultClassId());
});

test('un país sin tipos da cero y nombre vacío', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    same(['rate' => 0, 'name' => ''], taxRateAt($app, $standard, 'FR', '75001'));
    same(['rate' => 0, 'name' => ''], taxRateAt($app, $standard, 'US', '10001'));
    same(['rate' => 0, 'name' => ''], taxRateAt($app, $standard, 'PT', '1000'));
});

test('un tipo inactivo se ignora', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    taxAddRate($app, 'standard', 'FR', 2000, 'TVA 20 %');
    same(['rate' => 2000, 'name' => 'TVA 20 %'], taxRateAt($app, $standard, 'FR', '75001'));

    $app->db()->run("UPDATE {tax_rates} SET active = 0 WHERE name = 'TVA 20 %'");
    taxService($app)->flush();
    same(['rate' => 0, 'name' => ''], taxRateAt($app, $standard, 'FR', '75001'), 'apagado, vuelve a no haber tipo');

    // El de península, apagado, deja paso a nadie más: cero; el de Canarias sigue.
    $app->db()->run("UPDATE {tax_rates} SET active = 0 WHERE name = 'IVA 21 %'");
    taxService($app)->flush();
    same(['rate' => 0, 'name' => ''], taxRateAt($app, $standard, 'ES', '28001'));
    same(700, taxRateAt($app, $standard, 'ES', '35001')['rate']);
});

test('un rango de códigos postales gana al prefijo de menor prioridad y respeta sus extremos', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    taxAddRate($app, 'standard', 'ES', 600, 'Rango especial', ['postcodes' => '51000-51999', 'priority' => 20]);
    same(600, taxRateAt($app, $standard, 'ES', '51000')['rate'], 'el primero del rango');
    same(600, taxRateAt($app, $standard, 'ES', '51500')['rate']);
    same(600, taxRateAt($app, $standard, 'ES', '51999')['rate'], 'el último del rango');
    same(['rate' => 0, 'name' => 'Sin IVA (IPSI en destino)'], taxRateAt($app, $standard, 'ES', '52000'), 'fuera del rango, el prefijo 52 de serie');
    same(2100, taxRateAt($app, $standard, 'ES', '50999')['rate'], 'justo debajo');
    same(['rate' => 0, 'name' => 'Sin IVA (IPSI en destino)'], taxRateAt($app, $standard, 'ES', '5150'), 'un código corto no casa con un rango: queda el prefijo «51»');
});

test('a igual concreción gana la mayor prioridad, y a igual prioridad el menor id', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    taxAddRate($app, 'standard', 'PT', 2300, 'IVA PT 23 %', ['priority' => 1]);
    same(2300, taxRateAt($app, $standard, 'PT', '1000')['rate'], 'solo uno');
    $high = taxAddRate($app, 'standard', 'PT', 2200, 'IVA PT 22 %', ['priority' => 5]);
    same(2200, taxRateAt($app, $standard, 'PT', '1000')['rate'], 'la prioridad 5 gana a la 1');
    taxAddRate($app, 'standard', 'PT', 999, 'IVA PT 9,99 %', ['priority' => 5]);
    same(['rate' => 2200, 'name' => 'IVA PT 22 %'], taxRateAt($app, $standard, 'PT', '1000'), 'con la misma prioridad, el de menor id (' . $high . ')');
    taxAddRate($app, 'standard', 'PT', 1, 'Prioridad negativa', ['priority' => -4]);
    same(2200, taxRateAt($app, $standard, 'PT', '1000')['rate']);
});

test('la región gana al país solo, aunque este tenga más prioridad, y no distingue mayúsculas', function (): void {
    $app = taxTestApp();
    $standard = taxClassId($app, 'standard');
    taxAddRate($app, 'standard', 'DE', 1900, 'MwSt 19 %', ['priority' => 9]);
    taxAddRate($app, 'standard', 'DE', 700, 'MwSt Bayern', ['region' => 'BY', 'priority' => 0]);
    same(700, taxRateAt($app, $standard, 'DE', '80331', 'BY')['rate']);
    same(700, taxRateAt($app, $standard, 'DE', '80331', 'by')['rate'], 'en minúsculas');
    same(1900, taxRateAt($app, $standard, 'DE', '50667', 'NW')['rate'], 'otra región: el país solo');
    same(1900, taxRateAt($app, $standard, 'DE', '50667', '')['rate'], 'sin región: el país solo');

    // Los códigos postales mandan sobre la región.
    taxAddRate($app, 'standard', 'DE', 500, 'MwSt código', ['region' => 'NW', 'postcodes' => '80', 'priority' => 0]);
    same(500, taxRateAt($app, $standard, 'DE', '80331', 'BY')['rate'], 'casa el código postal y se ignora su región');
    same(1900, taxRateAt($app, $standard, 'DE', '70000', 'NW')['rate'], 'sin casar el código, un tipo con códigos no vale aunque la región sea la suya');
});

test('un destino vacío o sin país es el de la tienda, y cambiar el país de la tienda cambia el resultado', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    same(['country' => 'ES', 'region' => '', 'postcode' => '', 'city' => ''], $tax->baseDestination());
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->rate(null, []));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->rate(null, ['country' => '', 'postcode' => '28001']));

    $app->settings()->set('tax.base_postcode', '35001');
    same('35001', $tax->baseDestination()['postcode']);
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $tax->rate(null, []), 'con la tienda en Las Palmas, un destino vacío lleva el IGIC');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->rate(null, ['country' => 'ES', 'postcode' => '28001']), 'un destino con país no usa el código de la tienda');

    $app->settings()->set('tax.base_country', 'PT');
    $app->settings()->set('tax.base_postcode', '');
    taxAddRate($app, 'standard', 'PT', 2300, 'IVA PT 23 %');
    same('PT', $tax->baseDestination()['country']);
    same(['rate' => 2300, 'name' => 'IVA PT 23 %'], $tax->rate(null, []));

    $app->settings()->set('tax.base_country', 'no es un país');
    same('ES', $tax->baseDestination()['country'], 'un valor que no es un país vuelve a España');
});

test('las lecturas se guardan en memoria durante la petición y flush() las suelta', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $standard = taxClassId($app, 'standard');
    same(2100, $tax->rate($standard, ['country' => 'ES', 'postcode' => '28001'])['rate']);

    $app->db()->run("UPDATE {tax_rates} SET rate = 2200, name = 'IVA 22 %' WHERE name = 'IVA 21 %'");
    same(2100, $tax->rate($standard, ['country' => 'ES', 'postcode' => '28001'])['rate'], 'sigue lo leído');
    $tax->flush();
    same(['rate' => 2200, 'name' => 'IVA 22 %'], $tax->rate($standard, ['country' => 'ES', 'postcode' => '28001']));

    $before = count($tax->classes());
    $app->db()->insert('tax_classes', ['code' => 'nueva', 'name' => 'Nueva', 'is_default' => 0, 'position' => 9]);
    same($before, count($tax->classes()), 'las clases también van en memoria');
    $tax->flush();
    same($before + 1, count($tax->classes()));
});

test('las clases salen por id con su código y su nombre, en el orden de la tienda', function (): void {
    $app = taxTestApp();
    $classes = taxService($app)->classes();
    same(['standard', 'reduced', 'super-reduced', 'zero'], array_values(array_map(static function (array $c): string {
        return $c['code'];
    }, $classes)));
    same(['code' => 'standard', 'name' => 'Standard rate'], $classes[taxClassId($app, 'standard')]);
    same(['code' => 'zero', 'name' => 'Zero rate'], $classes[taxClassId($app, 'zero')]);
});

test('el filtro tax.rate está sellado: sin la capacidad un módulo no cambia el resultado', function (): void {
    $app = taxTestApp();
    $app->hooks()->setCurrentModule('x');
    $app->hooks()->filter('tax.rate', static function (array $rate): array {
        return ['rate' => 0, 'name' => 'Pirata'];
    });
    $app->hooks()->setCurrentModule('core');

    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, null, 'ES', '28001'), 'el oyente no se registró');
    $errors = $app->hooks()->errors();
    $last = $errors[count($errors) - 1];
    same('x', $last['module']);
    same('tax.rate', $last['hook']);
    contains('Sealed hook', $last['error']);
});

test('con la capacidad pricing concedida, el filtro tax.rate cambia el tipo y recibe la clase y el destino ya resueltos', function (): void {
    $app = taxTestApp();
    $seen = [];
    $app->hooks()->grant('x', 'pricing');
    $app->hooks()->setCurrentModule('x');
    $app->hooks()->filter('tax.rate', static function (array $rate, ?int $classId, array $destination) use (&$seen): array {
        $seen = [$rate, $classId, $destination];

        return ['rate' => $rate['rate'] + 100, 'name' => $rate['name'] . ' + recargo'];
    });
    $app->hooks()->setCurrentModule('core');

    same(['rate' => 2200, 'name' => 'IVA 21 % + recargo'], taxRateAt($app, null, 'es', '28001'));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $seen[0], 'recibe el tipo calculado');
    same(taxClassId($app, 'standard'), $seen[1], 'recibe la clase ya resuelta (la de por defecto si venía nula)');
    same('ES', $seen[2]['country']);
    same('28001', $seen[2]['postcode']);

    // Un país sin tipos también pasa por el filtro.
    same(['rate' => 100, 'name' => ' + recargo'], taxRateAt($app, null, 'FR', '75001'));
});

test('un filtro que devuelve basura no rompe el cálculo: se queda el tipo de antes, y el tipo nunca es negativo', function (): void {
    $app = taxTestApp();
    $app->hooks()->grant('x', 'pricing');
    $app->hooks()->setCurrentModule('x');
    $mode = 'basura';
    $app->hooks()->filter('tax.rate', static function ($rate) use (&$mode) {
        if ($mode === 'basura') {
            return 'no soy un array';
        }
        if ($mode === 'sin tipo') {
            return ['name' => 'Sin tipo'];
        }

        return ['rate' => -500, 'name' => 'Negativo'];
    });
    $app->hooks()->setCurrentModule('core');

    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, null, 'ES', '28001'));
    $mode = 'sin tipo';
    same(['rate' => 2100, 'name' => 'IVA 21 %'], taxRateAt($app, null, 'ES', '28001'));
    $mode = 'negativo';
    same(['rate' => 0, 'name' => 'Negativo'], taxRateAt($app, null, 'ES', '28001'));
});

test('el impuesto del envío: una clase fija, el tipo más alto de las líneas o la clase por defecto', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $peninsula = ['country' => 'ES', 'postcode' => '28001'];
    $canarias = ['country' => 'ES', 'postcode' => '35001'];
    $lines = [['rate' => 1000, 'name' => 'IVA 10 %'], ['rate' => 2100, 'name' => 'IVA 21 %'], ['rate' => 400, 'name' => 'IVA 4 %']];

    same('highest', $app->settings()->str('tax.shipping_class'), 'de serie, el más alto');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->shippingRate($peninsula, $lines));
    same(['rate' => 1000, 'name' => 'IVA 10 %'], $tax->shippingRate($peninsula, [['rate' => 400, 'name' => 'IVA 4 %'], ['rate' => 1000, 'name' => 'IVA 10 %']]));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->shippingRate($peninsula), 'sin líneas, la clase por defecto');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $tax->shippingRate($canarias), 'sin líneas y en Canarias');
    same(['rate' => 1000, 'name' => 'primero'], $tax->shippingRate($peninsula, [['rate' => 1000, 'name' => 'primero'], ['rate' => 1000, 'name' => 'segundo']]), 'a igualdad, la primera línea');
    same(['rate' => 0, 'name' => 'Sin IVA'], $tax->shippingRate($peninsula, [['rate' => 0, 'name' => 'Sin IVA']]), 'todas a cero: cero, no la clase por defecto');
    same(['rate' => 1000, 'name' => 'IVA 10 %'], $tax->shippingRate($peninsula, ['basura', ['rate' => 1000, 'name' => 'IVA 10 %'], ['name' => 'sin tipo']]), 'las líneas que no son un tipo se saltan');

    $app->settings()->set('tax.shipping_class', 'reduced');
    same(['rate' => 1000, 'name' => 'IVA 10 %'], $tax->shippingRate($peninsula, $lines), 'una clase fija manda sobre las líneas');
    same(['rate' => 300, 'name' => 'IGIC 3 %'], $tax->shippingRate($canarias, $lines), 'y se resuelve por destino');

    $app->settings()->set('tax.shipping_class', 'clase-borrada');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->shippingRate($peninsula, $lines), 'un código que ya no existe se trata como «highest»');
});

test('exenciones: grupo exento, y empresa de otro país de la UE solo si el ajuste está encendido', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    $fr = ['country' => 'FR', 'postcode' => '75001'];
    $es = ['country' => 'ES', 'postcode' => '28001'];

    same(false, $tax->exempt(null, $fr), 'sin cliente');
    same(true, $tax->exempt(['group_tax_exempt' => 1], $fr), 'el grupo lo dice con group_tax_exempt');
    same(true, $tax->exempt(['group_tax_exempt' => '1'], $es), 'y vale en cualquier destino');
    same(true, $tax->exempt(['tax_exempt' => 1], $fr), 'o con tax_exempt');
    same(true, $tax->exempt(['group_tax_exempt' => 0, 'tax_exempt' => 1], $fr), 'basta con uno de los dos');
    same(false, $tax->exempt(['group_tax_exempt' => 0, 'tax_exempt' => 0], $fr));
    same(false, $tax->exempt(['group_tax_exempt' => '0', 'tax_exempt' => null], $fr));
    same(false, $tax->exempt(['vat_id' => 'FR12345678901'], $fr), 'con el ajuste apagado, el NIF solo no exime');

    $app->settings()->set('tax.eu_b2b_exempt', true);
    same(true, $tax->exempt(['vat_id' => 'FR12345678901'], $fr), 'con el ajuste, NIF y otro país de la UE');
    same(true, $tax->exempt(['vat_id' => 'FR12345678901'], ['country' => 'fr']), 'país en minúsculas');
    same(false, $tax->exempt(['vat_id' => 'FR12345678901'], $es), 'dentro del país de la tienda no');
    same(false, $tax->exempt(['vat_id' => 'FR12345678901'], []), 'sin destino es el de la tienda');
    same(false, $tax->exempt(['vat_id' => 'US123'], ['country' => 'US']), 'un país fuera de la UE no');
    same(false, $tax->exempt(['vat_id' => '   '], $fr), 'un NIF en blanco no cuenta');
    same(false, $tax->exempt(['vat_id' => ''], $fr));
    same(false, $tax->exempt([], $fr), 'sin NIF');

    $app->settings()->set('tax.base_country', 'PT');
    same(true, $tax->exempt(['vat_id' => 'ES12345678Z'], $es), 'con la tienda en Portugal, España es otro país de la UE');
    same(false, $tax->exempt(['vat_id' => 'PT123456789'], ['country' => 'PT']));
});

test('los ajustes de precios: de serie los precios llevan el impuesto y se enseñan con él', function (): void {
    $app = taxTestApp();
    $tax = taxService($app);
    same(true, $tax->pricesIncludeTax());
    same(true, $tax->displayWithTax());
    $app->settings()->set('tax.prices_include_tax', false);
    $app->settings()->set('tax.display_with_tax', false);
    same(false, $tax->pricesIncludeTax());
    same(false, $tax->displayWithTax());
    $app->settings()->set('tax.prices_include_tax', 1);
    same(true, $tax->pricesIncludeTax(), 'un 1 guardado también vale');
});

test('la lista de códigos postales: se normaliza, se valida y casa por prefijo o por rango', function (): void {
    same('35,38', Postcodes::normalize(' 35 , 38 '));
    same('35,38', Postcodes::normalize('35,,38,'));
    same('51000-51999', Postcodes::normalize('51000 - 51999'));
    same('AB1,28001', Postcodes::normalize('ab1, 28 001'));
    same('', Postcodes::normalize(''));
    same('', Postcodes::normalize(' , '));
    foreach (['35;38', '51999-51000', '5100-51999', '35-', '-35', '35.5', 'Ñandú', '12345678901', '35-38-40', '<b>', "35'; DROP", '🌶'] as $bad) {
        same(null, Postcodes::normalize($bad), $bad);
    }

    same(true, Postcodes::matches('35,38', '35001'));
    same(true, Postcodes::matches('35,38', '38670'));
    same(false, Postcodes::matches('35,38', '36001'));
    same(false, Postcodes::matches('35', '3'), 'el código es más corto que el prefijo');
    same(true, Postcodes::matches('35', '35 001'), 'los espacios del código se ignoran');
    same(true, Postcodes::matches('1000', '1000-001'), 'el guion del código portugués se ignora');
    same(true, Postcodes::matches('51000-51999', '51500'));
    same(false, Postcodes::matches('51000-51999', '52000'));
    same(false, Postcodes::matches('51000-51999', '5150'), 'un rango pide la misma longitud');
    same(false, Postcodes::matches('51000-51999', 'AB123'), 'un rango solo casa con dígitos');
    same(true, Postcodes::matches('ab1', 'AB1 2CD'), 'sin distinguir mayúsculas');
    same(false, Postcodes::matches('', '35001'), 'una lista vacía no casa con nada');
    same(false, Postcodes::matches('35', ''), 'ni un código vacío');
    same(false, Postcodes::matches('35;38', '35001'), 'una lista inválida tampoco');
});
