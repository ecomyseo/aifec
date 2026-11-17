<?php
/**
 * AIFEC — lo que el asistente de PrestaShop (ecom_asistente 0.13.0) aprendió a leer de los datos del cliente y AIFEC no leía:
 * el pasaporte como documento, «I'm from the UK.» delante del nombre, «la ciudad es Oviedo no Tlf…» y el mensaje que es solo el
 * nombre («vale pues lucas martin perez», «lucas, martin perez»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Checkout\TextReader;

/** @return array<string,string> */
function ck0130(string $text): array
{
    return TextReader::read($text, [], true)['fields'];
}

test('el pasaporte vale de documento y no se queda pegado a la calle', function (): void {
    $f = ck0130('Lucas Martín Pérez, pasaporte X1234567, Calle Mayor 5, 28013 Madrid');
    same('X1234567', $f['vat_id'] ?? '');
    same('Calle Mayor 5', $f['line1'] ?? '');
    same('123456789', ck0130('Ana Ruiz, passport no. 123456789, Calle Mayor 5, 28013 Madrid')['vat_id'] ?? '');
});

test('de dónde es quien escribe no se come el nombre', function (): void {
    $f = ck0130("I'm from the UK. John Smith, Calle Mayor 5, 28013 Madrid, john@example.com");
    same('John', $f['first_name'] ?? '');
    same('Smith', $f['last_name'] ?? '');
    check(!isset($f['country']) || $f['country'] === 'ES', 'y no cambia el país de la entrega');
});

test('la ciudad con etiqueta acaba en «no», «not» o en el teléfono', function (): void {
    same('Oviedo', ck0130('Ana López, Calle Uría 3, 33003, la ciudad es Oviedo no Tlf 999111222')['city'] ?? '');
    same('Leeds', ck0130('John Smith, 10 High Street, city: Leeds not London')['city'] ?? '');
});

test('un mensaje que es solo el nombre se lee como nombre; lo que no es un nombre, no', function (): void {
    same(['first_name' => 'Lucas', 'last_name' => 'Martin Perez'], ck0130('vale pues lucas martin perez'));
    same(['first_name' => 'Lucas', 'last_name' => 'Martin Perez'], ck0130('lucas, martin perez'));
    same([], ck0130('quiero pagar con tarjeta'), 'una frase de la compra no es un nombre');
    same([], ck0130('la silla roja'), 'ni lo que empieza por un artículo');
    same([], ck0130('vale'), 'ni una palabra suelta');
});
