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
 * Contraseñas: se guardan con Argon2id (o bcrypt de coste 12 si el servidor no lo tiene) y se
 * aceptan además los formatos de las tiendas de las que se migran cuentas, para poder entrar
 * y volver a guardarlas con el algoritmo actual (`needsRehash`).
 *
 * Formatos de WordPress comprobados contra su código fuente (WordPress 7.1.2,
 * `wp-includes/pluggable.php` y `class-phpass.php`):
 *  - moderno: `$wp` + bcrypt de `base64(hmac_sha384(contraseña, 'wp-sha384'))`. Al CREAR el hash
 *    WordPress aplica `trim()` a la contraseña, pero al COMPROBARLO no: aquí se comprueba tal cual.
 *  - phpass portable: `$P$` (o `$H$` de phpBB) + coste + 8 de sal + 22 de resumen.
 *  - md5 puro: lo que WordPress comprueba cuando el hash mide 32 caracteres o menos.
 *  - WordPress rechaza contraseñas de más de 4096 caracteres; aquí también, en todos los formatos.
 */
final class Passwords
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const BCRYPT_COST = 12;

    private const MAX_LENGTH = 4096;

    public static function hash(string $password): string
    {
        if (self::argon2idAvailable()) {
            $hash = password_hash($password, self::algorithm());
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        }
        // En PHP anterior a 8 `password_hash` puede devolver false; nunca se devuelve un hash falso.
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Could not hash the password');
        }

        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '' || strlen($password) > self::MAX_LENGTH) {
            return false;
        }

        if (str_starts_with($hash, '$wp$')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

            return password_verify($prehashed, substr($hash, 3));
        }
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return self::phpassVerify($password, $hash);
        }
        if (self::isMd5($hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }
        if (self::isNative($hash)) {
            return password_verify($password, $hash);
        }

        return false;
    }

    /**
     * PrestaShop: el bcrypt normal va por `verify`; el antiguo es `md5($cookieKey . $contraseña)`
     * y necesita la clave de cookie de la tienda de origen (`_COOKIE_KEY_`).
     */
    public static function verifyPrestashopLegacy(string $password, string $hash, string $cookieKey): bool
    {
        if ($hash === '' || strlen($password) > self::MAX_LENGTH) {
            return false;
        }
        if (self::isMd5($hash)) {
            return hash_equals(strtolower($hash), md5($cookieKey . $password));
        }

        return self::verify($password, $hash);
    }

    /** Verdadero para todo lo que no sea un hash de `password_hash` con el algoritmo y el coste actuales. */
    public static function needsRehash(string $hash): bool
    {
        if (!self::isNative($hash)) {
            return true;
        }
        if (self::argon2idAvailable()) {
            return password_needs_rehash($hash, self::algorithm());
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    private static function argon2idAvailable(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /** @return string|int el valor de `PASSWORD_ARGON2ID` (cadena desde PHP 7.4) */
    private static function algorithm()
    {
        return constant('PASSWORD_ARGON2ID');
    }

    /** Un hash de `password_hash`: bcrypt (`$2y$`, y los `$2a$`/`$2b$` de otras tiendas) o Argon2. */
    private static function isNative(string $hash): bool
    {
        return preg_match('/^\$(2[abxy]|argon2id|argon2i)\$/', $hash) === 1;
    }

    private static function isMd5(string $hash): bool
    {
        return preg_match('/^[0-9a-fA-F]{32}$/', $hash) === 1;
    }

    /** phpass portable (`$P$` y `$H$`): MD5 iterado `2^coste` veces. */
    private static function phpassVerify(string $password, string $stored): bool
    {
        if (strlen($stored) !== 34) {
            return false;
        }
        $log2 = strpos(self::ITOA64, $stored[3]);
        if ($log2 === false || $log2 < 7 || $log2 > 30) {
            return false;
        }
        $count = 1 << $log2;
        $hash = md5(substr($stored, 4, 8) . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return hash_equals($stored, substr($stored, 0, 12) . self::phpassEncode64($hash, 16));
    }

    private static function phpassEncode64(string $input, int $count): string
    {
        $out = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $out .= self::ITOA64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $out .= self::ITOA64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $out .= self::ITOA64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $out .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $out;
    }
}
