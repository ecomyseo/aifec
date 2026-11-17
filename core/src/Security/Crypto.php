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
 * Cifrado de secretos guardados (claves de API, contraseñas SMTP). Cifra y autentica: un texto
 * tocado, o abierto con otra clave, no se descifra.
 *
 * Formato: `s1:` + base64(nonce . cifrado) con libsodium (`crypto_secretbox`), o
 * `g1:` + base64(iv . etiqueta . cifrado) con AES-256-GCM de OpenSSL si no hay libsodium.
 * `decrypt` abre los dos, sea cual sea el que hubiera al cifrar.
 */
final class Crypto
{
    public const DRIVER_SODIUM = 'sodium';

    public const DRIVER_OPENSSL = 'openssl';

    private const GCM_IV_BYTES = 12;

    private const GCM_TAG_BYTES = 16;

    /** @var string clave de 32 bytes derivada */
    private $key;

    /** @var string */
    private $driver;

    /**
     * @param string      $key    clave de la instalación (se deriva una de 32 bytes con SHA-256)
     * @param string|null $driver solo para pruebas: fuerza `sodium` u `openssl`; null elige el mejor disponible
     */
    public function __construct(string $key, ?string $driver = null)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('The encryption key must not be empty');
        }
        $this->key = hash('sha256', $key, true);
        if ($driver === null) {
            $driver = self::sodiumAvailable() ? self::DRIVER_SODIUM : self::DRIVER_OPENSSL;
        }
        if ($driver === self::DRIVER_SODIUM && !self::sodiumAvailable()) {
            throw new \RuntimeException('The sodium extension is not available');
        }
        if ($driver !== self::DRIVER_SODIUM && $driver !== self::DRIVER_OPENSSL) {
            throw new \InvalidArgumentException('Unknown driver');
        }
        if ($driver === self::DRIVER_OPENSSL && !function_exists('openssl_encrypt')) {
            throw new \RuntimeException('The openssl extension is not available');
        }
        $this->driver = $driver;
    }

    public function encrypt(string $plain): string
    {
        if ($this->driver === self::DRIVER_SODIUM) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
        }

        $iv = random_bytes(self::GCM_IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_BYTES);
        if ($cipher === false || strlen($tag) !== self::GCM_TAG_BYTES) {
            throw new \RuntimeException('Could not encrypt the value');
        }

        return 'g1:' . base64_encode($iv . $tag . $cipher);
    }

    /** El texto original, o `null` si está manipulado, está mal formado o la clave no es la suya. */
    public function decrypt(string $cipher): ?string
    {
        if (strlen($cipher) < 4) {
            return null;
        }
        $raw = base64_decode(substr($cipher, 3), true);
        if ($raw === false) {
            return null;
        }
        $prefix = substr($cipher, 0, 3);
        if ($prefix === 's1:') {
            return $this->openSodium($raw);
        }
        if ($prefix === 'g1:') {
            return $this->openGcm($raw);
        }

        return null;
    }

    private function openSodium(string $raw): ?string
    {
        if (!self::sodiumAvailable() || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        return $plain === false ? null : $plain;
    }

    private function openGcm(string $raw): ?string
    {
        if (!function_exists('openssl_decrypt') || strlen($raw) < self::GCM_IV_BYTES + self::GCM_TAG_BYTES) {
            return null;
        }
        $iv = substr($raw, 0, self::GCM_IV_BYTES);
        $tag = substr($raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES);
        $plain = openssl_decrypt(substr($raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private static function sodiumAvailable(): bool
    {
        return extension_loaded('sodium') && function_exists('sodium_crypto_secretbox');
    }
}
