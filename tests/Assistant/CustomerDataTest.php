<?php
/**
 * AIFEC — pruebas de los datos de cliente en texto libre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Assistant\CustomerData;

/** Igualdad estricta de dos arrays sin tener en cuenta el orden de las claves. */
function customerDataSame(array $expected, array $actual, string $msg = ''): void
{
    ksort($expected);
    ksort($actual);
    same($expected, $actual, $msg);
}

test('un nombre compuesto corriente va entero al nombre: «María José Vidal» es María José + Vidal (16/11/2026)', function (): void {
    same(['first' => 'María José', 'last' => 'Vidal'], CustomerData::splitName(['María', 'José', 'Vidal']));
    same(['first' => 'José Luis', 'last' => 'Pérez Gil'], CustomerData::splitName(['José', 'Luis', 'Pérez', 'Gil']));
    same(['first' => 'Ana', 'last' => 'Ruiz Marín'], CustomerData::splitName(['Ana', 'Ruiz', 'Marín']), 'un nombre y dos apellidos no se tocan');
    same(['first' => 'María', 'last' => 'José'], CustomerData::splitName(['María', 'José']), 'con dos palabras no se adivina: nombre y apellido');
    $found = CustomerData::extract('María José Vidal, calle de Ejemplo 2, 08001 Barcelona, mj@cliente.test');
    same('María José', isset($found['first_name']) ? $found['first_name'] : '', 'y el texto libre de la caja lo reparte igual');
    same('Vidal', isset($found['last_name']) ? $found['last_name'] : '');
});

test('saca nombre, apellidos y correo de «Ana García López, ana@correo.es»', function (): void {
    customerDataSame([
        'email' => 'ana@correo.es',
        'first_name' => 'Ana',
        'last_name' => 'García López',
        'name_stated' => false,
    ], CustomerData::extract('Ana García López, ana@correo.es'));
});

test('«me llamo Pedro Ruiz y mi correo es…»: el nombre dicho acaba donde empieza el relleno', function (): void {
    customerDataSame([
        'email' => 'p.ruiz@mail.com',
        'tax_id' => '12345678Z',
        'first_name' => 'Pedro',
        'last_name' => 'Ruiz',
        'name_stated' => true,
    ], CustomerData::extract('me llamo Pedro Ruiz y mi correo es P.Ruiz@Mail.com, NIF 12345678z'));
});

test('«soy de Valencia» no es un nombre', function (): void {
    same([], CustomerData::extract('soy de Valencia'));
    same([], CustomerData::extract('soy de Valencia y quiero 2 cajas'));
});

test('caso real: todo en minúsculas y un espacio antes de la coma', function (): void {
    customerDataSame([
        'email' => 'marta@ejemplo.test',
        'tax_id' => '12345678Z',
        'first_name' => 'Marta',
        'last_name' => 'Gil',
        'name_stated' => false,
    ], CustomerData::extract('marta gil , marta@ejemplo.test, 12345678Z'));
});

test('las partículas de los apellidos van en minúsculas', function (): void {
    customerDataSame([
        'email' => 'jc@x.es',
        // «Juan Carlos» es un nombre compuesto: el apellido es «de la Fuente» (16/11/2026).
        'first_name' => 'Juan Carlos',
        'last_name' => 'de la Fuente',
        'name_stated' => false,
    ], CustomerData::extract('juan carlos de la fuente, jc@x.es'));
});

test('un correo suelto, con o sin frase delante', function (): void {
    customerDataSame(['email' => 'pepe@x.es'], CustomerData::extract('mi email es pepe@x.es'));
    customerDataSame(['email' => 'ana@x.es'], CustomerData::extract('ana@x.es'));
    customerDataSame(['email' => 'ana.lopez+tienda@dominio.co.uk'], CustomerData::extract('Mi correo: Ana.Lopez+Tienda@Dominio.co.uk.'));
});

