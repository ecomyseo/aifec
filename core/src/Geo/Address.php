<?php
/**
 * AIFEC — una dirección postal como la lee una persona.
 *
 * Las direcciones se guardan con códigos (país «ES», provincia «46») y se enseñan con nombres («España», «Valencia»).
 * Hay un solo sitio que hace esa conversión: lo usan la página del pedido, la zona de cliente, la ficha del pedido en
 * el panel y los correos. Antes cada uno lo hacía a su manera y dos de ellos enseñaban los códigos tal cual.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Geo;

final class Address
{
    /**
     * Las líneas de una dirección, en el orden en que se escriben en un sobre: nombre, empresa, NIF, calle, código postal
     * y población, provincia, país y teléfono. Lo que esté vacío no sale.
     *
     * @param array<string,mixed> $address first_name, last_name, company, vat_id, line1, line2, postcode, city, region, country, phone
     * @param array<string,bool>  $options name, tax_id y phone (los tres true por defecto): false para dejar fuera esa línea
     *
     * @return string[]
     */
    public static function lines(array $address, string $locale = 'es', array $options = []): array
    {
        $text = static function (string $key) use ($address): string {
            return isset($address[$key]) && is_scalar($address[$key]) ? trim((string) $address[$key]) : '';
        };
        $with = static function (string $key) use ($options): bool {
            return !array_key_exists($key, $options) || !empty($options[$key]);
        };

        $lines = [];
        $name = $with('name') ? trim($text('first_name') . ' ' . $text('last_name')) : '';
        foreach ([$name, $text('company'), $with('tax_id') ? $text('vat_id') : '', $text('line1'), $text('line2')] as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $city = $text('city');
        $place = trim($text('postcode') . ' ' . $city);
        if ($place !== '') {
            $lines[] = $place;
        }
        $country = strtoupper($text('country'));
        $region = self::regionName($country, $text('region'));
        // «46000 Valencia» y debajo «Valencia» otra vez no aporta nada.
        if ($region !== '' && self::fold($region) !== self::fold($city)) {
            $lines[] = $region;
        }
        if ($country !== '') {
            $countryName = Countries::name($country, $locale);
            $lines[] = $countryName !== '' ? $countryName : $country;
        }
        if ($with('phone') && $text('phone') !== '') {
            $lines[] = $text('phone');
        }

        return $lines;
    }

    /**
     * El nombre de una provincia o región a partir de lo guardado. En España se guarda el prefijo postal («46») o el
     * código de dos letras que usan otras tiendas («V»): los dos se convierten en «Valencia». Lo que no se reconoce
     * (el nombre ya escrito, la región de otro país) se devuelve como está.
     */
    public static function regionName(string $country, string $region): string
    {
        $region = trim($region);
        if ($region === '' || strtoupper(trim($country)) !== 'ES') {
            return $region;
        }
        $wanted = strtoupper($region);
        foreach (Spain::provinces() as $province) {
            if ((string) $province['prefix'] === $region || strtoupper((string) $province['code']) === $wanted) {
                return (string) $province['name'];
            }
        }

        return $region;
    }

    /** Para comparar dos nombres sin tildes ni mayúsculas. */
    private static function fold(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ò' => 'o', 'ç' => 'c']);
    }
}
