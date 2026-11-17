<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\Redsys;

/**
 * Firma HMAC_SHA256_V1 de Redsys.
 *
 * La clave del comercio no firma directamente: se diversifica cifrando el número de pedido con 3DES
 * (CBC, IV a cero, sin relleno automático) y el resultado es la clave del HMAC-SHA256.
 * Los métodos son estáticos para poder llamarlos con o sin instancia.
 */
final class RedsysSigner
{
    public const VERSION = 'HMAC_SHA256_V1';

    /**
     * Firma (base64 estándar) de los parámetros ya codificados en base64.
     */
    public static function sign(string $merchantParametersB64, string $order, string $secretKeyB64): string
    {
        $key = self::diversify($order, $secretKeyB64);

        return base64_encode(hash_hmac('sha256', $merchantParametersB64, $key, true));
    }

    /**
     * Comprueba la firma de un aviso o de la vuelta del navegador. Nunca lanza: cualquier cosa rara es «no válida».
     * La firma puede venir en base64 estándar o en la variante segura para URL (- y _ por + y /).
     */
    public static function verify(string $merchantParametersB64, string $signature, string $secretKeyB64): bool
    {
        if ($merchantParametersB64 === '' || $signature === '') {
            return false;
        }

        try {
            $order = self::field(self::decodeParameters($merchantParametersB64), 'Ds_Order');
            if ($order === null || $order === '') {
                return false;
            }
            $expected = self::normalise(self::sign($merchantParametersB64, $order, $secretKeyB64));
        } catch (RedsysException $e) {
            return false;
        }

        return hash_equals($expected, self::normalise($signature));
    }

    /**
     * Decodifica Ds_MerchantParameters (base64 estándar o seguro para URL) a un array.
     *
     * @return array<string,mixed>
     */
    public static function decodeParameters(string $merchantParametersB64): array
    {
        $std = strtr($merchantParametersB64, '-_', '+/');
        $missing = strlen($std) % 4;
        if ($missing !== 0) {
            $std .= str_repeat('=', 4 - $missing);
        }
        $json = base64_decode($std, true);
        if ($json === false || $json === '') {
            throw new RedsysException('The merchant parameters are not valid base64');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RedsysException('The merchant parameters are not a JSON object');
        }

        return $data;
    }

    /**
     * Valor de un campo buscándolo sin distinguir mayúsculas (Redsys manda a veces Ds_Order y a veces DS_ORDER).
     * Devuelve null si no está o si no es un valor simple.
     *
     * @param array<string,mixed> $data
     */
    public static function field(array $data, string $name): ?string
    {
        $wanted = strtolower($name);
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) !== $wanted) {
                continue;
            }
            if (is_string($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                return (string) $value;
            }

            return null;
        }

        return null;
    }

    /** Clave diversificada: 3DES-CBC del número de pedido (relleno de ceros hasta múltiplo de 8) con la clave del comercio. */
    private static function diversify(string $order, string $secretKeyB64): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RedsysException('The openssl extension is not available');
        }
        if ($order === '') {
            throw new RedsysException('The order number is empty');
        }
        $key = base64_decode($secretKeyB64, true);
        if ($key === false || strlen($key) !== 24) {
            throw new RedsysException('The secret key must be base64 of 24 bytes');
        }
        $data = str_pad($order, (int) (ceil(strlen($order) / 8) * 8), "\0");
        $out = openssl_encrypt($data, 'des-ede3-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 8));
        if (!is_string($out) || $out === '') {
            throw new RedsysException('The order key could not be derived');
        }

        return $out;
    }

    /** Pasa la firma a la variante segura para URL y sin relleno, para comparar las dos formas por igual. */
    private static function normalise(string $signature): string
    {
        return rtrim(strtr($signature, '+/', '-_'), '=');
    }
}
