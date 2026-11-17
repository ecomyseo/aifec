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
 * Fuentes base del PDF (las 14 estándar, que no hay que incrustar) y sus anchos.
 *
 * Los anchos son los de los ficheros AFM de Adobe para Helvetica y Helvetica-Bold, en milésimas de
 * em, indexados por el byte WinAnsi (CP1252) de 0 a 255. Helvetica-Oblique usa los de Helvetica
 * y Helvetica-BoldOblique los de Helvetica-Bold. Courier es de ancho fijo (600).
 */
final class PdfFontMetrics
{
    private const COURIER_WIDTH = 600;

    /** @var array<string,int[]> caché de tablas ya montadas */
    private static array $cache = [];

    /** Nombre PostScript de la fuente para una familia («Helvetica», «Arial», «Courier») y un estilo («», «B», «I», «BI»). */
    public static function baseFont(string $family, string $style): string
    {
        $fam = strtolower(trim($family));
        if ($fam === 'helvetica' || $fam === 'arial') {
            $prefix = 'Helvetica';
        } elseif ($fam === 'courier') {
            $prefix = 'Courier';
        } else {
            throw new PdfException('Unsupported font family: ' . $family);
        }
        $up = strtoupper($style);
        if ($up !== '' && trim($up, 'BI') !== '') {
            throw new PdfException('Unsupported font style: ' . $style);
        }
        $bold = strpos($up, 'B') !== false;
        $italic = strpos($up, 'I') !== false;

        return $prefix . ($bold || $italic ? '-' : '') . ($bold ? 'Bold' : '') . ($italic ? 'Oblique' : '');
    }

    /**
     * Anchos de los 256 bytes WinAnsi para una fuente base.
     *
     * @return int[]
     */
    public static function widths(string $baseFont): array
    {
        if (isset(self::$cache[$baseFont])) {
            return self::$cache[$baseFont];
        }
        if (strncmp($baseFont, 'Courier', 7) === 0) {
            $table = array_fill(0, 256, self::COURIER_WIDTH);
        } elseif (strpos($baseFont, 'Bold') !== false) {
            $table = self::helveticaBold();
        } elseif (strncmp($baseFont, 'Helvetica', 9) === 0) {
            $table = self::helvetica();
        } else {
            throw new PdfException('Unknown base font: ' . $baseFont);
        }
        self::$cache[$baseFont] = $table;

        return $table;
    }

    /** Ancho en milésimas de em de una cadena de bytes WinAnsi. */
    public static function width(string $baseFont, string $bytes): int
    {
        $table = self::widths($baseFont);
        $sum = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; ++$i) {
            $sum += $table[ord($bytes[$i])];
        }

        return $sum;
    }

    /** @return int[] */
    private static function helvetica(): array
    {
        return array_merge(array_fill(0, 32, 278), [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350,
            556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
            350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
        ]);
    }

    /** @return int[] */
    private static function helveticaBold(): array
    {
        return array_merge(array_fill(0, 32, 278), [
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350,
            556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
            350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
            611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
        ]);
    }
}
