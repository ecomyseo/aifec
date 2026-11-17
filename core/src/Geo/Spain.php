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
 * Geografía y documentos de España: provincia y zona de envío a partir del código postal,
 * validación de NIF, NIE y CIF, y teléfonos nacionales.
 */
final class Spain
{
    private const TAX_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** Letras de control de un CIF, indexadas por el dígito de control (0 a 9). */
    private const CIF_LETTERS = 'JABCDEFGHI';

    /**
     * Caché de la tabla de provincias: se lee del fichero una sola vez por proceso.
     *
     * @var array<int,array{code:string,prefix:string,name:string,region:string}>|null
     */
    private static ?array $provinces = null;

    /**
     * Caché de la misma tabla indexada por prefijo ('01' a '52').
     *
     * @var array<string,array{code:string,prefix:string,name:string,region:string}>|null
     */
    private static ?array $byPrefix = null;

    /**
     * Las 52 provincias: `code` (el de WooCommerce), `prefix` (dos primeras cifras del código
     * postal), `name` y `region`.
     *
     * @return array<int,array{code:string,prefix:string,name:string,region:string}>
     */
    public static function provinces(): array
    {
        self::load();

        return self::$provinces ?? [];
    }

    /**
     * La provincia de un código postal, o null si el código no es válido.
     *
     * @return array{code:string,prefix:string,name:string,region:string}|null
     */
    public static function provinceByPostcode(string $cp): ?array
    {
        $cp = trim($cp);
        if (!self::validPostcode($cp)) {
            return null;
        }
        self::load();

        return (self::$byPrefix ?? [])[substr($cp, 0, 2)] ?? null;
    }

    /**
     * Cinco cifras con prefijo de 01 a 52; se admiten espacios alrededor.
     */
    public static function validPostcode(string $cp): bool
    {
        $cp = trim($cp);
        if (preg_match('/^[0-9]{5}$/D', $cp) !== 1) {
            return false;
        }
        $prefix = (int) substr($cp, 0, 2);

        return $prefix >= 1 && $prefix <= 52;
    }

    /**
     * Zona de envío: `peninsula`, `baleares`, `canarias`, `ceuta`, `melilla`, o '' si el código
     * postal no es válido.
     */
    public static function zone(string $cp): string
    {
        $cp = trim($cp);
        if (!self::validPostcode($cp)) {
            return '';
        }
        $prefix = (int) substr($cp, 0, 2);
        if ($prefix === 7) {
            return 'baleares';
        }
        if ($prefix === 35 || $prefix === 38) {
            return 'canarias';
        }
        if ($prefix === 51) {
            return 'ceuta';
        }
        if ($prefix === 52) {
            return 'melilla';
        }

        return 'peninsula';
    }

    public static function isPeninsula(string $cp): bool
    {
        return self::zone($cp) === 'peninsula';
    }

    /**
     * Mayúsculas y solo letras y cifras: «12.345.678-z» pasa a «12345678Z».
     */
    public static function normalizeTaxId(string $id): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($id));
    }

    /**
     * NIF (DNI), NIE o CIF con su letra o dígito de control correctos. Admite minúsculas,
     * espacios y guiones.
     */
    public static function validTaxId(string $id): bool
    {
        $v = self::normalizeTaxId($id);

        if (preg_match('/^[0-9]{8}[A-Z]$/D', $v) === 1) {
            return self::TAX_LETTERS[(int) substr($v, 0, 8) % 23] === $v[8];
        }

        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/D', $v) === 1) {
            $number = (string) strpos('XYZ', $v[0]) . substr($v, 1, 7);

            return self::TAX_LETTERS[(int) $number % 23] === $v[8];
        }

        if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/D', $v) === 1) {
            return self::validCif($v);
        }

        return false;
    }

    /**
     * Teléfono nacional de 9 cifras (empieza por 6, 7, 8 o 9) sin prefijo ni separadores;
     * quita +34 y 0034. Devuelve '' si no es un teléfono español válido.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);
        $digits = (string) preg_replace('/^(?:\+|00)?34(?=[6789][0-9]{8}$)/', '', $digits);

        return preg_match('/^[6789][0-9]{8}$/D', $digits) === 1 ? $digits : '';
    }

    /**
     * Control de un CIF ya normalizado: letra (N, P, Q, R, S, W), dígito (A, B, E, H) o
     * cualquiera de los dos (el resto de entidades).
     */
    private static function validCif(string $v): bool
    {
        $sum = 0;
        for ($i = 1; $i <= 7; ++$i) {
            $digit = (int) $v[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                $digit = intdiv($digit, 10) + $digit % 10;
            }
            $sum += $digit;
        }
        $control = (10 - $sum % 10) % 10;
        $asDigit = (string) $control;
        $asLetter = self::CIF_LETTERS[$control];
        $given = $v[8];
        $first = $v[0];

        if (strpos('ABEH', $first) !== false) {
            return $given === $asDigit;
        }
        if (strpos('NPQRSW', $first) !== false) {
            return $given === $asLetter;
        }

        return $given === $asDigit || $given === $asLetter;
    }

    private static function load(): void
    {
        if (self::$provinces !== null) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/data/geo/es-provinces.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Province table not found: ' . $file);
        }
        $rows = require $file;
        $rows = is_array($rows) ? array_values($rows) : [];
        $byPrefix = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['prefix'])) {
                $byPrefix[(string) $row['prefix']] = $row;
            }
        }
        self::$provinces = $rows;
        self::$byPrefix = $byPrefix;
    }
}
