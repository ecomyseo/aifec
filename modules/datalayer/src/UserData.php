<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

/**
 * Correo y teléfono resumidos con SHA-256 para las conversiones mejoradas de Google y la API de conversiones de Meta.
 *
 * Se normalizan como piden esos servicios (minúsculas, sin espacios; en el correo de Gmail, sin puntos ni «+etiqueta») y se
 * resumen. Nunca se manda ni se imprime el dato en claro.
 */
final class UserData
{
    /** El correo normalizado, o '' si no parece un correo. */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return '';
        }
        $parts = explode('@', $email);
        $domain = $parts[1];
        $local = $parts[0];
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $plus = strpos($local, '+');
            $local = str_replace('.', '', $plus === false ? $local : substr($local, 0, $plus));
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /**
     * El teléfono normalizado: solo cifras con «+» delante si lo traía (o «00»). Sin cifras suficientes, ''.
     * No se inventa el prefijo del país: si el número no lo trae, se resume tal cual.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $plus = strpos($phone, '+') === 0 || strpos($phone, '00') === 0;
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strpos($phone, '00') === 0) {
            $digits = (string) substr($digits, 2);
        }
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return '';
        }

        return ($plus ? '+' : '') . $digits;
    }

    /** SHA-256 en hexadecimal del correo normalizado ('' si no vale). */
    public static function emailHash(string $email): string
    {
        $normal = self::normalizeEmail($email);

        return $normal === '' ? '' : hash('sha256', $normal);
    }

    /** SHA-256 en hexadecimal del teléfono normalizado ('' si no vale). */
    public static function phoneHash(string $phone): string
    {
        $normal = self::normalizePhone($phone);

        return $normal === '' ? '' : hash('sha256', $normal);
    }
}
