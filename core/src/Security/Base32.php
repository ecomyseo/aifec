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

/** Base32 del RFC 4648 (alfabeto A-Z y 2-7). Se codifica sin relleno; al decodificar se tolera. */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $data): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; ++$i) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            // Solo hacen falta los bits que aún no se han emitido.
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    /**
     * Decodifica ignorando mayúsculas, espacios, guiones y relleno «=».
     *
     * @throws \InvalidArgumentException si hay un carácter que no es del alfabeto
     */
    public static function decode(string $text): string
    {
        $clean = strtoupper(str_replace([' ', '-', '='], '', $text));
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($clean);
        for ($i = 0; $i < $len; ++$i) {
            $pos = strpos(self::ALPHABET, $clean[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid Base32 character');
            }
            $buffer = ($buffer << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $out;
    }
}
