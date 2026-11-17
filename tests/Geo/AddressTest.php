<?php
/**
 * AIFEC — una dirección como la lee una persona: con nombres («Valencia», «España») y no con los códigos con que se
 * guarda («46», «ES»). Visto en las capturas del panel: la ficha del pedido enseñaba «46» y «ES».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Geo\Address;

test('dirección española: la provincia y el país salen con su nombre, en su orden', function (): void {
    $address = [
        'first_name' => 'Marta', 'last_name' => 'Ruiz Peña', 'company' => 'Muebles Ñandú & Cía', 'vat_id' => '12345678Z',
        'line1' => 'Calle de la Muestra 5', 'line2' => '2.º B', 'postcode' => '03001', 'city' => 'Alicante',
        'region' => '46', 'country' => 'ES', 'phone' => '999000001',
    ];
    same([
        'Marta Ruiz Peña', 'Muebles Ñandú & Cía', '12345678Z', 'Calle de la Muestra 5', '2.º B', '03001 Alicante',
        'Valencia', 'España', '999000001',
    ], Address::lines($address));

    same(['Marta Ruiz Peña', 'Muebles Ñandú & Cía', 'Calle de la Muestra 5', '2.º B', '03001 Alicante', 'Valencia', 'España'],
        Address::lines($address, 'es', ['tax_id' => false, 'phone' => false]), 'sin NIF ni teléfono si no se piden');
    same('Muebles Ñandú & Cía', Address::lines($address, 'es', ['name' => false])[0], 'sin el nombre de la persona');
    same('Spain', Address::lines($address, 'en')[7], 'el país, en el idioma pedido');
});

test('la provincia que repite la población no se escribe dos veces', function (): void {
    $lines = Address::lines(['line1' => 'Calle de la Muestra 5', 'postcode' => '46000', 'city' => 'Valencia', 'region' => '46', 'country' => 'ES']);
    same(['Calle de la Muestra 5', '46000 Valencia', 'España'], $lines);
    $lines = Address::lines(['postcode' => '03001', 'city' => 'ALICANTE', 'region' => '03', 'country' => 'es']);
    same(['03001 ALICANTE', 'España'], $lines, 'sin distinguir mayúsculas, y el país en minúsculas también vale');
    $lines = Address::lines(['postcode' => '29001', 'city' => 'Malaga', 'region' => '29', 'country' => 'ES']);
    same(['29001 Malaga', 'España'], $lines, 'ni tildes');
});

test('la provincia se reconoce por su prefijo postal o por su código de letras, y lo demás se deja como está', function (): void {
    same('Valencia', Address::regionName('ES', '46'));
    same('Valencia', Address::regionName('ES', 'V'), 'el código de dos letras que usan otras tiendas');
    same('Las Palmas', Address::regionName('es', 'gc'));
    same('Barcelona', Address::regionName('ES', '08'));
    same('Comarca del Bierzo', Address::regionName('ES', 'Comarca del Bierzo'), 'lo que no es un código se respeta');
    same('Lisboa', Address::regionName('PT', 'Lisboa'), 'la región de otro país se enseña tal cual');
    same('46', Address::regionName('FR', '46'), 'un «46» de otro país no es Valencia');
    same('', Address::regionName('ES', '  '));
});

test('una dirección a medias o con datos raros no rompe nada', function (): void {
    same([], Address::lines([]));
    same(['Portugal'], Address::lines(['country' => 'PT']));
    same(['ZZ'], Address::lines(['country' => 'zz']), 'un país que no se conoce sale con su código');
    same(['Ana'], Address::lines(['first_name' => ' Ana ', 'last_name' => '', 'city' => ['no', 'es', 'texto'], 'postcode' => null]));
    same(['28001'], Address::lines(['postcode' => 28001]), 'un código postal que llega como número');
});