test('dirección completa con teléfono', function (): void {
    customerDataSame([
        'email' => 'marta@ejemplo.test',
        'phone' => '999123456',
        'first_name' => 'Marta',
        'last_name' => 'Gil',
        'name_stated' => false,
        'address' => 'Calle Mayor 5 3ºB',
        'postcode' => '11001',
        'city' => 'Cádiz',
    ], CustomerData::extract('marta gil, Calle Mayor 5 3ºB, 11001 Cádiz, marta@ejemplo.test, 999 12 34 56'));
});

test('el código postal y la ciudad pueden ir en trozos separados', function (): void {
    customerDataSame([
        'email' => 'ana@x.es',
        'first_name' => 'Ana',
        'last_name' => 'Gil',
        'name_stated' => false,
        'address' => 'Avda. del Puerto 12',
        'city' => 'Valencia',
        'postcode' => '46021',
    ], CustomerData::extract('Ana Gil, Avda. del Puerto 12, Valencia, 46021, ana@x.es'));
});

test('un número de cinco cifras sin correo, NIF ni dirección no es un código postal', function (): void {
    same([], CustomerData::extract('quiero 2 botes de 46800 gramos'));
    same([], CustomerData::extract('quiero la talla 42, 999123456'), 'ni «talla 42» es una calle ni un teléfono suelto cuenta');
    same([], CustomerData::extract('pon 12345 en el carrito'));
    customerDataSame(['postcode' => '46800'], CustomerData::extract('46800'), 'como el prototipo: un código válido que va solo en su trozo sí cuenta');
});

test('la vía se recorta quitando el relleno de delante', function (): void {
    $r = CustomerData::extract('mi direccion es calle mayor 5, 46800 xativa');
    same('calle mayor 5', $r['address']);
    same('46800', $r['postcode']);
    same('xativa', $r['city']);
    same('Avenida del Mar 3', CustomerData::extract('envíalo a Avenida del Mar 3, 07001 Palma, a@b.es')['address']);
});

test('un teléfono solo, con o sin la etiqueta', function (): void {
    customerDataSame(['phone' => '999123456'], CustomerData::extract('mi teléfono es 999 12 34 56'));
    customerDataSame(['phone' => '999123456'], CustomerData::extract('999123456'));
    customerDataSame(['phone' => '999123456'], CustomerData::extract('+34 999 12 34 56'));
});

test('una dirección con teléfono y sin correo también es un mensaje de datos', function (): void {
    customerDataSame([
        'address' => 'conde de aranda 5',
        'phone' => '999345678',
    ], CustomerData::extract('conde de aranda 5, 999345678'));
});

test('el nombre dicho se distingue del deducido', function (): void {
    same(true, CustomerData::extract('me llamo Ana Gil, ana@x.es')['name_stated']);
    same(true, CustomerData::extract('Hola, soy Ana Gil')['name_stated']);
    same(true, CustomerData::extract('mi nombre es Ana Gil')['name_stated']);
    same(false, CustomerData::extract('Ana Gil, ana@x.es')['name_stated']);
    same(false, CustomerData::extract('Perdona, Ana Gil, ana@x.es')['name_stated'], 'lo que sobra es el nombre');
    check(!array_key_exists('name_stated', CustomerData::extract('ana@x.es')), 'sin nombre no hay name_stated');
});

test('el nombre no exige mayúscula inicial', function (): void {
    $r = CustomerData::extract('me llamo ana gil, ana@x.es');
    same('Ana', $r['first_name']);
    same('Gil', $r['last_name']);
    $r = CustomerData::extract('josé luis, jl@x.es');
    same('José', $r['first_name']);
    same('Luis', $r['last_name']);
});

test('«perdona», «hola», «gracias», «quiero» y «necesito» nunca son un nombre', function (): void {
    foreach (['Perdona', 'perdona', 'Hola', 'Gracias', 'quiero', 'Necesito', 'hola gracias', 'quiero pagar'] as $word) {
        customerDataSame(['email' => 'ana@x.es'], CustomerData::extract($word . ', ana@x.es'), $word);
    }
    same([], CustomerData::extract('hola'));
    same([], CustomerData::extract('gracias'));
});

