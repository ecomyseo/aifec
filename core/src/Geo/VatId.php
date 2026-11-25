<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Geo;

/**
 * El NIF-IVA (número de identificación a efectos de IVA) con el formato que usa VIES: dos letras del país y el número. Solo comprueba
 * el FORMATO (y la letra o dígito de control de los españoles): que el número exista y esté dado de alta lo dice VIES, y eso es una
 * consulta a un servicio de la Comisión que esta clase no hace.
 *
 * Un número sin prefijo se entiende del país de la tienda («B12345674» es español). Con un prefijo que no es de la UE (GB, CH, US…)
 * no hay formato que comprobar y se acepta si parece un identificador.
 */
final class VatId
{
    /** País (código VIES) => expresión del número sin prefijo. «ES» se comprueba además con su control. */
    private const FORMATS = [
        'AT' => '/^U\d{8}$/', 'BE' => '/^[01]\d{9}$/', 'BG' => '/^\d{9,10}$/', 'CY' => '/^\d{8}[A-Z]$/', 'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/', 'DK' => '/^\d{8}$/', 'EE' => '/^\d{9}$/', 'EL' => '/^\d{9}$/', 'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^\d{8}$/', 'FR' => '/^[0-9A-Z]{2}\d{9}$/', 'HR' => '/^\d{11}$/', 'HU' => '/^\d{8}$/',
        'IE' => '/^(?:\d[A-Z0-9+*]\d{5}[A-Z]|\d{7}[A-Z]{1,2})$/', 'IT' => '/^\d{11}$/', 'LT' => '/^(?:\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/', 'LV' => '/^\d{11}$/', 'MT' => '/^\d{8}$/', 'NL' => '/^\d{9}B\d{2}$/', 'PL' => '/^\d{10}$/', 'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/', 'SE' => '/^\d{12}$/', 'SI' => '/^\d{8}$/', 'SK' => '/^\d{10}$/', 'XI' => '/^(?:\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
    ];

    /** Mayúsculas y solo letras y cifras: «es b-12345674» pasa a «ESB12345674». «GR» se escribe «EL» en VIES. */
    public static function normalize(string $id): string
    {
        $v = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($id));

        return strpos($v, 'GR') === 0 && preg_match('/^GR\d{9}$/', $v) === 1 ? 'EL' . substr($v, 2) : $v;
    }

    /**
     * Separa el país y el número: ['country' => 'ES', 'number' => 'B12345674'], o null si no se puede (vacío o sin forma de NIF-IVA).
     *
     * @return array{country:string,number:string}|null
     */
    public static function split(string $id, string $defaultCountry = 'ES'): ?array
    {
        $v = self::normalize($id);
        if ($v === '') {
            return null;
        }
        // Dos letras seguidas son un país: ningún número de IVA nacional empieza así (el español, una letra y cifras).
        if (preg_match('/^[A-Z]{2}[A-Z0-9]/', $v) === 1) {
            return ['country' => substr($v, 0, 2), 'number' => substr($v, 2)];
        }

        return ['country' => self::country($defaultCountry), 'number' => $v];
    }

    /** ¿Tiene forma de NIF-IVA (y control correcto si es español)? Vacío no es válido: quien lo permite vacío lo decide antes. */
    public static function valid(string $id, string $defaultCountry = 'ES'): bool
    {
        $parts = self::split($id, $defaultCountry);
        if ($parts === null) {
            return false;
        }
        if (!isset(self::FORMATS[$parts['country']])) {
            // Sin formato VIES que comprobar (un país de fuera de la UE, o el de la tienda si no es de la UE): parece un identificador.
            return preg_match('/^(?=.*\d)[A-Z0-9]{4,18}$/', $parts['number']) === 1;
        }

        return self::fits($parts['country'], $parts['number']);
    }

    /**
     * El número tal como lo pide VIES: país y número, sin separadores («ESB12345674»). Null si no es válido.
     */
    public static function vies(string $id, string $defaultCountry = 'ES'): ?string
    {
        $parts = self::split($id, $defaultCountry);
        if ($parts === null || !isset(self::FORMATS[$parts['country']]) || !self::fits($parts['country'], $parts['number'])) {
            return null;
        }

        return $parts['country'] . $parts['number'];
    }

    private static function country(string $iso2): string
    {
        $c = strtoupper(trim($iso2));

        return $c === 'GR' ? 'EL' : ($c === '' ? 'ES' : $c);
    }

    private static function fits(string $country, string $number): bool
    {
        if (!isset(self::FORMATS[$country]) || preg_match(self::FORMATS[$country], $number) !== 1) {
            return false;
        }

        return $country !== 'ES' || Spain::validTaxId($number);
    }
}
