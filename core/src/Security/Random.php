<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Security;

/** Aleatorios de calidad criptográfica (`random_bytes` y `random_int`). */
final class Random
{
    /** Cadena hexadecimal de `$bytes` bytes aleatorios (el doble de caracteres). */
    public static function token(int $bytes = 32): string
    {
        self::assertPositive($bytes, 'bytes');

        return bin2hex(random_bytes($bytes));
    }

    /** `$bytes` bytes aleatorios en base64 apto para URL (sin relleno). */
    public static function base64url(int $bytes = 32): string
    {
        self::assertPositive($bytes, 'bytes');

        return self::encodeUrl(random_bytes($bytes));
    }

    /** Cadena de `$length` cifras decimales (puede empezar por cero), p. ej. un código de un solo uso. */
    public static function digits(int $length): string
    {
        return self::pick('0123456789', $length);
    }

    /**
     * `$length` caracteres elegidos al azar del alfabeto, sin sesgo (`random_int` descarta
     * internamente los valores que sesgarían el reparto). El alfabeto se trata como bytes.
     */
    public static function pick(string $alphabet, int $length): string
    {
        self::assertPositive($length, 'length');
        $max = strlen($alphabet) - 1;
        if ($max < 0) {
            throw new \InvalidArgumentException('The alphabet must not be empty');
        }
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /** Base64 apto para URL: `+` y `/` cambiados por `-` y `_`, sin relleno. */
    public static function encodeUrl(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function assertPositive(int $value, string $name): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('The ' . $name . ' must be at least 1');
        }
    }
}
