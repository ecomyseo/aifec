<?php
/**
 * AIFEC — pruebas de los países.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Geo\Countries;

test('nombres de países en castellano y en inglés', function (): void {
    same('España', Countries::name('ES'));
    same('España', Countries::name('ES', 'es'));
    same('Spain', Countries::name('ES', 'en'));
    same('Alemania', Countries::name('DE', 'es'));
    same('Germany', Countries::name('DE', 'en'));
    same('Francia', Countries::name('fr', 'es'), 'el código se admite en minúsculas');
    same('United States', Countries::name('US', 'en'));
    same('Estados Unidos', Countries::name('US', 'es'));
    same('Reino Unido', Countries::name('GB', 'es'));
    same('United Kingdom', Countries::name('gb', 'en'));
});

test('un código que no es un país da cadena vacía', function (): void {
    same('', Countries::name(''));
    same('', Countries::name('E'));
    same('', Countries::name('ESP'));
    same('', Countries::name('1A'));
    same('', Countries::name('ZZ'));
    same('', Countries::name('<s'));
});

test('el idioma raro o mal escrito no rompe nada', function (): void {
    same('España', Countries::name('ES', 'es-ES'));
    same('Spain', Countries::name('ES', 'en_GB'));
    check(Countries::name('ES', '') !== '');
    check(Countries::name('ES', '!!') !== '');
});

test('la tabla de respaldo cubre los 27 de la Unión Europea y los demás pedidos, en dos idiomas', function (): void {
    $eu = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];
    $others = ['GB', 'CH', 'NO', 'AD', 'US', 'MX', 'AR', 'CL', 'CO', 'PE'];
    same(27, count($eu));
    foreach (array_merge($eu, $others) as $iso) {
        check(Countries::fallbackName($iso, 'es') !== '', "falta «{$iso}» en castellano");
        check(Countries::fallbackName($iso, 'en') !== '', "falta «{$iso}» en inglés");
        check(Countries::name($iso, 'es') !== '', "name() no devuelve nada para {$iso}");
    }
    same('España', Countries::fallbackName('ES', 'es'));
    same('Spain', Countries::fallbackName('ES', 'en'));
    same('Spain', Countries::fallbackName('ES', 'fr'), 'otro idioma: inglés');
    same('Perú', Countries::fallbackName('PE', 'es'));
    same('', Countries::fallbackName('JP', 'es'));
    same('', Countries::fallbackName('xx', 'es'));
});

test('todos los países, con su código y ordenados', function (): void {
    $es = Countries::all('es');
    check(count($es) >= 38, 'al menos los de la tabla propia');
    same('España', $es['ES']);
    same('Alemania', $es['DE']);
    $order = array_flip(array_keys($es));
    check($order['DE'] < $order['ES'], 'Alemania antes que España');
    check($order['ES'] < $order['FR'], 'España antes que Francia');
    check($order['FR'] < $order['SE'], 'Francia antes que Suecia');
    foreach ($es as $iso => $name) {
        check(preg_match('/^[A-Z]{2}$/', (string) $iso) === 1, 'clave ISO: ' . $iso);
        check($name !== '', 'nombre vacío en ' . $iso);
    }
    $en = Countries::all('en');
    same('Spain', $en['ES']);
    $orderEn = array_flip(array_keys($en));
    check($orderEn['ES'] < $orderEn['SE'], 'Spain antes que Sweden');
    check($orderEn['FR'] < $orderEn['DE'], 'France antes que Germany: en inglés se ordena por el nombre inglés');
});

test('pertenencia a la Unión Europea', function (): void {
    check(Countries::isEu('ES'));
    check(Countries::isEu('es'));
    check(Countries::isEu('DE'));
    check(Countries::isEu('SE'));
    check(!Countries::isEu('GB'));
    check(!Countries::isEu('CH'));
    check(!Countries::isEu('NO'));
    check(!Countries::isEu('US'));
    check(!Countries::isEu(''));
    check(!Countries::isEu('ESP'));
    $count = 0;
    foreach (array_keys(Countries::all('en')) as $iso) {
        if (Countries::isEu((string) $iso)) {
            ++$count;
        }
    }
    same(27, $count, 'son 27 y todos tienen nombre');
});

test('códigos postales por país', function (): void {
    check(Countries::validPostcode('ES', '28001'));
    check(!Countries::validPostcode('ES', '99999'));
    check(Countries::validPostcode('PT', '1000-001'));
    check(!Countries::validPostcode('PT', '1000001'));
    check(Countries::validPostcode('FR', '75001'));
    check(!Countries::validPostcode('FR', '7500'));
    check(Countries::validPostcode('DE', '10115'));
    check(!Countries::validPostcode('DE', '1011'));
    check(Countries::validPostcode('IT', '00100'));
    check(Countries::validPostcode('GB', 'SW1A 1AA'));
    check(Countries::validPostcode('GB', 'sw1a1aa'));
    check(Countries::validPostcode('GB', 'M1 1AE'));
    check(!Countries::validPostcode('GB', '12345'));
    check(Countries::validPostcode('NL', '1012 AB'));
    check(Countries::validPostcode('NL', '1012AB'));
    check(!Countries::validPostcode('NL', '0123 AB'));
    check(Countries::validPostcode('BE', '1000'));
    check(!Countries::validPostcode('BE', '0999'));
    check(Countries::validPostcode('AD', 'AD500'));
    check(!Countries::validPostcode('AD', '50000'));
    check(Countries::validPostcode('US', '90210'));
    check(Countries::validPostcode('US', '90210-1234'));
    check(!Countries::validPostcode('US', '9021'));
});

test('códigos postales de otros países: cualquier valor no vacío de hasta 12 caracteres', function (): void {
    check(Countries::validPostcode('JP', '100-0001'));
    check(Countries::validPostcode('MX', '01000'));
    check(Countries::validPostcode('AR', 'C1425'));
    check(Countries::validPostcode('JP', '123456789012'));
    check(!Countries::validPostcode('JP', '1234567890123'));
    check(!Countries::validPostcode('JP', ''));
    check(!Countries::validPostcode('JP', '   '));
    check(!Countries::validPostcode('ES', ''));
    check(Countries::validPostcode('es', ' 28001 '));
    check(Countries::validPostcode('JP', 'ñandú 1'), 'cuenta caracteres, no bytes');
});
