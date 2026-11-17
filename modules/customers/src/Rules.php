<?php
/**
 * AIFEC — reglas de los datos de un cliente: correo, teléfono, NIF, nombres y direcciones. Funciones puras.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers;

use Aifec\Assistant\CustomerData;
use Aifec\Geo\Countries;
use Aifec\Geo\Spain;

/**
 * Los métodos que validan lanzan \InvalidArgumentException con un mensaje en inglés (las pantallas lo pasan por t());
 * los que normalizan devuelven '' o null cuando el dato no vale, y quien llama decide si es un error.
 */
final class Rules
{
    /** Texto de un dato de entrada: lo que no es escalar cuenta como vacío. */
    public static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Casilla o interruptor: 1, «1», «on», «true», «yes» y true valen como marcados. */
    public static function bool($value): bool
    {
        return in_array($value, [1, '1', true, 'on', 'true', 'yes'], true);
    }

    /** Recorta, junta los espacios repetidos y corta a $max caracteres. */
    public static function text($value, int $max): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim(self::str($value)));

        return mb_substr(is_string($clean) ? $clean : '', 0, $max, 'UTF-8');
    }

    /** Correo en minúsculas, o '' si no es una dirección válida. */
    public static function email(string $email): string
    {
        $email = strtolower(trim($email));

        return $email !== '' && strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /**
     * Teléfono normalizado: el español de nueve cifras sin prefijo, o el internacional con su «+».
     * '' si va vacío, null si no es un teléfono.
     */
    public static function phone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $es = Spain::normalizePhone($phone);
        if ($es !== '') {
            return $es;
        }
        $digits = preg_replace('/[\s().-]+/', '', $phone);

        return is_string($digits) && preg_match('/^\+?[0-9]{6,15}$/D', $digits) === 1 ? $digits : null;
    }

    /**
     * NIF, NIE, CIF o número de IVA de otro país, en mayúsculas y sin separadores.
     * '' si va vacío, null si no vale.
     */
    public static function vat(string $vat): ?string
    {
        if (trim($vat) === '') {
            return '';
        }
        $v = Spain::normalizeTaxId($vat);

        return Spain::validTaxId($v) || preg_match('/^[A-Z]{2}[A-Z0-9]{4,16}$/D', $v) === 1 ? $v : null;
    }

    /**
     * Nombre y apellidos con mayúsculas de nombre propio.
     *
     * @return array{first_name:string,last_name:string}
     */
    public static function names(string $first, string $last): array
    {
        $clean = CustomerData::clean(['first_name' => $first, 'last_name' => $last]);

        return ['first_name' => (string) $clean['first_name'], 'last_name' => (string) $clean['last_name']];
    }

    /** Ciudad con mayúsculas de nombre propio. */
    public static function city(string $city): string
    {
        $clean = CustomerData::clean(['city' => $city]);

        return (string) $clean['city'];
    }

    /** Código de idioma («es», «en»): las dos primeras letras, o el de reserva. */
    public static function locale(string $locale, string $fallback): string
    {
        $lang = strtolower(substr(trim($locale), 0, 2));

        return preg_match('/^[a-z]{2}$/D', $lang) === 1 ? $lang : $fallback;
    }

    /**
     * Valida y normaliza una dirección completa. Devuelve las columnas de `addresses` que se guardan
     * (sin las casillas de «por defecto», que decide el servicio).
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException con el motivo, en inglés
     */
    public static function address(array $in): array
    {
        $names = self::names(self::text(isset($in['first_name']) ? $in['first_name'] : '', 120), self::text(isset($in['last_name']) ? $in['last_name'] : '', 120));
        if ($names['first_name'] === '') {
            throw new \InvalidArgumentException('The first name is required');
        }
        $line1 = self::text(isset($in['line1']) ? $in['line1'] : '', 191);
        if ($line1 === '') {
            throw new \InvalidArgumentException('The address line is required');
        }
        $city = self::city(self::text(isset($in['city']) ? $in['city'] : '', 120));
        if ($city === '') {
            throw new \InvalidArgumentException('The city is required');
        }
        $country = strtoupper(self::text(isset($in['country']) && self::str($in['country']) !== '' ? $in['country'] : 'ES', 20));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new \InvalidArgumentException('The country must be a two-letter code');
        }
        $postcode = strtoupper(self::text(isset($in['postcode']) ? $in['postcode'] : '', 16));
        if ($postcode === '') {
            throw new \InvalidArgumentException('The postcode is required');
        }
        if (!Countries::validPostcode($country, $postcode)) {
            throw new \InvalidArgumentException('The postcode is not valid for that country');
        }
        $region = self::text(isset($in['region']) ? $in['region'] : '', 16);
        if ($region === '' && $country === 'ES') {
            $province = Spain::provinceByPostcode($postcode);
            $region = $province !== null ? $province['prefix'] : '';
        }
        $phone = self::phone(self::str(isset($in['phone']) ? $in['phone'] : ''));
        if ($phone === null) {
            throw new \InvalidArgumentException('The phone number is not valid');
        }
        $vat = self::vat(self::str(isset($in['vat_id']) ? $in['vat_id'] : ''));
        if ($vat === null) {
            throw new \InvalidArgumentException('The tax ID is not valid');
        }
        $label = self::text(isset($in['label']) ? $in['label'] : '', 60);
        $company = self::text(isset($in['company']) ? $in['company'] : '', 160);
        $line2 = self::text(isset($in['line2']) ? $in['line2'] : '', 191);

        return [
            'label' => $label !== '' ? $label : null,
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'] !== '' ? $names['last_name'] : null,
            'company' => $company !== '' ? $company : null,
            'vat_id' => $vat !== '' ? $vat : null,
            'phone' => $phone !== '' ? $phone : null,
            'line1' => $line1,
            'line2' => $line2 !== '' ? $line2 : null,
            'postcode' => $postcode,
            'city' => $city,
            'region' => $region !== '' ? $region : null,
            'country' => $country,
        ];
    }
}
