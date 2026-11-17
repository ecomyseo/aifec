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
 * La clave firmada que se comprueba sin conexión: el mismo formato que ya leen los programas desplegados de los clientes
 * (EcomTPV), así que NO se cambia sin subir el número de versión del formato.
 *
 * Cuerpo de 15 bytes:
 *
 *     [0]      versión del formato (1)
 *     [1..9]   NIF en ASCII, relleno con espacios por la derecha
 *     [10]     unidades que cubre la clave
 *     [11..12] día de emisión, contado desde el 1/1/2026, big-endian
 *     [13..14] día hasta el que llegan las actualizaciones, o 0 si no hay tope
 *
 * Detrás va la firma ECDSA (curva prime256v1, SHA-256, en DER). Todo junto se escribe en Base32 con un alfabeto sin I, O, 0 ni 1,
 * en grupos de cinco con guiones y con el prefijo «ECTPV-». El programa comprueba la firma con la clave pública, en el propio
 * equipo del cliente: no hay servidor de activación.
 *
 * La clave privada es un secreto del sitio ($app->secret): nunca sale en una pantalla ni en el registro.
 */
final class SignedKey
{
    public const PREFIX = 'ECTPV';
    public const VERSION = 1;
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const ORIGIN = '2026-01-01 00:00:00';

    /**
     * Firma una clave. Devuelve ['key' => …, 'error' => ''] o ['key' => '', 'error' => motivo en inglés].
     *
     * @param string   $pem          clave privada EC en formato PEM
     * @param int      $now          instante de emisión (segundos Unix, UTC)
     * @param int|null $updatesUntil hasta cuándo llegan las actualizaciones (segundos Unix, UTC); null = sin tope
     *
     * @return array{key:string,error:string}
     */
    public static function generate(string $pem, string $nif, int $units, ?int $updatesUntil, int $now): array
    {
        $nif = Nif::clean($nif);
        if ($nif === '' || strlen($nif) > 9) {
            return ['key' => '', 'error' => 'The tax ID is empty or longer than nine characters'];
        }
        if (!function_exists('openssl_sign')) {
            return ['key' => '', 'error' => 'The OpenSSL extension is missing'];
        }
        if (strpos($pem, 'PRIVATE KEY') === false) {
            return ['key' => '', 'error' => 'The signing key is not a PEM private key'];
        }
        $private = @openssl_pkey_get_private($pem);
        if ($private === false) {
            return ['key' => '', 'error' => 'The signing key cannot be opened'];
        }
        $body = self::body($nif, $units, $updatesUntil, $now);
        $signature = '';
        if (!openssl_sign($body, $signature, $private, OPENSSL_ALGO_SHA256)) {
            return ['key' => '', 'error' => 'The signature failed'];
        }

        return ['key' => self::PREFIX . '-' . implode('-', str_split(self::encode($body . $signature), 5)), 'error' => ''];
    }

    /**
     * Lee una clave y comprueba su firma con la clave pública. null si no tiene la forma de una clave.
     *
     * @return array{nif:string,units:int,issued_day:int,updates_day:int,valid:bool}|null
     */
    public static function inspect(string $key, string $publicPem): ?array
    {
        $key = strtoupper((string) preg_replace('/\s+/', '', $key));
        if (strpos($key, self::PREFIX . '-') !== 0) {
            return null;
        }
        $raw = self::decode(str_replace('-', '', substr($key, strlen(self::PREFIX) + 1)));
        if ($raw === null || strlen($raw) <= 15 || ord($raw[0]) !== self::VERSION) {
            return null;
        }
        $body = substr($raw, 0, 15);
        $signature = substr($raw, 15);
        $public = @openssl_pkey_get_public($publicPem);

        return [
            'nif' => rtrim(substr($body, 1, 9)),
            'units' => ord($body[10]),
            'issued_day' => (ord($body[11]) << 8) | ord($body[12]),
            'updates_day' => (ord($body[13]) << 8) | ord($body[14]),
            'valid' => $public !== false && openssl_verify($body, $signature, $public, OPENSSL_ALGO_SHA256) === 1,
        ];
    }

    /** El día (desde el 1/1/2026, UTC) de un instante. */
    public static function dayOf(int $timestamp): int
    {
        return max(0, min(65535, intdiv($timestamp - self::originTimestamp(), 86400)));
    }

    private static function originTimestamp(): int
    {
        return (new \DateTimeImmutable(self::ORIGIN, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private static function body(string $nif, int $units, ?int $updatesUntil, int $now): string
    {
        $issued = self::dayOf($now);
        $until = $updatesUntil !== null ? self::dayOf($updatesUntil) : 0;
        $units = max(1, min(255, $units));

        return chr(self::VERSION) . str_pad($nif, 9, ' ', STR_PAD_RIGHT) . chr($units)
            . chr(($issued >> 8) & 0xFF) . chr($issued & 0xFF) . chr(($until >> 8) & 0xFF) . chr($until & 0xFF);
    }

    private static function encode(string $data): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($data); $i < $n; ++$i) {
            $buffer = (($buffer << 8) | ord($data[$i])) & 0xFFFFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::ALPHABET[($buffer >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    private static function decode(string $text): ?string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($text); $i < $n; ++$i) {
            $value = strpos(self::ALPHABET, $text[$i]);
            if ($value === false) {
                return null;
            }
            $buffer = (($buffer << 5) | $value) & 0xFFFFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($buffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $out;
    }
}
