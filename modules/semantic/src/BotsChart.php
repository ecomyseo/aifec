<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

/**
 * La gráfica diaria de visitas, dibujada en el servidor como un SVG (sin JavaScript): una barra por día, con las visitas de
 * robots abajo y las llegadas desde un asistente encima. Los colores salen de las variables del panel, así que sirven en el
 * tema claro y en el oscuro.
 */
final class BotsChart
{
    private const WIDTH = 640;
    private const HEIGHT = 180;
    private const LEFT = 36;
    private const RIGHT = 6;
    private const TOP = 8;
    private const BOTTOM = 22;

    /**
     * @param array<int,array{day:string,bot:int,ref:int}> $days   un elemento por día, del más antiguo al más reciente
     * @param string                                       $title  descripción de la gráfica para quien no la ve
     * @param string                                       $botLabel nombre de la serie de robots
     * @param string                                       $refLabel nombre de la serie de visitas desde asistentes
     */
    public static function svg(array $days, string $title, string $botLabel, string $refLabel): string
    {
        $days = array_values($days);
        $count = count($days);
        $max = 1;
        foreach ($days as $day) {
            $max = max($max, (int) $day['bot'] + (int) $day['ref']);
        }
        $plotW = self::WIDTH - self::LEFT - self::RIGHT;
        $plotH = self::HEIGHT - self::TOP - self::BOTTOM;
        $slot = $count > 0 ? $plotW / $count : $plotW;
        $barW = max(1.0, $slot * 0.7);
        $base = self::TOP + $plotH;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img" aria-label="' . e($title) . '" style="width:100%;height:auto;display:block">';
        $svg .= '<title>' . e($title) . '</title>';
        $svg .= '<line x1="' . self::LEFT . '" y1="' . $base . '" x2="' . (self::WIDTH - self::RIGHT) . '" y2="' . $base . '" style="stroke:var(--muted)" stroke-width="1"/>';
        $svg .= '<line x1="' . self::LEFT . '" y1="' . self::TOP . '" x2="' . (self::WIDTH - self::RIGHT) . '" y2="' . self::TOP . '" style="stroke:var(--bg)" stroke-width="1" stroke-dasharray="3 3"/>';
        $svg .= '<text x="' . (self::LEFT - 4) . '" y="' . ($base + 3) . '" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">0</text>';
        $svg .= '<text x="' . (self::LEFT - 4) . '" y="' . (self::TOP + 4) . '" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e((string) $max) . '</text>';

        foreach ($days as $i => $day) {
            $x = self::LEFT + $i * $slot + ($slot - $barW) / 2;
            $bot = (int) $day['bot'];
            $ref = (int) $day['ref'];
            $botH = $plotH * $bot / $max;
            $refH = $plotH * $ref / $max;
            $tip = e((string) $day['day'] . ' · ' . $botLabel . ': ' . $bot . ' · ' . $refLabel . ': ' . $ref);
            if ($bot > 0) {
                $svg .= '<rect x="' . self::num($x) . '" y="' . self::num($base - $botH) . '" width="' . self::num($barW) . '" height="' . self::num($botH) . '" rx="1" style="fill:var(--accent)"><title>' . $tip . '</title></rect>';
            }
            if ($ref > 0) {
                $svg .= '<rect x="' . self::num($x) . '" y="' . self::num($base - $botH - $refH) . '" width="' . self::num($barW) . '" height="' . self::num($refH) . '" rx="1" style="fill:var(--muted)"><title>' . $tip . '</title></rect>';
            }
        }
        if ($count > 0) {
            $first = substr((string) $days[0]['day'], 5);
            $last = substr((string) $days[$count - 1]['day'], 5);
            $svg .= '<text x="' . self::LEFT . '" y="' . (self::HEIGHT - 6) . '" style="fill:var(--muted);font-size:var(--f1)">' . e($first) . '</text>';
            $svg .= '<text x="' . (self::WIDTH - self::RIGHT) . '" y="' . (self::HEIGHT - 6) . '" text-anchor="end" style="fill:var(--muted);font-size:var(--f1)">' . e($last) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** Un número de coordenada con dos decimales como mucho y siempre con punto. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
