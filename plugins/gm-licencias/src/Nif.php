<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

/**
 * Comprueba el dígito de control de un NIF, un NIE o un CIF español. Una licencia firmada con el NIF mal escrito no le sirve al
 * cliente (el programa la rechaza porque no coincide con el obligado tributario), por eso se valida antes de emitirla.
 */
final class Nif
{
    private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** El NIF limpio: solo letras y cifras, en mayúsculas. */
    public static function clean(string $nif): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $nif));
    }

    public static function valid(string $nif): bool
    {
        $nif = self::clean($nif);
        if (strlen($nif) !== 9) {
            return false;
        }
        // Persona física: ocho cifras y letra.
        if (preg_match('/^[0-9]{8}[A-Z]$/', $nif) === 1) {
            return $nif[8] === self::LETTERS[((int) substr($nif, 0, 8)) % 23];
        }
        // NIE: X, Y o Z, siete cifras y letra. La inicial vale por una cifra.
        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nif) === 1) {
            $number = (int) (strpos('XYZ', $nif[0]) . substr($nif, 1, 7));

            return $nif[8] === self::LETTERS[$number % 23];
        }
        // CIF: letra, siete cifras y control (cifra o letra).
        if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $nif) === 1) {
            $sum = 0;
            for ($i = 1; $i <= 7; ++$i) {
                $digit = (int) $nif[$i];
                if ($i % 2 === 1) {
                    $digit *= 2;
                    $digit = intdiv($digit, 10) + ($digit % 10);
                }
                $sum += $digit;
            }
            $control = (10 - ($sum % 10)) % 10;

            return $nif[8] === (string) $control || $nif[8] === substr('JABCDEFGHI', $control, 1);
        }

        return false;
    }
}
