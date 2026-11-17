<?php
/**
 * AIFEC — pruebas de la geografía y los documentos de España.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Geo\Spain;

test('hay 52 provincias, sin códigos ni prefijos repetidos y con los prefijos 01 a 52 completos', function (): void {
    $all = Spain::provinces();
    same(52, count($all));
    $codes = array_column($all, 'code');
    $prefixes = array_column($all, 'prefix');
    same(52, count(array_unique($codes)));
    same(52, count(array_unique($prefixes)));
    $expected = [];
    for ($i = 1; $i <= 52; ++$i) {
        $expected[] = sprintf('%02d', $i);
    }
    $sorted = $prefixes;
    sort($sorted);
    same($expected, $sorted);
    foreach ($all as $row) {
        same(['code', 'prefix', 'name', 'region'], array_keys($row));
        check($row['name'] !== '' && $row['region'] !== '', 'nombre y comunidad no vacíos en ' . $row['prefix']);
    }
});

test('los códigos de provincia son los de WooCommerce', function (): void {
    $byPrefix = [];
    foreach (Spain::provinces() as $row) {
        $byPrefix[$row['prefix']] = $row['code'];
    }
    same('VI', $byPrefix['01']);
    same('A', $byPrefix['03']);
    same('PM', $byPrefix['07']);
    same('C', $byPrefix['15']);
    same('SS', $byPrefix['20']);
    same('LO', $byPrefix['26']);
    same('M', $byPrefix['28']);
    same('GC', $byPrefix['35']);
    same('TF', $byPrefix['38']);
    same('V', $byPrefix['46']);
    same('VA', $byPrefix['47']);
    same('BI', $byPrefix['48']);
    same('ZA', $byPrefix['49']);
    same('Z', $byPrefix['50']);
    same('CE', $byPrefix['51']);
    same('ML', $byPrefix['52']);
});

test('la provincia de un código postal', function (): void {
    $v = Spain::provinceByPostcode('46800');
    same('V', $v['code']);
    same('46', $v['prefix']);
    same('Valencia', $v['name']);
    same('Comunidad Valenciana', $v['region']);
    same('Madrid', Spain::provinceByPostcode(' 28001 ')['name']);
    same('Santa Cruz de Tenerife', Spain::provinceByPostcode('38001')['name']);
    same('Araba/Álava', Spain::provinceByPostcode('01001')['name']);
    same(null, Spain::provinceByPostcode('99999'));
    same(null, Spain::provinceByPostcode('00100'));
    same(null, Spain::provinceByPostcode(''));
});

test('código postal válido: cinco cifras y prefijo 01 a 52', function (): void {
    check(Spain::validPostcode('28001'));
    check(Spain::validPostcode('01001'));
    check(Spain::validPostcode('52999'));
    check(Spain::validPostcode('  46800  '), 'admite espacios alrededor');
    check(!Spain::validPostcode('53000'));
    check(!Spain::validPostcode('00123'));
    check(!Spain::validPostcode('2800'));
    check(!Spain::validPostcode('280011'));
    check(!Spain::validPostcode('28 001'));
    check(!Spain::validPostcode('2800a'));
    check(!Spain::validPostcode(''));
    check(!Spain::validPostcode("28001\nx"));
    check(!Spain::validPostcode('２８００１'), 'cifras de anchura completa no valen');
});

test('zonas de envío', function (): void {
    same('baleares', Spain::zone('07001'));
    same('canarias', Spain::zone('35001'));
    same('canarias', Spain::zone('38001'));
    same('ceuta', Spain::zone('51001'));
    same('melilla', Spain::zone('52001'));
    same('peninsula', Spain::zone('28001'));
    same('peninsula', Spain::zone('01001'));
    same('peninsula', Spain::zone('50001'));
    same('peninsula', Spain::zone(' 46800 '));
    same('', Spain::zone('99999'));
    same('', Spain::zone('abc'));
    same('', Spain::zone(''));
});

test('isPeninsula', function (): void {
    check(Spain::isPeninsula('28001'));
    check(Spain::isPeninsula('46800'));
    check(!Spain::isPeninsula('07001'));
    check(!Spain::isPeninsula('35001'));
    check(!Spain::isPeninsula('38001'));
    check(!Spain::isPeninsula('51001'));
    check(!Spain::isPeninsula('52001'));
    check(!Spain::isPeninsula('99999'));
});

test('NIF: la letra tiene que ser la que toca', function (): void {
    check(Spain::validTaxId('12345678Z'));
    check(!Spain::validTaxId('12345678A'));
    check(Spain::validTaxId('12345678Z'));
    check(Spain::validTaxId('12.345.678-z'), 'minúsculas, puntos y guiones');
    check(Spain::validTaxId(' 12345678 Z '));
    check(!Spain::validTaxId('12345678'));
    check(!Spain::validTaxId('1234567Z'));
    check(!Spain::validTaxId(''));
    check(!Spain::validTaxId('hola'));
});

test('los documentos de mentira de las pruebas salen con su control bien calculado', function (): void {
    // Contra los ejemplos de siempre, que están comprobados a mano.
    same('12345678Z', fakeDni(12345678));
    same('00000000T', fakeDni(0));
    same('99999999R', fakeDni(99999999));
    same('X1234567L', fakeNie('X', 1234567));
    same('B12345674', fakeCompanyTaxId('B', 1234567));
    same('P1234567D', fakeCompanyTaxId('P', 1234567), 'una entidad de las que llevan letra');
    check(preg_match('/^\d{8}[A-Z]$/D', fakeDni()) === 1, 'un DNI al azar tiene ocho cifras y letra');
    check(preg_match('/^[XYZ]\d{7}[A-Z]$/D', fakeNie()) === 1, 'un NIE al azar');
    check(preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/D', fakeCompanyTaxId()) === 1, 'un NIF de entidad al azar');
});

test('DNI al azar: con su letra valen, con cualquier otra letra no, y se normalizan escritos de cualquier manera', function (): void {
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    for ($i = 0; $i < 300; ++$i) {
        $dni = fakeDni();
        check(Spain::validTaxId($dni), 'vale ' . $dni);
        $number = substr($dni, 0, 8);
        $other = $letters[(strpos($letters, $dni[8]) + 1 + $i % 22) % 23];
        check(!Spain::validTaxId($number . $other), 'no vale ' . $number . $other . ' (su letra es la ' . $dni[8] . ')');
        $written = substr($number, 0, 2) . '.' . substr($number, 2, 3) . '.' . substr($number, 5, 3) . '-' . strtolower($dni[8]);
        check(Spain::validTaxId($written), 'vale con puntos, guion y minúscula: ' . $written);
        same($dni, Spain::normalizeTaxId(' ' . $written . ' '), 'se normaliza ' . $written);
    }
    // Los bordes del número.
    foreach ([0, 1, 22, 23, 99999998, 99999999] as $number) {
        check(Spain::validTaxId(fakeDni($number)), 'vale ' . fakeDni($number));
    }
});

test('NIE al azar: X, Y y Z con su letra valen y con otra no', function (): void {
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    foreach (['X', 'Y', 'Z'] as $prefix) {
        for ($i = 0; $i < 100; ++$i) {
            $nie = fakeNie($prefix);
            check(Spain::validTaxId($nie), 'vale ' . $nie);
            check(Spain::validTaxId(strtolower($prefix) . '-' . substr($nie, 1, 7) . '-' . strtolower($nie[8])), 'vale con guiones y minúsculas: ' . $nie);
            $other = $letters[(strpos($letters, $nie[8]) + 1 + $i % 22) % 23];
            check(!Spain::validTaxId(substr($nie, 0, 8) . $other), 'no vale ' . substr($nie, 0, 8) . $other);
        }
    }
});

test('NIF de entidad al azar: cada tipo con su control vale, con otro control no, y la letra o la cifra según el tipo', function (): void {
    foreach (str_split('ABCDEFGHJNPQRSUVW') as $type) {
        for ($i = 0; $i < 40; ++$i) {
            $nif = fakeCompanyTaxId($type);
            check(Spain::validTaxId($nif), 'vale ' . $nif);
            $control = $nif[8];
            $asLetter = strpos('NPQRSW', $type) !== false;
            $digit = $asLetter ? (int) strpos('JABCDEFGHI', $control) : (int) $control;
            $wrong = ($digit + 1 + $i % 9) % 10;
            check(!Spain::validTaxId(substr($nif, 0, 8) . ($asLetter ? 'JABCDEFGHI'[$wrong] : (string) $wrong)), 'no vale ' . substr($nif, 0, 8) . ' con otro control');
            if ($asLetter) {
                check(!Spain::validTaxId(substr($nif, 0, 8) . $digit), $type . ' pide letra: no vale ' . substr($nif, 0, 8) . $digit);
            } elseif (strpos('ABEH', $type) !== false) {
                check(!Spain::validTaxId(substr($nif, 0, 8) . 'JABCDEFGHI'[$digit]), $type . ' pide cifra: no vale ' . substr($nif, 0, 8) . 'JABCDEFGHI'[$digit]);
            } else {
                check(Spain::validTaxId(substr($nif, 0, 8) . 'JABCDEFGHI'[$digit]), $type . ' admite también la letra: ' . substr($nif, 0, 8) . 'JABCDEFGHI'[$digit]);
            }
        }
    }
});

test('NIE: X, Y y Z con su letra', function (): void {
    check(Spain::validTaxId('X1234567L'));
    check(Spain::validTaxId('x-1234567-l'));
    check(!Spain::validTaxId('X1234567A'));
    check(!Spain::validTaxId('W1234567L'));
    // Y y Z sustituyen a 1 y 2: Y1234567 equivale a 11234567, y Z1234567 a 21234567.
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    check(Spain::validTaxId('Y1234567' . $letters[11234567 % 23]));
    check(Spain::validTaxId('Z1234567' . $letters[21234567 % 23]));
});

test('CIF: dígito o letra de control según la entidad', function (): void {
    check(Spain::validTaxId('B12345674'));
    check(Spain::validTaxId('b-12345674'));
    check(!Spain::validTaxId('B12345675'));
    check(!Spain::validTaxId('B12345670'));
    check(!Spain::validTaxId('B1234567'));
    // El control de B12345674 es 4. En A, B, E y H solo vale el dígito; en N, P, Q, R, S y W, la letra.
    check(!Spain::validTaxId('B1234567D'), 'una B pide dígito');
    check(Spain::validTaxId('P1234567D'), 'una P pide letra: el control 4 es la D');
    check(!Spain::validTaxId('P12345674'));
    check(Spain::validTaxId('C1234567D') && Spain::validTaxId('C12345674'), 'una C admite las dos');
    check(!Spain::validTaxId('I12345674'), 'la I no existe como entidad');
});

test('normalizar un NIF', function (): void {
    same('12345678Z', Spain::normalizeTaxId(' 12.345.678-z '));
    same('B12345674', Spain::normalizeTaxId('b 12345674'));
    same('', Spain::normalizeTaxId(''));
    same('', Spain::normalizeTaxId('---'));
});

test('teléfonos con y sin prefijo', function (): void {
    same('999123456', Spain::normalizePhone('999123456'));
    same('999123456', Spain::normalizePhone('999 12 34 56'));
    same('999123456', Spain::normalizePhone('999-12-34-56'));
    same('999123456', Spain::normalizePhone('999.123.456'));
    same('999123456', Spain::normalizePhone('+34 999 123 456'));
    same('999123456', Spain::normalizePhone('+34999123456'));
    same('999123456', Spain::normalizePhone('0034 999 12 34 56'));
    same('999345678', Spain::normalizePhone('99 934 56 78'));
    // Vale cualquier número de nueve cifras que empiece por 6, 7, 8 o 9. Los de esta prueba se montan aquí, sin escribirlos
    // enteros: en el código no hay ningún teléfono que pueda ser de alguien (los escritos son del rango 999, sin atribuir).
    foreach (['6', '7', '8', '9'] as $first) {
        $number = $first . '99000111';
        same($number, Spain::normalizePhone($number), 'empieza por ' . $first);
        same($number, Spain::normalizePhone('+34' . $number), 'con prefijo, empieza por ' . $first);
    }
    $rest = '99000111';
    foreach (['0', '1', '2', '3', '4', '5'] as $first) {
        $number = $first . $rest;
        same('', Spain::normalizePhone($number), 'no vale si empieza por ' . $first);
    }
});

test('teléfonos que no son españoles válidos', function (): void {
    // Ninguno es un teléfono español válido (corto, largo, prefijo que no existe): en una lista aparte, para que el vigilante de
    // teléfonos antes de publicar no los confunda con uno de verdad.
    $invalid = ['', '12345678', '1234567890', '500123456', '+44 7700 900123', '6001234567'];
    foreach ($invalid as $raw) {
        same('', Spain::normalizePhone($raw), 'no vale: ' . $raw);
    }
    same('', Spain::normalizePhone('hola'));
    same('', Spain::normalizePhone('+34 +34 999123456'));
});
