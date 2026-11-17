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
 * Países: nombre en el idioma que se pida, pertenencia a la Unión Europea y forma de un código
 * postal. Los nombres salen de la extensión `intl`; si falta, de una tabla propia con los 27
 * países de la Unión Europea y unos cuantos más (castellano e inglés).
 */
final class Countries
{
    /** Códigos ISO 3166-1 alfa-2 asignados. */
    private const ISO_CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
        'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
        'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
        'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
        'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
        'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM',
        'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ',
        'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** Los 27 Estados miembros de la Unión Europea. */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /** Tabla de respaldo: código => [castellano, inglés]. */
    private const FALLBACK = [
        'AT' => ['Austria', 'Austria'],
        'BE' => ['Bélgica', 'Belgium'],
        'BG' => ['Bulgaria', 'Bulgaria'],
        'HR' => ['Croacia', 'Croatia'],
        'CY' => ['Chipre', 'Cyprus'],
        'CZ' => ['Chequia', 'Czechia'],
        'DK' => ['Dinamarca', 'Denmark'],
        'EE' => ['Estonia', 'Estonia'],
        'FI' => ['Finlandia', 'Finland'],
        'FR' => ['Francia', 'France'],
        'DE' => ['Alemania', 'Germany'],
        'GR' => ['Grecia', 'Greece'],
        'HU' => ['Hungría', 'Hungary'],
        'IE' => ['Irlanda', 'Ireland'],
        'IT' => ['Italia', 'Italy'],
        'LV' => ['Letonia', 'Latvia'],
        'LT' => ['Lituania', 'Lithuania'],
        'LU' => ['Luxemburgo', 'Luxembourg'],
        'MT' => ['Malta', 'Malta'],
        'NL' => ['Países Bajos', 'Netherlands'],
        'PL' => ['Polonia', 'Poland'],
        'PT' => ['Portugal', 'Portugal'],
        'RO' => ['Rumanía', 'Romania'],
        'SK' => ['Eslovaquia', 'Slovakia'],
        'SI' => ['Eslovenia', 'Slovenia'],
        'ES' => ['España', 'Spain'],
        'SE' => ['Suecia', 'Sweden'],
        'GB' => ['Reino Unido', 'United Kingdom'],
        'CH' => ['Suiza', 'Switzerland'],
        'NO' => ['Noruega', 'Norway'],
        'AD' => ['Andorra', 'Andorra'],
        'US' => ['Estados Unidos', 'United States'],
        'MX' => ['México', 'Mexico'],
        'AR' => ['Argentina', 'Argentina'],
        'CL' => ['Chile', 'Chile'],
        'CO' => ['Colombia', 'Colombia'],
        'PE' => ['Perú', 'Peru'],
    ];

    /** Letras con tilde y su equivalente, para ordenar sin la extensión `intl`. */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * Nombre de un país en el idioma pedido ('es', 'en', 'fr'…). Devuelve '' si el código no es
     * un país ISO.
     */
    public static function name(string $iso2, string $locale = 'es'): string
    {
        $iso = self::iso($iso2);
        if ($iso === '' || !in_array($iso, self::ISO_CODES, true)) {
            return '';
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('und-' . $iso, self::cleanLocale($locale));
            if (is_string($name) && $name !== '' && $name !== $iso) {
                return $name;
            }
        }

        return self::fallbackName($iso, $locale);
    }

    /**
     * Nombre de la tabla propia (sin `intl`): castellano si el idioma empieza por «es», inglés
     * en cualquier otro caso. '' si el país no está en la tabla.
     */
    public static function fallbackName(string $iso2, string $locale = 'es'): string
    {
        $iso = self::iso($iso2);
        if (!isset(self::FALLBACK[$iso])) {
            return '';
        }

        return self::FALLBACK[$iso][strtolower(substr(trim($locale), 0, 2)) === 'es' ? 0 : 1];
    }

    /**
     * Todos los países como `['ES' => 'España', …]`, ordenados alfabéticamente según el idioma.
     * Sin `intl` solo salen los de la tabla propia.
     *
     * @return array<string,string>
     */
    public static function all(string $locale = 'es'): array
    {
        $out = [];
        foreach (self::ISO_CODES as $iso) {
            $name = self::name($iso, $locale);
            if ($name !== '') {
                $out[$iso] = $name;
            }
        }

        $collator = class_exists(\Collator::class) ? \Collator::create(self::cleanLocale($locale)) : null;
        if ($collator !== null) {
            $collator->asort($out);

            return $out;
        }
        uasort($out, static fn (string $a, string $b): int => strcmp(self::fold($a), self::fold($b)));

        return $out;
    }

    public static function isEu(string $iso2): bool
    {
        return in_array(self::iso($iso2), self::EU, true);
    }

    /**
     * Forma de un código postal. Hay patrones para ES, PT, FR, DE, IT, GB, NL, BE, AD y US; en
     * los demás países vale cualquier valor no vacío de hasta 12 caracteres.
     */
    public static function validPostcode(string $iso2, string $postcode): bool
    {
        $iso = self::iso($iso2);
        $pc = trim($postcode);
        if ($pc === '') {
            return false;
        }

        switch ($iso) {
            case 'ES':
                return Spain::validPostcode($pc);
            case 'PT':
                return preg_match('/^[0-9]{4}-[0-9]{3}$/D', $pc) === 1;
            case 'FR':
            case 'DE':
            case 'IT':
                return preg_match('/^[0-9]{5}$/D', $pc) === 1;
            case 'GB':
                return preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/iD', $pc) === 1;
            case 'NL':
                return preg_match('/^[1-9][0-9]{3} ?[A-Z]{2}$/iD', $pc) === 1;
            case 'BE':
                return preg_match('/^[1-9][0-9]{3}$/D', $pc) === 1;
            case 'AD':
                return preg_match('/^AD[0-9]{3}$/iD', $pc) === 1;
            case 'US':
                return preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D', $pc) === 1;
        }

        return mb_strlen($pc, 'UTF-8') <= 12;
    }

    /** El código en mayúsculas si son dos letras; '' en otro caso. */
    private static function iso(string $iso2): string
    {
        $iso = strtoupper(trim($iso2));

        return preg_match('/^[A-Z]{2}$/D', $iso) === 1 ? $iso : '';
    }

    private static function cleanLocale(string $locale): string
    {
        $locale = trim($locale);

        return preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale) === 1 ? $locale : 'es';
    }

    private static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::ACCENTS);
    }
}