test('nombres con tildes, eñes, diéresis, apóstrofos y guiones', function (): void {
    $r = CustomerData::extract('Zoë Müller, zoe@x.es');
    same(['Zoë', 'Müller'], [$r['first_name'], $r['last_name']]);
    $r = CustomerData::extract('ÑANDÚ PEÑA, n@x.es');
    same(['Ñandú', 'Peña'], [$r['first_name'], $r['last_name']]);
    $r = CustomerData::extract("Ana O'Brien-Pérez, a@x.es");
    same(['Ana', "O'Brien-Pérez"], [$r['first_name'], $r['last_name']], 'lo que ya trae mayúsculas internas se respeta');
    $r = CustomerData::extract('ana pérez-reverte, a@x.es');
    same(['Ana', 'Pérez-Reverte'], [$r['first_name'], $r['last_name']]);
    same('McDonald', CustomerData::extract('Ana McDonald, a@x.es')['last_name']);
});

test('casos raros: vacío, espacios, comillas, «&», emojis y bytes mal formados', function (): void {
    same([], CustomerData::extract(''));
    same([], CustomerData::extract("  \n\t "));
    same([], CustomerData::extract('&'));
    same([], CustomerData::extract('"""'));
    customerDataSame(['email' => 'ana@x.es'], CustomerData::extract('"ana@x.es"'));
    customerDataSame(['email' => 'ana@x.es'], CustomerData::extract('María José 😀, ana@x.es'));
    $r = CustomerData::extract("Ana & Pedro Gil, ana@x.es\xFF");
    same('ana@x.es', $r['email']);
    check(!isset($r['first_name']), 'con un «&» en medio no es un nombre');
    $r = CustomerData::extract("Ana Gil\n12345678Z\n28001 Madrid");
    same('12345678Z', $r['tax_id']);
    same('28001', $r['postcode']);
    same('Madrid', $r['city']);
});

test('un NIF inválido no se extrae', function (): void {
    same([], CustomerData::extract('12345678A'));
    customerDataSame(['email' => 'a@b.es'], CustomerData::extract('a@b.es 12345678A'));
    customerDataSame(['email' => 'a@b.es', 'tax_id' => 'B12345674'], CustomerData::extract('a@b.es b12345674'));
});

test('faltan datos: un cliente completo no echa en falta nada', function (): void {
    $c = CustomerData::clean([
        'email' => 'a@b.es', 'first_name' => 'A', 'last_name' => 'B',
        'address' => 'C/ Mayor 1', 'postcode' => '46800', 'city' => 'Xàtiva',
    ]);
    same('V', $c['province']);
    same([], CustomerData::missing($c));
});

test('faltan datos: un código postal inexistente cuenta como que falta', function (): void {
    $c = ['email' => 'a@b.es', 'first_name' => 'A', 'last_name' => 'B', 'address' => 'C/ Mayor 1', 'postcode' => '46800', 'city' => 'Xàtiva'];
    same(['postcode'], CustomerData::missing(array_merge($c, ['postcode' => '99999'])));
    same(['postcode'], CustomerData::missing(array_merge($c, ['postcode' => '4680'])));
    same([], CustomerData::missing(array_merge($c, ['postcode' => ' 46800 '])));
});

test('faltan datos: todo mal o vacío', function (): void {
    same(['email', 'first_name', 'last_name', 'address', 'postcode', 'city'], CustomerData::missing(['email' => 'mal']));
    same(['email', 'first_name', 'last_name', 'address', 'postcode', 'city'], CustomerData::missing([]));
    same(['first_name'], CustomerData::missing(['email' => 'a@b.es', 'first_name' => '   ', 'last_name' => 'B', 'address' => 'x', 'postcode' => '28001', 'city' => 'M']));
    same(['email'], CustomerData::missing(['email' => ['no'], 'first_name' => 'A', 'last_name' => 'B', 'address' => 'x', 'postcode' => '28001', 'city' => 'M']), 'un valor que no es texto cuenta como vacío');
});

