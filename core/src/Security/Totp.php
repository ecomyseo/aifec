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

/** Segundo factor por código temporal (RFC 6238): HMAC-SHA1, 6 dígitos, periodos de 30 segundos. */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Secreto nuevo, en Base32 sin relleno (20 bytes = 32 caracteres, el tamaño que esperan las apps). */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new \InvalidArgumentException('The secret must have at least 10 bytes');
        }

        return Base32::encode(random_bytes($bytes));
    }

    /** Código de 6 cifras de un instante (segundos Unix). El secreto está en Base32. */
    public static function code(string $secret, int $timestamp): string
    {
        return self::hotp(Base32::decode($secret), intdiv(max(0, $timestamp), self::PERIOD));
    }

    /**
     * Comprueba un código admitiendo `$window` periodos de desfase a cada lado (por defecto ±1,
     * es decir ±30 s, para los relojes que no van en hora). Compara en tiempo constante.
     */
    public static function verify(string $secret, string $code, int $timestamp, int $window = 1): bool
    {
        $code = str_replace(' ', '', $code);
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }
        $key = Base32::decode($secret);
        $counter = intdiv(max(0, $timestamp), self::PERIOD);
        $window = max(0, min($window, 10));
        $found = false;
        for ($offset = -$window; $offset <= $window; ++$offset) {
            if ($counter + $offset < 0) {
                continue;
            }
            // No se sale al primer acierto: el tiempo no revela en qué ventana estaba el código.
            if (hash_equals(self::hotp($key, $counter + $offset), $code)) {
                $found = true;
            }
        }

        return $found;
    }

    /** Dirección `otpauth://` que las apps (Google Authenticator, Aegis…) leen desde el código QR. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** HOTP (RFC 4226) con truncado dinámico; el contador son 8 bytes en orden de red. */
    private static function hotp(string $key, int $counter): string
    {
        $message = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $mac = hash_hmac('sha1', $message, $key, true);
        $offset = ord($mac[19]) & 0x0f;
        $number = ((ord($mac[$offset]) & 0x7f) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);

        return str_pad((string) ($number % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
