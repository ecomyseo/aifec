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

/**
 * Fichas contra la falsificación de peticiones (CSRF), sin estado en el servidor: el HMAC liga
 * la ficha a la sesión, al formulario y al instante en que se emitió.
 * Formato: `<instante>.<hmac en base64url>`.
 */
final class Csrf
{
    /** Margen, en segundos, para relojes de servidores que no van exactamente a la par. */
    private const FUTURE_SKEW = 60;

    public static function issue(string $sessionSecret, string $formId, int $now): string
    {
        if ($sessionSecret === '') {
            throw new \InvalidArgumentException('The session secret must not be empty');
        }

        return $now . '.' . self::mac($sessionSecret, $formId, $now);
    }

    public static function check(string $sessionSecret, string $formId, string $token, int $now, int $maxAge = 7200): bool
    {
        if ($sessionSecret === '' || preg_match('/^([0-9]{1,15})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return false;
        }
        $issuedAt = (int) $m[1];
        if (!hash_equals(self::mac($sessionSecret, $formId, $issuedAt), $m[2])) {
            return false;
        }
        $age = $now - $issuedAt;

        return $age >= -self::FUTURE_SKEW && $age <= $maxAge;
    }

    private static function mac(string $sessionSecret, string $formId, int $issuedAt): string
    {
        // El largo del identificador va delante para que dos pares (formulario, instante) distintos no den el mismo mensaje.
        $message = 'aifec-csrf|' . strlen($formId) . ':' . $formId . '|' . $issuedAt;

        return Random::encodeUrl(hash_hmac('sha256', $message, $sessionSecret, true));
    }
}
