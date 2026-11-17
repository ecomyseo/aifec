<?php
/**
 * AIFEC — utilidades del IBAN: dejarlo en forma canónica, comprobar sus dígitos de control (módulo 97) y escribirlo en grupos de cuatro.
 *
 * Solo se comprueba lo que vale para cualquier país: dos letras, dos cifras de control, entre 15 y 34 caracteres en total y
 * que el resto de la cuenta sean letras y cifras. No se lleva una tabla de longitudes por país.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsTransfer;

final class Iban
{
    private const MIN_LENGTH = 15;
    private const MAX_LENGTH = 34;

    /**
     * Mayúsculas y sin los separadores que se escriben al copiar un IBAN (espacios, también el de no separar, guiones y puntos):
     * «es91 2100-0418» → «ES9121000418». Cualquier otro carácter se deja: que sobre algo es motivo para rechazarlo, no para tirarlo.
     */
    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]|\x{00A0}/u', '', $iban));
    }

    /** ¿Es un IBAN con forma correcta y dígitos de control buenos? Acepta espacios y minúsculas. */
    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);
        $length = strlen($iban);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban) !== 1) {
            return false;
        }

        return self::mod97(substr($iban, 4) . substr($iban, 0, 4)) === 1;
    }

    /** El IBAN en grupos de cuatro separados por un espacio: «ES9121000418» → «ES91 2100 0418». Vacío si no hay nada. */
    public static function format(string $iban): string
    {
        return trim(implode(' ', str_split(self::normalize($iban), 4)));
    }

    /**
     * Resto de dividir entre 97 el número que sale de cambiar cada letra por su valor (A = 10 … Z = 35), cifra a cifra para
     * no desbordar un entero.
     */
    private static function mod97(string $text): int
    {
        $rest = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($text[$i]);
            if ($code >= 48 && $code <= 57) {
                $rest = ($rest * 10 + ($code - 48)) % 97;
            } else {
                $rest = ($rest * 100 + ($code - 55)) % 97;
            }
        }

        return $rest;
    }
}