test('faltan datos: lista de campos propia, con NIF y teléfono', function (): void {
    same(['tax_id', 'phone'], CustomerData::missing(['tax_id' => '12345678A', 'phone' => '123'], ['tax_id', 'phone']));
    same([], CustomerData::missing(['tax_id' => '12345678z', 'phone' => '+34 999 12 34 56'], ['tax_id', 'phone']));
    same(['phone'], CustomerData::missing([], ['phone']));
    same([], CustomerData::missing([], []));
});

test('faltan datos: el código postal se valida según el país', function (): void {
    same([], CustomerData::missing(['country' => 'PT', 'postcode' => '1000-001'], ['postcode']));
    same(['postcode'], CustomerData::missing(['country' => 'PT', 'postcode' => '28001'], ['postcode']));
    same(['postcode'], CustomerData::missing(['country' => 'FR', 'postcode' => '99'], ['postcode']));
});

test('limpiar: mayúsculas de nombre propio, y se respeta lo que ya las trae', function (): void {
    $m = CustomerData::clean(['first_name' => 'marta', 'last_name' => 'gil de la fuente', 'city' => 'cádiz']);
    same(['Marta', 'Gil de la Fuente', 'Cádiz'], [$m['first_name'], $m['last_name'], $m['city']]);
    same('McDonald', CustomerData::clean(['last_name' => 'McDonald'])['last_name']);
    same('De la Fuente', CustomerData::clean(['last_name' => 'de la Fuente'])['last_name'], 'el apellido empieza siempre por mayúscula');
    same('Ñandú', CustomerData::clean(['first_name' => 'ñandú'])['first_name']);
    same('Villanueva de la Serena', CustomerData::clean(['city' => 'villanueva de la serena'])['city']);
    same('La Coruña', CustomerData::clean(['city' => 'La Coruña'])['city']);
});

test('limpiar: espacios, correo, NIF inválido, país y provincia', function (): void {
    $c = CustomerData::clean([
        'email' => ' A@B.ES ', 'first_name' => '  Ana ', 'last_name' => 'Gil', 'tax_id' => '12345678A',
        'country' => 'xx1', 'address' => "  Calle   Sol\t2 ", 'postcode' => '28001', 'city' => 'Madrid',
    ]);
    same('a@b.es', $c['email']);
    same('Ana', $c['first_name']);
    same('', $c['tax_id'], 'NIF con la letra mal: vaciado');
    same('ES', $c['country'], 'país por defecto');
    same('Calle Sol 2', $c['address']);
    same('M', $c['province']);
    same('Madrid', $c['province_name']);
    same([], CustomerData::missing($c));
});

test('limpiar: NIF válido, teléfono y valores raros', function (): void {
    same('12345678Z', CustomerData::clean(['tax_id' => '12.345.678-z'])['tax_id']);
    same('B12345674', CustomerData::clean(['tax_id' => 'b-12345674'])['tax_id']);
    same('999123456', CustomerData::clean(['phone' => '+34 999 12 34 56'])['phone']);
    same('+33 600', CustomerData::clean(['phone' => '(+33) 600 abc'])['phone']);
    same('', CustomerData::clean(['email' => 'mal'])['email']);
    same('', CustomerData::clean(['postcode' => '99999'])['postcode']);
    same('', CustomerData::clean(['postcode' => '99999'])['province']);
    $c = CustomerData::clean([]);
    same('ES', $c['country']);
    same('', $c['email']);
    same('', $c['first_name']);
    $c = CustomerData::clean(['first_name' => ['x'], 'city' => null, 'address' => 12, 'email' => false]);
    same(['', '', '12', ''], [$c['first_name'], $c['city'], $c['address'], $c['email']]);
    same(60, mb_strlen(CustomerData::clean(['first_name' => str_repeat('ñ', 100)])['first_name'], 'UTF-8'));
});

