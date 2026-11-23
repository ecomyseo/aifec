<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

/**
 * Lector mínimo de CBOR (RFC 8949): lo justo para leer lo que mandan las passkeys: enteros, cadenas de bytes y de texto,
 * listas, mapas y los valores simples. Todo lo demás (etiquetas, decimales, longitudes indefinidas) se rechaza.
 */
final class Cbor
{
    private const MAX_DEPTH = 8;

    /**
     * Decodifica un valor CBOR. Con $offset se puede leer uno que no ocupa todo el texto (la clave pública va pegada a lo que sigue).
     *
     * @param int $offset desde dónde leer; al volver, dónde acaba el valor
     *
     * @return mixed
     */
    public static function decode(string $data, int &$offset = 0)
    {
        return self::read($data, $offset, 0);
    }

    /**
     * @return mixed
     */
    private static function read(string $d, int &$o, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('CBOR too deep');
        }
        if ($o >= strlen($d)) {
            throw new \InvalidArgumentException('CBOR truncated');
        }
        $initial = ord($d[$o]);
        ++$o;
        $major = $initial >> 5;
        $info = $initial & 0x1f;
        if ($major === 7) {
            if ($info === 20) {
                return false;
            }
            if ($info === 21) {
                return true;
            }
            if ($info === 22 || $info === 23) {
                return null;
            }
            throw new \InvalidArgumentException('CBOR value not supported');
        }
        $length = self::length($d, $o, $info);
        switch ($major) {
            case 0:
                return $length;
            case 1:
                return -1 - $length;
            case 2:
            case 3:
                if ($length < 0 || $o + $length > strlen($d)) {
                    throw new \InvalidArgumentException('CBOR truncated');
                }
                $text = substr($d, $o, $length);
                $o += $length;

                return $text;
            case 4:
                if ($length > 1024) {
                    throw new \InvalidArgumentException('CBOR list too long');
                }
                $list = [];
                for ($i = 0; $i < $length; ++$i) {
                    $list[] = self::read($d, $o, $depth + 1);
                }

                return $list;
            case 5:
                if ($length > 1024) {
                    throw new \InvalidArgumentException('CBOR map too long');
                }
                $map = [];
                for ($i = 0; $i < $length; ++$i) {
                    $key = self::read($d, $o, $depth + 1);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \InvalidArgumentException('CBOR key not supported');
                    }
                    $map[$key] = self::read($d, $o, $depth + 1);
                }

                return $map;
        }
        throw new \InvalidArgumentException('CBOR type not supported');
    }

    private static function length(string $d, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info] ?? 0;
        if ($bytes === 0 || $o + $bytes > strlen($d)) {
            throw new \InvalidArgumentException('CBOR length not supported');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; ++$i) {
            $value = ($value << 8) | ord($d[$o + $i]);
        }
        $o += $bytes;
        if ($value < 0 || $value > 0x7fffffff) {
            throw new \InvalidArgumentException('CBOR length too large');
        }

        return $value;
    }
}
