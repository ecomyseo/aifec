<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Pdf;

/**
 * Conversión de texto UTF-8 a WinAnsi (CP1252), que es la codificación de las fuentes base del PDF.
 *
 * Lo que no existe en CP1252 (emojis, ideogramas, letras de otros alfabetos) sale como «?».
 * Una secuencia UTF-8 inválida también sale como «?», un byte cada vez.
 */
final class PdfEncoding
{
    /** Los caracteres de CP1252 en 0x80-0x9F, que no coinciden con Latin-1: punto de código => byte. */
    private const WIN_ANSI_EXTRA = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * Parte un texto UTF-8 en caracteres (cada elemento es un carácter completo en UTF-8).
     * Si la extensión intl está, antes se compone («n» + virgulilla suelta pasa a «ñ»).
     *
     * @return string[]
     */
    public static function chars(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }
        $chars = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $b = ord($text[$i]);
            if ($b < 0x80) {
                $chars[] = $text[$i];
                ++$i;
                continue;
            }
            if ($b >= 0xC2 && $b <= 0xDF) {
                $extra = 1;
            } elseif ($b >= 0xE0 && $b <= 0xEF) {
                $extra = 2;
            } elseif ($b >= 0xF0 && $b <= 0xF4) {
                $extra = 3;
            } else {
                $chars[] = '?';
                ++$i;
                continue;
            }
            $chunk = substr($text, $i, $extra + 1);
            if (strlen($chunk) === $extra + 1 && preg_match('//u', $chunk) === 1) {
                $chars[] = $chunk;
                $i += $extra + 1;
            } else {
                $chars[] = '?';
                ++$i;
            }
        }

        return $chars;
    }

    /** Punto de código Unicode de un carácter UTF-8 ya validado por chars(). */
    public static function codePoint(string $char): int
    {
        $b0 = ord($char[0]);
        $len = strlen($char);
        if ($len === 1) {
            return $b0;
        }
        if ($len === 2) {
            return (($b0 & 0x1F) << 6) | (ord($char[1]) & 0x3F);
        }
        if ($len === 3) {
            return (($b0 & 0x0F) << 12) | ((ord($char[1]) & 0x3F) << 6) | (ord($char[2]) & 0x3F);
        }

        return (($b0 & 0x07) << 18) | ((ord($char[1]) & 0x3F) << 12) | ((ord($char[2]) & 0x3F) << 6) | (ord($char[3]) & 0x3F);
    }

    /** Byte WinAnsi de un carácter UTF-8. Los de control salen como espacio y lo que no existe, como «?». */
    public static function byteFor(string $char): int
    {
        if (strlen($char) === 1) {
            $o = ord($char);

            return ($o < 32 || $o === 127) ? 32 : $o;
        }
        $cp = self::codePoint($char);
        if ($cp >= 0xA0 && $cp <= 0xFF) {
            return $cp;
        }

        return self::WIN_ANSI_EXTRA[$cp] ?? 63;
    }

    /** Texto UTF-8 -> cadena de bytes WinAnsi. */
    public static function encode(string $text): string
    {
        $out = '';
        foreach (self::chars($text) as $char) {
            $out .= chr(self::byteFor($char));
        }

        return $out;
    }

    /** Escapa una cadena de bytes para meterla entre paréntesis en el flujo del PDF. */
    public static function escape(string $bytes): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }

    /** Cadena de texto de un diccionario del PDF (título, autor): literal si es ASCII, UTF-16BE con marca si no. */
    public static function pdfString(string $text): string
    {
        $chars = self::chars($text);
        $ascii = true;
        foreach ($chars as $char) {
            $o = ord($char[0]);
            if (strlen($char) > 1 || $o < 32 || $o === 127) {
                $ascii = false;
                break;
            }
        }
        if ($ascii) {
            return '(' . self::escape(implode('', $chars)) . ')';
        }
        $hex = 'FEFF';
        foreach ($chars as $char) {
            $cp = self::codePoint($char);
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $hex .= sprintf('%04X%04X', 0xD800 + ($cp >> 10), 0xDC00 + ($cp & 0x3FF));
            } else {
                $hex .= sprintf('%04X', $cp);
            }
        }

        return '<' . $hex . '>';
    }
}