test('limpiar: en otro país no se deduce provincia ni se exige el NIF español', function (): void {
    $c = CustomerData::clean(['country' => 'fr', 'postcode' => '75001', 'tax_id' => 'FR12345678901', 'province' => 'Île-de-France']);
    same('FR', $c['country']);
    same('75001', $c['postcode']);
    same('FR12345678901', $c['tax_id']);
    same('Île-de-France', $c['province']);
    same('Île-de-France', $c['province_name']);
    same('', CustomerData::clean(['country' => 'FR', 'postcode' => '7500'])['postcode']);
});

test('fusionar: lo nuevo solo pisa lo conocido si trae valor', function (): void {
    $known = ['email' => 'a@b.es', 'city' => 'Valencia', 'phone' => '999123456'];
    $merged = CustomerData::merge($known, ['email' => 'nuevo@b.es', 'city' => '', 'phone' => null, 'address' => 'Calle Sol 2']);
    customerDataSame(['email' => 'nuevo@b.es', 'city' => 'Valencia', 'phone' => '999123456', 'address' => 'Calle Sol 2'], $merged);
    same($known, CustomerData::merge($known, []), 'sin nada nuevo, todo igual');
    same(['email' => 'a@b.es'], CustomerData::merge([], ['email' => 'a@b.es', 'city' => '   ']));
});

test('fusionar: un nombre deducido no pisa a uno dicho expresamente', function (): void {
    $known = ['first_name' => 'Ana', 'last_name' => 'Gil', 'name_stated' => true, 'email' => 'a@b.es'];
    $merged = CustomerData::merge($known, ['first_name' => 'Perdona', 'last_name' => 'Pedro', 'name_stated' => false, 'city' => 'Madrid']);
    customerDataSame(['first_name' => 'Ana', 'last_name' => 'Gil', 'name_stated' => true, 'email' => 'a@b.es', 'city' => 'Madrid'], $merged);
});

test('fusionar: un nombre dicho pisa al conocido, dicho o deducido', function (): void {
    $stated = ['first_name' => 'Ana', 'last_name' => 'Gil', 'name_stated' => true];
    customerDataSame(
        ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => true],
        CustomerData::merge($stated, ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => true])
    );
    $deduced = ['first_name' => 'Ana', 'last_name' => 'Gil', 'name_stated' => false];
    customerDataSame(
        ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => true],
        CustomerData::merge($deduced, ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => true])
    );
    customerDataSame(
        ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => false],
        CustomerData::merge($deduced, ['first_name' => 'Eva', 'last_name' => 'Ruiz', 'name_stated' => false])
    );
});

test('fusionar: un nombre deducido rellena lo que falta aunque lo anterior fuese dicho', function (): void {
    $known = ['first_name' => 'Ana', 'name_stated' => true];
    $merged = CustomerData::merge($known, ['first_name' => 'Otra', 'last_name' => 'Gil', 'name_stated' => false]);
    same('Ana', $merged['first_name']);
    same('Gil', $merged['last_name']);
    same(true, $merged['name_stated']);
});

test('fusionar con lo extraído de dos mensajes seguidos', function (): void {
    $c = CustomerData::merge([], CustomerData::extract('me llamo Ana Gil'));
    $c = CustomerData::merge($c, CustomerData::extract('Perdona, ana@x.es'));
    $c = CustomerData::merge($c, CustomerData::extract('Calle Sol 2, 28001 Madrid, otra Persona'));
    same('Ana', $c['first_name']);
    same('Gil', $c['last_name']);
    same('ana@x.es', $c['email']);
    same('Calle Sol 2', $c['address']);
    same('28001', $c['postcode']);
    same('Madrid', $c['city']);
    same([], CustomerData::missing($c));
});
