<?php
/**
 * AIFEC — el formato neutro de migración: los ejemplos son válidos, lo mal formado se rechaza diciendo dónde, el
 * resumen es estable, lo secreto no sale y las conversiones redondean siempre igual.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\NeutralSchema;
use Aifec\Modules\Import\Types;

require_once __DIR__ . '/NeutralSamples.php';

test('hay esquema, ejemplo, etiqueta y requisitos para cada tipo, y el ejemplo es válido', function (): void {
    $schema = NeutralSchema::types();
    same(Types::ORDER, array_values(array_intersect(Types::ORDER, array_keys($schema))), 'cada tipo del orden tiene esquema');
    same(count(Types::ORDER), count($schema), 'y no hay esquemas de tipos que no estén en el orden');
    same(count(Types::ORDER), count(array_unique(Types::ORDER)));
    foreach (Types::ORDER as $type) {
        check(isset(Types::labels()[$type]), 'etiqueta de ' . $type);
        check(isset(Types::requires()[$type]), 'requisitos de ' . $type);
        same([], Neutral::check($type, neutralSample($type)), 'el ejemplo de ' . $type . ' cumple el esquema');
    }
    foreach (array_merge(Types::LINKED, Types::PERSONAL, Types::CONFIG) as $type) {
        check(Types::valid($type), $type . ' existe');
    }
    same(['tax_rates', 'products', 'orders'], Types::sorted(['orders', 'nada', 'products', 'tax_rates']));
});

test('cada declaración del esquema usa una clase conocida y cada parte nombrada existe', function (): void {
    $kinds = ['id', 'ref', 'refs', 'str', 'text', 'html', 'secret', 'strs', 'i18n', 'i18n_html', 'i18n_list', 'int', 'int+', 'cents', 'micros', 'rate', 'float', 'bool', 'date',
        'country', 'lang', 'enum', 'image', 'images', 'part', 'list', 'paths', 'map', 'secrets'];
    $parts = NeutralSchema::parts();
    $types = NeutralSchema::types();
    foreach ($types + $parts as $owner => $fields) {
        foreach ($fields as $name => $declaration) {
            $bare = rtrim($declaration, '!?');
            $colon = strpos($bare, ':');
            $kind = $colon === false ? $bare : substr($bare, 0, $colon);
            $argument = $colon === false ? '' : substr($bare, $colon + 1);
            check(in_array($kind, $kinds, true), $owner . '.' . $name . ': clase desconocida «' . $kind . '»');
            if ($kind === 'part' || $kind === 'list') {
                check(isset($parts[$argument]), $owner . '.' . $name . ': no existe la parte «' . $argument . '»');
            }
            if ($kind === 'ref' || $kind === 'refs') {
                check(isset($types[$argument]), $owner . '.' . $name . ': referencia a un tipo que no existe «' . $argument . '»');
            }
        }
    }
});

test('rechaza lo mal formado diciendo el campo', function (): void {
    same(['unknown type nada'], Neutral::check('nada', []));

    $p = neutralSample('products');
    unset($p['name']);
    $p['price'] = '106.61';
    $p['color'] = 'rojo';
    $p['status'] = 'publish';
    $p['manage_stock'] = 1;
    $p['sale_from'] = '2026-11-01 00:00:00';
    $p['brand_id'] = 9;
    $p['compare_price'] = null;
    $problems = Neutral::check('products', $p);
    contains('products.color: unknown field', implode("\n", $problems));
    contains('products.name: missing', implode("\n", $problems));
    contains('products.price: expected an integer', implode("\n", $problems));
    contains('products.status: expected one of published|draft|private', implode("\n", $problems));
    contains('products.manage_stock: expected true or false', implode("\n", $problems));
    contains('products.sale_from: expected a UTC instant', implode("\n", $problems));
    contains('products.brand_id: expected a source id', implode("\n", $problems));
    same(7, count($problems), 'ni uno más: compare_price admite null');

    $p = neutralSample('products');
    $p['name'] = ['es' => '   '];
    contains('products.name: empty in every language', implode("\n", Neutral::check('products', $p)));
    $p['name'] = ['castellano' => 'Mesa'];
    contains('is not a language code', implode("\n", Neutral::check('products', $p)));
    $p['name'] = 'Mesa';
    contains('products.name: expected [language => text]', implode("\n", Neutral::check('products', $p)));

    $p = neutralSample('products');
    $p['price_includes_tax'] = null;
    contains('products.price_includes_tax: null not allowed', implode("\n", Neutral::check('products', $p)));
});

test('reglas de las partes: imagen sin origen, variantes, líneas de devolución, rutas y códigos', function (): void {
    $p = neutralSample('products');
    $p['images'][1] = ['alt' => ['es' => 'sin dirección']];
    $p['variants'][1]['id'] = '501';
    $p['variants'][0]['values'] = [];
    $p['variants'][0]['image_index'] = 7;
    $p['old_paths'] = ['mesas/101.html'];
    $text = implode("\n", Neutral::check('products', $p));
    contains('products.images[1]: an image needs url or path', $text);
    contains('products.variants[1].id: repeated', $text);
    contains('products.variants[0].values: a variant needs at least one attribute value', $text);
    contains('products.variants[0].image_index: there is no image at that position', $text);
    contains('products.old_paths[0]: a path has to start with /', $text);

    $o = neutralSample('orders');
    $o['refunds'] = [['amount' => 590, 'lines' => [['index' => 5, 'qty' => 1]]]];
    $o['items'][0]['qty'] = '1';
    unset($o['items'][1]['line_tax']);
    $text = implode("\n", Neutral::check('orders', $o));
    contains('orders.refunds[0].lines[0].index: there is no item at that position', $text);
    contains('orders.items[0].qty: expected an integer', $text);
    contains('orders.items[1].line_tax: missing', $text);

    $r = neutralSample('redirects');
    $r['code'] = 303;
    $r['source'] = 'mesas-antiguas';
    $text = implode("\n", Neutral::check('redirects', $r));
    contains('redirects.code', $text);
    contains('redirects.source: a path has to start with /', $text);

    $review = neutralSample('reviews');
    $review['rating'] = 6;
    contains('reviews.rating: expected 1 to 5', implode("\n", Neutral::check('reviews', $review)));

    $rate = neutralSample('tax_rates');
    $rate['postcodes'] = '35 y 38';
    $rate['rate'] = 21;
    $rate['country'] = 'es';
    $text = implode("\n", Neutral::check('tax_rates', $rate));
    contains('tax_rates.postcodes', $text);
    contains('tax_rates.country: expected a two-letter country code in capitals', $text);
    same(false, strpos($text, 'tax_rates.rate'), '21 es un tipo válido (0,21 %): el lector tiene que mandar 2100, y eso lo vigilan sus pruebas');

    $media = neutralSample('media');
    unset($media['url']);
    contains('media: needs url or path', implode("\n", Neutral::check('media', $media)));

    $menu = neutralSample('menus');
    $menu['items'][0]['children'][0]['type'] = 'enlace';
    contains('menus.items[0].children[0].type: expected one of', implode("\n", Neutral::check('menus', $menu)));
});

test('el resumen no cambia con el orden de las claves ni con la fecha de cambio, y sí con un dato', function (): void {
    $a = neutralSample('products');
    $b = array_reverse($a, true);
    $b['updated_at'] = '2027-01-01T00:00:00Z';
    $b['variants'][0] = array_reverse($b['variants'][0], true);
    same(Neutral::hash($a), Neutral::hash($b));
    same(40, strlen(Neutral::hash($a)));

    $c = $a;
    $c['variants'][1]['price'] = 13141;
    check(Neutral::hash($a) !== Neutral::hash($c), 'un céntimo en una variante cambia el resumen');
    $d = $a;
    $d['images'] = array_reverse($d['images']);
    check(Neutral::hash($a) !== Neutral::hash($d), 'el orden de una lista sí cuenta');
});

test('lo secreto no sale: contraseñas, claves de pasarela y la del correo', function (): void {
    $customer = Neutral::redact('customers', neutralSample('customers'));
    same('***', $customer['password_hash']);
    same('ana@cliente.test', $customer['email'], 'lo demás queda igual');
    same('Calle Mayor 1', $customer['addresses'][0]['line1']);

    $method = Neutral::redact('payment_methods', neutralSample('payment_methods'));
    same(['merchant_code' => '***', 'terminal' => '***', 'secret_key' => '***'], $method['settings']);
    same('redsys', $method['code']);

    $settings = Neutral::redact('settings', neutralSample('settings'));
    same('***', $settings['mail']['password']);
    same('smtp.tienda.test', $settings['mail']['host']);
    notContains('clave-de-mentira', (string) json_encode([$customer, $method, $settings]));

    $empty = neutralSample('customers');
    $empty['password_hash'] = null;
    same(null, Neutral::redact('customers', $empty)['password_hash']);
    same([], Neutral::redact('nada', ['a' => 1]));
});

test('importes: céntimos exactos con la mitad hacia arriba, sin errores de coma flotante', function (): void {
    same(1999, Neutral::cents('19.99'));
    same(1999, Neutral::cents(19.99));
    same(2000, Neutral::cents('19.995'));
    same(1999, Neutral::cents('19.994999'));
    same(10661, Neutral::cents('106.611570'));
    same(8264, Neutral::cents('82.644628'), '100 € con IVA al 21 % en PrestaShop: 82,644628 sin impuestos');
    same(-1250, Neutral::cents('-12.50'));
    same(-1251, Neutral::cents('-12.505'));
    same(500, Neutral::cents(5));
    same(1050, Neutral::cents('10,5'));
    same(50, Neutral::cents('.5'));
    same(0, Neutral::cents(''));
    same(0, Neutral::cents('abc'));
    same(0, Neutral::cents(null));
    same(123456789, Neutral::cents('1234567.89'));
    same(29, Neutral::cents(0.29), '0,29 no se queda en 28');
    same(115, Neutral::cents(1.15));
    same(2100, Neutral::rate('21.000'));
    same(550, Neutral::rate(5.5));
    same(400, Neutral::rate('4'));
    same(0, Neutral::rate('0.000'));
});

test('fechas a UTC, pesos a gramos y medidas a milímetros', function (): void {
    same('2026-03-01T09:30:00Z', Neutral::utc('2026-03-01 10:30:00', 'Europe/Madrid'));
    same('2026-07-01T08:30:00Z', Neutral::utc('2026-07-01 10:30:00', 'Europe/Madrid'), 'en verano son dos horas');
    same('2026-03-01T10:30:00Z', Neutral::utc('2026-03-01 10:30:00'));
    same('2026-03-01T10:30:00Z', Neutral::utc('2026-03-01T10:30:00+00:00', 'Europe/Madrid'), 'si la fecha ya trae su zona, manda la suya');
    same(null, Neutral::utc('0000-00-00 00:00:00'));
    same(null, Neutral::utc(''));
    same(null, Neutral::utc(null));
    same(null, Neutral::utc('pronto'));
    same([], Neutral::check('redirects', ['id' => '1', 'source' => '/a', 'target' => '/b', 'updated_at' => Neutral::utc('2026-03-01 10:30:00')]));

    same(6500, Neutral::grams('6.5', 'kg'));
    same(6500, Neutral::grams(6500, 'g'));
    same(454, Neutral::grams(1, 'lb'));
    same(28, Neutral::grams('1', 'oz'));
    same(0, Neutral::grams('', 'kg'));
    same(0, Neutral::grams(-3, 'kg'));
    same(1500, Neutral::grams('1,5', 'KG'));
    same(400, Neutral::millimetres(40, 'cm'));
    same(1200, Neutral::millimetres('1.2', 'm'));
    same(254, Neutral::millimetres(10, 'in'));
    same(35, Neutral::millimetres(35, 'mm'));
});
